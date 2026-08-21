<?php
/** Manager-only Shared Brain configuration and run diagnostics REST API. @package ACL_Agent_Rooms */
namespace ACL\AgentRooms\Rest;

use ACL\AgentRooms\Capabilities;
use ACL\AgentRooms\Repositories\BrainRepository;
use ACL\AgentRooms\Repositories\BrainRunRepository;
use ACL\AgentRooms\Services\BrainConfigService;

if ( ! defined( 'ABSPATH' ) ) {
	exit;}
class BrainsController extends AbstractController {
	private BrainRepository $brains;
	private BrainRunRepository $runs;
	private BrainConfigService $config;
	public function __construct( ?BrainRepository $brains = null, ?BrainRunRepository $runs = null, ?BrainConfigService $config = null ) {
		$this->brains = $brains ?: new BrainRepository();
		$this->runs   = $runs ?: new BrainRunRepository();
		$this->config = $config ?: new BrainConfigService( $this->brains );}
	public function register_routes(): void {
		$item = array(
			'id' => array(
				'required'          => true,
				'sanitize_callback' => 'absint',
				'validate_callback' => static fn( $v )=>absint( $v ) > 0,
			),
		);
		register_rest_route(
			self::NAMESPACE,
			'/brains',
			array(
				array(
					'methods'             => 'GET',
					'callback'            => array( $this, 'index' ),
					'permission_callback' => array( $this, 'read_permissions' ),
				),
				array(
					'methods'             => 'POST',
					'callback'            => array( $this, 'create' ),
					'permission_callback' => array( $this, 'write_permissions' ),
					'args'                => $this->schema( true ),
				),
			)
		);
		register_rest_route(
			self::NAMESPACE,
			'/brains/(?P<id>[\d]+)',
			array(
				array(
					'methods'             => 'GET',
					'callback'            => array( $this, 'show' ),
					'permission_callback' => array( $this, 'read_permissions' ),
					'args'                => $item,
				),
				array(
					'methods'             => 'PATCH',
					'callback'            => array( $this, 'update' ),
					'permission_callback' => array( $this, 'write_permissions' ),
					'args'                => array_merge( $item, $this->schema( false ) ),
				),
				array(
					'methods'             => 'DELETE',
					'callback'            => array( $this, 'delete' ),
					'permission_callback' => array( $this, 'write_permissions' ),
					'args'                => $item,
				),
			)
		);
		register_rest_route(
			self::NAMESPACE,
			'/rooms/(?P<room_id>[\d]+)/brain-runs',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'runs' ),
				'permission_callback' => array( $this, 'read_permissions' ),
				'args'                => array(
					'room_id' => array(
						'required'          => true,
						'sanitize_callback' => 'absint',
					),
					'limit'   => array(
						'required'          => false,
						'sanitize_callback' => 'absint',
					),
				),
			)
		);}
	public function read_permissions() {
		return $this->require_capability( Capabilities::MANAGE_AGENTS );
	}public function write_permissions( \WP_REST_Request $r ) {
		return $this->mutating_capability( $r, Capabilities::MANAGE_AGENTS );}
	public function index(): \WP_REST_Response {
		return new \WP_REST_Response( array( 'brains' => array_map( array( $this, 'prepare' ), $this->brains->all() ) ), 200 );
	}public function show( \WP_REST_Request $r ) {
		$brain = $this->brains->find( absint( $r['id'] ) );
		return $brain ? new \WP_REST_Response( $this->prepare( $brain ), 200 ) : new \WP_Error( 'acl_ar_brain_not_found', __( 'Brain not found.', 'acl-agent-rooms' ), array( 'status' => 404 ) );}
	public function create( \WP_REST_Request $r ) {
		$data  = $this->data( $r );
		$valid = $this->config->validate_pair( $data['provider'], $data['model'] );
		if ( is_wp_error( $valid ) ) {
			return $valid;
		}$id = $this->brains->create( $data );
		return is_wp_error( $id ) ? $id : new \WP_REST_Response( $this->prepare( $this->brains->find( (int) $id ) ), 201 );}
	public function update( \WP_REST_Request $r ) {
		$id  = absint( $r['id'] );
		$old = $this->brains->find( $id );
		if ( ! $old ) {
			return new \WP_Error( 'acl_ar_brain_not_found', __( 'Brain not found.', 'acl-agent-rooms' ), array( 'status' => 404 ) );
		}$data = $this->data( $r, $old );
		$valid = $this->config->validate_pair( $data['provider'], $data['model'] );
		if ( is_wp_error( $valid ) ) {
			return $valid;
		}$result = $this->brains->update( $id, $data );
		if ( is_wp_error( $result ) ) {
			return $result;
		}if ( empty( $data['enabled'] ) ) {
			$this->runs->cancel_for_brain( $id );
		}return new \WP_REST_Response( $this->prepare( $this->brains->find( $id ) ), 200 );}
	public function delete( \WP_REST_Request $r ) {
		$result = $this->brains->delete( absint( $r['id'] ) );
		return is_wp_error( $result ) ? $result : new \WP_REST_Response( array( 'deleted' => (bool) $result ), 200 );}
	public function runs( \WP_REST_Request $r ): \WP_REST_Response {
		$rows = array_map(
			static function ( $run ) {
				return array(
					'id'                 => $run['id'],
					'brain_id'           => $run['brain_id'],
					'status'             => $run['status'],
					'agent_ids'          => $run['target_agent_ids'],
					'provider'           => $run['provider'],
					'model'              => $run['model'],
					'attempts'           => $run['attempts'],
					'prompt_tokens'      => $run['prompt_tokens'],
					'completion_tokens'  => $run['completion_tokens'],
					'total_tokens'       => $run['total_tokens'],
					'response_event_ids' => $run['response_event_ids'],
					'public_error'       => $run['public_error'],
					'created_at'         => $run['created_at'],
					'completed_at'       => $run['completed_at'],
				);
			},
			$this->runs->for_room( absint( $r['room_id'] ), absint( $r->get_param( 'limit' ) ?: 50 ) )
		);
		return new \WP_REST_Response( array( 'brain_runs' => $rows ), 200 );}
	public function prepare( array $b ): array {
		return array(
			'id'                   => $b['id'],
			'name'                 => $b['name'],
			'slug'                 => $b['slug'],
			'description'          => $b['description'],
			'provider'             => $b['provider'],
			'model'                => $b['model'],
			'orchestration_prompt' => $b['orchestration_prompt'],
			'temperature'          => $b['temperature'],
			'max_tokens_per_agent' => $b['max_tokens_per_agent'],
			'max_total_tokens'     => $b['max_total_tokens'],
			'settings'             => $b['settings'],
			'enabled'              => $b['enabled'],
			'referenced_agents'    => $this->brains->referenced_count( (int) $b['id'] ),
			'created_at'           => $b['created_at'],
			'updated_at'           => $b['updated_at'],
		);}
	private function data( \WP_REST_Request $r, array $old = array() ): array {
		$value    = static fn( $key, $default )=>null !== $r->get_param( $key ) ? $r->get_param( $key ) : ( $old[ $key ] ?? $default );
		$settings = $value( 'settings', array() );
		$name     = sanitize_text_field( (string) $value( 'name', '' ) );
		return array(
			'owner_user_id'        => (int) ( $old['owner_user_id'] ?? get_current_user_id() ),
			'name'                 => $name,
			'slug'                 => sanitize_title( (string) $value( 'slug', $name ) ),
			'description'          => sanitize_textarea_field( (string) $value( 'description', '' ) ),
			'provider'             => sanitize_text_field( (string) $value( 'provider', '' ) ),
			'model'                => sanitize_text_field( (string) $value( 'model', '' ) ),
			'orchestration_prompt' => sanitize_textarea_field( (string) $value( 'orchestration_prompt', '' ) ),
			'temperature'          => null === $value( 'temperature', 0.7 ) ? null : (float) $value( 'temperature', 0.7 ),
			'max_tokens_per_agent' => absint( $value( 'max_tokens_per_agent', 600 ) ),
			'max_total_tokens'     => absint( $value( 'max_total_tokens', 6000 ) ),
			'settings'             => is_array( $settings ) ? $settings : array(),
			'enabled'              => rest_sanitize_boolean( $value( 'enabled', true ) ),
		);}
	private function schema( bool $required ): array {
		return array(
			'name'                 => array(
				'required'          => $required,
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_text_field',
			),
			'slug'                 => array(
				'required'          => false,
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_title',
			),
			'description'          => array(
				'required' => false,
				'type'     => 'string',
			),
			'provider'             => array(
				'required'          => $required,
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_text_field',
			),
			'model'                => array(
				'required'          => $required,
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_text_field',
			),
			'orchestration_prompt' => array(
				'required' => false,
				'type'     => 'string',
			),
			'temperature'          => array(
				'required' => false,
				'type'     => array( 'number', 'null' ),
				'minimum'  => 0,
				'maximum'  => 2,
			),
			'max_tokens_per_agent' => array(
				'required' => false,
				'type'     => 'integer',
				'minimum'  => 64,
				'maximum'  => 8000,
			),
			'max_total_tokens'     => array(
				'required' => false,
				'type'     => 'integer',
				'minimum'  => 64,
				'maximum'  => 32000,
			),
			'settings'             => array(
				'required' => false,
				'type'     => 'object',
			),
			'enabled'              => array(
				'required' => false,
				'type'     => 'boolean',
				'default'  => true,
			),
		);}
}
