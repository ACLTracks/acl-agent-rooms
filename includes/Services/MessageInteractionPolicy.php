<?php
/** Central interaction authorization. @package ACL_Agent_Rooms */
namespace ACL\AgentRooms\Services;

use ACL\AgentRooms\Repositories\EventRepository;
use ACL\AgentRooms\Repositories\RoomRepository;
if ( ! defined( 'ABSPATH' ) ) {
	exit;}
class MessageInteractionPolicy {
	private RoomRepository $rooms;
	private AccessService $access;
	private EventRepository $events;
	private EventVisibilityService $visibility;
	public function __construct( ?RoomRepository $rooms = null, ?AccessService $access = null, ?EventRepository $events = null ) {
		$this->rooms      = $rooms ?: new RoomRepository();
		$this->access     = $access ?: new AccessService( $this->rooms );
		$this->events     = $events ?: new EventRepository();
		$this->visibility = new EventVisibilityService( $this->access );}
	public static function reactions(): array {
		$defaults = array( '👍', '❤️', '😂', '😮', '😢', '🎉', '⭐', '👀' );
		$list     = apply_filters( 'acl_ar_allowed_message_reactions', $defaults );
		return array_values( array_unique( array_intersect( $defaults, is_array( $list ) ? $list : array() ) ) );}
	public function target( int $room_id, int $event_id, int $user_id, string $action ) {
		$room  = $this->rooms->find( $room_id );
		$event = $this->events->find( $event_id );
		if ( ! $room || ! $event || (int) $event['room_id'] !== $room_id || ( new RoomCutoffPolicy( $this->rooms, $this->events ) )->event_is_cleared( $event, (int) ( $room['cleared_through_event_id'] ?? 0 ) ) ) {
			return new \WP_Error( 'acl_ar_event_not_found', __( 'Message event not found.', 'acl-agent-rooms' ), array( 'status' => 404 ) );
		}$manage = $this->access->can_manage_room( $room_id, $user_id );
		if ( ! $this->visibility->can_view( $event, $user_id, $manage ) || ! empty( $event['deleted_at'] ) ) {
			return new \WP_Error( 'acl_ar_event_forbidden', __( 'You cannot interact with this event.', 'acl-agent-rooms' ), array( 'status' => 403 ) );}
		if ( 'reply' === $action ) {
			if ( ! $this->access->can_access_room( $room_id, $user_id, true ) || 'active' !== (string) ( $room['status'] ?? '' ) ) {
				return new \WP_Error( 'acl_ar_event_forbidden', __( 'You cannot reply in this room.', 'acl-agent-rooms' ), array( 'status' => 403 ) );
			}if ( ! in_array( (string) $event['event_type'], array( 'message', 'system_notice' ), true ) ) {
				return new \WP_Error( 'acl_ar_event_not_editable', __( 'That event cannot be replied to.', 'acl-agent-rooms' ), array( 'status' => 400 ) );}
		}
		if ( 'reaction' === $action && ! in_array( (string) $event['event_type'], array( 'message', 'system_notice' ), true ) ) {
			return new \WP_Error( 'acl_ar_event_not_editable', __( 'That event cannot receive reactions.', 'acl-agent-rooms' ), array( 'status' => 400 ) );}
		if ( 'edit' === $action ) {
			if ( 'message' !== (string) $event['event_type'] || 'user' !== (string) $event['actor_type'] ) {
				return new \WP_Error( 'acl_ar_event_not_editable', __( 'Only human messages can be edited.', 'acl-agent-rooms' ), array( 'status' => 400 ) );
			}if ( (int) $event['actor_id'] !== $user_id ) {
				return new \WP_Error( 'acl_ar_event_forbidden', __( 'You can edit only your own messages.', 'acl-agent-rooms' ), array( 'status' => 403 ) );
			}$window = max( 0, (int) apply_filters( 'acl_ar_message_edit_window_seconds', 900, $room_id, $user_id ) );
			if ( strtotime( (string) $event['created_at'] . ' UTC' ) < time() - $window ) {
				return new \WP_Error( 'acl_ar_edit_window_expired', __( 'The message edit window has expired.', 'acl-agent-rooms' ), array( 'status' => 409 ) );}
		}
		return $event;
	}
}
