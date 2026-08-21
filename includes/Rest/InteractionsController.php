<?php
/** Phase 6 edit, reaction, and read-state REST API. @package ACL_Agent_Rooms */
namespace ACL\AgentRooms\Rest;

use ACL\AgentRooms\Repositories\EventRepository;
use ACL\AgentRooms\Repositories\RoomRepository;
use ACL\AgentRooms\Services\AccessService;
use ACL\AgentRooms\Services\EventProjectionService;
use ACL\AgentRooms\Services\MessageEditService;
use ACL\AgentRooms\Services\MessageInteractionPolicy;
use ACL\AgentRooms\Services\ReactionService;
use ACL\AgentRooms\Services\ReadStateService;
if ( ! defined( 'ABSPATH' ) ) {
	exit;}
class InteractionsController extends AbstractController {
	private RoomRepository $rooms;
	private AccessService $access;
	private EventRepository $events;
	private MessageEditService $edits;
	private ReactionService $reactions;
	private ReadStateService $reads;
	private EventProjectionService $projection;
	public function __construct( ?RoomRepository $rooms = null, ?AccessService $access = null ) {
		$this->rooms      = $rooms ?: new RoomRepository();
		$this->access     = $access ?: new AccessService( $this->rooms );
		$this->events     = new EventRepository();
		$policy           = new MessageInteractionPolicy( $this->rooms, $this->access, $this->events );
		$this->edits      = new MessageEditService( $policy, $this->events );
		$this->reactions  = new ReactionService( $policy, $this->events );
		$this->reads      = new ReadStateService( $this->events, null, $this->rooms, $this->access );
		$this->projection = new EventProjectionService( null, $this->events );}
	public function register_routes(): void {
		$id     = array(
			'required'          => true,
			'sanitize_callback' => 'absint',
			'validate_callback' => static fn( $v )=>absint( $v ) > 0,
		);
		$client = array(
			'required'          => true,
			'sanitize_callback' => 'sanitize_text_field',
		);
		register_rest_route(
			self::NAMESPACE,
			'/rooms/(?P<id>[\d]+)/events/(?P<event_id>[\d]+)',
			array(
				'methods'             => 'PATCH',
				'callback'            => array( $this, 'edit' ),
				'permission_callback' => array( $this, 'write_permissions' ),
				'args'                => array(
					'id'                => $id,
					'event_id'          => $id,
					'content'           => array(
						'required' => true,
						'type'     => 'string',
					),
					'client_request_id' => $client,
				),
			)
		);
		$reaction_args = array(
			'id'                => $id,
			'event_id'          => $id,
			'reaction'          => array(
				'required'          => true,
				'type'              => 'string',
				'sanitize_callback' => static fn( $v )=>rawurldecode( (string) $v ),
				'validate_callback' => static fn( $v )=>in_array( rawurldecode( (string) $v ), MessageInteractionPolicy::reactions(), true ),
			),
			'client_request_id' => $client,
		);
		register_rest_route(
			self::NAMESPACE,
			'/rooms/(?P<id>[\d]+)/events/(?P<event_id>[\d]+)/reactions/(?P<reaction>[^/]+)',
			array(
				array(
					'methods'             => 'PUT',
					'callback'            => array( $this, 'add_reaction' ),
					'permission_callback' => array( $this, 'write_permissions' ),
					'args'                => $reaction_args,
				),
				array(
					'methods'             => 'DELETE',
					'callback'            => array( $this, 'remove_reaction' ),
					'permission_callback' => array( $this, 'write_permissions' ),
					'args'                => $reaction_args,
				),
			)
		);
		register_rest_route(
			self::NAMESPACE,
			'/rooms/(?P<id>[\d]+)/read-state',
			array(
				array(
					'methods'             => 'GET',
					'callback'            => array( $this, 'read_state' ),
					'permission_callback' => array( $this, 'read_permissions' ),
					'args'                => array( 'id' => $id ),
				),
				array(
					'methods'             => 'PATCH',
					'callback'            => array( $this, 'advance_read' ),
					'permission_callback' => array( $this, 'write_permissions' ),
					'args'                => array(
						'id'                 => $id,
						'last_read_event_id' => array(
							'required'          => true,
							'sanitize_callback' => 'absint',
							'validate_callback' => static fn( $v )=>is_numeric( $v ) && (int) $v >= 0,
						),
					),
				),
			)
		);}
	public function read_permissions( \WP_REST_Request $r ) {
		$base = $this->require_room_user();
		if ( is_wp_error( $base ) ) {
			return $base;
		}$id = absint( $r['id'] );
		return $this->rooms->find( $id ) && $this->access->can_access_room( $id ) ? true : new \WP_Error( 'acl_ar_room_forbidden', __( 'You cannot access this room.', 'acl-agent-rooms' ), array( 'status' => 403 ) );}
	public function write_permissions( \WP_REST_Request $r ) {
		$nonce = $this->verify_nonce( $r );
		return is_wp_error( $nonce ) ? $nonce : $this->read_permissions( $r );}
	public function edit( \WP_REST_Request $r ) {
		$result = $this->edits->edit( absint( $r['id'] ), absint( $r['event_id'] ), get_current_user_id(), (string) $r['content'], (string) $r['client_request_id'] );
		if ( is_wp_error( $result ) ) {
			return $result;
		}$message = $this->projection->project_page( array( $result['original'] ), $this->access->can_manage_room( absint( $r['id'] ) ), get_current_user_id() )[0];
		$mutation = $this->projection->project_page( array( $result['event'] ), false, get_current_user_id() )[0];
		return new \WP_REST_Response(
			array(
				'message'   => $message,
				'mutation'  => $mutation,
				'duplicate' => $result['duplicate'],
			),
			200
		);}
	public function add_reaction( \WP_REST_Request $r ) {
		return $this->reaction( $r, 'add' );
	}public function remove_reaction( \WP_REST_Request $r ) {
		return $this->reaction( $r, 'remove' );}
	private function reaction( \WP_REST_Request $r, string $op ) {
		$result = $this->reactions->mutate( absint( $r['id'] ), absint( $r['event_id'] ), get_current_user_id(), rawurldecode( (string) $r['reaction'] ), $op, (string) $r['client_request_id'] );
		if ( is_wp_error( $result ) ) {
			return $result;
		}return new \WP_REST_Response(
			array(
				'event_id'  => absint( $r['event_id'] ),
				'reactions' => $result['reactions'],
				'mutation'  => $this->projection->project_page( array( $result['event'] ), false, get_current_user_id() )[0],
				'duplicate' => $result['duplicate'],
			),
			200
		);}
	public function read_state( \WP_REST_Request $r ) {
		return new \WP_REST_Response( $this->reads->state( absint( $r['id'] ), get_current_user_id() ), 200 );
	}public function advance_read( \WP_REST_Request $r ) {
		$state = $this->reads->advance( absint( $r['id'] ), get_current_user_id(), absint( $r['last_read_event_id'] ) );
		return is_wp_error( $state ) ? $state : new \WP_REST_Response( $state, 200 );}
}
