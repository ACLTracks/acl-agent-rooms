<?php
// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange -- This file operates on plugin-owned operational tables and requires uncached transaction/read-after-write truth.
// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- Identifiers are fixed $wpdb->prefix suffixes, fixed internal schema fragments, or allowlisted names; runtime values use placeholders or wpdb mutation arrays.
// phpcs:disable PluginCheck.Security.DirectDB.UnescapedDBParameter -- PHPCS cannot model the fixed identifier provenance above; request data is never used as an SQL identifier.
/** Atomically persists one canonical human message and event. @package ACL_Agent_Rooms */

namespace ACL\AgentRooms\Services;

use ACL\AgentRooms\Repositories\EventRepository;
use ACL\AgentRooms\Repositories\MessageRepository;
use ACL\AgentRooms\Repositories\ReadStateRepository;
use ACL\AgentRooms\Repositories\RoomRepository;

if ( ! defined( 'ABSPATH' ) ) {
	exit; }

class HumanMessageService {
	private RoomRepository $rooms;
	private MessageRepository $messages;
	private EventRepository $events;
	private ReadStateRepository $reads;
	private RoomEventService $room_events;

	public function __construct( ?RoomRepository $rooms = null, ?MessageRepository $messages = null, ?EventRepository $events = null, ?ReadStateRepository $reads = null ) {
		$this->rooms       = $rooms ?: new RoomRepository();
		$this->messages    = $messages ?: new MessageRepository();
		$this->events      = $events ?: new EventRepository();
		$this->reads       = $reads ?: new ReadStateRepository();
		$this->room_events = new RoomEventService( $this->events );
	}

	/**
	 * @return array{message:array,event:array,created:bool}|\WP_Error
	 */
	public function persist( int $room_id, int $user_id, string $content, string $client_request_id, array $metadata = array(), bool $advance_natural_trigger = false ) {
		global $wpdb;

		$wpdb->last_error = '';
		if ( false === $wpdb->query( 'START TRANSACTION' ) ) {
			return new \WP_Error( 'acl_ar_human_message_transaction_failed', __( 'The message could not be saved safely.', 'acl-agent-rooms' ), array( 'status' => 500 ) );
		}

		$locked_room = $this->rooms->find_for_update( $room_id );
		if ( ! $locked_room || 'active' !== (string) ( $locked_room['status'] ?? '' ) ) {
			$wpdb->query( 'ROLLBACK' );
			return new \WP_Error( 'acl_ar_room_inactive', __( 'This room is not accepting new messages.', 'acl-agent-rooms' ), array( 'status' => 423 ) );
		}

		$created = $this->messages->create_user_idempotent( $room_id, $user_id, $content, $client_request_id, $metadata );
		if ( is_wp_error( $created ) ) {
			$wpdb->query( 'ROLLBACK' );
			return $created;
		}

		$message = $created['message'] ?: $this->messages->find( (int) $created['id'] );
		$event   = $message ? $this->room_events->create_message_event( $message ) : null;
		if ( ! $message || is_wp_error( $event ) || ! is_array( $event ) ) {
			$wpdb->query( 'ROLLBACK' );
			return is_wp_error( $event ) ? $event : new \WP_Error( 'acl_ar_human_message_event_failed', __( 'The message event could not be saved safely.', 'acl-agent-rooms' ), array( 'status' => 500 ) );
		}
		if ( $advance_natural_trigger && 'natural' === (string) ( $locked_room['conversation_mode'] ?? 'immediate' ) && ! $this->rooms->advance_natural_trigger( $room_id, (int) $event['id'] ) ) {
			$wpdb->query( 'ROLLBACK' );
			return new \WP_Error( 'acl_ar_natural_trigger_advance_failed', __( 'The conversation could not advance safely.', 'acl-agent-rooms' ), array( 'status' => 500 ) );
		}

		$read_boundary = $this->reads->advance( $room_id, $user_id, (int) $event['id'] );
		$injected      = apply_filters( 'acl_ar_human_message_persist_fail', false, 'before_commit', $room_id, (int) $event['id'] );
		if ( $read_boundary < (int) $event['id'] || $injected ) {
			$wpdb->query( 'ROLLBACK' );
			return new \WP_Error( 'acl_ar_human_message_commit_failed', __( 'The message could not be committed safely.', 'acl-agent-rooms' ), array( 'status' => 500 ) );
		}

		if ( false === $wpdb->query( 'COMMIT' ) ) {
			$wpdb->query( 'ROLLBACK' );
			return new \WP_Error( 'acl_ar_human_message_commit_failed', __( 'The message could not be committed safely.', 'acl-agent-rooms' ), array( 'status' => 500 ) );
		}

		do_action( 'acl_ar_human_message_committed', $room_id, $user_id, $message, $event, ! empty( $created['created'] ) );
		return array(
			'message' => $message,
			'event'   => $event,
			'created' => ! empty( $created['created'] ),
		);
	}
}
