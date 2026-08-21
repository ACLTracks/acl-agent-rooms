<?php
/**
 * Switchboard admin discovery and test helpers.
 *
 * @package ACL_Agent_Rooms
 */

namespace ACL\AgentRooms\Services;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SwitchboardAdminService {
	public const CUSTOM_MODEL_VALUE = '__acl_ar_custom_model__';

	private ?array $status = null;

	public function status(): array {
		if ( null !== $this->status ) {
			return $this->status;
		}

		$status = array(
			'plugin_detected'       => $this->is_plugin_detected(),
			'is_available_callable' => function_exists( 'acl_switchboard_is_available' ),
			'chat_callable'         => function_exists( 'acl_switchboard_chat' ),
			'available'             => false,
			'available_error'       => '',
			'providers_callable'    => function_exists( 'acl_switchboard_get_providers' ),
			'models_callable'       => function_exists( 'acl_switchboard_get_models' ) || function_exists( 'acl_switchboard_get_approved_models' ),
			'providers'             => array(),
			'models'                => array(),
			'provider_discovery'    => false,
			'model_discovery'       => false,
			'warning'               => '',
		);

		if ( $status['is_available_callable'] ) {
			try {
				$status['available'] = (bool) acl_switchboard_is_available();
			} catch ( \Throwable $throwable ) {
				$status['available_error'] = $throwable->getMessage();
			}
		}

		$status['providers']          = $this->fetch_providers();
		$status['models']             = $this->fetch_models();
		$status['provider_discovery'] = ! empty( $status['providers'] );
		$status['model_discovery']    = ! empty( $status['models'] );

		if ( $status['available'] && ( ! $status['provider_discovery'] || ! $status['model_discovery'] ) ) {
			$status['warning'] = __( 'Switchboard is available, but provider/model discovery is not exposed. Enter route/model manually or update Switchboard integration.', 'acl-agent-rooms' );
		}

		$this->status = $status;
		return $status;
	}

	public function settings(): array {
		return wp_parse_args(
			get_option( 'acl_ar_settings', array() ),
			array(
				'default_provider_route'   => 'default',
				'default_model'            => 'default',
				'default_temperature'      => 0.7,
				'default_max_tokens'       => 1200,
				'rate_limit_count'         => 30,
				'rate_limit_window'        => 600,
				'agent_rate_limit_count'   => 12,
				'agent_rate_limit_window'  => 600,
				'data_retention_days'      => 0,
				'delete_data_on_uninstall' => false,
			)
		);
	}

	public function sanitize_settings_from_request( array $source ): array {
		return array(
			'default_provider_route'   => sanitize_text_field( wp_unslash( $source['default_provider_route'] ?? 'default' ) ),
			'default_model'            => $this->sanitize_model_from_request( $source, 'default_model', 'default' ),
			'default_temperature'      => $this->sanitize_temperature( $source['default_temperature'] ?? 0.7 ),
			'default_max_tokens'       => max( 1, absint( $source['default_max_tokens'] ?? 1200 ) ),
			'rate_limit_count'         => max( 1, absint( $source['rate_limit_count'] ?? 30 ) ),
			'rate_limit_window'        => max( 60, absint( $source['rate_limit_window'] ?? 600 ) ),
			'agent_rate_limit_count'   => max( 1, absint( $source['agent_rate_limit_count'] ?? 12 ) ),
			'agent_rate_limit_window'  => max( 60, absint( $source['agent_rate_limit_window'] ?? 600 ) ),
			'data_retention_days'      => absint( $source['data_retention_days'] ?? 0 ),
			'delete_data_on_uninstall' => ! empty( $source['delete_data_on_uninstall'] ),
		);
	}

	public function sanitize_model_from_request( array $source, string $field, string $default = '' ): string {
		$model = sanitize_text_field( wp_unslash( $source[ $field ] ?? $default ) );
		if ( self::CUSTOM_MODEL_VALUE !== $model ) {
			return $model;
		}

		$custom = sanitize_text_field( wp_unslash( $source[ $field . '_custom' ] ?? '' ) );
		return '' !== $custom ? $custom : $default;
	}

	public function test( array $args = array() ) {
		$settings       = $this->settings();
		$provider_route = sanitize_text_field( (string) ( $args['provider_route'] ?? $settings['default_provider_route'] ) );
		$model          = sanitize_text_field( (string) ( $args['model'] ?? $settings['default_model'] ) );
		$temperature    = $this->sanitize_temperature( $args['temperature'] ?? $settings['default_temperature'] );
		$max_tokens     = max( 1, absint( $args['max_tokens'] ?? $settings['default_max_tokens'] ) );
		$system_prompt  = sanitize_textarea_field( (string) ( $args['system_prompt'] ?? __( 'You are a concise test agent.', 'acl-agent-rooms' ) ) );
		$message        = sanitize_textarea_field( (string) ( $args['message'] ?? __( 'Reply with OK.', 'acl-agent-rooms' ) ) );

		$client = new SwitchboardClient();
		return $client->send(
			array(
				'provider_route' => '' !== $provider_route ? $provider_route : 'default',
				'model'          => '' !== $model ? $model : 'default',
				'system_prompt'  => $system_prompt,
				'messages'       => array(
					array(
						'role'    => 'user',
						'content' => $message,
					),
				),
				'temperature'    => $temperature,
				'max_tokens'     => $max_tokens,
				'metadata'       => array(
					'source' => 'acl-agent-rooms-admin-test',
				),
			)
		);
	}

	public function is_available(): bool {
		$status = $this->status();
		return ! empty( $status['available'] ) && ! empty( $status['chat_callable'] );
	}

	private function is_plugin_detected(): bool {
		if ( function_exists( 'acl_switchboard' ) || function_exists( 'acl_switchboard_chat' ) || class_exists( 'ACL_Switchboard\\Plugin' ) ) {
			return true;
		}

		if ( ! function_exists( 'is_plugin_active' ) && defined( 'ABSPATH' ) ) {
			$plugin_file = ABSPATH . 'wp-admin/includes/plugin.php';
			if ( is_readable( $plugin_file ) ) {
				require_once $plugin_file;
			}
		}

		return function_exists( 'is_plugin_active' ) && is_plugin_active( 'acl-switchboard/acl-switchboard.php' );
	}

	private function fetch_providers(): array {
		if ( ! function_exists( 'acl_switchboard_get_providers' ) ) {
			return array();
		}

		try {
			$providers = acl_switchboard_get_providers( array( 'service' => 'chat' ) );
		} catch ( \Throwable $throwable ) {
			return array();
		}

		return $this->normalize_providers( is_array( $providers ) ? $providers : array() );
	}

	private function fetch_models(): array {
		$models = array();

		if ( function_exists( 'acl_switchboard_get_approved_models' ) ) {
			try {
				$approved = acl_switchboard_get_approved_models( array( 'service' => 'chat' ) );
				$models   = array_merge( $models, $this->normalize_models_payload( $approved ) );
			} catch ( \Throwable $throwable ) {
			}
		}

		if ( function_exists( 'acl_switchboard_get_models' ) ) {
			try {
				$catalog = acl_switchboard_get_models( array( 'service' => 'chat' ) );
				$models  = array_merge( $models, $this->normalize_models_payload( $catalog ) );
			} catch ( \Throwable $throwable ) {
			}
		}

		$unique = array();
		foreach ( $models as $model ) {
			$key = (string) $model['provider'] . '|' . (string) $model['model'];
			if ( '' !== trim( $key, '|' ) ) {
				$unique[ $key ] = $model;
			}
		}

		uasort(
			$unique,
			static function ( array $left, array $right ): int {
				return strcasecmp( (string) $left['label'], (string) $right['label'] );
			}
		);

		return array_values( $unique );
	}

	private function normalize_providers( array $providers ): array {
		$normalized = array();

		foreach ( $providers as $key => $provider ) {
			if ( ! is_array( $provider ) ) {
				continue;
			}

			$slug = sanitize_text_field( (string) ( $provider['slug'] ?? $provider['id'] ?? $key ) );
			if ( '' === $slug ) {
				continue;
			}

			$normalized[] = array(
				'slug'       => $slug,
				'label'      => sanitize_text_field( (string) ( $provider['label'] ?? $provider['name'] ?? $slug ) ),
				'enabled'    => ! array_key_exists( 'enabled', $provider ) || ! empty( $provider['enabled'] ),
				'configured' => array_key_exists( 'configured', $provider ) ? (bool) $provider['configured'] : null,
			);
		}

		usort(
			$normalized,
			static function ( array $left, array $right ): int {
				return strcasecmp( (string) $left['label'], (string) $right['label'] );
			}
		);

		return $normalized;
	}

	private function normalize_models_payload( $payload, string $inherited_provider = '', string $inherited_provider_label = '' ): array {
		if ( ! is_array( $payload ) ) {
			return array();
		}

		if ( isset( $payload['models'] ) && is_array( $payload['models'] ) ) {
			$models = $payload['models'];
			if ( '' === $inherited_provider ) {
				$inherited_provider = sanitize_text_field( (string) ( $payload['provider'] ?? $payload['slug'] ?? $payload['id'] ?? '' ) );
			}
			if ( '' === $inherited_provider_label ) {
				$inherited_provider_label = sanitize_text_field( (string) ( $payload['provider_label'] ?? $payload['label'] ?? $payload['name'] ?? $inherited_provider ) );
			}
		} else {
			$models = $payload;
		}

		$normalized = array();
		foreach ( $models as $provider_key => $model ) {
			if ( is_array( $model ) && isset( $model['models'] ) && is_array( $model['models'] ) ) {
				$provider       = sanitize_text_field( (string) ( $model['provider'] ?? $model['slug'] ?? $model['id'] ?? ( is_string( $provider_key ) ? $provider_key : $inherited_provider ) ) );
				$label_source   = (string) ( $model['provider_label'] ?? $model['label'] ?? $model['name'] ?? '' );
				$provider_label = sanitize_text_field( '' !== $label_source ? $label_source : ( '' !== $provider ? $provider : $inherited_provider_label ) );
				$normalized     = array_merge( $normalized, $this->normalize_models_payload( $model['models'], $provider, $provider_label ) );
				continue;
			}

			if ( is_array( $model ) && ! isset( $model['model'], $model['id'] ) && $this->looks_like_model_list( $model ) ) {
				$provider       = is_string( $provider_key ) ? sanitize_text_field( $provider_key ) : $inherited_provider;
				$provider_label = '' !== $provider ? $provider : $inherited_provider_label;
				$normalized     = array_merge( $normalized, $this->normalize_models_payload( $model, $provider, $provider_label ) );
				continue;
			}

			if ( ! is_array( $model ) ) {
				continue;
			}

			$model_id = sanitize_text_field( (string) ( $model['model'] ?? $model['id'] ?? '' ) );
			if ( '' === $model_id ) {
				continue;
			}

			$provider = sanitize_text_field( (string) ( $model['provider'] ?? $model['provider_route'] ?? $inherited_provider ) );
			if ( '' === $provider && is_string( $provider_key ) && $provider_key !== $model_id ) {
				$provider = sanitize_text_field( $provider_key );
			}
			$label                 = sanitize_text_field( (string) ( $model['label'] ?? $model['name'] ?? $model_id ) );
			$provider_label_source = (string) ( $model['provider_label'] ?? $model['provider_name'] ?? $inherited_provider_label );
			$provider_label        = sanitize_text_field( '' !== $provider_label_source ? $provider_label_source : $provider );

			$normalized[] = array(
				'model'          => $model_id,
				'label'          => $label,
				'provider'       => $provider,
				'provider_label' => $provider_label,
			);
		}

		return $normalized;
	}

	private function looks_like_model_list( array $items ): bool {
		foreach ( $items as $item ) {
			if ( is_array( $item ) ) {
				return true;
			}
		}

		return false;
	}

	private function sanitize_temperature( $value ): float {
		return max( 0, min( 2, (float) wp_unslash( $value ) ) );
	}
}
