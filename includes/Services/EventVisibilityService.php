<?php
/** Audience-aware event visibility. @package ACL_Agent_Rooms */

namespace ACL\AgentRooms\Services;

if ( ! defined( 'ABSPATH' ) ) {
	exit; }

class EventVisibilityService {
	private AccessService $access;
	public function __construct( ?AccessService $access = null ) {
		$this->access = $access ?: new AccessService(); }

	public function can_view( array $event, int $user_id, bool $can_manage ): bool {
		switch ( (string) ( $event['audience_type'] ?? '' ) ) {
			case 'room':
				return true;
			case 'user':
				if ( 'whisper' === (string) ( $event['event_type'] ?? '' ) ) {
					return (int) ( $event['audience_id'] ?? 0 ) === $user_id || ( 'user' === (string) ( $event['actor_type'] ?? '' ) && (int) ( $event['actor_id'] ?? 0 ) === $user_id ) || $can_manage;}
				return (int) ( $event['audience_id'] ?? 0 ) === $user_id || $can_manage;
			case 'agent':
			case 'moderators':
				return $can_manage;
			default:
				return false;
		}
	}

	public function scope_key( int $room_id, int $user_id, bool $can_manage ): string {
		return hash( 'sha256', $room_id . '|' . $user_id . '|' . ( $can_manage ? 'manager' : 'reader' ) );
	}
}
