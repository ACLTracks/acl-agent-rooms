<?php
/** Authorized room-level Clear Chat REST action. @package ACL_Agent_Rooms */
namespace ACL\AgentRooms\Rest;

use ACL\AgentRooms\Repositories\RoomRepository;
use ACL\AgentRooms\Services\AccessService;
use ACL\AgentRooms\Services\RateLimiter;
use ACL\AgentRooms\Services\RoomClearService;

if ( ! defined( 'ABSPATH' ) ) {
	exit; }

class RoomClearController extends AbstractController {
	private RoomRepository $rooms;
	private AccessService $access;
	private RoomClearService $service;

	public function __construct( ?RoomRepository $rooms = null, ?AccessService $access = null, ?RoomClearService $service = null ) {
		$this->rooms   = $rooms ?: new RoomRepository();
		$this->access  = $access ?: new AccessService( $this->rooms );
		$this->service = $service ?: new RoomClearService( $this->rooms, null, null, null, $this->access );
	}

	public function register_routes(): void {
		register_rest_route(
			self::NAMESPACE,
			'/rooms/(?P<room_id>[\d]+)/clear',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'clear' ),
				'permission_callback' => array( $this, 'permissions' ),
				'args'                => array(
					'room_id'         => array(
						'required'          => true,
						'sanitize_callback' => 'absint',
						'validate_callback' => static fn( $value ) => absint( $value ) > 0,
					),
					'confirm'         => array(
						'required'          => true,
						'type'              => 'boolean',
						'sanitize_callback' => 'rest_sanitize_boolean',
						'validate_callback' => static fn( $value ) => true === $value || 1 === $value || '1' === $value || 'true' === strtolower( (string) $value ),
					),
					'idempotency_key' => array(
						'required'          => true,
						'type'              => 'string',
						'sanitize_callback' => static fn( $value ) => trim( (string) $value ),
						'validate_callback' => static fn( $value ) => is_string( $value ) && 16 <= strlen( $value ) && 64 >= strlen( $value ) && 1 === preg_match( '/^[A-Za-z0-9._:-]+$/', $value ),
					),
				),
			)
		);
	}

	public function permissions( \WP_REST_Request $request ) {
		$nonce = $this->verify_nonce( $request );
		if ( is_wp_error( $nonce ) ) {
			return $nonce; }
		$required = $this->require_room_user();
		if ( is_wp_error( $required ) ) {
			return $required; }
		$room_id = absint( $request['room_id'] );
		$room    = $this->rooms->find( $room_id );
		if ( ! $room ) {
			return new \WP_Error( 'acl_ar_room_not_found', __( 'Room not found.', 'acl-agent-rooms' ), array( 'status' => 404 ) ); }
		if ( ! $this->access->can_manage_room( $room_id ) || ! $this->access->can_read_room( $room_id ) ) {
			return new \WP_Error( 'acl_ar_chat_clear_forbidden', __( 'You cannot clear this room.', 'acl-agent-rooms' ), array( 'status' => 403 ) ); }
		if ( empty( $room['allow_chat_clear'] ) ) {
			return new \WP_Error( 'acl_ar_chat_clear_disabled', __( 'Clear Chat is not enabled for this room.', 'acl-agent-rooms' ), array( 'status' => 403 ) ); }
		return true;
	}

	public function clear( \WP_REST_Request $request ) {
		$rate = ( new RateLimiter() )->can_clear_room( get_current_user_id(), absint( $request['room_id'] ) );
		if ( is_wp_error( $rate ) ) {
			return $rate; }
		$result = $this->service->clear( absint( $request['room_id'] ), get_current_user_id(), (string) $request['idempotency_key'] );
		if ( is_wp_error( $result ) ) {
			return $result; }
		return new \WP_REST_Response(
			$result,
			200,
			array(
				'Cache-Control' => 'private, no-store, max-age=0',
				'Pragma'        => 'no-cache',
				'Vary'          => 'Cookie, X-WP-Nonce',
			)
		);
	}
}
