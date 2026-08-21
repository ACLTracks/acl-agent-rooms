<?php
// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange -- This file operates on plugin-owned operational tables and requires uncached transaction/read-after-write truth.
// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- Identifiers are fixed $wpdb->prefix suffixes, fixed internal schema fragments, or allowlisted names; runtime values use placeholders or wpdb mutation arrays.
// phpcs:disable PluginCheck.Security.DirectDB.UnescapedDBParameter -- PHPCS cannot model the fixed identifier provenance above; request data is never used as an SQL identifier.
/**
 * Agent persistence.
 *
 * @package ACL_Agent_Rooms
 */

namespace ACL\AgentRooms\Repositories;

use ACL\AgentRooms\Models\Agent;
use ACL\AgentRooms\Support\Time;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class AgentRepository {
	private ?bool $has_avatar_attachment_id_column = null;

	private function table(): string {
		global $wpdb;
		return $wpdb->prefix . 'acl_ar_agents';
	}

	public function all( array $args = array() ): array {
		global $wpdb;

		$table = $this->table();
		$where = array( '1=1' );

		if ( array_key_exists( 'enabled', $args ) ) {
			$where[] = $wpdb->prepare( 'enabled = %d', ! empty( $args['enabled'] ) ? 1 : 0 );
		}

		if ( ! empty( $args['visibility'] ) ) {
			$where[] = $wpdb->prepare( 'visibility = %s', sanitize_key( (string) $args['visibility'] ) );
		}

		$rows = $wpdb->get_results( 'SELECT * FROM ' . $table . ' WHERE ' . implode( ' AND ', $where ) . ' ORDER BY name ASC, id ASC', ARRAY_A );
		return array_map( array( Agent::class, 'from_row' ), is_array( $rows ) ? $rows : array() );
	}

	public function find( int $id ): ?array {
		global $wpdb;

		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . $this->table() . ' WHERE id = %d', $id ), ARRAY_A );
		return $row ? Agent::from_row( $row ) : null;
	}

	public function find_many( array $ids ): array {
		global $wpdb;
		$ids = array_values( array_unique( array_filter( array_map( 'absint', $ids ) ) ) );
		if ( empty( $ids ) ) {
			return array(); }
		$placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
		$rows         = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . $this->table() . " WHERE id IN ({$placeholders})", ...$ids ), ARRAY_A );
		$agents       = array();
		foreach ( is_array( $rows ) ? $rows : array() as $row ) {
			$agent                        = Agent::from_row( $row );
			$agents[ (int) $agent['id'] ] = $agent; }
		return $agents;
	}

	public function find_by_slug( string $slug ): ?array {
		global $wpdb;

		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . $this->table() . ' WHERE slug = %s', sanitize_title( $slug ) ), ARRAY_A );
		return $row ? Agent::from_row( $row ) : null;
	}

	public function create( array $data ) {
		global $wpdb;

		$now  = Time::mysql_gmt();
		$data = wp_parse_args(
			$data,
			array(
				'owner_user_id'                      => get_current_user_id() ?: null,
				'description'                        => '',
				'avatar_attachment_id'               => 0,
				'avatar_url'                         => '',
				'config_mode'                        => 'independent',
				'shared_config_id'                   => null,
				'execution_mode'                     => 'independent',
				'brain_id'                           => null,
				'temperature'                        => 0.7,
				'max_tokens'                         => 1200,
				'natural_participation_chance'       => 60,
				'natural_question_tendency'          => 20,
				'natural_delay_min_ms'               => null,
				'natural_delay_max_ms'               => null,
				'natural_cooldown_seconds'           => 20,
				'natural_max_auto_responses_per_10m' => 4,
				'natural_conversation_role'          => 'balanced',
				'visibility'                         => 'private',
				'enabled'                            => 1,
				'created_at'                         => $now,
				'updated_at'                         => $now,
			)
		);

		$stored   = $this->data_for_storage( $data );
		$inserted = $wpdb->insert( $this->table(), $stored, $this->formats_for_storage( $stored ) );

		if ( false === $inserted ) {
			return new \WP_Error( 'acl_ar_agent_not_created', $wpdb->last_error ?: __( 'Agent could not be created.', 'acl-agent-rooms' ) );
		}

		return (int) $wpdb->insert_id;
	}

	public function update( int $id, array $data ) {
		global $wpdb;

		$data['updated_at'] = Time::mysql_gmt();
		unset( $data['created_at'] );

		$stored  = $this->data_for_storage( $data, false );
		$updated = $wpdb->update( $this->table(), $stored, array( 'id' => $id ), $this->formats_for_storage( $stored ), array( '%d' ) );

		if ( false === $updated ) {
			return new \WP_Error( 'acl_ar_agent_not_updated', $wpdb->last_error ?: __( 'Agent could not be updated.', 'acl-agent-rooms' ) );
		}

		return true;
	}

	public function delete( int $id ): bool {
		global $wpdb;

		$wpdb->delete( $wpdb->prefix . 'acl_ar_room_agents', array( 'agent_id' => $id ), array( '%d' ) );
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
				'name'                               => sanitize_text_field( (string) ( $data['name'] ?? '' ) ),
				'slug'                               => sanitize_title( (string) ( $data['slug'] ?? $data['name'] ?? '' ) ),
				'description'                        => wp_kses_post( (string) ( $data['description'] ?? '' ) ),
				'avatar_url'                         => esc_url_raw( (string) ( $data['avatar_url'] ?? '' ) ),
				'config_mode'                        => 'shared' === (string) ( $data['config_mode'] ?? 'independent' ) ? 'shared' : 'independent',
				'shared_config_id'                   => ! empty( $data['shared_config_id'] ) ? absint( $data['shared_config_id'] ) : null,
				'execution_mode'                     => 'brain' === (string) ( $data['execution_mode'] ?? 'independent' ) ? 'brain' : 'independent',
				'brain_id'                           => ! empty( $data['brain_id'] ) ? absint( $data['brain_id'] ) : null,
				'provider_route'                     => sanitize_text_field( (string) ( $data['provider_route'] ?? '' ) ),
				'model'                              => sanitize_text_field( (string) ( $data['model'] ?? '' ) ),
				'system_prompt'                      => wp_kses_post( (string) ( $data['system_prompt'] ?? '' ) ),
				'temperature'                        => max( 0, min( 2, (float) ( $data['temperature'] ?? 0.7 ) ) ),
				'max_tokens'                         => max( 1, absint( $data['max_tokens'] ?? 1200 ) ),
				'natural_participation_chance'       => max( 0, min( 100, absint( $data['natural_participation_chance'] ?? 60 ) ) ),
				'natural_question_tendency'          => max( 0, min( 100, absint( $data['natural_question_tendency'] ?? 20 ) ) ),
				'natural_delay_min_ms'               => isset( $data['natural_delay_min_ms'] ) && '' !== $data['natural_delay_min_ms'] ? max( 0, min( 60000, absint( $data['natural_delay_min_ms'] ) ) ) : null,
				'natural_delay_max_ms'               => isset( $data['natural_delay_max_ms'] ) && '' !== $data['natural_delay_max_ms'] ? max( 0, min( 60000, absint( $data['natural_delay_max_ms'] ) ) ) : null,
				'natural_cooldown_seconds'           => max( 0, min( 3600, absint( $data['natural_cooldown_seconds'] ?? 20 ) ) ),
				'natural_max_auto_responses_per_10m' => max( 0, min( 20, absint( $data['natural_max_auto_responses_per_10m'] ?? 4 ) ) ),
				'natural_conversation_role'          => in_array( (string) ( $data['natural_conversation_role'] ?? 'balanced' ), array( 'quiet', 'balanced', 'talkative', 'facilitator' ), true ) ? (string) $data['natural_conversation_role'] : 'balanced',
				'visibility'                         => in_array( (string) ( $data['visibility'] ?? 'private' ), array( 'private', 'public' ), true ) ? (string) $data['visibility'] : 'private',
				'enabled'                            => ! empty( $data['enabled'] ) ? 1 : 0,
				'updated_at'                         => (string) ( $data['updated_at'] ?? Time::mysql_gmt() ),
			)
		);

		if ( $include_created ) {
			$stored['created_at'] = (string) ( $data['created_at'] ?? Time::mysql_gmt() );
		}

		if ( $this->has_avatar_attachment_id_column() ) {
			$with_avatar = array();
			foreach ( $stored as $key => $value ) {
				$with_avatar[ $key ] = $value;
				if ( 'description' === $key ) {
					$with_avatar['avatar_attachment_id'] = $this->sanitize_avatar_attachment_id( $data['avatar_attachment_id'] ?? 0 );
				}
			}
			$stored = $with_avatar;
		}

		return $stored;
	}

	private function formats_for_storage( array $stored ): array {
		$formats = array(
			'owner_user_id'                      => '%d',
			'name'                               => '%s',
			'slug'                               => '%s',
			'description'                        => '%s',
			'avatar_attachment_id'               => '%d',
			'avatar_url'                         => '%s',
			'config_mode'                        => '%s',
			'shared_config_id'                   => '%d',
			'execution_mode'                     => '%s',
			'brain_id'                           => '%d',
			'provider_route'                     => '%s',
			'model'                              => '%s',
			'system_prompt'                      => '%s',
			'temperature'                        => '%f',
			'max_tokens'                         => '%d',
			'natural_participation_chance'       => '%d',
			'natural_question_tendency'          => '%d',
			'natural_delay_min_ms'               => '%d',
			'natural_delay_max_ms'               => '%d',
			'natural_cooldown_seconds'           => '%d',
			'natural_max_auto_responses_per_10m' => '%d',
			'natural_conversation_role'          => '%s',
			'visibility'                         => '%s',
			'enabled'                            => '%d',
			'updated_at'                         => '%s',
			'created_at'                         => '%s',
		);

		return array_map(
			static function ( string $key ) use ( $formats ): string {
				return $formats[ $key ] ?? '%s';
			},
			array_keys( $stored )
		);
	}

	private function sanitize_avatar_attachment_id( $attachment_id ): int {
		$attachment_id = absint( $attachment_id );
		if ( $attachment_id <= 0 ) {
			return 0;
		}

		if ( function_exists( 'get_post' ) ) {
			$post = get_post( $attachment_id );
			if ( ! $post || 'attachment' !== (string) $post->post_type ) {
				return 0;
			}
		}

		if ( function_exists( 'wp_attachment_is_image' ) && ! wp_attachment_is_image( $attachment_id ) ) {
			return 0;
		}

		return $attachment_id;
	}

	private function has_avatar_attachment_id_column(): bool {
		if ( null !== $this->has_avatar_attachment_id_column ) {
			return $this->has_avatar_attachment_id_column;
		}

		global $wpdb;

		$table                                 = $this->table();
		$column                                = $wpdb->get_var( "SHOW COLUMNS FROM {$table} LIKE 'avatar_attachment_id'" );
		$this->has_avatar_attachment_id_column = is_string( $column ) && '' !== $column;

		return $this->has_avatar_attachment_id_column;
	}
}
