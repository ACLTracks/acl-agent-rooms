<?php
/** Cursor-based visible event synchronization. @package ACL_Agent_Rooms */

namespace ACL\AgentRooms\Services;

use ACL\AgentRooms\Repositories\EventRepository;

if ( ! defined( 'ABSPATH' ) ) {
	exit; }

class EventSyncService {
	private const MAX_CANDIDATE_SCAN = 2000;
	private EventRepository $events;
	private EventVisibilityService $visibility;
	private EventCursor $cursors;

	public function __construct( ?EventRepository $events = null, ?EventVisibilityService $visibility = null, ?EventCursor $cursors = null ) {
		$this->events     = $events ?: new EventRepository();
		$this->visibility = $visibility ?: new EventVisibilityService();
		$this->cursors    = $cursors ?: new EventCursor();
	}

	public function page( int $room_id, int $user_id, bool $can_manage, string $mode, int $boundary, int $limit, int $cutoff = 0 ): array {
		$descending    = in_array( $mode, array( 'initial', 'before' ), true );
		$scan_boundary = $boundary;
		$visible       = array();
		$scanned       = 0;
		$exhausted     = false;
		$chunk         = min( 500, max( 100, $limit * 2 ) );
		while ( count( $visible ) < $limit + 1 && $scanned < self::MAX_CANDIDATE_SCAN && ! $exhausted ) {
			$remaining = min( $chunk, self::MAX_CANDIDATE_SCAN - $scanned );
			if ( 'initial' === $mode && 0 === $scanned ) {
				$candidates = $this->events->scan_newest_for_room( $room_id, $remaining ); } elseif ( $descending ) {
				$candidates = $this->events->scan_room_before( $room_id, $scan_boundary, $remaining ); } else {
					$candidates = $this->events->scan_room_after( $room_id, $scan_boundary, $remaining ); }
				if ( empty( $candidates ) ) {
					$exhausted = true;
					break; }
				$scanned += count( $candidates );
				foreach ( $candidates as $candidate ) {
					if ( (int) $candidate['id'] > $cutoff && ! ( new RoomCutoffPolicy() )->event_is_cleared( $candidate, $cutoff ) && $this->visibility->can_view( $candidate, $user_id, $can_manage ) ) {
								$visible[] = $candidate;
						if ( count( $visible ) >= $limit + 1 ) {
							break; }
					}
				}
				$last          = end( $candidates );
				$scan_boundary = (int) $last['id'];
				reset( $candidates );
				if ( count( $candidates ) < $remaining || ( $descending && $scan_boundary <= $cutoff ) ) {
					$exhausted = true; }
		}

		$has_extra = count( $visible ) > $limit;
		$visible   = array_slice( $visible, 0, $limit );
		if ( $descending ) {
			$visible = array_reverse( $visible ); }
		$oldest         = $visible ? (int) $visible[0]['id'] : 0;
		$newest         = $visible ? (int) $visible[ count( $visible ) - 1 ]['id'] : 0;
		$latest_visible = $this->latest_visible_id( $room_id, $user_id, $can_manage, $cutoff );
		$after_boundary = $newest ?: ( 'after' === $mode ? $boundary : $latest_visible );
		return array(
			'events'                  => $visible,
			'paging'                  => array(
				'before_cursor'   => $has_extra && $oldest ? $this->cursors->encode( $room_id, 'before', $oldest, $cutoff ) : null,
				'after_cursor'    => $this->cursors->encode( $room_id, 'after', max( $cutoff, $after_boundary ), $cutoff ),
				'has_more_before' => $descending ? $has_extra : false,
				'has_more_after'  => 'after' === $mode ? $has_extra : ( 'before' === $mode && ! empty( $visible ) ),
			),
			'latest_visible_event_id' => $latest_visible,
		);
	}

	private function latest_visible_id( int $room_id, int $user_id, bool $can_manage, int $cutoff = 0 ): int {
		$boundary = 0;
		$scanned  = 0;
		while ( $scanned < self::MAX_CANDIDATE_SCAN ) {
			$rows = 0 === $scanned ? $this->events->scan_newest_for_room( $room_id, 100 ) : $this->events->scan_room_before( $room_id, $boundary, 100 );
			if ( empty( $rows ) ) {
				return 0; }
			foreach ( $rows as $row ) {
				if ( (int) $row['id'] > $cutoff && ! ( new RoomCutoffPolicy() )->event_is_cleared( $row, $cutoff ) && $this->visibility->can_view( $row, $user_id, $can_manage ) ) {
					return (int) $row['id']; }
			}
			$scanned += count( $rows );
			$last     = end( $rows );
			$boundary = (int) $last['id'];
			if ( count( $rows ) < 100 || $boundary <= $cutoff ) {
				return $cutoff; }
		}
		return $cutoff;
	}
}
