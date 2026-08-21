<?php
// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange -- This file operates on plugin-owned operational tables and requires uncached transaction/read-after-write truth.
// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- Identifiers are fixed $wpdb->prefix suffixes, fixed internal schema fragments, or allowlisted names; runtime values use placeholders or wpdb mutation arrays.
// phpcs:disable PluginCheck.Security.DirectDB.UnescapedDBParameter -- PHPCS cannot model the fixed identifier provenance above; request data is never used as an SQL identifier.
/**
 * Shared AI config persistence.
 *
 * @package ACL_Agent_Rooms
 */

namespace ACL\AgentRooms\Repositories;

use ACL\AgentRooms\Models\SharedConfig;
use ACL\AgentRooms\Support\Time;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SharedConfigRepository {
	private function table(): string {
		global $wpdb;
		return $wpdb->prefix . 'acl_ar_shared_configs';
	}

	public function all( array $args = array() ): array {
		global $wpdb;

		$table = $this->table();
		$where = array( '1=1' );

		if ( array_key_exists( 'enabled', $args ) ) {
			$where[] = $wpdb->prepare( 'enabled = %d', ! empty( $args['enabled'] ) ? 1 : 0 );
		}

		$rows = $wpdb->get_results( 'SELECT * FROM ' . $table . ' WHERE ' . implode( ' AND ', $where ) . ' ORDER BY name ASC, id ASC', ARRAY_A );
		return array_map( array( SharedConfig::class, 'from_row' ), is_array( $rows ) ? $rows : array() );
	}

	public function find( int $id ): ?array {
		global $wpdb;

		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . $this->table() . ' WHERE id = %d', $id ), ARRAY_A );
		return $row ? SharedConfig::from_row( $row ) : null;
	}

	public function create( array $data ) {
		global $wpdb;

		$now  = Time::mysql_gmt();
		$data = wp_parse_args(
			$data,
			array(
				'owner_user_id' => get_current_user_id() ?: null,
				'temperature'   => 0.7,
				'max_tokens'    => 1200,
				'enabled'       => 1,
				'created_at'    => $now,
				'updated_at'    => $now,
			)
		);

		$inserted = $wpdb->insert(
			$this->table(),
			$this->data_for_storage( $data ),
			array( '%d', '%s', '%s', '%s', '%s', '%s', '%f', '%d', '%d', '%s', '%s' )
		);

		if ( false === $inserted ) {
			return new \WP_Error( 'acl_ar_shared_config_not_created', $wpdb->last_error ?: __( 'Shared AI config could not be created.', 'acl-agent-rooms' ) );
		}

		return (int) $wpdb->insert_id;
	}

	public function update( int $id, array $data ) {
		global $wpdb;

		$data['updated_at'] = Time::mysql_gmt();
		unset( $data['created_at'] );

		$updated = $wpdb->update(
			$this->table(),
			$this->data_for_storage( $data, false ),
			array( 'id' => $id )
		);

		if ( false === $updated ) {
			return new \WP_Error( 'acl_ar_shared_config_not_updated', $wpdb->last_error ?: __( 'Shared AI config could not be updated.', 'acl-agent-rooms' ) );
		}

		return true;
	}

	public function delete( int $id ): bool {
		global $wpdb;

		$wpdb->update(
			$wpdb->prefix . 'acl_ar_agents',
			array(
				'config_mode'      => 'independent',
				'shared_config_id' => null,
			),
			array( 'shared_config_id' => $id ),
			array( '%s', '%d' ),
			array( '%d' )
		);

		return false !== $wpdb->delete( $this->table(), array( 'id' => $id ), array( '%d' ) );
	}

	private function data_for_storage( array $data, bool $include_created = true ): array {
		$stored = array();

		if ( $include_created || array_key_exists( 'owner_user_id', $data ) ) {
			$stored['owner_user_id'] = isset( $data['owner_user_id'] ) ? absint( $data['owner_user_id'] ) : null;
		}

		$stored = array_merge(
			$stored,
			array(
				'name'           => sanitize_text_field( (string) ( $data['name'] ?? '' ) ),
				'slug'           => sanitize_title( (string) ( $data['slug'] ?? $data['name'] ?? '' ) ),
				'provider_route' => sanitize_text_field( (string) ( $data['provider_route'] ?? '' ) ),
				'model'          => sanitize_text_field( (string) ( $data['model'] ?? '' ) ),
				'system_prompt'  => wp_kses_post( (string) ( $data['system_prompt'] ?? '' ) ),
				'temperature'    => max( 0, min( 2, (float) ( $data['temperature'] ?? 0.7 ) ) ),
				'max_tokens'     => max( 1, absint( $data['max_tokens'] ?? 1200 ) ),
				'enabled'        => ! empty( $data['enabled'] ) ? 1 : 0,
				'updated_at'     => (string) ( $data['updated_at'] ?? Time::mysql_gmt() ),
			)
		);

		if ( $include_created ) {
			$stored['created_at'] = (string) ( $data['created_at'] ?? Time::mysql_gmt() );
		}

		return $stored;
	}
}
