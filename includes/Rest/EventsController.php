<?php
/** Cursor-based room event REST API. @package ACL_Agent_Rooms */

namespace ACL\AgentRooms\Rest;

use ACL\AgentRooms\Repositories\RoomRepository;
use ACL\AgentRooms\Services\AccessService;
use ACL\AgentRooms\Services\RoomApplicationService;

if ( ! defined( 'ABSPATH' ) ) {
	exit; }

class EventsController extends AbstractController {
	private RoomRepository $rooms;
	private AccessService $access;
	private RoomApplicationService $application;
	public function __construct( ?RoomRepository $rooms = null, ?AccessService $access = null, ?RoomApplicationService $application = null ) {
		$this->rooms       = $rooms ?: new RoomRepository();
		$this->access      = $access ?: new AccessService( $this->rooms );
		$this->application = $application ?: new RoomApplicationService( $this->rooms, $this->access );
	}

	public function register_routes(): void {
		register_rest_route(
			self::NAMESPACE,
			'/rooms/(?P<id>[\d]+)/events',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'index' ),
				'permission_callback' => array( $this, 'read_permissions' ),
				'args'                => array(
					'id'            => array(
						'required'          => true,
						'sanitize_callback' => 'absint',
						'validate_callback' => static function ( $value ) {
																					return absint( $value ) > 0 ? true : new \WP_Error( 'acl_ar_room_not_found', __( 'Room not found.', 'acl-agent-rooms' ), array( 'status' => 404 ) );},
					),
					'before_cursor' => array(
						'required'          => false,
						'sanitize_callback' => static fn( $value ): string=>is_string( $value ) ? trim( $value ) : '',
						'validate_callback' => array( $this, 'validate_cursor_argument' ),
					),
					'after_cursor'  => array(
						'required'          => false,
						'sanitize_callback' => static fn( $value ): string=>is_string( $value ) ? trim( $value ) : '',
						'validate_callback' => array( $this, 'validate_cursor_argument' ),
					),
					'limit'         => array(
						'required'          => false,
						'default'           => 50,
						'sanitize_callback' => static fn( $value )=>is_numeric( $value ) ? (int) $value : $value,
						'validate_callback' => array( $this, 'validate_limit_argument' ),
					),
				),
			)
		);
	}

	public function validate_cursor_argument( $value ) {
		return ( is_string( $value ) && strlen( $value ) <= 2048 ) ? true : new \WP_Error( 'acl_ar_invalid_cursor', __( 'The event cursor is invalid.', 'acl-agent-rooms' ), array( 'status' => 400 ) ); }
	public function validate_limit_argument( $value ) {
		return ( is_numeric( $value ) && (int) $value >= 1 && (int) $value <= 100 ) ? true : new \WP_Error( 'acl_ar_invalid_event_limit', __( 'Event limit must be between 1 and 100.', 'acl-agent-rooms' ), array( 'status' => 400 ) ); }

	public function read_permissions( \WP_REST_Request $request ) {
		$allowed = $this->require_room_user();
		if ( is_wp_error( $allowed ) ) {
			return $allowed;}
		$room_id = absint( $request['id'] );
		if ( ! $this->rooms->find( $room_id ) ) {
			return new \WP_Error( 'acl_ar_room_not_found', __( 'Room not found.', 'acl-agent-rooms' ), array( 'status' => 404 ) );}
		return $this->access->can_access_room( $room_id ) ? true : new \WP_Error( 'acl_ar_room_forbidden', __( 'You cannot access this room.', 'acl-agent-rooms' ), array( 'status' => 403 ) );
	}

	public function index( \WP_REST_Request $request ) {
		$data = $this->application->events( absint( $request['id'] ), get_current_user_id(), $request->get_param( 'before_cursor' ), $request->get_param( 'after_cursor' ), $request->get_param( 'limit' ) );
		if ( is_wp_error( $data ) ) {
			return $data;}
		$cache_data = $data;
		unset( $cache_data['sync']['server_time'] );
		$etag    = '"' . hash( 'sha256', get_current_user_id() . '|' . wp_json_encode( $cache_data ) ) . '"';
		$headers = array(
			'Cache-Control' => 'private, no-cache, must-revalidate, max-age=0',
			'Vary'          => 'Cookie, X-WP-Nonce',
			'ETag'          => $etag,
		);
		if ( trim( (string) $request->get_header( 'If-None-Match' ) ) === $etag ) {
			return new \WP_REST_Response( null, 304, $headers );}
		return new \WP_REST_Response( $data, 200, $headers );
	}
}
