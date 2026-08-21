<?php
/**
 * Room REST controller.
 *
 * @package ACL_Agent_Rooms
 */

namespace ACL\AgentRooms\Rest;

use ACL\AgentRooms\Repositories\AgentRepository;
use ACL\AgentRooms\Repositories\RoomRepository;
use ACL\AgentRooms\Services\AccessService;
use ACL\AgentRooms\Support\Arr;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class RoomsController extends AbstractController {
	private RoomRepository $rooms;
	private AgentRepository $agents;
	private AccessService $access;

	public function __construct( RoomRepository $rooms, AgentRepository $agents, AccessService $access ) {
		$this->rooms  = $rooms;
		$this->agents = $agents;
		$this->access = $access;
	}

	public function register_routes(): void {
		register_rest_route(
			self::NAMESPACE,
			'/rooms',
			array(
				array(
					'methods'             => 'GET',
					'callback'            => array( $this, 'index' ),
					'permission_callback' => array( $this, 'index_permissions' ),
				),
				array(
					'methods'             => 'POST',
					'callback'            => array( $this, 'create' ),
					'permission_callback' => array( $this, 'create_permissions' ),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/rooms/(?P<id>[\d]+)',
			array(
				array(
					'methods'             => 'GET',
					'callback'            => array( $this, 'show' ),
					'permission_callback' => array( $this, 'show_permissions' ),
				),
				array(
					'methods'             => 'PATCH',
					'callback'            => array( $this, 'update' ),
					'permission_callback' => array( $this, 'manage_permissions' ),
				),
				array(
					'methods'             => 'DELETE',
					'callback'            => array( $this, 'delete' ),
					'permission_callback' => array( $this, 'manage_permissions' ),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/rooms/(?P<id>[\d]+)/agents',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'assign_agents' ),
				'permission_callback' => array( $this, 'manage_permissions' ),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/rooms/(?P<id>[\d]+)/agents/(?P<agent_id>[\d]+)',
			array(
				'methods'             => 'DELETE',
				'callback'            => array( $this, 'remove_agent' ),
				'permission_callback' => array( $this, 'manage_permissions' ),
			)
		);
	}

	public function index_permissions( \WP_REST_Request $request = null ) {
		return $this->require_room_user();
	}

	public function create_permissions( \WP_REST_Request $request ) {
		$nonce = $this->verify_nonce( $request );
		if ( is_wp_error( $nonce ) ) {
			return $nonce;
		}

		if ( current_user_can( 'acl_ar_create_rooms' ) || current_user_can( 'acl_ar_manage_all_rooms' ) ) {
			return true;
		}

		return new \WP_Error( 'acl_ar_rest_forbidden', __( 'You cannot create agent rooms.', 'acl-agent-rooms' ), array( 'status' => 403 ) );
	}

	public function show_permissions( \WP_REST_Request $request ) {
		$allowed = $this->require_room_user();
		if ( is_wp_error( $allowed ) ) {
			return $allowed;
		}

		$room_id = absint( $request['id'] );
		if ( ! $this->rooms->find( $room_id ) ) {
			return new \WP_Error( 'acl_ar_room_not_found', __( 'Room not found.', 'acl-agent-rooms' ), array( 'status' => 404 ) );
		}

		return $this->access->can_access_room( $room_id )
			? true
			: new \WP_Error( 'acl_ar_room_forbidden', __( 'You cannot access this room.', 'acl-agent-rooms' ), array( 'status' => 403 ) );
	}

	public function manage_permissions( \WP_REST_Request $request ) {
		$nonce = $this->verify_nonce( $request );
		if ( is_wp_error( $nonce ) ) {
			return $nonce;
		}

		$room_id = absint( $request['id'] );
		if ( ! $this->rooms->find( $room_id ) ) {
			return new \WP_Error( 'acl_ar_room_not_found', __( 'Room not found.', 'acl-agent-rooms' ), array( 'status' => 404 ) );
		}

		return $this->access->can_manage_room( $room_id )
			? true
			: new \WP_Error( 'acl_ar_room_forbidden', __( 'You cannot manage this room.', 'acl-agent-rooms' ), array( 'status' => 403 ) );
	}

	public function index( \WP_REST_Request $request = null ): \WP_REST_Response {
		$rooms = $this->rooms->accessible_for_user( get_current_user_id() );
		return new \WP_REST_Response( array( 'rooms' => array_map( array( $this, 'prepare_room' ), $rooms ) ), 200 );
	}

	public function show( \WP_REST_Request $request ): \WP_REST_Response {
		$room = $this->rooms->find( absint( $request['id'] ) );
		return new \WP_REST_Response( array( 'room' => $this->prepare_room( $room ) ), 200 );
	}

	public function create( \WP_REST_Request $request ) {
		$data = $this->room_data_from_request( $request );
		if ( '' === $data['title'] || '' === $data['slug'] ) {
			return new \WP_Error( 'acl_ar_room_invalid', __( 'Room title is required.', 'acl-agent-rooms' ), array( 'status' => 400 ) );
		}
		$agent_ids = Arr::ids( (array) $request->get_param( 'agent_ids' ) );
		foreach ( $agent_ids as $agent_id ) {
			if ( ! $this->agents->find( $agent_id ) ) {
				return new \WP_Error( 'acl_ar_agent_not_found', __( 'One assigned agent was not found.', 'acl-agent-rooms' ), array( 'status' => 404 ) );
			}
		}

		$id = $this->rooms->create( $data );
		if ( is_wp_error( $id ) ) {
			return $id;
		}

		if ( ! empty( $agent_ids ) ) {
			$assigned = $this->rooms->assign_agents( (int) $id, $agent_ids );
			if ( is_wp_error( $assigned ) ) {
				return $assigned;
			}
		}

		return new \WP_REST_Response( array( 'room' => $this->prepare_room( $this->rooms->find( (int) $id ) ) ), 201 );
	}

	public function update( \WP_REST_Request $request ) {
		$room = $this->rooms->find( absint( $request['id'] ) );
		if ( ! $room ) {
			return new \WP_Error( 'acl_ar_room_not_found', __( 'Room not found.', 'acl-agent-rooms' ), array( 'status' => 404 ) );
		}

		$updated = $this->rooms->update( (int) $room['id'], $this->room_data_from_request( $request, $room ) );
		if ( is_wp_error( $updated ) ) {
			return $updated;
		}

		if ( null !== $request->get_param( 'agent_ids' ) ) {
			$assigned = $this->rooms->assign_agents( (int) $room['id'], Arr::ids( (array) $request->get_param( 'agent_ids' ) ) );
			if ( is_wp_error( $assigned ) ) {
				return $assigned;
			}
		}

		return new \WP_REST_Response( array( 'room' => $this->prepare_room( $this->rooms->find( (int) $room['id'] ) ) ), 200 );
	}

	public function delete( \WP_REST_Request $request ): \WP_REST_Response {
		$deleted = $this->rooms->delete( absint( $request['id'] ) );
		if ( is_wp_error( $deleted ) ) {
			return new \WP_REST_Response(
				array(
					'code'    => $deleted->get_error_code(),
					'message' => $deleted->get_error_message(),
				),
				500
			);
		}
		return new \WP_REST_Response( array( 'deleted' => true ), 200 );
	}

	public function assign_agents( \WP_REST_Request $request ) {
		$room_id   = absint( $request['id'] );
		$agent_ids = Arr::ids( (array) $request->get_param( 'agent_ids' ) );

		if ( empty( $agent_ids ) && $request->get_param( 'agent_id' ) ) {
			$agent_ids = array( absint( $request->get_param( 'agent_id' ) ) );
		}

		foreach ( $agent_ids as $agent_id ) {
			if ( ! $this->agents->find( $agent_id ) ) {
				return new \WP_Error( 'acl_ar_agent_not_found', __( 'One assigned agent was not found.', 'acl-agent-rooms' ), array( 'status' => 404 ) );
			}
		}

		$assigned = $this->rooms->assign_agents( $room_id, $agent_ids );
		if ( is_wp_error( $assigned ) ) {
			return $assigned;
		}
		return new \WP_REST_Response( array( 'room' => $this->prepare_room( $this->rooms->find( $room_id ) ) ), 200 );
	}

	public function remove_agent( \WP_REST_Request $request ): \WP_REST_Response {
		$this->rooms->remove_agent( absint( $request['id'] ), absint( $request['agent_id'] ) );
		return new \WP_REST_Response( array( 'removed' => true ), 200 );
	}

	private function room_data_from_request( \WP_REST_Request $request, ?array $existing = null ): array {
		$title = null !== $request->get_param( 'title' ) ? sanitize_text_field( (string) $request->get_param( 'title' ) ) : (string) ( $existing['title'] ?? '' );
		$slug  = null !== $request->get_param( 'slug' ) ? sanitize_title( (string) $request->get_param( 'slug' ) ) : (string) ( $existing['slug'] ?? sanitize_title( $title ) );

		if ( '' === $slug ) {
			$slug = sanitize_title( $title );
		}

		return array(
			'owner_user_id'        => (int) ( $existing['owner_user_id'] ?? get_current_user_id() ),
			'title'                => $title,
			'slug'                 => $slug,
			'description'          => wp_kses_post( (string) ( $request->get_param( 'description' ) ?? ( $existing['description'] ?? '' ) ) ),
			'top_context'          => wp_kses_post( (string) ( $request->get_param( 'top_context' ) ?? ( $existing['top_context'] ?? '' ) ) ),
			'type'                 => sanitize_key( (string) ( $request->get_param( 'type' ) ?? ( $existing['type'] ?? 'solo' ) ) ),
			'visibility'           => sanitize_key( (string) ( $request->get_param( 'visibility' ) ?? ( $existing['visibility'] ?? 'private' ) ) ),
			'status'               => sanitize_key( (string) ( $request->get_param( 'status' ) ?? ( $existing['status'] ?? 'active' ) ) ),
			'agent_reply_mode'     => sanitize_key( (string) ( $request->get_param( 'agent_reply_mode' ) ?? ( $existing['agent_reply_mode'] ?? 'manual' ) ) ),
			'max_context_messages' => absint( $request->get_param( 'max_context_messages' ) ?? ( $existing['max_context_messages'] ?? 20 ) ),
			'max_agents_per_turn'  => absint( $request->get_param( 'max_agents_per_turn' ) ?? ( $existing['max_agents_per_turn'] ?? 1 ) ),
			'allow_chat_clear'     => null !== $request->get_param( 'allow_chat_clear' ) ? rest_sanitize_boolean( $request->get_param( 'allow_chat_clear' ) ) : ! empty( $existing['allow_chat_clear'] ),
		);
	}

	private function prepare_room( ?array $room ): array {
		if ( ! $room ) {
			return array();
		}

		$room['agents']    = array_map( array( $this, 'prepare_agent_summary' ), $this->rooms->get_agents( (int) $room['id'] ) );
		$room['shortcode'] = '[acl_agent_room id="' . (int) $room['id'] . '"]';
		unset( $room['chat_cleared_at'], $room['chat_cleared_by_user_id'] );

		return $room;
	}

	private function prepare_agent_summary( array $agent ): array {
		return array(
			'id'                   => (int) $agent['id'],
			'name'                 => (string) $agent['name'],
			'slug'                 => (string) $agent['slug'],
			'avatar_attachment_id' => (int) $agent['avatar_attachment_id'],
			'avatar_url'           => (string) $agent['avatar_url'],
			'avatar_alt'           => (string) $agent['avatar_alt'],
		);
	}
}
