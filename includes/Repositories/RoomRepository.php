<?php
// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange -- This file operates on plugin-owned operational tables and requires uncached transaction/read-after-write truth.
// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- Identifiers are fixed $wpdb->prefix suffixes, fixed internal schema fragments, or allowlisted names; runtime values use placeholders or wpdb mutation arrays.
// phpcs:disable PluginCheck.Security.DirectDB.UnescapedDBParameter -- PHPCS cannot model the fixed identifier provenance above; request data is never used as an SQL identifier.
/**
 * Room persistence.
 *
 * @package ACL_Agent_Rooms
 */

namespace ACL\AgentRooms\Repositories;

use ACL\AgentRooms\Models\Agent;
use ACL\AgentRooms\Models\Room;
use ACL\AgentRooms\Support\Arr;
use ACL\AgentRooms\Support\Time;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class RoomRepository {
	private function table(): string {
		global $wpdb;
		return $wpdb->prefix . 'acl_ar_rooms';
	}

	public function all(): array {
		global $wpdb;

		$rows = $wpdb->get_results( 'SELECT * FROM ' . $this->table() . ' ORDER BY updated_at DESC, id DESC', ARRAY_A );
		return array_map( array( Room::class, 'from_row' ), is_array( $rows ) ? $rows : array() );
	}

	public function accessible_for_user( int $user_id ): array {
		global $wpdb;

		if ( current_user_can( 'acl_ar_manage_all_rooms' ) ) {
			return $this->all();
		}

		$rooms   = $this->table();
		$members = $wpdb->prefix . 'acl_ar_room_members';
		$sql     = $wpdb->prepare(
			"SELECT DISTINCT r.* FROM {$rooms} r
			LEFT JOIN {$members} m ON m.room_id = r.id
			WHERE r.owner_user_id = %d OR m.user_id = %d OR (r.type = %s AND r.visibility = %s)
			ORDER BY r.updated_at DESC, r.id DESC",
			$user_id,
			$user_id,
			'public',
			'public'
		);

		$rows = $wpdb->get_results( $sql, ARRAY_A );
		return array_map( array( Room::class, 'from_row' ), is_array( $rows ) ? $rows : array() );
	}

	public function find( int $id ): ?array {
		global $wpdb;

		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . $this->table() . ' WHERE id = %d', $id ), ARRAY_A );
		return $row ? Room::from_row( $row ) : null;
	}

	public function find_by_slug( string $slug ): ?array {
		global $wpdb;

		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . $this->table() . ' WHERE slug = %s', sanitize_title( $slug ) ), ARRAY_A );
		return $row ? Room::from_row( $row ) : null;
	}

	/** Lock a room row inside an existing transaction. */
	public function find_for_update( int $id ): ?array {
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . $this->table() . ' WHERE id = %d FOR UPDATE', $id ), ARRAY_A );
		return $row ? Room::from_row( $row ) : null;
	}

	public function record_clear( int $id, int $cutoff, int $user_id, string $cleared_at ): bool {
		global $wpdb;
		return false !== $wpdb->update(
			$this->table(),
			array(
				'cleared_through_event_id' => $cutoff,
				'chat_cleared_at'          => $cleared_at,
				'chat_cleared_by_user_id'  => $user_id,
				'updated_at'               => $cleared_at,
			),
			array( 'id' => $id ),
			array( '%d', '%s', '%d', '%s' ),
			array( '%d' )
		);
	}

	public function create( array $data ) {
		global $wpdb;

		$now  = Time::mysql_gmt();
		$data = wp_parse_args(
			$data,
			array(
				'owner_user_id'                         => get_current_user_id(),
				'description'                           => '',
				'top_context'                           => '',
				'type'                                  => 'solo',
				'visibility'                            => 'private',
				'status'                                => 'active',
				'agent_reply_mode'                      => 'manual',
				'max_context_messages'                  => 20,
				'max_agents_per_turn'                   => 1,
				'conversation_mode'                     => 'immediate',
				'natural_min_responders'                => 1,
				'natural_max_responders'                => 2,
				'natural_initial_delay_min_ms'          => 1500,
				'natural_initial_delay_max_ms'          => 4500,
				'natural_inter_turn_delay_min_ms'       => 2500,
				'natural_inter_turn_delay_max_ms'       => 8000,
				'natural_allow_silence'                 => 0,
				'natural_silence_chance'                => 10,
				'natural_cancel_pending_on_new_message' => 1,
				'natural_max_pending_turns'             => 4,
				'natural_steering_question_bias'        => 35,
				'allow_chat_clear'                      => 0,
				'project_instructions'                  => '',
				'room_files_enabled'                    => 0,
				'room_files_agent_access'               => 0,
				'file_context_mode'                     => 'hybrid',
				'file_context_max_files'                => 5,
				'file_context_max_chars'                => 12000,
				'created_at'                            => $now,
				'updated_at'                            => $now,
			)
		);

		$stored   = $this->data_for_storage( $data );
		$inserted = $wpdb->insert( $this->table(), $stored, $this->formats_for_storage( $stored ) );

		if ( false === $inserted ) {
			return new \WP_Error( 'acl_ar_room_not_created', $wpdb->last_error ?: __( 'Room could not be created.', 'acl-agent-rooms' ) );
		}

		$room_id = (int) $wpdb->insert_id;
		$this->add_member( $room_id, (int) $data['owner_user_id'], 'owner' );

		return $room_id;
	}

	public function update( int $id, array $data ) {
		global $wpdb;

		$data['updated_at'] = Time::mysql_gmt();
		unset( $data['created_at'], $data['owner_user_id'] );

		$updated = $wpdb->update(
			$this->table(),
			$this->data_for_storage( $data, false ),
			array( 'id' => $id )
		);

		if ( false === $updated ) {
			return new \WP_Error( 'acl_ar_room_not_updated', $wpdb->last_error ?: __( 'Room could not be updated.', 'acl-agent-rooms' ) );
		}

		return true;
	}

	public function delete( int $id ) {
		global $wpdb;
		$transaction = $this->supports_transactions();
		if ( $transaction ) {
			$wpdb->query( 'START TRANSACTION' );
		}

		$tables = array( 'acl_ar_room_file_versions', 'acl_ar_room_files', 'acl_ar_conversation_turns', 'acl_ar_room_agents', 'acl_ar_room_members', 'acl_ar_agent_jobs', 'acl_ar_brain_runs', 'acl_ar_messages', 'acl_ar_room_reads', 'acl_ar_room_presence', 'acl_ar_room_presence_sessions', 'acl_ar_event_search', 'acl_ar_room_restrictions' );
		foreach ( $tables as $step => $suffix ) {
			$result = $wpdb->delete( $wpdb->prefix . $suffix, array( 'room_id' => $id ), array( '%d' ) );
			if ( false === $result || apply_filters( 'acl_ar_room_mutation_fail', false, 'delete', $step, $id ) ) {
				if ( $transaction ) {
					$wpdb->query( 'ROLLBACK' );
				}
				return new \WP_Error( 'acl_ar_room_delete_failed', __( 'Room deletion failed and was rolled back.', 'acl-agent-rooms' ) );
			}
		}
		$events            = $wpdb->prefix . 'acl_ar_events';
		$reactions         = $wpdb->prefix . 'acl_ar_event_reactions';
		$step              = count( $tables );
		$deleted_reactions = $wpdb->query( $wpdb->prepare( "DELETE er FROM {$reactions} er INNER JOIN {$events} e ON e.id = er.event_id WHERE e.room_id = %d", $id ) );
		if ( false === $deleted_reactions || apply_filters( 'acl_ar_room_mutation_fail', false, 'delete', $step, $id ) ) {
			if ( $transaction ) {
				$wpdb->query( 'ROLLBACK' ); }
			return new \WP_Error( 'acl_ar_room_delete_failed', __( 'Room deletion failed and was rolled back.', 'acl-agent-rooms' ) );
		}
		++$step;
		if ( false === $wpdb->delete( $events, array( 'room_id' => $id ), array( '%d' ) ) || apply_filters( 'acl_ar_room_mutation_fail', false, 'delete', $step, $id ) ) {
			if ( $transaction ) {
				$wpdb->query( 'ROLLBACK' ); }
			return new \WP_Error( 'acl_ar_room_delete_failed', __( 'Room deletion failed and was rolled back.', 'acl-agent-rooms' ) );
		}

		$deleted = $wpdb->delete( $this->table(), array( 'id' => $id ), array( '%d' ) );
		if ( false === $deleted || 0 === $deleted || apply_filters( 'acl_ar_room_mutation_fail', false, 'delete', $step + 1, $id ) ) {
			if ( $transaction ) {
				$wpdb->query( 'ROLLBACK' );
			}
			return new \WP_Error( 'acl_ar_room_delete_failed', __( 'Room deletion failed and was rolled back.', 'acl-agent-rooms' ) );
		}
		if ( $transaction ) {
			$wpdb->query( 'COMMIT' );
		}
		return true;
	}

	public function add_member( int $room_id, int $user_id, string $role = 'member' ): bool {
		global $wpdb;

		if ( $room_id <= 0 || $user_id <= 0 ) {
			return false;
		}

		$table = $wpdb->prefix . 'acl_ar_room_members';
		$now   = Time::mysql_gmt();

		$sql = $wpdb->prepare(
			"INSERT INTO {$table} (room_id, user_id, role, created_at)
			VALUES (%d, %d, %s, %s)
			ON DUPLICATE KEY UPDATE role = VALUES(role)",
			$room_id,
			$user_id,
			sanitize_key( $role ),
			$now
		);

		return false !== $wpdb->query( $sql );
	}

	public function is_member( int $room_id, int $user_id ): bool {
		global $wpdb;

		$table = $wpdb->prefix . 'acl_ar_room_members';
		$count = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE room_id = %d AND user_id = %d", $room_id, $user_id ) );

		return $count > 0;
	}

	public function is_owner( int $room_id, int $user_id ): bool {
		$room = $this->find( $room_id );
		return $room && (int) $room['owner_user_id'] === $user_id;
	}

	public function assign_agents( int $room_id, array $agent_ids ) {
		global $wpdb;

		$agent_ids = Arr::ids( $agent_ids );
		$table     = $wpdb->prefix . 'acl_ar_room_agents';
		if ( ! $this->find( $room_id ) ) {
			return new \WP_Error( 'acl_ar_room_not_found', __( 'Room was not found.', 'acl-agent-rooms' ) );
		}
		if ( ! empty( $agent_ids ) ) {
			$placeholders = implode( ',', array_fill( 0, count( $agent_ids ), '%d' ) );
			$agents_table = $wpdb->prefix . 'acl_ar_agents';
			$found        = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$agents_table} WHERE id IN ({$placeholders})", ...$agent_ids ) );
			if ( $found !== count( $agent_ids ) ) {
				return new \WP_Error( 'acl_ar_agent_not_found', __( 'Every assigned agent must exist before room assignment.', 'acl-agent-rooms' ) );
			}
		}

		$transaction = $this->supports_transactions();
		if ( $transaction ) {
			$wpdb->query( 'START TRANSACTION' );
		}
		if ( false === $wpdb->delete( $table, array( 'room_id' => $room_id ), array( '%d' ) ) ) {
			if ( $transaction ) {
				$wpdb->query( 'ROLLBACK' );
			}
			return new \WP_Error( 'acl_ar_room_agents_failed', __( 'Agent assignment could not be replaced.', 'acl-agent-rooms' ) );
		}

		$sort = 0;
		foreach ( $agent_ids as $agent_id ) {
			$inserted = $wpdb->insert(
				$table,
				array(
					'room_id'    => $room_id,
					'agent_id'   => $agent_id,
					'sort_order' => $sort,
					'enabled'    => 1,
					'created_at' => Time::mysql_gmt(),
				),
				array( '%d', '%d', '%d', '%d', '%s' )
			);
			if ( false === $inserted || apply_filters( 'acl_ar_room_mutation_fail', false, 'assign', $sort, $room_id ) ) {
				if ( $transaction ) {
					$wpdb->query( 'ROLLBACK' );
				}
				return new \WP_Error( 'acl_ar_room_agents_failed', __( 'Agent assignment failed and was rolled back.', 'acl-agent-rooms' ) );
			}
			++$sort;
		}

		if ( $transaction ) {
			$wpdb->query( 'COMMIT' );
		}
		return true;
	}

	public function add_agent( int $room_id, int $agent_id ): bool {
		global $wpdb;

		$table = $wpdb->prefix . 'acl_ar_room_agents';
		$sql   = $wpdb->prepare(
			"INSERT INTO {$table} (room_id, agent_id, sort_order, enabled, created_at)
			VALUES (%d, %d, 0, 1, %s)
			ON DUPLICATE KEY UPDATE enabled = 1",
			$room_id,
			$agent_id,
			Time::mysql_gmt()
		);

		return false !== $wpdb->query( $sql );
	}

	public function remove_agent( int $room_id, int $agent_id ): bool {
		global $wpdb;

		return false !== $wpdb->delete(
			$wpdb->prefix . 'acl_ar_room_agents',
			array(
				'room_id'  => $room_id,
				'agent_id' => $agent_id,
			),
			array( '%d', '%d' )
		);
	}

	public function get_agent_ids( int $room_id ): array {
		global $wpdb;

		$table = $wpdb->prefix . 'acl_ar_room_agents';
		$ids   = $wpdb->get_col( $wpdb->prepare( "SELECT agent_id FROM {$table} WHERE room_id = %d AND enabled = 1 ORDER BY sort_order ASC, id ASC", $room_id ) );

		return Arr::ids( is_array( $ids ) ? $ids : array() );
	}

	public function get_agents( int $room_id ): array {
		global $wpdb;

		$links  = $wpdb->prefix . 'acl_ar_room_agents';
		$agents = $wpdb->prefix . 'acl_ar_agents';
		$sql    = $wpdb->prepare(
			"SELECT a.*,ra.sort_order,ra.participation_state,ra.auto_muted,ra.state_updated_at FROM {$agents} a
			INNER JOIN {$links} ra ON ra.agent_id = a.id
			WHERE ra.room_id = %d AND ra.enabled = 1 AND a.enabled = 1
			ORDER BY ra.sort_order ASC, a.id ASC",
			$room_id
		);

		$rows = $wpdb->get_results( $sql, ARRAY_A );
		return array_map( array( Agent::class, 'from_row' ), is_array( $rows ) ? $rows : array() );
	}

	public function get_assignment( int $room_id, int $agent_id ): ?array {
		global $wpdb;
		$table = $wpdb->prefix . 'acl_ar_room_agents';
		$row   = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE room_id=%d AND agent_id=%d AND enabled=1", $room_id, $agent_id ), ARRAY_A );
		return $row ?: null; }

	private function data_for_storage( array $data, bool $include_created = true ): array {
		$type              = (string) ( $data['type'] ?? 'solo' );
		$visibility        = (string) ( $data['visibility'] ?? 'private' );
		$mode              = (string) ( $data['agent_reply_mode'] ?? 'manual' );
		$status            = (string) ( $data['status'] ?? 'active' );
		$conversation_mode = (string) ( $data['conversation_mode'] ?? 'immediate' );
		$file_context_mode = (string) ( $data['file_context_mode'] ?? 'hybrid' );
		$min_responders    = max( 0, min( 10, absint( $data['natural_min_responders'] ?? 1 ) ) );
		$max_responders    = max( $min_responders, min( 10, absint( $data['natural_max_responders'] ?? 2 ) ) );
		$initial_min       = max( 0, min( 60000, absint( $data['natural_initial_delay_min_ms'] ?? 1500 ) ) );
		$initial_max       = max( $initial_min, min( 60000, absint( $data['natural_initial_delay_max_ms'] ?? 4500 ) ) );
		$inter_min         = max( 0, min( 60000, absint( $data['natural_inter_turn_delay_min_ms'] ?? 2500 ) ) );
		$inter_max         = max( $inter_min, min( 60000, absint( $data['natural_inter_turn_delay_max_ms'] ?? 8000 ) ) );

		$stored = array();

		if ( $include_created || array_key_exists( 'owner_user_id', $data ) ) {
			$stored['owner_user_id'] = absint( $data['owner_user_id'] ?? get_current_user_id() );
		}

		$stored = array_merge(
			$stored,
			array(
				'title'                                 => sanitize_text_field( (string) ( $data['title'] ?? '' ) ),
				'slug'                                  => sanitize_title( (string) ( $data['slug'] ?? $data['title'] ?? '' ) ),
				'description'                           => wp_kses_post( (string) ( $data['description'] ?? '' ) ),
				'top_context'                           => wp_kses_post( (string) ( $data['top_context'] ?? '' ) ),
				'type'                                  => in_array( $type, array( 'solo', 'private', 'public' ), true ) ? $type : 'solo',
				'visibility'                            => in_array( $visibility, array( 'private', 'public' ), true ) ? $visibility : 'private',
				'status'                                => in_array( $status, array( 'active', 'paused', 'archived' ), true ) ? $status : 'active',
				'agent_reply_mode'                      => in_array( $mode, array( 'manual', 'mention', 'slash', 'auto' ), true ) ? $mode : 'manual',
				'max_context_messages'                  => max( 1, min( 200, absint( $data['max_context_messages'] ?? 20 ) ) ),
				'max_agents_per_turn'                   => max( 1, min( 20, absint( $data['max_agents_per_turn'] ?? 1 ) ) ),
				'conversation_mode'                     => 'natural' === $conversation_mode ? 'natural' : 'immediate',
				'natural_min_responders'                => $min_responders,
				'natural_max_responders'                => $max_responders,
				'natural_initial_delay_min_ms'          => $initial_min,
				'natural_initial_delay_max_ms'          => $initial_max,
				'natural_inter_turn_delay_min_ms'       => $inter_min,
				'natural_inter_turn_delay_max_ms'       => $inter_max,
				'natural_allow_silence'                 => ! empty( $data['natural_allow_silence'] ) ? 1 : 0,
				'natural_silence_chance'                => max( 0, min( 100, absint( $data['natural_silence_chance'] ?? 10 ) ) ),
				'natural_cancel_pending_on_new_message' => ! empty( $data['natural_cancel_pending_on_new_message'] ) ? 1 : 0,
				'natural_max_pending_turns'             => max( 1, min( 10, absint( $data['natural_max_pending_turns'] ?? 4 ) ) ),
				'natural_steering_question_bias'        => max( 0, min( 100, absint( $data['natural_steering_question_bias'] ?? 35 ) ) ),
				'allow_chat_clear'                      => ! empty( $data['allow_chat_clear'] ) ? 1 : 0,
				'project_instructions'                  => sanitize_textarea_field( (string) ( $data['project_instructions'] ?? '' ) ),
				'room_files_enabled'                    => ! empty( $data['room_files_enabled'] ) ? 1 : 0,
				'room_files_agent_access'               => ! empty( $data['room_files_agent_access'] ) ? 1 : 0,
				'file_context_mode'                     => in_array( $file_context_mode, array( 'manual', 'automatic', 'hybrid' ), true ) ? $file_context_mode : 'hybrid',
				'file_context_max_files'                => max( 1, min( 20, absint( $data['file_context_max_files'] ?? 5 ) ) ),
				'file_context_max_chars'                => max( 1000, min( 100000, absint( $data['file_context_max_chars'] ?? 12000 ) ) ),
				'updated_at'                            => (string) ( $data['updated_at'] ?? Time::mysql_gmt() ),
			)
		);

		if ( $include_created ) {
			$stored['created_at'] = (string) ( $data['created_at'] ?? Time::mysql_gmt() );
		} else {
			// Repository updates are patch operations. Do not replace omitted room
			// fields with normalization defaults when a caller changes one setting.
			$requested               = array_fill_keys( array_keys( $data ), true );
			$requested['updated_at'] = true;
			$stored                  = array_intersect_key( $stored, $requested );
		}

		return $stored;
	}

	private function supports_transactions(): bool {
		global $wpdb;
		$engine = $wpdb->get_var(
			$wpdb->prepare(
				'SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s',
				DB_NAME,
				$this->table()
			)
		);
		return 'INNODB' === strtoupper( (string) $engine );
	}

	private function formats_for_storage( array $stored ): array {
		$integer_fields = array( 'owner_user_id', 'max_context_messages', 'max_agents_per_turn', 'natural_min_responders', 'natural_max_responders', 'natural_initial_delay_min_ms', 'natural_initial_delay_max_ms', 'natural_inter_turn_delay_min_ms', 'natural_inter_turn_delay_max_ms', 'natural_allow_silence', 'natural_silence_chance', 'natural_cancel_pending_on_new_message', 'natural_max_pending_turns', 'natural_steering_question_bias', 'allow_chat_clear', 'room_files_enabled', 'room_files_agent_access', 'file_context_max_files', 'file_context_max_chars' );
		return array_map( static fn( string $key ): string => in_array( $key, $integer_fields, true ) ? '%d' : '%s', array_keys( $stored ) );
	}
}
