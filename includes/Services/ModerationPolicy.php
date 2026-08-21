<?php
/** Central moderation policy. @package ACL_Agent_Rooms */
namespace ACL\AgentRooms\Services;

use ACL\AgentRooms\Repositories\RoomRepository;
use ACL\AgentRooms\Repositories\RoomRestrictionRepository;
if ( ! defined( 'ABSPATH' ) ) {
	exit; }
class ModerationPolicy {
	private RoomRepository $rooms;
	private RoomRestrictionRepository $restrictions;
	public function __construct( ?RoomRepository $rooms = null, ?RoomRestrictionRepository $restrictions = null ) {
		$this->rooms        = $rooms ?: new RoomRepository();
		$this->restrictions = $restrictions ?: new RoomRestrictionRepository();}
	public function is_banned( int $room_id, int $user_id ): bool {
		return $this->restrictions->has( $room_id, $user_id, 'ban' );}
	public function is_muted( int $room_id, int $user_id ): bool {
		return $this->restrictions->has( $room_id, $user_id, 'mute' );}
	public function can_target( int $room_id, int $actor_id, int $target_id ) {
		if ( $actor_id === $target_id ) {
			return new \WP_Error( 'acl_ar_moderation_self', __( 'You cannot moderate yourself.', 'acl-agent-rooms' ), array( 'status' => 422 ) );
		}$room = $this->rooms->find( $room_id );
		if ( ! $room ) {
			return new \WP_Error( 'acl_ar_room_not_found', __( 'Room not found.', 'acl-agent-rooms' ), array( 'status' => 404 ) );
		}if ( (int) $room['owner_user_id'] === $target_id || user_can( $target_id, 'acl_ar_manage_all_rooms' ) ) {
			return new \WP_Error( 'acl_ar_moderation_protected', __( 'Room owners and equal or higher managers cannot be targeted.', 'acl-agent-rooms' ), array( 'status' => 403 ) );
		}return true;}
}
