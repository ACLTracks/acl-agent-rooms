<?php
/** Safe bounded text/code extraction. @package ACL_Agent_Rooms */
namespace ACL\AgentRooms\Services;

use ACL\AgentRooms\Repositories\RoomFileRepository;

if ( ! defined( 'ABSPATH' ) ) {
	exit; }

class RoomFileExtractionService {
	public const MAX_SOURCE_BYTES    = 2097152;
	public const MAX_EXTRACTED_CHARS = 500000;
	private StorageBridge $storage;
	private RoomFileRepository $files;
	public function __construct( ?StorageBridge $storage = null, ?RoomFileRepository $files = null ) {
		$this->storage = $storage ?: new StorageBridge();
		$this->files   = $files ?: new RoomFileRepository();}
	public static function supported_extensions(): array {
		return array( 'txt', 'md', 'markdown', 'json', 'yaml', 'yml', 'xml', 'csv', 'php', 'js', 'jsx', 'ts', 'tsx', 'css', 'scss', 'html', 'htm', 'py', 'java', 'c', 'h', 'cpp', 'hpp', 'cs', 'go', 'rs', 'sql', 'sh', 'ps1', 'ini', 'env.example' ); }
	public function process( int $file_id ) {
		$file    = $this->files->find( $file_id );
		$version = $file ? $this->files->active_version( $file_id ) : null;
		if ( ! $file || ! $version || ! empty( $file['removed_at'] ) ) {
			return new \WP_Error( 'acl_ar_room_file_not_found', __( 'Room file not found.', 'acl-agent-rooms' ), array( 'status' => 404 ) );}
		if ( 'ready' === (string) $version['extraction_status'] && hash_equals( (string) $file['content_hash'], (string) $version['content_hash'] ) ) {
			return array(
				'reused'  => true,
				'file'    => $file,
				'version' => $version,
			);}
		if ( ! in_array( (string) $file['file_extension'], self::supported_extensions(), true ) ) {
			$this->files->fail_extraction( $file_id, (int) $version['id'], 'unsupported_type' );
			return new \WP_Error( 'acl_ar_room_file_unsupported', __( 'This file type cannot be used as room context.', 'acl-agent-rooms' ), array( 'status' => 415 ) );}
		$read = $this->storage->read( (int) $version['storage_asset_id'], (int) $version['storage_owner_user_id'], self::MAX_SOURCE_BYTES );
		if ( is_wp_error( $read ) ) {
			$this->files->fail_extraction( $file_id, (int) $version['id'], $read->get_error_code() );
			return $read;
		}$bytes = (string) $read['bytes'];
		if ( strpos( $bytes, "\0" ) !== false || $this->binary_ratio( $bytes ) > 0.02 ) {
			$this->files->fail_extraction( $file_id, (int) $version['id'], 'binary_content' );
			return new \WP_Error( 'acl_ar_room_file_binary', __( 'Binary file content cannot be indexed.', 'acl-agent-rooms' ), array( 'status' => 415 ) );}
		if ( substr( $bytes, 0, 3 ) === "\xEF\xBB\xBF" ) {
			$bytes = substr( $bytes, 3 );
		}if ( 1 !== preg_match( '//u', $bytes ) ) {
			if ( function_exists( 'mb_convert_encoding' ) ) {
				$bytes = mb_convert_encoding( $bytes, 'UTF-8', 'UTF-8, Windows-1252, ISO-8859-1' );
			}if ( 1 !== preg_match( '//u', $bytes ) ) {
				$this->files->fail_extraction( $file_id, (int) $version['id'], 'invalid_encoding' );
				return new \WP_Error( 'acl_ar_room_file_encoding', __( 'File encoding is not valid text.', 'acl-agent-rooms' ), array( 'status' => 415 ) );}
		}
		$text = str_replace( array( "\r\n", "\r" ), "\n", $bytes );
		if ( strlen( $text ) > self::MAX_EXTRACTED_CHARS ) {
			$text = function_exists( 'mb_strcut' ) ? mb_strcut( $text, 0, self::MAX_EXTRACTED_CHARS, 'UTF-8' ) : substr( $text, 0, self::MAX_EXTRACTED_CHARS );
		}$lines = explode( "\n", $text );
		$search = $this->normalize_search( $text . ' ' . (string) $file['room_label'] . ' ' . (string) $file['original_filename'] );
		if ( ! $this->files->save_extraction( $file_id, (int) $version['id'], $text, $search, count( $lines ), (string) $read['checksum'] ) ) {
			return new \WP_Error( 'acl_ar_room_file_index_failed', __( 'Extracted file content could not be saved.', 'acl-agent-rooms' ) );}
		return array(
			'reused'  => false,
			'file'    => $this->files->find( $file_id ),
			'version' => $this->files->active_version( $file_id ),
		);
	}
	private function binary_ratio( string $bytes ): float {
		if ( $bytes === '' ) {
			return 0.0;
		}$sample = substr( $bytes, 0, 8192 );
		$count   = preg_match_all( '/[\x01-\x08\x0B\x0C\x0E-\x1F]/', $sample, $m );
		return false === $count ? 1.0 : $count / max( 1, strlen( $sample ) );}
	private function normalize_search( string $text ): string {
		$text = function_exists( 'mb_strtolower' ) ? mb_strtolower( $text, 'UTF-8' ) : strtolower( $text );
		$text = preg_replace( '/[^\p{L}\p{N}_.$:\/-]+/u', ' ', $text );
		return trim( (string) preg_replace( '/\s+/', ' ', $text ) );}
}
