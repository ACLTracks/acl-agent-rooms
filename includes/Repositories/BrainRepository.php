<?php
// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange -- This file operates on plugin-owned operational tables and requires uncached transaction/read-after-write truth.
// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- Identifiers are fixed $wpdb->prefix suffixes, fixed internal schema fragments, or allowlisted names; runtime values use placeholders or wpdb mutation arrays.
// phpcs:disable PluginCheck.Security.DirectDB.UnescapedDBParameter -- PHPCS cannot model the fixed identifier provenance above; request data is never used as an SQL identifier.
/** Shared Brain persistence. @package ACL_Agent_Rooms */
namespace ACL\AgentRooms\Repositories;

use ACL\AgentRooms\Models\Brain;
use ACL\AgentRooms\Support\Json;
use ACL\AgentRooms\Support\Time;

if ( ! defined( 'ABSPATH' ) ) {
	exit; }

class BrainRepository {
	private function table(): string {
		global $wpdb;
		return $wpdb->prefix . 'acl_ar_brains'; }
	public function all( array $args = array() ): array {
		global $wpdb;
		$where = array( '1=1' );
		if ( array_key_exists( 'enabled', $args ) ) {
			$where[] = $wpdb->prepare( 'enabled=%d', ! empty( $args['enabled'] ) ? 1 : 0 ); }
		$rows = $wpdb->get_results( 'SELECT * FROM ' . $this->table() . ' WHERE ' . implode( ' AND ', $where ) . ' ORDER BY name,id', ARRAY_A );
		return array_map( array( Brain::class, 'from_row' ), is_array( $rows ) ? $rows : array() );
	}
	public function find( int $id ): ?array {
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . $this->table() . ' WHERE id=%d', $id ), ARRAY_A );
		return $row ? Brain::from_row( $row ) : null; }
	public function find_by_slug( string $slug ): ?array {
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . $this->table() . ' WHERE slug=%s', sanitize_title( $slug ) ), ARRAY_A );
		return $row ? Brain::from_row( $row ) : null; }
	public function create( array $data ) {
		global $wpdb;
		$now    = Time::mysql_gmt();
		$data   = wp_parse_args(
			$data,
			array(
				'owner_user_id'        => get_current_user_id(),
				'description'          => '',
				'orchestration_prompt' => '',
				'temperature'          => 0.7,
				'max_tokens_per_agent' => 600,
				'max_total_tokens'     => 6000,
				'settings'             => array(),
				'enabled'              => 1,
				'created_at'           => $now,
				'updated_at'           => $now,
			)
		);
		$stored = $this->storage( $data, true );
		$ok     = $wpdb->insert( $this->table(), $stored, $this->formats( $stored ) );
		if ( false === $ok ) {
			return new \WP_Error( 'acl_ar_brain_not_created', $wpdb->last_error ?: __( 'Brain could not be created.', 'acl-agent-rooms' ) );
		}return (int) $wpdb->insert_id; }
	public function update( int $id, array $data ) {
		global $wpdb;
		$existing = $this->find( $id );
		if ( ! $existing ) {
			return new \WP_Error( 'acl_ar_brain_not_found', __( 'Brain was not found.', 'acl-agent-rooms' ), array( 'status' => 404 ) );
		}$data              = array_merge( $existing, $data );
		$data['updated_at'] = Time::mysql_gmt();
		unset( $data['created_at'] );
		$stored = $this->storage( $data, false );
		$ok     = $wpdb->update( $this->table(), $stored, array( 'id' => $id ), $this->formats( $stored ), array( '%d' ) );
		return false === $ok ? new \WP_Error( 'acl_ar_brain_not_updated', $wpdb->last_error ?: __( 'Brain could not be updated.', 'acl-agent-rooms' ) ) : true; }
	public function referenced_count( int $id ): int {
		global $wpdb;
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}acl_ar_agents WHERE brain_id=%d AND execution_mode='brain'", $id ) ); }
	public function delete( int $id ) {
		global $wpdb;
		$count = $this->referenced_count( $id );
		if ( $count ) {
			return new \WP_Error(
				'acl_ar_brain_in_use',
				/* translators: %d: Number of agents still assigned to the Shared Brain. */
				sprintf( _n( '%d agent still uses this Brain. Reassign it first.', '%d agents still use this Brain. Reassign them first.', $count, 'acl-agent-rooms' ), $count ),
				array(
					'status'            => 409,
					'referenced_agents' => $count,
				)
			);
		}return false !== $wpdb->delete( $this->table(), array( 'id' => $id ), array( '%d' ) ); }
	public function set_enabled( int $id, bool $enabled ) {
		return $this->update( $id, array( 'enabled' => $enabled ? 1 : 0 ) ); }
	private function storage( array $data, bool $created ): array {
		$allowed_settings = array();
		$input            = is_array( $data['settings'] ?? null ) ? $data['settings'] : array();
		foreach ( array( 'response_format', 'stop', 'frequency_penalty', 'presence_penalty' ) as $key ) {
			if ( ! array_key_exists( $key, $input ) ) {
				continue;
			}$value = $input[ $key ];
			if ( is_scalar( $value ) ) {
				$allowed_settings[ $key ] = is_string( $value ) ? sanitize_text_field( $value ) : $value;
			} elseif ( 'stop' === $key && is_array( $value ) ) {
				$allowed_settings[ $key ] = array_slice( array_map( 'sanitize_text_field', $value ), 0, 4 );}
		}
		$out = array(
			'owner_user_id'        => absint( $data['owner_user_id'] ?? get_current_user_id() ),
			'name'                 => sanitize_text_field( (string) ( $data['name'] ?? '' ) ),
			'slug'                 => sanitize_title( (string) ( $data['slug'] ?? $data['name'] ?? '' ) ),
			'description'          => sanitize_textarea_field( (string) ( $data['description'] ?? '' ) ),
			'provider'             => sanitize_text_field( (string) ( $data['provider'] ?? '' ) ),
			'model'                => sanitize_text_field( (string) ( $data['model'] ?? '' ) ),
			'orchestration_prompt' => sanitize_textarea_field( (string) ( $data['orchestration_prompt'] ?? '' ) ),
			'temperature'          => null === $data['temperature'] ? null : max( 0, min( 2, (float) $data['temperature'] ) ),
			'max_tokens_per_agent' => max( 64, min( 8000, absint( $data['max_tokens_per_agent'] ?? 600 ) ) ),
			'max_total_tokens'     => max( 64, min( 32000, absint( $data['max_total_tokens'] ?? 6000 ) ) ),
			'settings_json'        => Json::encode( $allowed_settings ),
			'enabled'              => ! empty( $data['enabled'] ) ? 1 : 0,
			'updated_at'           => (string) ( $data['updated_at'] ?? Time::mysql_gmt() ),
		);
		if ( $created ) {
			$out['created_at'] = (string) ( $data['created_at'] ?? Time::mysql_gmt() );
		}return $out;
	}
	private function formats( array $data ): array {
		$map = array(
			'owner_user_id'        => '%d',
			'temperature'          => '%f',
			'max_tokens_per_agent' => '%d',
			'max_total_tokens'     => '%d',
			'enabled'              => '%d',
		);
		return array_map( static fn( $k )=>$map[ $k ] ?? '%s', array_keys( $data ) ); }
}
