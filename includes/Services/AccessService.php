<?php
/**
 * Room access rules.
 *
 * @package ACL_Agent_Rooms
 */

namespace ACL\AgentRooms\Services;

use ACL\AgentRooms\Repositories\RoomRepository;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class AccessService {
	private RoomRepository $rooms;
	private ModerationPolicy $moderation;

	public function __construct( ?RoomRepository $rooms = null ) {
		$this->rooms      = $rooms ?: new RoomRepository();
		$this->moderation = new ModerationPolicy( $this->rooms );
	}

	public function can_use_rooms(): bool {
		return is_user_logged_in() && current_user_can( 'acl_ar_use_rooms' );
	}

	public function can_access_room( int $room_id, int $user_id = 0, bool $write = false ): bool {
		return $write ? $this->can_write_room( $room_id, $user_id ) : $this->can_read_room( $room_id, $user_id );
	}

	public function can_read_room( int $room_id, int $user_id = 0 ): bool {
		$user_id = $user_id > 0 ? $user_id : get_current_user_id();
		if ( $user_id <= 0 || ! user_can( $user_id, 'acl_ar_use_rooms' ) ) {
			return false;
		}
		if ( $this->moderation->is_banned( $room_id, $user_id ) ) {
			return false; }

		if ( user_can( $user_id, 'acl_ar_manage_all_rooms' ) ) {
			return true;
		}

		$room = $this->rooms->find( $room_id );
		if ( ! $room ) {
			return false;
		}

		if ( (int) $room['owner_user_id'] === $user_id || $this->rooms->is_member( $room_id, $user_id ) ) {
			return true;
		}

		return $this->is_publicly_readable( $room );
	}

	public function can_write_room( int $room_id, int $user_id = 0 ): bool {
		$user_id = $user_id > 0 ? $user_id : get_current_user_id();
		return $this->can_read_room( $room_id, $user_id ) && ! $this->moderation->is_muted( $room_id, $user_id );
	}

	public function can_manage_room( int $room_id, int $user_id = 0 ): bool {
		$user_id = $user_id > 0 ? $user_id : get_current_user_id();

		if ( $user_id <= 0 ) {
			return false;
		}

		if ( user_can( $user_id, 'acl_ar_manage_all_rooms' ) ) {
			return true;
		}

		return user_can( $user_id, 'acl_ar_manage_own_rooms' ) && $this->rooms->is_owner( $room_id, $user_id );
	}

	public function is_publicly_readable( array $room ): bool {
		return 'public' === (string) ( $room['type'] ?? '' )
			&& 'public' === (string) ( $room['visibility'] ?? '' );
	}
}
