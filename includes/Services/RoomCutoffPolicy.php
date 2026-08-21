<?php
/** Central logical transcript-cutoff rules. @package ACL_Agent_Rooms */
namespace ACL\AgentRooms\Services;

use ACL\AgentRooms\Repositories\EventRepository;
use ACL\AgentRooms\Repositories\RoomRepository;

if ( ! defined( 'ABSPATH' ) ) {
	exit; }

class RoomCutoffPolicy {
	public const TRANSCRIPT_TYPES = array( 'message', 'system_notice', 'action', 'dice_roll', 'coin_flip', 'whisper' );
	private RoomRepository $rooms;
	private EventRepository $events;

	public function __construct( ?RoomRepository $rooms = null, ?EventRepository $events = null ) {
		$this->rooms  = $rooms ?: new RoomRepository();
		$this->events = $events ?: new EventRepository();
	}

	public function cutoff( int $room_id ): int {
		$room = $this->rooms->find( $room_id );
		return max( 0, (int) ( $room['cleared_through_event_id'] ?? 0 ) );
	}

	public function is_transcript_bearing( array $event ): bool {
		return in_array( (string) ( $event['event_type'] ?? $event['type'] ?? '' ), self::TRANSCRIPT_TYPES, true );
	}

	public function event_is_cleared( array $event, ?int $cutoff = null ): bool {
		$cutoff = null === $cutoff ? $this->cutoff( (int) ( $event['room_id'] ?? 0 ) ) : max( 0, $cutoff );
		$id     = (int) ( $event['id'] ?? 0 );
		if ( $id <= 0 || $id > $cutoff ) {
			return false; }
		if ( $this->is_transcript_bearing( $event ) ) {
			return true; }
		return in_array( (string) ( $event['event_type'] ?? $event['type'] ?? '' ), array( 'message_edit', 'reaction', 'message_delete', 'moderation' ), true )
			&& (int) ( $event['parent_event_id'] ?? 0 ) <= $cutoff;
	}

	public function event_id_is_cleared( int $room_id, int $event_id ): bool {
		$event = $this->events->find( $event_id );
		return $event && (int) $event['room_id'] === $room_id && $this->event_is_cleared( $event );
	}

	public function legacy_message_is_cleared( int $room_id, int $message_id ): bool {
		$event = $this->events->find_by_legacy_message_id( $message_id );
		return $event && (int) $event['room_id'] === $room_id && $this->event_is_cleared( $event );
	}
}
