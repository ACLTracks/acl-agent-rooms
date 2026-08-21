<?php
/** Event retrieval application orchestration. @package ACL_Agent_Rooms */

namespace ACL\AgentRooms\Services;

use ACL\AgentRooms\Repositories\RoomRepository;

if ( ! defined( 'ABSPATH' ) ) {
	exit; }

class RoomApplicationService {
	private RoomRepository $rooms;
	private AccessService $access;
	private EventCursor $cursors;
	private EventSyncService $sync;
	private EventProjectionService $projection;
	public function __construct( ?RoomRepository $rooms = null, ?AccessService $access = null, ?EventCursor $cursors = null, ?EventSyncService $sync = null, ?EventProjectionService $projection = null ) {
		$this->rooms      = $rooms ?: new RoomRepository();
		$this->access     = $access ?: new AccessService( $this->rooms );
		$this->cursors    = $cursors ?: new EventCursor();
		$this->sync       = $sync ?: new EventSyncService( null, new EventVisibilityService( $this->access ), $this->cursors );
		$this->projection = $projection ?: new EventProjectionService();
	}

	public function events( int $room_id, int $user_id, $before_cursor, $after_cursor, $limit ) {
		$room = $this->rooms->find( $room_id );
		if ( ! $room ) {
			return new \WP_Error( 'acl_ar_room_not_found', __( 'Room not found.', 'acl-agent-rooms' ), array( 'status' => 404 ) ); }
		if ( ! $this->access->can_access_room( $room_id, $user_id ) ) {
			return new \WP_Error( 'acl_ar_room_forbidden', __( 'You cannot access this room.', 'acl-agent-rooms' ), array( 'status' => 403 ) ); }
		$before_cursor = is_string( $before_cursor ) ? trim( $before_cursor ) : '';
		$after_cursor  = is_string( $after_cursor ) ? trim( $after_cursor ) : '';
		if ( '' !== $before_cursor && '' !== $after_cursor ) {
			return new \WP_Error( 'acl_ar_invalid_cursor', __( 'Use either a before cursor or an after cursor, not both.', 'acl-agent-rooms' ), array( 'status' => 400 ) ); }
		if ( null === $limit || '' === $limit ) {
			$limit = 50; }
		if ( ! is_numeric( $limit ) || (int) $limit < 1 || (int) $limit > 100 ) {
			return new \WP_Error( 'acl_ar_invalid_event_limit', __( 'Event limit must be between 1 and 100.', 'acl-agent-rooms' ), array( 'status' => 400 ) ); }
		$mode     = 'initial';
		$boundary = 0;
		$cutoff   = max( 0, (int) ( $room['cleared_through_event_id'] ?? 0 ) );
		if ( '' !== $before_cursor ) {
			$mode    = 'before';
			$decoded = $this->cursors->decode( $before_cursor, $room_id, 'before', $cutoff );
			if ( is_wp_error( $decoded ) ) {
				return $decoded;
			} $boundary = max( $cutoff, (int) $decoded['event_id'] ); }
		if ( '' !== $after_cursor ) {
			$mode    = 'after';
			$decoded = $this->cursors->decode( $after_cursor, $room_id, 'after', $cutoff );
			if ( is_wp_error( $decoded ) ) {
				return $decoded;
			} $boundary = max( $cutoff, (int) $decoded['event_id'] ); }
		$can_manage = $this->access->can_manage_room( $room_id, $user_id );
		$page       = $this->sync->page( $room_id, $user_id, $can_manage, $mode, $boundary, (int) $limit, $cutoff );
		( new AgentStateReconciler() )->reconcile_room( $room_id );
		$states = array();
		foreach ( ( new \ACL\AgentRooms\Repositories\PresenceRepository() )->for_room( $room_id ) as $row ) {
			if ( 'agent' === (string) $row['actor_type'] ) {
				$states[] = array(
					'agent_id' => (int) $row['actor_id'],
					'state'    => (string) $row['state'],
				);}
		}
		return array(
			'events' => $this->projection->project_page( $page['events'], $can_manage, $user_id ),
			'paging' => $page['paging'],
			'sync'   => array(
				'room_id'                  => $room_id,
				'latest_visible_event_id'  => (int) $page['latest_visible_event_id'],
				'cleared_through_event_id' => $cutoff,
				'agent_states'             => $states,
				'server_time'              => gmdate( 'c' ),
			),
		);
	}
}
