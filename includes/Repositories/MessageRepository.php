<?php
// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange -- This file operates on plugin-owned operational tables and requires uncached transaction/read-after-write truth.
// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- Identifiers are fixed $wpdb->prefix suffixes, fixed internal schema fragments, or allowlisted names; runtime values use placeholders or wpdb mutation arrays.
// phpcs:disable PluginCheck.Security.DirectDB.UnescapedDBParameter -- PHPCS cannot model the fixed identifier provenance above; request data is never used as an SQL identifier.
/**
 * Message persistence.
 *
 * @package ACL_Agent_Rooms
 */

namespace ACL\AgentRooms\Repositories;

use ACL\AgentRooms\Models\Message;
use ACL\AgentRooms\Support\Json;
use ACL\AgentRooms\Support\Time;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class MessageRepository {
	private bool $last_create_was_duplicate = false;

	private function table(): string {
		global $wpdb;
		return $wpdb->prefix . 'acl_ar_messages';
	}

	public function create( array $data ) {
		global $wpdb;
		$this->last_create_was_duplicate = false;

		$data = wp_parse_args(
			$data,
			array(
				'sender_user_id'    => null,
				'sender_agent_id'   => null,
				'status'            => 'sent',
				'client_request_id' => null,
				'response_job_id'   => null,
				'brain_run_id'      => null,
				'brain_agent_id'    => null,
				'metadata'          => array(),
				'provider_route'    => null,
				'model'             => null,
				'prompt_tokens'     => 0,
				'completion_tokens' => 0,
				'total_tokens'      => 0,
				'created_at'        => Time::mysql_gmt(),
			)
		);

		$inserted = $wpdb->insert(
			$this->table(),
			array(
				'room_id'           => absint( $data['room_id'] ?? 0 ),
				'sender_type'       => sanitize_key( (string) ( $data['sender_type'] ?? 'user' ) ),
				'sender_user_id'    => isset( $data['sender_user_id'] ) ? absint( $data['sender_user_id'] ) : null,
				'sender_agent_id'   => isset( $data['sender_agent_id'] ) ? absint( $data['sender_agent_id'] ) : null,
				'content'           => wp_kses_post( (string) ( $data['content'] ?? '' ) ),
				'status'            => sanitize_key( (string) $data['status'] ),
				'client_request_id' => ! empty( $data['client_request_id'] ) ? sanitize_text_field( (string) $data['client_request_id'] ) : null,
				'response_job_id'   => ! empty( $data['response_job_id'] ) ? absint( $data['response_job_id'] ) : null,
				'brain_run_id'      => ! empty( $data['brain_run_id'] ) ? absint( $data['brain_run_id'] ) : null,
				'brain_agent_id'    => ! empty( $data['brain_agent_id'] ) ? absint( $data['brain_agent_id'] ) : null,
				'metadata_json'     => Json::encode( is_array( $data['metadata'] ) ? $data['metadata'] : array() ),
				'provider_route'    => isset( $data['provider_route'] ) ? sanitize_text_field( (string) $data['provider_route'] ) : null,
				'model'             => isset( $data['model'] ) ? sanitize_text_field( (string) $data['model'] ) : null,
				'prompt_tokens'     => absint( $data['prompt_tokens'] ),
				'completion_tokens' => absint( $data['completion_tokens'] ),
				'total_tokens'      => absint( $data['total_tokens'] ),
				'created_at'        => (string) $data['created_at'],
			),
			array( '%d', '%s', '%d', '%d', '%s', '%s', '%s', '%d', '%d', '%d', '%s', '%s', '%s', '%d', '%d', '%d', '%s' )
		);

		if ( false === $inserted ) {
			if ( ! empty( $data['brain_run_id'] ) && ! empty( $data['brain_agent_id'] ) ) {
				$existing = $this->find_by_brain_response( absint( $data['brain_run_id'] ), absint( $data['brain_agent_id'] ) );
				if ( $existing ) {
					$this->last_create_was_duplicate = true;
					return (int) $existing['id']; }
			}
			if ( ! empty( $data['response_job_id'] ) ) {
				$existing = $this->find_by_response_job_id( absint( $data['response_job_id'] ) );
				if ( $existing ) {
					$this->last_create_was_duplicate = true;
					return (int) $existing['id'];
				}
			}
			if ( ! empty( $data['client_request_id'] ) && ! empty( $data['sender_user_id'] ) ) {
				$existing = $this->find_by_client_request( absint( $data['room_id'] ?? 0 ), absint( $data['sender_user_id'] ), (string) $data['client_request_id'] );
				if ( $existing ) {
					$this->last_create_was_duplicate = true;
					return (int) $existing['id'];
				}
			}
			return new \WP_Error( 'acl_ar_message_not_created', $wpdb->last_error ?: __( 'Message could not be created.', 'acl-agent-rooms' ) );
		}

		return (int) $wpdb->insert_id;
	}

	public function create_user_idempotent( int $room_id, int $user_id, string $content, string $client_request_id = '', array $metadata = array() ) {
		global $wpdb;
		if ( '' !== $client_request_id ) {
			$existing = $this->find_by_client_request( $room_id, $user_id, $client_request_id );
			if ( $existing ) {
				return array(
					'id'      => (int) $existing['id'],
					'created' => false,
					'message' => $existing,
				);
			}
		}

		$id = $this->create(
			array(
				'room_id'           => $room_id,
				'sender_type'       => 'user',
				'sender_user_id'    => $user_id,
				'content'           => $content,
				'client_request_id' => '' !== $client_request_id ? $client_request_id : null,
				'metadata'          => $metadata,
			)
		);
		if ( is_wp_error( $id ) ) {
			return $id;
		}

		$message = $this->find( (int) $id );
		$created = ! $this->last_create_was_duplicate;
		// A duplicate-key race returns the existing row from create(); compare IDs to a pre-insert lookup where possible.
		if ( '' !== $client_request_id ) {
			$resolved = $this->find_by_client_request( $room_id, $user_id, $client_request_id );
			$message  = $resolved ?: $message;
			$id       = (int) ( $message['id'] ?? $id );
			$created  = ! $this->last_create_was_duplicate;
		}

		return array(
			'id'      => (int) $id,
			'created' => $created,
			'message' => $message,
		);
	}

	public function find( int $id ): ?array {
		global $wpdb;

		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . $this->table() . ' WHERE id = %d', $id ), ARRAY_A );
		return $row ? Message::from_row( $row ) : null;
	}

	public function find_by_client_request( int $room_id, int $user_id, string $client_request_id ): ?array {
		global $wpdb;
		if ( '' === $client_request_id ) {
			return null;
		}
		$row = $wpdb->get_row(
			$wpdb->prepare( 'SELECT * FROM ' . $this->table() . ' WHERE room_id = %d AND sender_user_id = %d AND client_request_id = %s LIMIT 1', $room_id, $user_id, $client_request_id ),
			ARRAY_A
		);
		return $row ? Message::from_row( $row ) : null;
	}

	public function find_by_response_job_id( int $job_id ): ?array {
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . $this->table() . ' WHERE response_job_id = %d LIMIT 1', $job_id ), ARRAY_A );
		return $row ? Message::from_row( $row ) : null;
	}
	public function find_by_brain_response( int $brain_run_id, int $agent_id ): ?array {
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . $this->table() . ' WHERE brain_run_id=%d AND brain_agent_id=%d LIMIT 1', $brain_run_id, $agent_id ), ARRAY_A );
		return $row ? Message::from_row( $row ) : null; }

	public function update_content( int $id, string $content ): bool {
		global $wpdb;
		return false !== $wpdb->update( $this->table(), array( 'content' => wp_strip_all_tags( $content ) ), array( 'id' => $id ), array( '%s' ), array( '%d' ) );
	}
	public function redact( int $id, string $placeholder, string $status = 'removed' ): bool {
		global $wpdb;
		return false !== $wpdb->update(
			$this->table(),
			array(
				'content' => $placeholder,
				'status'  => $status,
			),
			array( 'id' => $id ),
			array( '%s', '%s' ),
			array( '%d' )
		); }

	public function for_room( int $room_id, int $limit = 50, int $after_id = 0 ): array {
		global $wpdb;

		$limit  = max( 1, min( 200, $limit ) );
		$table  = $this->table();
		$events = $wpdb->prefix . 'acl_ar_events';
		$rooms  = $wpdb->prefix . 'acl_ar_rooms';

		if ( $after_id > 0 ) {
			$sql = $wpdb->prepare(
				"SELECT m.* FROM {$table} m INNER JOIN {$rooms} r ON r.id=m.room_id LEFT JOIN {$events} e ON e.legacy_message_id=m.id WHERE m.room_id = %d AND m.id > %d AND (COALESCE(r.cleared_through_event_id,0)=0 OR e.id>r.cleared_through_event_id) ORDER BY m.id ASC LIMIT %d",
				$room_id,
				$after_id,
				$limit
			);
		} else {
			$sql = $wpdb->prepare(
				"SELECT m.* FROM {$table} m INNER JOIN {$rooms} r ON r.id=m.room_id LEFT JOIN {$events} e ON e.legacy_message_id=m.id WHERE m.room_id = %d AND (COALESCE(r.cleared_through_event_id,0)=0 OR e.id>r.cleared_through_event_id) ORDER BY m.id DESC LIMIT %d",
				$room_id,
				$limit
			);
		}

		$rows = $wpdb->get_results( $sql, ARRAY_A );
		$rows = is_array( $rows ) ? $rows : array();

		if ( 0 === $after_id ) {
			$rows = array_reverse( $rows );
		}

		return array_map( array( Message::class, 'from_row' ), $rows );
	}

	public function context_for_room( int $room_id, int $limit ): array {
		return $this->for_room( $room_id, $limit, 0 );
	}

	public function latest_user_message_for_room( int $room_id ): ?array {
		global $wpdb;

		$row = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT m.* FROM ' . $this->table() . ' m INNER JOIN ' . $wpdb->prefix . 'acl_ar_rooms r ON r.id=m.room_id LEFT JOIN ' . $wpdb->prefix . 'acl_ar_events e ON e.legacy_message_id=m.id WHERE m.room_id = %d AND m.sender_type = %s AND (COALESCE(r.cleared_through_event_id,0)=0 OR e.id>r.cleared_through_event_id) ORDER BY m.id DESC LIMIT 1',
				$room_id,
				'user'
			),
			ARRAY_A
		);

		return $row ? Message::from_row( $row ) : null;
	}

	public function missing_event_batch( int $limit = 100 ): array {
		global $wpdb;
		$limit    = max( 1, min( 500, $limit ) );
		$messages = $this->table();
		$events   = $wpdb->prefix . 'acl_ar_events';
		$rows     = $wpdb->get_results(
			$wpdb->prepare( "SELECT m.* FROM {$messages} m LEFT JOIN {$events} e ON e.legacy_message_id = m.id WHERE e.id IS NULL ORDER BY m.id ASC LIMIT %d", $limit ),
			ARRAY_A
		);
		return array_map( array( Message::class, 'from_row' ), is_array( $rows ) ? $rows : array() );
	}

	public function count_missing_events(): int {
		global $wpdb;
		$messages = $this->table();
		$events   = $wpdb->prefix . 'acl_ar_events';
		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$messages} m LEFT JOIN {$events} e ON e.legacy_message_id = m.id WHERE e.id IS NULL" );
	}
}
