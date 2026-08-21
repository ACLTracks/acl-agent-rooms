<?php
/** Visible-only persistent unread state. @package ACL_Agent_Rooms */
namespace ACL\AgentRooms\Services;

use ACL\AgentRooms\Repositories\EventRepository;
use ACL\AgentRooms\Repositories\ReadStateRepository;
use ACL\AgentRooms\Repositories\RoomRepository;
if ( ! defined( 'ABSPATH' ) ) {
	exit;}
class ReadStateService {
	private EventRepository $events;
	private ReadStateRepository $reads;
	private RoomRepository $rooms;
	private AccessService $access;
	private EventVisibilityService $visibility;
	public function __construct( ?EventRepository $events = null, ?ReadStateRepository $reads = null, ?RoomRepository $rooms = null, ?AccessService $access = null ) {
		$this->events     = $events ?: new EventRepository();
		$this->reads      = $reads ?: new ReadStateRepository();
		$this->rooms      = $rooms ?: new RoomRepository();
		$this->access     = $access ?: new AccessService( $this->rooms );
		$this->visibility = new EventVisibilityService( $this->access );}
	public function state( int $room_id, int $user_id ): array {
		$cutoff = ( new RoomCutoffPolicy( $this->rooms, $this->events ) )->cutoff( $room_id );
		return $this->calculate( $room_id, $user_id, max( $cutoff, $this->reads->get( $room_id, $user_id ) ) );}
	public function advance( int $room_id, int $user_id, int $event_id ) {
		if ( $event_id > 0 ) {
			$event  = $this->events->find( $event_id );
			$manage = $this->access->can_manage_room( $room_id, $user_id );
			if ( ! $event || (int) $event['room_id'] !== $room_id || ! $this->visibility->can_view( $event, $user_id, $manage ) ) {
				return new \WP_Error( 'acl_ar_event_forbidden', __( 'The read boundary is not visible in this room.', 'acl-agent-rooms' ), array( 'status' => 403 ) );
			}
		}$stored = $this->reads->advance( $room_id, $user_id, $event_id );
		return $this->calculate( $room_id, $user_id, $stored );}
	private function calculate( int $room_id, int $user_id, int $boundary ): array {
		$cutoff   = ( new RoomCutoffPolicy( $this->rooms, $this->events ) )->cutoff( $room_id );
		$boundary = max( $boundary, $cutoff );
		$manage   = $this->access->can_manage_room( $room_id, $user_id );
		$after    = $cutoff;
		$latest   = $cutoff;
		$unread   = 0;
		$first    = 0;
		$bearing  = RoomCutoffPolicy::TRANSCRIPT_TYPES;
		do {
			$rows = $this->events->scan_room_after( $room_id, $after, 500 );
			foreach ( $rows as $event ) {
				$after = max( $after, (int) $event['id'] );
				if ( ! $this->visibility->can_view( $event, $user_id, $manage ) ) {
					continue;
				}$latest = max( $latest, (int) $event['id'] );
				if ( (int) $event['id'] <= $boundary || ! in_array( (string) $event['event_type'], $bearing, true ) || ( 'user' === (string) $event['actor_type'] && (int) $event['actor_id'] === $user_id ) ) {
					continue;
				}++$unread;
				if ( ! $first ) {
					$first = (int) $event['id'];
				}
			}
		} while ( 500 === count( $rows ) );
		return array(
			'last_read_event_id'      => $boundary,
			'latest_visible_event_id' => $latest,
			'unread_count'            => $unread,
			'first_unread_event_id'   => $first,
		);}
}
