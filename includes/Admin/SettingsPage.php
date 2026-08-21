<?php
/**
 * Settings admin page.
 *
 * @package ACL_Agent_Rooms
 */

namespace ACL\AgentRooms\Admin;

use ACL\AgentRooms\Capabilities;
use ACL\AgentRooms\Services\SwitchboardAdminService;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SettingsPage {
	private SwitchboardAdminService $switchboard;
	private $test_result = null;

	public function __construct( ?SwitchboardAdminService $switchboard = null ) {
		$this->switchboard = $switchboard ?: new SwitchboardAdminService();
	}

	public function render(): void {
		if ( ! Capabilities::current_user_can( Capabilities::MANAGE_SETTINGS ) ) {
			wp_die( esc_html__( 'You cannot manage agent rooms settings.', 'acl-agent-rooms' ) );
		}

		echo '<div class="wrap acl-ar-admin">';
		echo '<h1>' . esc_html__( 'ACL Agent Rooms - Settings', 'acl-agent-rooms' ) . '</h1>';
		$this->notice();
		$this->render_test_result();
		echo '<div class="acl-ar-admin-grid">';
		$this->render_status();
		$this->render_form();
		echo '</div>';
		$this->render_pro_information();
		echo '</div>';
	}

	public function process_request(): void {
		if ( empty( $_POST['acl_ar_settings_action'] ) ) {
			return;
		}
		if ( wp_doing_ajax() || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) ) {
			return;
		}
		if ( ! Capabilities::current_user_can( Capabilities::MANAGE_SETTINGS ) ) {
			wp_die( esc_html__( 'You cannot manage agent rooms settings.', 'acl-agent-rooms' ) );
		}

		check_admin_referer( 'acl_ar_save_settings', 'acl_ar_settings_nonce' );

		$action   = sanitize_key( wp_unslash( $_POST['acl_ar_settings_action'] ) );
		$settings = $this->switchboard->sanitize_settings_from_request( $_POST );

		if ( 'test' === $action ) {
			$this->test_result = $this->switchboard->test(
				array(
					'provider_route' => $settings['default_provider_route'],
					'model'          => $settings['default_model'],
					'temperature'    => $settings['default_temperature'],
					'max_tokens'     => min( 64, (int) $settings['default_max_tokens'] ),
				)
			);
			return;
		}

		update_option( 'acl_ar_settings', $settings, false );
		wp_safe_redirect( add_query_arg( 'acl_ar_notice', 'settings_saved', admin_url( 'admin.php?page=acl-agent-rooms-settings' ) ) );
		exit;
	}

	private function notice(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- This read-only post-redirect notice changes no state.
		$notice = isset( $_GET['acl_ar_notice'] ) ? sanitize_key( wp_unslash( $_GET['acl_ar_notice'] ) ) : '';
		if ( 'settings_saved' === $notice ) {
			echo '<div class="notice notice-success"><p>' . esc_html__( 'Settings saved.', 'acl-agent-rooms' ) . '</p></div>';
		}
	}

	private function render_test_result(): void {
		if ( null === $this->test_result ) {
			return;
		}

		if ( is_wp_error( $this->test_result ) ) {
			echo '<div class="notice notice-error acl-ar-test-result"><p><strong>' . esc_html__( 'Switchboard test failed.', 'acl-agent-rooms' ) . '</strong></p><p>' . esc_html( $this->test_result->get_error_message() ) . '</p></div>';
			return;
		}

		$content = trim( (string) ( $this->test_result['content'] ?? '' ) );
		$usage   = is_array( $this->test_result['usage'] ?? null ) ? $this->test_result['usage'] : array();
		echo '<div class="notice notice-success acl-ar-test-result"><p><strong>' . esc_html__( 'Switchboard test succeeded.', 'acl-agent-rooms' ) . '</strong></p>';
		echo '<p class="acl-ar-test-output">' . esc_html( '' !== $content ? $content : __( 'The provider returned an empty content field.', 'acl-agent-rooms' ) ) . '</p>';
		echo '<dl><dt>' . esc_html__( 'Provider', 'acl-agent-rooms' ) . '</dt><dd>' . esc_html( (string) ( $this->test_result['raw_provider'] ?? '' ) ) . '</dd>';
		echo '<dt>' . esc_html__( 'Total tokens', 'acl-agent-rooms' ) . '</dt><dd>' . esc_html( (string) ( $usage['total_tokens'] ?? 0 ) ) . '</dd></dl></div>';
	}

	private function render_status(): void {
		$status = $this->switchboard->status();

		echo '<section class="acl-ar-panel">';
		echo '<h2>' . esc_html__( 'Switchboard Status', 'acl-agent-rooms' ) . '</h2>';
		if ( empty( $status['available'] ) || empty( $status['chat_callable'] ) ) {
			echo '<div class="notice notice-warning inline acl-ar-action-notice"><p><strong>' . esc_html__( 'Switchboard is unavailable.', 'acl-agent-rooms' ) . '</strong> ';
			echo esc_html__( 'Activate and configure ACL Switchboard, then return here to test the default provider route and model. Agent Rooms stores only route/model identifiers, not provider API keys.', 'acl-agent-rooms' ) . '</p></div>';
		}
		echo '<table class="widefat striped"><tbody>';
		$this->status_row( __( 'Active Plugin Detected', 'acl-agent-rooms' ), ! empty( $status['plugin_detected'] ) );
		$this->status_row( __( 'acl_switchboard_is_available()', 'acl-agent-rooms' ), ! empty( $status['available'] ), (string) $status['available_error'] );
		$this->status_row( __( 'acl_switchboard_chat()', 'acl-agent-rooms' ), ! empty( $status['chat_callable'] ) );
		$this->status_row( __( 'Provider Discovery', 'acl-agent-rooms' ), ! empty( $status['provider_discovery'] ), sprintf( '%d', count( $status['providers'] ) ) );
		$this->status_row( __( 'Model Discovery', 'acl-agent-rooms' ), ! empty( $status['model_discovery'] ), sprintf( '%d', count( $status['models'] ) ) );
		echo '</tbody></table>';

		if ( ! empty( $status['warning'] ) ) {
			echo '<p class="notice notice-warning inline"><span>' . esc_html( $status['warning'] ) . '</span></p>';
		}

		$this->render_provider_summary( $status['providers'] );
		$this->render_model_summary( $status['models'] );
		echo '<p class="description">' . esc_html__( 'Provider credentials stay in ACL Switchboard. Agent Rooms stores only provider route and model identifiers.', 'acl-agent-rooms' ) . '</p>';
		echo '</section>';
	}

	private function render_form(): void {
		$settings = $this->switchboard->settings();
		$status   = $this->switchboard->status();

		echo '<section class="acl-ar-panel">';
		echo '<h2>' . esc_html__( 'Defaults and Limits', 'acl-agent-rooms' ) . '</h2>';
		echo '<form method="post">';
		wp_nonce_field( 'acl_ar_save_settings', 'acl_ar_settings_nonce' );
		echo '<table class="form-table" role="presentation"><tbody>';
		$this->provider_row( 'default_provider_route', __( 'Default Provider Route', 'acl-agent-rooms' ), (string) $settings['default_provider_route'], $status['providers'] );
		$this->model_row( 'default_model', __( 'Default Model', 'acl-agent-rooms' ), (string) $settings['default_model'], $status['models'], (string) $settings['default_provider_route'] );
		$this->number_row( 'default_temperature', __( 'Default Temperature', 'acl-agent-rooms' ), (float) $settings['default_temperature'], 0, '0.01', 2 );
		$this->number_row( 'default_max_tokens', __( 'Default Max Tokens', 'acl-agent-rooms' ), (int) $settings['default_max_tokens'], 1 );
		$this->number_row( 'rate_limit_count', __( 'Rate Limit Count', 'acl-agent-rooms' ), (int) $settings['rate_limit_count'], 1 );
		$this->number_row( 'rate_limit_window', __( 'Rate Limit Window Seconds', 'acl-agent-rooms' ), (int) $settings['rate_limit_window'], 60 );
		$this->number_row( 'data_retention_days', __( 'Data Retention Days', 'acl-agent-rooms' ), (int) $settings['data_retention_days'], 0 );
		echo '<tr><th scope="row">' . esc_html__( 'Uninstall Data Deletion', 'acl-agent-rooms' ) . '</th><td><label><input type="checkbox" name="delete_data_on_uninstall" value="1" ' . checked( ! empty( $settings['delete_data_on_uninstall'] ), true, false ) . '> ' . esc_html__( 'Delete ACL Agent Rooms data when the plugin is uninstalled', 'acl-agent-rooms' ) . '</label><p class="description">' . esc_html__( 'Leave unchecked to preserve rooms, agents, messages, jobs, usage logs, and settings.', 'acl-agent-rooms' ) . '</p></td></tr>';
		echo '</tbody></table>';
		echo '<p class="submit">';
		echo '<button type="submit" name="acl_ar_settings_action" value="save" class="button button-primary">' . esc_html__( 'Save Settings', 'acl-agent-rooms' ) . '</button> ';
		echo '<button type="submit" name="acl_ar_settings_action" value="test" class="button">' . esc_html__( 'Test Switchboard', 'acl-agent-rooms' ) . '</button>';
		echo '</p>';
		echo '</form></section>';
	}

	private function render_pro_information(): void {
		/**
		 * Filters the optional official ACL Agent Rooms Pro information URL.
		 *
		 * The default is deliberately empty until an official URL is configured.
		 *
		 * @param string $url Pro information URL.
		 */
		$url = esc_url( (string) apply_filters( 'acl_agent_rooms_pro_information_url', '' ) );

		echo '<section class="acl-ar-panel acl-ar-pro-information">';
		echo '<h2>' . esc_html__( 'ACL Agent Rooms Pro', 'acl-agent-rooms' ) . '</h2>';
		echo '<p>' . esc_html__( 'The optional Pro add-on provides advanced operational reporting for usage, costs, execution reliability, moderation history, and maintenance history. The complete room and agent experience remains available in ACL Agent Rooms.', 'acl-agent-rooms' ) . '</p>';
		if ( '' !== $url ) {
			echo '<p><a class="button" href="' . esc_url( $url ) . '" target="_blank" rel="noopener noreferrer">' . esc_html__( 'Learn about Pro', 'acl-agent-rooms' ) . '</a></p>';
		}
		echo '</section>';
	}

	private function status_row( string $label, bool $ok, string $detail = '' ): void {
		$value = $ok ? __( 'Yes', 'acl-agent-rooms' ) : __( 'No', 'acl-agent-rooms' );
		if ( '' !== $detail ) {
			$value .= ' - ' . $detail;
		}

		echo '<tr><th>' . esc_html( $label ) . '</th><td>' . esc_html( $value ) . '</td></tr>';
	}

	private function render_provider_summary( array $providers ): void {
		if ( empty( $providers ) ) {
			return;
		}

		echo '<h3>' . esc_html__( 'Discovered Provider Routes', 'acl-agent-rooms' ) . '</h3>';
		echo '<ul class="acl-ar-discovery-list">';
		foreach ( $providers as $provider ) {
			$meta = array();
			if ( false === $provider['configured'] ) {
				$meta[] = __( 'not configured', 'acl-agent-rooms' );
			}
			if ( empty( $provider['enabled'] ) ) {
				$meta[] = __( 'disabled', 'acl-agent-rooms' );
			}
			echo '<li><code>' . esc_html( (string) $provider['slug'] ) . '</code> ' . esc_html( (string) $provider['label'] );
			if ( ! empty( $meta ) ) {
				echo ' <span class="description">(' . esc_html( implode( ', ', $meta ) ) . ')</span>';
			}
			echo '</li>';
		}
		echo '</ul>';
	}

	private function render_model_summary( array $models ): void {
		if ( empty( $models ) ) {
			return;
		}

		$preview = array_slice( $models, 0, 12 );
		echo '<h3>' . esc_html__( 'Discovered Models', 'acl-agent-rooms' ) . '</h3>';
		echo '<ul class="acl-ar-discovery-list">';
		foreach ( $preview as $model ) {
			echo '<li><code>' . esc_html( (string) $model['model'] ) . '</code> ' . esc_html( (string) $model['label'] );
			if ( '' !== (string) $model['provider'] ) {
				echo ' <span class="description">(' . esc_html( (string) $model['provider'] ) . ')</span>';
			}
			echo '</li>';
		}
		if ( count( $models ) > count( $preview ) ) {
			/* translators: %d: Number of additional discovered models. */
			echo '<li>' . esc_html( sprintf( __( '%d more models available in the dropdown.', 'acl-agent-rooms' ), count( $models ) - count( $preview ) ) ) . '</li>';
		}
		echo '</ul>';
	}

	private function provider_row( string $name, string $label, string $value, array $providers ): void {
		if ( empty( $providers ) ) {
			$this->text_row( $name, $label, $value );
			return;
		}

		$found = 'default' === $value || '' === $value;
		echo '<tr><th scope="row"><label for="' . esc_attr( $name ) . '">' . esc_html( $label ) . '</label></th><td><select id="' . esc_attr( $name ) . '" name="' . esc_attr( $name ) . '">';
		echo '<option value="default" ' . selected( $value, 'default', false ) . '>' . esc_html__( 'Default route from Switchboard', 'acl-agent-rooms' ) . '</option>';
		foreach ( $providers as $provider ) {
			$slug  = (string) $provider['slug'];
			$found = $found || $slug === $value;
			echo '<option value="' . esc_attr( $slug ) . '" ' . selected( $value, $slug, false ) . '>' . esc_html( (string) $provider['label'] . ' (' . $slug . ')' ) . '</option>';
		}
		if ( ! $found ) {
			/* translators: %s: Custom provider route. */
			echo '<option value="' . esc_attr( $value ) . '" selected>' . esc_html( sprintf( __( 'Custom: %s', 'acl-agent-rooms' ), $value ) ) . '</option>';
		}
		echo '</select></td></tr>';
	}

	private function model_row( string $name, string $label, string $value, array $models, string $provider_route ): void {
		if ( empty( $models ) ) {
			$this->text_row( $name, $label, $value );
			return;
		}

		$provider_ownership = $this->model_provider_ownership( $models );
		$selected_index     = $this->selected_model_index( $models, $value, $provider_route );
		$custom_selected    = ! in_array( $value, array( '', 'default' ), true ) && null === $selected_index;
		$groups             = array();
		foreach ( $models as $index => $model ) {
			$group                      = (string) ( $model['provider_label'] ?: $model['provider'] ?: __( 'Models', 'acl-agent-rooms' ) );
			$groups[ $group ][ $index ] = $model;
		}

		echo '<tr class="acl-ar-model-row"><th scope="row"><label for="' . esc_attr( $name ) . '">' . esc_html( $label ) . '</label></th><td>';
		echo '<select id="' . esc_attr( $name ) . '" name="' . esc_attr( $name ) . '" class="regular-text acl-ar-model-select" data-provider-field="' . esc_attr( 'default_provider_route' ) . '" data-custom-field="' . esc_attr( $name . '_custom' ) . '" data-provider-filter="' . esc_attr( 'none' === $provider_ownership ? '0' : '1' ) . '" data-initial-model="' . esc_attr( $value ) . '">';
		echo '<option value="default" ' . selected( $value, 'default', false ) . '>' . esc_html__( 'Default model from Switchboard', 'acl-agent-rooms' ) . '</option>';
		foreach ( $groups as $group => $group_models ) {
			echo '<optgroup label="' . esc_attr( $group ) . '">';
			foreach ( $group_models as $index => $model ) {
				$model_id = (string) $model['model'];
				$provider = (string) ( $model['provider'] ?? '' );
				echo '<option value="' . esc_attr( $model_id ) . '" data-provider="' . esc_attr( $provider ) . '" ' . selected( $selected_index, $index, false ) . '>' . esc_html( (string) $model['label'] . ' (' . $model_id . ')' ) . '</option>';
			}
			echo '</optgroup>';
		}
		echo '<option value="' . esc_attr( SwitchboardAdminService::CUSTOM_MODEL_VALUE ) . '" data-acl-ar-custom-model="1" ' . selected( $custom_selected, true, false ) . '>' . esc_html__( 'Custom model...', 'acl-agent-rooms' ) . '</option>';
		echo '</select>';
		echo '<input id="' . esc_attr( $name . '_custom' ) . '" name="' . esc_attr( $name . '_custom' ) . '" type="text" class="regular-text acl-ar-model-custom" value="' . esc_attr( $custom_selected ? $value : '' ) . '" placeholder="' . esc_attr__( 'Enter a model ID', 'acl-agent-rooms' ) . '">';
		echo '<p class="description acl-ar-model-no-matches" hidden>' . esc_html__( 'No discovered models are owned by this provider. Choose Custom model or use the Switchboard default.', 'acl-agent-rooms' ) . '</p>';
		$this->model_provider_warning( $provider_ownership );
		echo '</td></tr>';
	}

	private function model_provider_ownership( array $models ): string {
		$with_provider = 0;
		foreach ( $models as $model ) {
			if ( '' !== (string) ( $model['provider'] ?? '' ) ) {
				++$with_provider;
			}
		}

		if ( 0 === $with_provider ) {
			return 'none';
		}

		return $with_provider === count( $models ) ? 'complete' : 'partial';
	}

	private function selected_model_index( array $models, string $value, string $provider_route ): ?int {
		if ( in_array( $value, array( '', 'default' ), true ) ) {
			return null;
		}

		$fallback = null;
		foreach ( $models as $index => $model ) {
			if ( (string) ( $model['model'] ?? '' ) !== $value ) {
				continue;
			}

			if ( null === $fallback ) {
				$fallback = (int) $index;
			}

			if ( ! in_array( $provider_route, array( '', 'default' ), true ) && (string) ( $model['provider'] ?? '' ) === $provider_route ) {
				return (int) $index;
			}
		}

		return $fallback;
	}

	private function model_provider_warning( string $provider_ownership ): void {
		if ( 'none' === $provider_ownership ) {
			echo '<p class="description acl-ar-model-warning">' . esc_html__( 'Switchboard model discovery did not include provider ownership, so the full model list remains available for every provider.', 'acl-agent-rooms' ) . '</p>';
			return;
		}

		if ( 'partial' === $provider_ownership ) {
			echo '<p class="description acl-ar-model-warning">' . esc_html__( 'Some discovered models do not include provider ownership; those models remain available for every provider.', 'acl-agent-rooms' ) . '</p>';
		}
	}

	private function text_row( string $name, string $label, string $value ): void {
		echo '<tr><th scope="row"><label for="' . esc_attr( $name ) . '">' . esc_html( $label ) . '</label></th><td><input id="' . esc_attr( $name ) . '" name="' . esc_attr( $name ) . '" type="text" class="regular-text" value="' . esc_attr( $value ) . '"></td></tr>';
	}

	private function number_row( string $name, string $label, $value, $min, string $step = '1', ?float $max = null ): void {
		echo '<tr><th scope="row"><label for="' . esc_attr( $name ) . '">' . esc_html( $label ) . '</label></th><td><input id="' . esc_attr( $name ) . '" name="' . esc_attr( $name ) . '" type="number" step="' . esc_attr( $step ) . '" min="' . esc_attr( (string) $min ) . '"';
		if ( null !== $max ) {
			echo ' max="' . esc_attr( (string) $max ) . '"';
		}
		echo ' value="' . esc_attr( (string) $value ) . '"></td></tr>';
	}
}
