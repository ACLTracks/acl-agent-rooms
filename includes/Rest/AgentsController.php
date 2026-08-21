<?php
/**
 * Agent REST controller.
 *
 * @package ACL_Agent_Rooms
 */

namespace ACL\AgentRooms\Rest;

use ACL\AgentRooms\Capabilities;
use ACL\AgentRooms\Repositories\AgentRepository;
use ACL\AgentRooms\Repositories\BrainRepository;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class AgentsController extends AbstractController {
	private AgentRepository $agents;

	public function __construct( AgentRepository $agents ) {
		$this->agents = $agents;
	}

	public function register_routes(): void {
		register_rest_route(
			self::NAMESPACE,
			'/agents',
			array(
				array(
					'methods'             => 'GET',
					'callback'            => array( $this, 'index' ),
					'permission_callback' => array( $this, 'index_permissions' ),
				),
				array(
					'methods'             => 'POST',
					'callback'            => array( $this, 'create' ),
					'permission_callback' => array( $this, 'manage_permissions' ),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/agents/(?P<id>[\d]+)',
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
	}

	public function index_permissions( \WP_REST_Request $request = null ) {
		return $this->require_room_user();
	}

	public function show_permissions( \WP_REST_Request $request ) {
		$allowed = $this->require_room_user();
		if ( is_wp_error( $allowed ) ) {
			return $allowed;
		}

		$agent = $this->agents->find( absint( $request['id'] ) );
		if ( ! $agent ) {
			return new \WP_Error( 'acl_ar_agent_not_found', __( 'Agent not found.', 'acl-agent-rooms' ), array( 'status' => 404 ) );
		}

		if ( Capabilities::current_user_can( Capabilities::MANAGE_AGENTS ) ) {
			return true;
		}

		return $agent && ! empty( $agent['enabled'] ) && 'public' === (string) $agent['visibility']
			? true
			: new \WP_Error( 'acl_ar_agent_forbidden', __( 'You cannot access this agent.', 'acl-agent-rooms' ), array( 'status' => 403 ) );
	}

	public function manage_permissions( \WP_REST_Request $request ) {
		return $this->mutating_capability( $request, 'acl_ar_manage_agents' );
	}

	public function index( \WP_REST_Request $request = null ): \WP_REST_Response {
		$can_manage = Capabilities::current_user_can( Capabilities::MANAGE_AGENTS );
		$args       = $can_manage ? array() : array(
			'enabled'    => true,
			'visibility' => 'public',
		);
		$agents     = $this->agents->all( $args );

		return new \WP_REST_Response(
			array(
				'agents' => array_map(
					function ( array $agent ) use ( $can_manage ): array {
						return $this->prepare_agent( $agent, $can_manage );
					},
					$agents
				),
			),
			200
		);
	}

	public function show( \WP_REST_Request $request ): \WP_REST_Response {
		$agent = $this->agents->find( absint( $request['id'] ) );
		return new \WP_REST_Response( array( 'agent' => $this->prepare_agent( $agent ?: array(), Capabilities::current_user_can( Capabilities::MANAGE_AGENTS ) ) ), 200 );
	}

	public function create( \WP_REST_Request $request ) {
		$data  = $this->agent_data_from_request( $request );
		$valid = $this->validate_execution( $data );
		if ( is_wp_error( $valid ) ) {
			return $valid; }
		if ( '' === $data['name'] || '' === $data['slug'] ) {
			return new \WP_Error( 'acl_ar_agent_invalid', __( 'Agent name is required.', 'acl-agent-rooms' ), array( 'status' => 400 ) );
		}

		$id = $this->agents->create( $data );
		if ( is_wp_error( $id ) ) {
			return $id;
		}

		return new \WP_REST_Response( array( 'agent' => $this->prepare_agent( $this->agents->find( (int) $id ), true ) ), 201 );
	}

	public function update( \WP_REST_Request $request ) {
		$agent = $this->agents->find( absint( $request['id'] ) );
		if ( ! $agent ) {
			return new \WP_Error( 'acl_ar_agent_not_found', __( 'Agent not found.', 'acl-agent-rooms' ), array( 'status' => 404 ) );
		}

		$data  = $this->agent_data_from_request( $request, $agent );
		$valid = $this->validate_execution( $data );
		if ( is_wp_error( $valid ) ) {
			return $valid; }
		$updated = $this->agents->update( (int) $agent['id'], $data );
		if ( is_wp_error( $updated ) ) {
			return $updated;
		}

		return new \WP_REST_Response( array( 'agent' => $this->prepare_agent( $this->agents->find( (int) $agent['id'] ), true ) ), 200 );
	}

	public function delete( \WP_REST_Request $request ): \WP_REST_Response {
		$agent_id = absint( $request['id'] );
		if ( ! $this->agents->find( $agent_id ) ) {
			return new \WP_REST_Response( array( 'message' => __( 'Agent not found.', 'acl-agent-rooms' ) ), 404 );
		}

		$this->agents->delete( $agent_id );
		return new \WP_REST_Response( array( 'deleted' => true ), 200 );
	}

	private function agent_data_from_request( \WP_REST_Request $request, ?array $existing = null ): array {
		$settings = wp_parse_args(
			get_option( 'acl_ar_settings', array() ),
			array(
				'default_provider_route' => 'default',
				'default_model'          => 'default',
				'default_temperature'    => 0.7,
				'default_max_tokens'     => 1200,
			)
		);
		$name     = null !== $request->get_param( 'name' ) ? sanitize_text_field( (string) $request->get_param( 'name' ) ) : (string) ( $existing['name'] ?? '' );
		$slug     = null !== $request->get_param( 'slug' ) ? sanitize_title( (string) $request->get_param( 'slug' ) ) : (string) ( $existing['slug'] ?? sanitize_title( $name ) );

		if ( '' === $slug ) {
			$slug = sanitize_title( $name );
		}

		return array(
			'owner_user_id'        => (int) ( $existing['owner_user_id'] ?? get_current_user_id() ),
			'name'                 => $name,
			'slug'                 => $slug,
			'description'          => wp_kses_post( (string) ( $request->get_param( 'description' ) ?? ( $existing['description'] ?? '' ) ) ),
			'avatar_attachment_id' => absint( $request->get_param( 'avatar_attachment_id' ) ?? ( $existing['avatar_attachment_id'] ?? 0 ) ),
			'avatar_url'           => (string) ( $existing['avatar_url'] ?? '' ),
			'config_mode'          => 'shared' === sanitize_key( (string) ( $request->get_param( 'config_mode' ) ?? ( $existing['config_mode'] ?? 'independent' ) ) ) ? 'shared' : 'independent',
			'shared_config_id'     => absint( $request->get_param( 'shared_config_id' ) ?? ( $existing['shared_config_id'] ?? 0 ) ),
			'execution_mode'       => 'brain' === sanitize_key( (string) ( $request->get_param( 'execution_mode' ) ?? ( $existing['execution_mode'] ?? 'independent' ) ) ) ? 'brain' : 'independent',
			'brain_id'             => absint( $request->get_param( 'brain_id' ) ?? ( $existing['brain_id'] ?? 0 ) ),
			'provider_route'       => sanitize_text_field( (string) ( $request->get_param( 'provider_route' ) ?? ( $existing['provider_route'] ?? ( $settings['default_provider_route'] ?? 'default' ) ) ) ),
			'model'                => sanitize_text_field( (string) ( $request->get_param( 'model' ) ?? ( $existing['model'] ?? ( $settings['default_model'] ?? 'default' ) ) ) ),
			'system_prompt'        => wp_kses_post( (string) ( $request->get_param( 'system_prompt' ) ?? ( $existing['system_prompt'] ?? '' ) ) ),
			'temperature'          => (float) ( $request->get_param( 'temperature' ) ?? ( $existing['temperature'] ?? $settings['default_temperature'] ) ),
			'max_tokens'           => absint( $request->get_param( 'max_tokens' ) ?? ( $existing['max_tokens'] ?? $settings['default_max_tokens'] ) ),
			'visibility'           => sanitize_key( (string) ( $request->get_param( 'visibility' ) ?? ( $existing['visibility'] ?? 'private' ) ) ),
			'enabled'              => null !== $request->get_param( 'enabled' ) ? $this->bool_param( $request->get_param( 'enabled' ) ) : (bool) ( $existing['enabled'] ?? true ),
		);
	}

	private function prepare_agent( array $agent, bool $include_private ): array {
		if ( empty( $agent ) ) {
			return array();
		}

		$summary = array(
			'id'                   => (int) $agent['id'],
			'name'                 => (string) $agent['name'],
			'slug'                 => (string) $agent['slug'],
			'description'          => (string) $agent['description'],
			'avatar_attachment_id' => (int) $agent['avatar_attachment_id'],
			'avatar_url'           => (string) $agent['avatar_url'],
			'avatar_alt'           => (string) $agent['avatar_alt'],
			'visibility'           => (string) $agent['visibility'],
			'enabled'              => (bool) $agent['enabled'],
		);

		if ( ! $include_private ) {
			return $summary;
		}

		return array_merge(
			$summary,
			array(
				'owner_user_id'    => $agent['owner_user_id'],
				'config_mode'      => (string) $agent['config_mode'],
				'shared_config_id' => $agent['shared_config_id'],
				'execution_mode'   => (string) $agent['execution_mode'],
				'brain_id'         => $agent['brain_id'],
				'provider_route'   => (string) $agent['provider_route'],
				'model'            => (string) $agent['model'],
				'system_prompt'    => (string) $agent['system_prompt'],
				'temperature'      => (float) $agent['temperature'],
				'max_tokens'       => (int) $agent['max_tokens'],
				'created_at'       => (string) $agent['created_at'],
				'updated_at'       => (string) $agent['updated_at'],
			)
		);
	}
	private function validate_execution( array $data ) {
		if ( 'brain' !== (string) ( $data['execution_mode'] ?? 'independent' ) ) {
			return true;
		} $brain = ! empty( $data['brain_id'] ) ? ( new BrainRepository() )->find( (int) $data['brain_id'] ) : null;
		return $brain && ! empty( $brain['enabled'] ) ? true : new \WP_Error( 'acl_ar_brain_unavailable', __( 'Select an enabled Shared Brain for this agent.', 'acl-agent-rooms' ), array( 'status' => 400 ) ); }
}
