<?php
/** Phase 7 command REST API. @package ACL_Agent_Rooms */
namespace ACL\AgentRooms\Rest;

use ACL\AgentRooms\Services\CommandService;
use ACL\AgentRooms\Services\MessagePolicy;
if ( ! defined( 'ABSPATH' ) ) {
	exit;}
class CommandsController extends AbstractController {
	private CommandService $commands;
	private ?MessagesController $messages;
	public function __construct( ?CommandService $commands = null, ?MessagesController $messages = null ) {
		$this->commands = $commands ?: new CommandService();
		$this->messages = $messages;}
	public function register_routes(): void {
		register_rest_route(
			self::NAMESPACE,
			'/rooms/(?P<id>[\d]+)/commands',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'execute' ),
				'permission_callback' => array( $this, 'permissions' ),
				'args'                => array(
					'id'                => array(
						'required'          => true,
						'sanitize_callback' => 'absint',
						'validate_callback' => static fn( $v )=>absint( $v ) > 0,
					),
					'input'             => array(
						'required'          => true,
						'type'              => 'string',
						'validate_callback' => static fn( $v )=>is_string( $v ) && strlen( $v ) <= 12000,
					),
					'client_request_id' => array(
						'required' => true,
						'type'     => 'string',
					),
					'recipient_user_id' => array(
						'required'          => false,
						'sanitize_callback' => 'absint',
					),
				),
			)
		);
		register_rest_route(
			self::NAMESPACE,
			'/commands',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'index' ),
				'permission_callback' => static fn()=>is_user_logged_in(),
			)
		);}
	public function permissions( \WP_REST_Request $r ) {
		$nonce = $this->verify_nonce( $r );
		return is_wp_error( $nonce ) ? $nonce : $this->require_room_user();}
	public function index(): \WP_REST_Response {
		return new \WP_REST_Response( array( 'commands' => $this->commands->definitions() ), 200 );}
	public function execute( \WP_REST_Request $r ) {
		$result = $this->commands->execute( absint( $r['id'] ), get_current_user_id(), (string) $r['input'], (string) $r['client_request_id'], absint( $r['recipient_user_id'] ) );
		if ( is_wp_error( $result ) ) {
			return $result;
		}if ( 'ask' === ( $result['delegate'] ?? '' ) ) {
			if ( ! $this->messages ) {
				return new \WP_Error( 'acl_ar_command_failed', __( 'Agent command handler is unavailable.', 'acl-agent-rooms' ), array( 'status' => 500 ) );
			}$legacy = new \WP_REST_Request( 'POST', '/' );
			$legacy->set_param( 'id', absint( $r['id'] ) );
			$legacy->set_param( 'content', (string) $r['input'] );
			$legacy->set_param( 'client_request_id', (string) $r['client_request_id'] );
			$response = $this->messages->create( $legacy );
			if ( is_wp_error( $response ) ) {
				return $response;
			}$data = $response->get_data();
			return new \WP_REST_Response(
				array(
					'command'       => array(
						'name'              => 'ask',
						'client_request_id' => (string) $r['client_request_id'],
						'event_id'          => (int) ( $data['event']['id'] ?? 0 ),
					),
					'result'        => array(
						'status' => 'queued',
						'jobs'   => $data['jobs'] ?? array(),
					),
					'message_id'    => (int) ( $data['message_id'] ?? 0 ),
					'duplicate'     => ! empty( $data['duplicate'] ),
					'event'         => $data['event'] ?? null,
					'orchestration' => $data['orchestration'] ?? array( 'status' => 'queued' ),
				),
				200
			);
		}return new \WP_REST_Response( $result, 200 );}
}
