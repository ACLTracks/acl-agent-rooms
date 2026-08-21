<?php
/**
 * Settings REST controller.
 *
 * @package ACL_Agent_Rooms
 */

namespace ACL\AgentRooms\Rest;

use ACL\AgentRooms\Services\SwitchboardAdminService;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SettingsController extends AbstractController {
	public function register_routes(): void {
		register_rest_route(
			self::NAMESPACE,
			'/settings',
			array(
				array(
					'methods'             => 'GET',
					'callback'            => array( $this, 'show' ),
					'permission_callback' => array( $this, 'show_permissions' ),
				),
				array(
					'methods'             => 'PATCH',
					'callback'            => array( $this, 'update' ),
					'permission_callback' => array( $this, 'update_permissions' ),
				),
			)
		);
	}

	public function show_permissions( \WP_REST_Request $request = null ) {
		return $this->require_capability( 'acl_ar_manage_settings' );
	}

	public function update_permissions( \WP_REST_Request $request ) {
		return $this->mutating_capability( $request, 'acl_ar_manage_settings' );
	}

	public function show( \WP_REST_Request $request = null ): \WP_REST_Response {
		return new \WP_REST_Response(
			array(
				'settings'    => $this->settings(),
				'switchboard' => $this->switchboard_status(),
			),
			200
		);
	}

	public function update( \WP_REST_Request $request ): \WP_REST_Response {
		$settings = $this->sanitize_settings( $request->get_json_params() ?: $request->get_params() );
		update_option( 'acl_ar_settings', $settings, false );

		return new \WP_REST_Response(
			array(
				'settings'    => $settings,
				'switchboard' => $this->switchboard_status(),
			),
			200
		);
	}

	public function settings(): array {
		return ( new SwitchboardAdminService() )->settings();
	}

	public function sanitize_settings( array $input ): array {
		$current = $this->settings();

		return array(
			'default_provider_route'   => sanitize_text_field( (string) ( $input['default_provider_route'] ?? $current['default_provider_route'] ) ),
			'default_model'            => sanitize_text_field( (string) ( $input['default_model'] ?? $current['default_model'] ) ),
			'default_temperature'      => max( 0, min( 2, (float) ( $input['default_temperature'] ?? $current['default_temperature'] ) ) ),
			'default_max_tokens'       => max( 1, absint( $input['default_max_tokens'] ?? $current['default_max_tokens'] ) ),
			'rate_limit_count'         => max( 1, absint( $input['rate_limit_count'] ?? $current['rate_limit_count'] ) ),
			'rate_limit_window'        => max( 60, absint( $input['rate_limit_window'] ?? $current['rate_limit_window'] ) ),
			'agent_rate_limit_count'   => max( 1, absint( $input['agent_rate_limit_count'] ?? $current['agent_rate_limit_count'] ) ),
			'agent_rate_limit_window'  => max( 60, absint( $input['agent_rate_limit_window'] ?? $current['agent_rate_limit_window'] ) ),
			'data_retention_days'      => absint( $input['data_retention_days'] ?? $current['data_retention_days'] ),
			'delete_data_on_uninstall' => ! empty( $input['delete_data_on_uninstall'] ),
		);
	}

	private function switchboard_status(): array {
		$status = ( new SwitchboardAdminService() )->status();

		return array(
			'plugin_detected'    => (bool) $status['plugin_detected'],
			'available'          => (bool) $status['available'],
			'chat_callable'      => (bool) $status['chat_callable'],
			'provider_discovery' => (bool) $status['provider_discovery'],
			'model_discovery'    => (bool) $status['model_discovery'],
			'providers_count'    => count( $status['providers'] ),
			'models_count'       => count( $status['models'] ),
			'providers'          => $status['providers'],
			'models'             => $status['models'],
			'warning'            => (string) $status['warning'],
		);
	}
}
