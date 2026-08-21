<?php
/** Visibility-safe room search and context. @package ACL_Agent_Rooms */
namespace ACL\AgentRooms\Services;

use ACL\AgentRooms\Repositories\EventRepository;
use ACL\AgentRooms\Repositories\EventSearchRepository;

if ( ! defined( 'ABSPATH' ) ) {
	exit; }

class EventSearchService {
	private AccessService $access;
	private EventSearchRepository $search;
	private EventRepository $events;
	private EventVisibilityService $visibility;
	private EventProjectionService $projection;
	private SearchCursor $cursors;

	public function __construct( ?AccessService $access = null ) {
		$this->access     = $access ?: new AccessService();
		$this->search     = new EventSearchRepository();
		$this->events     = new EventRepository();
		$this->visibility = new EventVisibilityService( $this->access );
		$this->projection = new EventProjectionService();
		$this->cursors    = new SearchCursor();
	}

	public function search( int $room_id, int $user_id, string $query, string $cursor = '', int $limit = 20 ) {
		$query        = trim( wp_strip_all_tags( $query ) );
		$query_length = function_exists( 'mb_strlen' ) ? mb_strlen( $query, 'UTF-8' ) : strlen( $query );
		if ( $query_length < 2 || $query_length > 190 ) {
			return new \WP_Error( 'acl_ar_search_query', __( 'Search queries must be between 2 and 190 characters.', 'acl-agent-rooms' ), array( 'status' => 400 ) );
		}
		if ( ! $this->access->can_read_room( $room_id, $user_id ) ) {
			return new \WP_Error( 'acl_ar_room_forbidden', __( 'You cannot access this room.', 'acl-agent-rooms' ), array( 'status' => 403 ) );
		}

		$before = 0;
		$room   = ( new \ACL\AgentRooms\Repositories\RoomRepository() )->find( $room_id );
		$cutoff = max( 0, (int) ( $room['cleared_through_event_id'] ?? 0 ) );
		if ( '' !== $cursor ) {
			$before = $this->cursors->decode( $cursor, $room_id, $user_id, $query, $cutoff );
			if ( is_wp_error( $before ) ) {
				return $before; }
		}

		$limit      = max( 1, min( 50, $limit ) );
		$can_manage = $this->access->can_manage_room( $room_id, $user_id );
		$rows       = $this->search->candidates( $room_id, $query, $before, $limit * 8 + 8, $cutoff );
		$visible    = array();
		foreach ( $rows as $event ) {
			if ( $this->visibility->can_view( $event, $user_id, $can_manage ) ) {
				$visible[] = $event;
				if ( count( $visible ) > $limit ) {
					break; }
			}
		}

		$more = count( $visible ) > $limit;
		$page = array_slice( $visible, 0, $limit );
		$next = $more && $page ? $this->cursors->encode( $room_id, $user_id, $query, (int) end( $page )['id'], $cutoff ) : null;
		return array(
			'results'     => $this->projection->project_page( $page, $can_manage, $user_id ),
			'next_cursor' => $next,
			'has_more'    => $more,
			'query'       => $query,
		);
	}

	public function context( int $room_id, int $user_id, int $event_id, int $radius = 3 ) {
		if ( ! $this->access->can_read_room( $room_id, $user_id ) ) {
			return new \WP_Error( 'acl_ar_room_forbidden', __( 'You cannot access this room.', 'acl-agent-rooms' ), array( 'status' => 403 ) );
		}

		$target     = $this->events->find( $event_id );
		$cutoff     = ( new RoomCutoffPolicy() )->cutoff( $room_id );
		$can_manage = $this->access->can_manage_room( $room_id, $user_id );
		if ( ! $target || (int) $target['room_id'] !== $room_id || ( new RoomCutoffPolicy() )->event_is_cleared( $target, $cutoff ) || ! $this->visibility->can_view( $target, $user_id, $can_manage ) ) {
			return new \WP_Error( 'acl_ar_event_not_found', __( 'Event not found.', 'acl-agent-rooms' ), array( 'status' => 404 ) );
		}

		$radius = max( 1, min( 10, $radius ) );
		$rows   = array_merge(
			$this->events->for_room_before( $room_id, $event_id, $radius ),
			array( $target ),
			$this->events->for_room_after( $room_id, $event_id, $radius )
		);
		$rows   = array_values( array_filter( $rows, fn( $event ) => ! ( new RoomCutoffPolicy() )->event_is_cleared( $event, $cutoff ) && $this->visibility->can_view( $event, $user_id, $can_manage ) ) );
		return array(
			'events'          => $this->projection->project_page( $rows, $can_manage, $user_id ),
			'target_event_id' => $event_id,
		);
	}
}
