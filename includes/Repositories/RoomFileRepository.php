<?php
// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange -- This file operates on plugin-owned operational tables and requires uncached transaction/read-after-write truth.
// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- Identifiers are fixed $wpdb->prefix suffixes, fixed internal schema fragments, or allowlisted names; runtime values use placeholders or wpdb mutation arrays.
// phpcs:disable PluginCheck.Security.DirectDB.UnescapedDBParameter -- PHPCS cannot model the fixed identifier provenance above; request data is never used as an SQL identifier.
/** Durable room-to-storage-asset associations and version lineage. @package ACL_Agent_Rooms */
namespace ACL\AgentRooms\Repositories;

use ACL\AgentRooms\Support\Time;

if ( ! defined( 'ABSPATH' ) ) {
	exit; }

class RoomFileRepository {
	private function files_table(): string {
		global $wpdb;
		return $wpdb->prefix . 'acl_ar_room_files'; }
	private function versions_table(): string {
		global $wpdb;
		return $wpdb->prefix . 'acl_ar_room_file_versions'; }

	public function for_room( int $room_id, bool $include_removed = false ): array {
		global $wpdb;
		$where = $include_removed ? '' : ' AND removed_at IS NULL';
		return (array) $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . $this->files_table() . " WHERE room_id=%d{$where} ORDER BY priority DESC,id ASC", $room_id ), ARRAY_A );
	}

	public function find( int $id ): ?array {
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . $this->files_table() . ' WHERE id=%d', $id ), ARRAY_A );
		return $row ?: null; }
	public function active_version( int $room_file_id ): ?array {
		global $wpdb;
		$files    = $this->files_table();
		$versions = $this->versions_table();
		$row      = $wpdb->get_row( $wpdb->prepare( "SELECT v.* FROM {$versions} v INNER JOIN {$files} f ON f.active_version_id=v.id WHERE f.id=%d AND f.removed_at IS NULL", $room_file_id ), ARRAY_A );
		return $row ?: null; }
	public function version( int $version_id ): ?array {
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . $this->versions_table() . ' WHERE id=%d', $version_id ), ARRAY_A );
		return $row ?: null; }

	public function attach( int $room_id, array $asset, int $actor_id ) {
		global $wpdb;
		$now      = Time::mysql_gmt();
		$asset_id = absint( $asset['id'] ?? 0 );
		$owner_id = absint( $asset['owner_user_id'] ?? 0 );
		$hash     = strtolower( (string) ( $asset['checksum'] ?? '' ) );
		if ( ! $asset_id || ! $owner_id || ! preg_match( '/^[a-f0-9]{64}$/', $hash ) ) {
			return new \WP_Error( 'acl_ar_room_file_asset_invalid', __( 'Storage asset metadata is incomplete.', 'acl-agent-rooms' ) );}
		$active_key = hash( 'sha256', $room_id . ':' . $asset_id );
		$wpdb->query( 'START TRANSACTION' );
		$inserted = $wpdb->insert(
			$this->files_table(),
			array(
				'room_id'               => $room_id,
				'storage_asset_id'      => $asset_id,
				'storage_owner_user_id' => $owner_id,
				'added_by_user_id'      => $actor_id,
				'room_label'            => sanitize_text_field( (string) $asset['original_name'] ),
				'original_filename'     => sanitize_file_name( (string) $asset['original_name'] ),
				'mime_type'             => sanitize_mime_type( (string) $asset['mime_type'] ),
				'file_extension'        => sanitize_key( (string) $asset['extension'] ),
				'file_size'             => absint( $asset['size'] ),
				'content_hash'          => $hash,
				'context_enabled'       => 1,
				'priority'              => 0,
				'extraction_status'     => 'pending',
				'indexing_status'       => 'pending',
				'active_key'            => $active_key,
				'created_at'            => $now,
				'updated_at'            => $now,
			),
			array( '%d', '%d', '%d', '%d', '%s', '%s', '%s', '%s', '%d', '%s', '%d', '%d', '%s', '%s', '%s', '%s', '%s' )
		);
		if ( false === $inserted ) {
			$wpdb->query( 'ROLLBACK' );
			return new \WP_Error( 'acl_ar_room_file_duplicate', __( 'This storage asset is already attached to the room.', 'acl-agent-rooms' ), array( 'status' => 409 ) );}
		$file_id    = (int) $wpdb->insert_id;
		$version_id = $this->insert_version( $file_id, $room_id, $asset, $actor_id, $now );
		if ( is_wp_error( $version_id ) ) {
			$wpdb->query( 'ROLLBACK' );
			return $version_id;}
		$updated = $wpdb->update( $this->files_table(), array( 'active_version_id' => $version_id ), array( 'id' => $file_id ), array( '%d' ), array( '%d' ) );
		if ( false === $updated ) {
			$wpdb->query( 'ROLLBACK' );
			return new \WP_Error( 'acl_ar_room_file_version_failed', __( 'Room file version could not be activated.', 'acl-agent-rooms' ) );}
		$wpdb->query( 'COMMIT' );
		return $this->find( $file_id );
	}

	public function replace( int $file_id, array $asset, int $actor_id ) {
		global $wpdb;
		$file = $this->find( $file_id );
		if ( ! $file || ! empty( $file['removed_at'] ) ) {
			return new \WP_Error( 'acl_ar_room_file_not_found', __( 'Room file not found.', 'acl-agent-rooms' ), array( 'status' => 404 ) );
		}$now    = Time::mysql_gmt();
		$new_key = hash( 'sha256', (int) $file['room_id'] . ':' . absint( $asset['id'] ?? 0 ) );
		$wpdb->query( 'START TRANSACTION' );
		$version_id = $this->insert_version( $file_id, (int) $file['room_id'], $asset, $actor_id, $now );
		if ( is_wp_error( $version_id ) ) {
			$wpdb->query( 'ROLLBACK' );
			return $version_id;}
		if ( ! empty( $file['active_version_id'] ) ) {
			$wpdb->update( $this->versions_table(), array( 'retired_at' => $now ), array( 'id' => (int) $file['active_version_id'] ), array( '%s' ), array( '%d' ) );}
		$data = array(
			'storage_asset_id'      => absint( $asset['id'] ),
			'storage_owner_user_id' => absint( $asset['owner_user_id'] ),
			'original_filename'     => sanitize_file_name( (string) $asset['original_name'] ),
			'mime_type'             => sanitize_mime_type( (string) $asset['mime_type'] ),
			'file_extension'        => sanitize_key( (string) $asset['extension'] ),
			'file_size'             => absint( $asset['size'] ),
			'content_hash'          => strtolower( (string) $asset['checksum'] ),
			'extraction_status'     => 'pending',
			'indexing_status'       => 'pending',
			'active_version_id'     => $version_id,
			'active_key'            => $new_key,
			'updated_at'            => $now,
		);
		if ( false === $wpdb->update( $this->files_table(), $data, array( 'id' => $file_id ) ) ) {
			$wpdb->query( 'ROLLBACK' );
			return new \WP_Error( 'acl_ar_room_file_replace_failed', __( 'Room file replacement could not be activated.', 'acl-agent-rooms' ) );
		}$wpdb->query( 'COMMIT' );
		return $this->find( $file_id );
	}

	private function insert_version( int $file_id, int $room_id, array $asset, int $actor_id, string $now ) {
		global $wpdb;
		$ok = $wpdb->insert(
			$this->versions_table(),
			array(
				'room_id'               => $room_id,
				'room_file_id'          => $file_id,
				'storage_asset_id'      => absint( $asset['id'] ),
				'storage_owner_user_id' => absint( $asset['owner_user_id'] ),
				'created_by_user_id'    => $actor_id,
				'original_filename'     => sanitize_file_name( (string) $asset['original_name'] ),
				'mime_type'             => sanitize_mime_type( (string) $asset['mime_type'] ),
				'file_extension'        => sanitize_key( (string) $asset['extension'] ),
				'file_size'             => absint( $asset['size'] ),
				'content_hash'          => strtolower( (string) $asset['checksum'] ),
				'extraction_status'     => 'pending',
				'indexing_status'       => 'pending',
				'created_at'            => $now,
				'activated_at'          => $now,
			),
			array( '%d', '%d', '%d', '%d', '%d', '%s', '%s', '%s', '%d', '%s', '%s', '%s', '%s', '%s' )
		);
		return false === $ok ? new \WP_Error( 'acl_ar_room_file_version_failed', __( 'Room file version could not be created.', 'acl-agent-rooms' ) ) : (int) $wpdb->insert_id;
	}

	public function update_metadata( int $id, array $data ): bool {
		global $wpdb;
		$allowed = array();
		$formats = array();
		if ( array_key_exists( 'room_label', $data ) ) {
			$allowed['room_label'] = sanitize_text_field( (string) $data['room_label'] );
			$formats[]             = '%s';
		}if ( array_key_exists( 'context_enabled', $data ) ) {
			$allowed['context_enabled'] = ! empty( $data['context_enabled'] ) ? 1 : 0;
			$formats[]                  = '%d';
		}if ( array_key_exists( 'priority', $data ) ) {
			$allowed['priority'] = max( -1000, min( 1000, (int) $data['priority'] ) );
			$formats[]           = '%d';
		}$allowed['updated_at'] = Time::mysql_gmt();
		$formats[]              = '%s';
		return false !== $wpdb->update( $this->files_table(), $allowed, array( 'id' => $id ), $formats, array( '%d' ) ); }
	public function remove( int $id ): bool {
		global $wpdb;
		$now = Time::mysql_gmt();
		return false !== $wpdb->update(
			$this->files_table(),
			array(
				'removed_at'        => $now,
				'active_key'        => null,
				'context_enabled'   => 0,
				'extraction_status' => 'removed',
				'indexing_status'   => 'removed',
				'updated_at'        => $now,
			),
			array( 'id' => $id ),
			array( '%s', '%s', '%d', '%s', '%s', '%s' ),
			array( '%d' )
		); }

	public function save_extraction( int $file_id, int $version_id, string $text, string $search_text, int $line_count, string $hash ): bool {
		global $wpdb;
		$ok = $wpdb->update(
			$this->versions_table(),
			array(
				'extracted_text'    => $text,
				'search_text'       => $search_text,
				'line_count'        => $line_count,
				'extracted_chars'   => strlen( $text ),
				'extraction_status' => 'ready',
				'indexing_status'   => 'ready',
				'error_code'        => null,
			),
			array(
				'id'           => $version_id,
				'room_file_id' => $file_id,
			),
			array( '%s', '%s', '%d', '%d', '%s', '%s', '%s' ),
			array( '%d', '%d' )
		);
		if ( false === $ok ) {
			return false;
		}return false !== $wpdb->update(
			$this->files_table(),
			array(
				'content_hash'      => $hash,
				'extraction_status' => 'ready',
				'indexing_status'   => 'ready',
				'updated_at'        => Time::mysql_gmt(),
			),
			array(
				'id'                => $file_id,
				'active_version_id' => $version_id,
			),
			array( '%s', '%s', '%s', '%s' ),
			array( '%d', '%d' )
		);
	}
	public function fail_extraction( int $file_id, int $version_id, string $code ): void {
		global $wpdb;
		$code = sanitize_key( $code );
		$wpdb->update(
			$this->versions_table(),
			array(
				'extracted_text'    => null,
				'search_text'       => null,
				'extraction_status' => 'failed',
				'indexing_status'   => 'failed',
				'error_code'        => $code,
			),
			array(
				'id'           => $version_id,
				'room_file_id' => $file_id,
			)
		);
		$wpdb->update(
			$this->files_table(),
			array(
				'extraction_status' => 'failed',
				'indexing_status'   => 'failed',
				'updated_at'        => Time::mysql_gmt(),
			),
			array(
				'id'                => $file_id,
				'active_version_id' => $version_id,
			)
		); }
	public function clear_version_content( int $file_id ): void {
		global $wpdb;
		$file = $this->find( $file_id );
		if ( ! $file ) {
			return;
		}$wpdb->update(
			$this->versions_table(),
			array(
				'extracted_text'    => null,
				'search_text'       => null,
				'extraction_status' => 'removed',
				'indexing_status'   => 'removed',
			),
			array( 'room_file_id' => $file_id )
		); }
}
