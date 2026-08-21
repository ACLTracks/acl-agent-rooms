<?php
// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange -- This file operates on plugin-owned operational tables and requires uncached transaction/read-after-write truth.
// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- Identifiers are fixed $wpdb->prefix suffixes, fixed internal schema fragments, or allowlisted names; runtime values use placeholders or wpdb mutation arrays.
// phpcs:disable PluginCheck.Security.DirectDB.UnescapedDBParameter -- PHPCS cannot model the fixed identifier provenance above; request data is never used as an SQL identifier.
/** Transactional, idempotent room transcript clearing. @package ACL_Agent_Rooms */
namespace ACL\AgentRooms\Services;

use ACL\AgentRooms\Repositories\BrainRunRepository;
use ACL\AgentRooms\Repositories\EventRepository;
use ACL\AgentRooms\Repositories\JobRepository;
use ACL\AgentRooms\Repositories\RoomRepository;
use ACL\AgentRooms\Support\Time;

if ( ! defined( 'ABSPATH' ) ) {
	exit; }

class RoomClearService {
	private RoomRepository $rooms;
	private EventRepository $events;
	private JobRepository $jobs;
	private BrainRunRepository $runs;
	private AccessService $access;
	private RoomEventService $room_events;

	public function __construct( ?RoomRepository $rooms = null, ?EventRepository $events = null, ?JobRepository $jobs = null, ?BrainRunRepository $runs = null, ?AccessService $access = null ) {
		$this->rooms       = $rooms ?: new RoomRepository();
		$this->events      = $events ?: new EventRepository();
		$this->jobs        = $jobs ?: new JobRepository();
		$this->runs        = $runs ?: new BrainRunRepository();
		$this->access      = $access ?: new AccessService( $this->rooms );
		$this->room_events = new RoomEventService( $this->events );
	}

	public function clear( int $room_id, int $user_id, string $idempotency_key ) {
		global $wpdb;
		$event_key = hash( 'sha256', 'room-clear:' . $room_id . ':' . $user_id . ':' . $idempotency_key );
		$wpdb->query( 'START TRANSACTION' );
		$room = $this->rooms->find_for_update( $room_id );
		if ( ! $room ) {
			return $this->rollback( new \WP_Error( 'acl_ar_room_not_found', __( 'Room not found.', 'acl-agent-rooms' ), array( 'status' => 404 ) ) ); }
		if ( empty( $room['allow_chat_clear'] ) ) {
			return $this->rollback( new \WP_Error( 'acl_ar_chat_clear_disabled', __( 'Clear Chat is not enabled for this room.', 'acl-agent-rooms' ), array( 'status' => 403 ) ) ); }
		if ( ! $this->access->can_manage_room( $room_id, $user_id ) || ! $this->access->can_read_room( $room_id, $user_id ) ) {
			return $this->rollback( new \WP_Error( 'acl_ar_chat_clear_forbidden', __( 'You cannot clear this room.', 'acl-agent-rooms' ), array( 'status' => 403 ) ) );
		}

		$existing = $this->events->find_by_idempotency_key( $event_key );
		if ( $existing && 'room_clear' === (string) $existing['event_type'] && (int) $existing['room_id'] === $room_id ) {
			$wpdb->query( 'COMMIT' );
			$this->health( 'duplicates' );
			return $this->result( true, $room_id, (int) ( $existing['metadata']['cleared_through_event_id'] ?? 0 ), (int) $existing['id'], true );
		}

		$old_cutoff        = max( 0, (int) ( $room['cleared_through_event_id'] ?? 0 ) );
		$latest_transcript = $this->events->highest_transcript_id( $room_id );
		if ( $latest_transcript <= $old_cutoff ) {
			$wpdb->query( 'COMMIT' );
			return $this->result( false, $room_id, $old_cutoff, 0, false );
		}

		$cutoff = max( $latest_transcript, $this->events->highest_id( $room_id ) );
		$now    = Time::mysql_gmt();
		if ( ! $this->rooms->record_clear( $room_id, $cutoff, $user_id, $now ) ) {
			return $this->rollback( new \WP_Error( 'acl_ar_chat_clear_failed', __( 'The room could not be cleared safely.', 'acl-agent-rooms' ), array( 'status' => 500 ) ) );
		}
		$this->jobs->cancel_for_room_cutoff( $room_id, $cutoff );
		$this->runs->cancel_for_room_cutoff( $room_id, $cutoff );
		( new ConversationTurnService() )->cancel_for_cutoff( $room_id, $cutoff );
		$event = $this->room_events->create(
			array(
				'room_id'         => $room_id,
				'event_type'      => 'room_clear',
				'actor_type'      => 'user',
				'actor_id'        => $user_id,
				'target_type'     => 'room',
				'target_id'       => $room_id,
				'audience_type'   => 'room',
				'idempotency_key' => $event_key,
				'content'         => null,
				'metadata'        => array( 'cleared_through_event_id' => $cutoff ),
			)
		);
		if ( is_wp_error( $event ) ) {
			return $this->rollback( $event ); }
		$wpdb->query( 'COMMIT' );
		( new AgentStateReconciler() )->reconcile_room( $room_id );
		return $this->result( true, $room_id, $cutoff, (int) $event['id'], false );
	}

	private function rollback( \WP_Error $error ) {
		global $wpdb;
		$wpdb->query( 'ROLLBACK' );
		$this->health( 'failed' );
		return $error; }
	private function result( bool $cleared, int $room_id, int $cutoff, int $event_id, bool $duplicate ): array {
		return array(
			'cleared'                  => $cleared,
			'room_id'                  => $room_id,
			'cleared_through_event_id' => $cutoff,
			'clear_event_id'           => $event_id,
			'duplicate'                => $duplicate,
		); }
	private function health( string $key ): void {
		$value         = (array) get_option( 'acl_ar_clear_health', array() );
		$value[ $key ] = absint( $value[ $key ] ?? 0 ) + 1;
		update_option( 'acl_ar_clear_health', $value, false ); }
}
