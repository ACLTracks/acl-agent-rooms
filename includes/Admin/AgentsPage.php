<?php
/**
 * Agents admin page.
 *
 * @package ACL_Agent_Rooms
 */

namespace ACL\AgentRooms\Admin;

use ACL\AgentRooms\Capabilities;
use ACL\AgentRooms\Repositories\AgentRepository;
use ACL\AgentRooms\Repositories\SharedConfigRepository;
use ACL\AgentRooms\Repositories\BrainRepository;
use ACL\AgentRooms\Services\AgentConfigResolver;
use ACL\AgentRooms\Services\SwitchboardAdminService;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class AgentsPage {
	private AgentRepository $agents;
	private SharedConfigRepository $shared_configs;
	private SwitchboardAdminService $switchboard;
	private AgentConfigResolver $resolver;
	private BrainRepository $brains;
	private $test_result = null;

	public function __construct( ?AgentRepository $agents = null, ?SwitchboardAdminService $switchboard = null, ?SharedConfigRepository $shared_configs = null, ?AgentConfigResolver $resolver = null, ?BrainRepository $brains = null ) {
		$this->agents         = $agents ?: new AgentRepository();
		$this->shared_configs = $shared_configs ?: new SharedConfigRepository();
		$this->switchboard    = $switchboard ?: new SwitchboardAdminService();
		$this->resolver       = $resolver ?: new AgentConfigResolver( $this->shared_configs );
		$this->brains         = $brains ?: new BrainRepository();
	}

	public function render(): void {
		if ( ! Capabilities::current_user_can( Capabilities::MANAGE_AGENTS ) ) {
			wp_die( esc_html__( 'You cannot manage agents.', 'acl-agent-rooms' ) );
		}

		$editing = null;
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- This read-only filter changes no state.
		if ( isset( $_GET['action'], $_GET['id'] ) && 'edit' === sanitize_key( wp_unslash( $_GET['action'] ) ) ) {
			$editing = $this->agents->find( absint( $_GET['id'] ) );
		}
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		$add_url = add_query_arg(
			array(
				'page'   => 'acl-agent-rooms-agents',
				'action' => 'add',
			),
			admin_url( 'admin.php' )
		);

		echo '<div class="wrap acl-ar-admin">';
		echo '<h1 class="wp-heading-inline">' . esc_html__( 'ACL Agent Rooms - Agents', 'acl-agent-rooms' ) . '</h1>';
		echo ' <a href="' . esc_url( $add_url ) . '" class="page-title-action">' . esc_html__( 'Add New Agent', 'acl-agent-rooms' ) . '</a>';
		echo '<hr class="wp-header-end">';
		$this->notice();
		$this->render_test_result();
		echo '<div class="acl-ar-admin-grid">';
		$this->render_list();
		$this->render_form( $editing );
		$this->render_shared_configs();
		echo '</div></div>';
	}

	public function process_request(): void {
		$action      = isset( $_GET['acl_ar_action'] ) ? sanitize_key( wp_unslash( $_GET['acl_ar_action'] ) ) : '';
		$has_request = ! empty( $_POST['acl_ar_shared_config_action'] ) || ! empty( $_POST['acl_ar_agent_action'] ) || ( isset( $_GET['id'] ) && in_array( $action, array( 'delete_agent', 'test_agent', 'delete_shared_config' ), true ) );
		if ( ! $has_request || wp_doing_ajax() || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) ) {
			return;
		}
		if ( ! Capabilities::current_user_can( Capabilities::MANAGE_AGENTS ) ) {
			wp_die( esc_html__( 'You cannot manage agents.', 'acl-agent-rooms' ) );
		}

		if ( isset( $_GET['acl_ar_action'], $_GET['id'] ) && 'delete_agent' === sanitize_key( wp_unslash( $_GET['acl_ar_action'] ) ) ) {
			$id = absint( $_GET['id'] );
			check_admin_referer( 'acl_ar_delete_agent_' . $id );
			$this->agents->delete( $id );
			wp_safe_redirect( add_query_arg( 'acl_ar_notice', 'agent_deleted', admin_url( 'admin.php?page=acl-agent-rooms-agents' ) ) );
			exit;
		}

		if ( isset( $_GET['acl_ar_action'], $_GET['id'] ) && 'test_agent' === sanitize_key( wp_unslash( $_GET['acl_ar_action'] ) ) ) {
			$id = absint( $_GET['id'] );
			check_admin_referer( 'acl_ar_test_agent_' . $id );
			$agent = $this->agents->find( $id );
			if ( ! $agent ) {
				$this->test_result = new \WP_Error( 'acl_ar_agent_not_found', __( 'Agent was not found.', 'acl-agent-rooms' ) );
				return;
			}

			$config = $this->resolver->resolve( $agent );
			if ( 'brain' === (string) ( $agent['execution_mode'] ?? 'independent' ) ) {
				$this->test_result = new \WP_Error( 'acl_ar_brain_test_post_required', __( 'Shared Brain execution must be tested with an explicit Brain POST action.', 'acl-agent-rooms' ) );
				return; }
			$this->test_result = $this->switchboard->test(
				array(
					'provider_route' => (string) $config['provider_route'],
					'model'          => (string) $config['model'],
					'temperature'    => (float) $config['temperature'],
					'max_tokens'     => min( 64, (int) $config['max_tokens'] ),
					'system_prompt'  => (string) $config['system_prompt'],
					/* translators: %s: Agent display name. */
					'message'        => sprintf( __( 'Reply with OK from %s.', 'acl-agent-rooms' ), (string) $agent['name'] ),
				)
			);
			return;
		}

		if ( isset( $_GET['acl_ar_action'], $_GET['id'] ) && 'delete_shared_config' === sanitize_key( wp_unslash( $_GET['acl_ar_action'] ) ) ) {
			$id = absint( $_GET['id'] );
			check_admin_referer( 'acl_ar_delete_shared_config_' . $id );
			$this->shared_configs->delete( $id );
			wp_safe_redirect( add_query_arg( 'acl_ar_notice', 'shared_config_deleted', admin_url( 'admin.php?page=acl-agent-rooms-agents' ) ) );
			exit;
		}

		if ( ! empty( $_POST['acl_ar_shared_config_action'] ) ) {
			$this->handle_shared_config_save();
			return;
		}

		if ( empty( $_POST['acl_ar_agent_action'] ) ) {
			return;
		}

		check_admin_referer( 'acl_ar_save_agent', 'acl_ar_agent_nonce' );

		$id             = absint( $_POST['agent_id'] ?? 0 );
		$existing       = $id > 0 ? $this->agents->find( $id ) : null;
		$execution_mode = 'brain' === sanitize_key( wp_unslash( $_POST['execution_mode'] ?? 'independent' ) ) ? 'brain' : 'independent';
		$brain_id       = absint( $_POST['brain_id'] ?? 0 );
		if ( 'brain' === $execution_mode ) {
			$brain = $brain_id ? $this->brains->find( $brain_id ) : null;
			if ( ! $brain || empty( $brain['enabled'] ) ) {
				wp_safe_redirect( add_query_arg( 'acl_ar_notice', 'agent_brain_error', admin_url( 'admin.php?page=acl-agent-rooms-agents' ) ) );
				exit; }
		}
		$natural_delay_min_seconds = sanitize_text_field( wp_unslash( $_POST['natural_delay_min_seconds'] ?? '' ) );
		$natural_delay_max_seconds = sanitize_text_field( wp_unslash( $_POST['natural_delay_max_seconds'] ?? '' ) );
		$data = array(
			'owner_user_id'                      => (int) ( $existing['owner_user_id'] ?? get_current_user_id() ),
			'name'                               => sanitize_text_field( wp_unslash( $_POST['name'] ?? '' ) ),
			'slug'                               => sanitize_title( wp_unslash( $_POST['slug'] ?? '' ) ),
			'description'                        => wp_kses_post( wp_unslash( $_POST['description'] ?? '' ) ),
			'avatar_attachment_id'               => absint( $_POST['avatar_attachment_id'] ?? 0 ),
			'avatar_url'                         => ! empty( $_POST['avatar_remove'] ) ? '' : (string) ( $existing['avatar_url'] ?? '' ),
			'config_mode'                        => 'shared' === sanitize_key( wp_unslash( $_POST['config_mode'] ?? ( $existing['config_mode'] ?? 'independent' ) ) ) ? 'shared' : 'independent',
			'shared_config_id'                   => absint( $_POST['shared_config_id'] ?? ( $existing['shared_config_id'] ?? 0 ) ),
			'execution_mode'                     => $execution_mode,
			'brain_id'                           => 'brain' === $execution_mode ? $brain_id : null,
			'provider_route'                     => sanitize_text_field( wp_unslash( $_POST['provider_route'] ?? ( $existing['provider_route'] ?? $this->switchboard->settings()['default_provider_route'] ) ) ),
			'model'                              => $this->switchboard->sanitize_model_from_request( $_POST, 'model', (string) ( $existing['model'] ?? '' ) ),
			'system_prompt'                      => wp_kses_post( wp_unslash( $_POST['system_prompt'] ?? '' ) ),
			'temperature'                        => max( 0, min( 2, (float) sanitize_text_field( wp_unslash( $_POST['temperature'] ?? ( $existing['temperature'] ?? 0.7 ) ) ) ) ),
			'max_tokens'                         => max( 1, absint( $_POST['max_tokens'] ?? ( $existing['max_tokens'] ?? 1200 ) ) ),
			'natural_participation_chance'       => absint( $_POST['natural_participation_chance'] ?? ( $existing['natural_participation_chance'] ?? 60 ) ),
			'natural_question_tendency'          => absint( $_POST['natural_question_tendency'] ?? ( $existing['natural_question_tendency'] ?? 20 ) ),
			'natural_delay_min_ms'               => '' === trim( $natural_delay_min_seconds ) ? null : (int) round( max( 0, min( 60, (float) $natural_delay_min_seconds ) ) * 1000 ),
			'natural_delay_max_ms'               => '' === trim( $natural_delay_max_seconds ) ? null : (int) round( max( 0, min( 60, (float) $natural_delay_max_seconds ) ) * 1000 ),
			'natural_cooldown_seconds'           => absint( $_POST['natural_cooldown_seconds'] ?? ( $existing['natural_cooldown_seconds'] ?? 20 ) ),
			'natural_max_auto_responses_per_10m' => absint( $_POST['natural_max_auto_responses_per_10m'] ?? ( $existing['natural_max_auto_responses_per_10m'] ?? 4 ) ),
			'natural_conversation_role'          => sanitize_key( wp_unslash( $_POST['natural_conversation_role'] ?? ( $existing['natural_conversation_role'] ?? 'balanced' ) ) ),
			'visibility'                         => sanitize_key( wp_unslash( $_POST['visibility'] ?? 'private' ) ),
			'enabled'                            => ! empty( $_POST['enabled'] ),
		);

		if ( '' === $data['slug'] ) {
			$data['slug'] = sanitize_title( $data['name'] );
		}

		$result = $id > 0 ? $this->agents->update( $id, $data ) : $this->agents->create( $data );
		$notice = is_wp_error( $result ) ? 'agent_error' : 'agent_saved';

		wp_safe_redirect( add_query_arg( 'acl_ar_notice', $notice, admin_url( 'admin.php?page=acl-agent-rooms-agents' ) ) );
		exit;
	}

	private function handle_shared_config_save(): void {
		check_admin_referer( 'acl_ar_save_shared_config', 'acl_ar_shared_config_nonce' );

		$id       = absint( $_POST['shared_config_id'] ?? 0 );
		$existing = $id > 0 ? $this->shared_configs->find( $id ) : null;
		$data     = array(
			'owner_user_id'  => (int) ( $existing['owner_user_id'] ?? get_current_user_id() ),
			'name'           => sanitize_text_field( wp_unslash( $_POST['shared_config_name'] ?? '' ) ),
			'slug'           => sanitize_title( wp_unslash( $_POST['shared_config_slug'] ?? '' ) ),
			'provider_route' => sanitize_text_field( wp_unslash( $_POST['shared_config_provider_route'] ?? '' ) ),
			'model'          => $this->switchboard->sanitize_model_from_request( $_POST, 'shared_config_model', (string) ( $existing['model'] ?? '' ) ),
			'system_prompt'  => wp_kses_post( wp_unslash( $_POST['shared_config_system_prompt'] ?? '' ) ),
			'temperature'    => max( 0, min( 2, (float) sanitize_text_field( wp_unslash( $_POST['shared_config_temperature'] ?? 0.7 ) ) ) ),
			'max_tokens'     => max( 1, absint( $_POST['shared_config_max_tokens'] ?? 1200 ) ),
			'enabled'        => ! empty( $_POST['shared_config_enabled'] ),
		);

		if ( '' === $data['slug'] ) {
			$data['slug'] = sanitize_title( $data['name'] );
		}

		$result = $id > 0 ? $this->shared_configs->update( $id, $data ) : $this->shared_configs->create( $data );
		$notice = is_wp_error( $result ) ? 'shared_config_error' : 'shared_config_saved';

		wp_safe_redirect( add_query_arg( 'acl_ar_notice', $notice, admin_url( 'admin.php?page=acl-agent-rooms-agents' ) ) );
		exit;
	}

	private function notice(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- This read-only post-redirect notice changes no state.
		$notice   = isset( $_GET['acl_ar_notice'] ) ? sanitize_key( wp_unslash( $_GET['acl_ar_notice'] ) ) : '';
		$messages = array(
			'agent_saved'           => __( 'Agent saved.', 'acl-agent-rooms' ),
			'agent_deleted'         => __( 'Agent deleted.', 'acl-agent-rooms' ),
			'agent_error'           => __( 'Agent could not be saved. Check the slug is unique.', 'acl-agent-rooms' ),
			'agent_brain_error'     => __( 'Agent could not be saved because the selected Shared Brain is missing or disabled.', 'acl-agent-rooms' ),
			'shared_config_saved'   => __( 'Shared AI config saved.', 'acl-agent-rooms' ),
			'shared_config_deleted' => __( 'Shared AI config deleted.', 'acl-agent-rooms' ),
			'shared_config_error'   => __( 'Shared AI config could not be saved. Check the slug is unique.', 'acl-agent-rooms' ),
		);

		if ( isset( $messages[ $notice ] ) ) {
			$class = in_array( $notice, array( 'agent_error', 'agent_brain_error', 'shared_config_error' ), true ) ? 'notice notice-error' : 'notice notice-success';
			echo '<div class="' . esc_attr( $class ) . '"><p>' . esc_html( $messages[ $notice ] ) . '</p></div>';
		}
	}

	private function render_test_result(): void {
		if ( null === $this->test_result ) {
			return;
		}

		if ( is_wp_error( $this->test_result ) ) {
			echo '<div class="notice notice-error acl-ar-test-result"><p><strong>' . esc_html__( 'Agent test failed.', 'acl-agent-rooms' ) . '</strong></p><p>' . esc_html( $this->test_result->get_error_message() ) . '</p></div>';
			return;
		}

		$content = trim( (string) ( $this->test_result['content'] ?? '' ) );
		$usage   = is_array( $this->test_result['usage'] ?? null ) ? $this->test_result['usage'] : array();
		echo '<div class="notice notice-success acl-ar-test-result"><p><strong>' . esc_html__( 'Agent test succeeded.', 'acl-agent-rooms' ) . '</strong></p>';
		echo '<p class="acl-ar-test-output">' . esc_html( '' !== $content ? $content : __( 'The provider returned an empty content field.', 'acl-agent-rooms' ) ) . '</p>';
		echo '<dl><dt>' . esc_html__( 'Provider', 'acl-agent-rooms' ) . '</dt><dd>' . esc_html( (string) ( $this->test_result['raw_provider'] ?? '' ) ) . '</dd>';
		echo '<dt>' . esc_html__( 'Total tokens', 'acl-agent-rooms' ) . '</dt><dd>' . esc_html( (string) ( $usage['total_tokens'] ?? 0 ) ) . '</dd></dl></div>';
	}

	private function render_list(): void {
		$agents = $this->agents->all();

		echo '<section class="acl-ar-panel">';
		echo '<h2>' . esc_html__( 'Agents', 'acl-agent-rooms' ) . '</h2>';
		echo '<p class="description">' . esc_html__( 'Provider credentials live in ACL Switchboard, not here.', 'acl-agent-rooms' ) . '</p>';
		echo '<table class="widefat striped"><thead><tr>';
		echo '<th>' . esc_html__( 'Name', 'acl-agent-rooms' ) . '</th>';
		echo '<th>' . esc_html__( 'Slug', 'acl-agent-rooms' ) . '</th>';
		echo '<th>' . esc_html__( 'AI Config', 'acl-agent-rooms' ) . '</th>';
		echo '<th>' . esc_html__( 'Provider Route', 'acl-agent-rooms' ) . '</th>';
		echo '<th>' . esc_html__( 'Model', 'acl-agent-rooms' ) . '</th>';
		echo '<th>' . esc_html__( 'Enabled', 'acl-agent-rooms' ) . '</th>';
		echo '<th>' . esc_html__( 'Actions', 'acl-agent-rooms' ) . '</th>';
		echo '</tr></thead><tbody>';

		if ( empty( $agents ) ) {
			echo '<tr><td colspan="7" class="acl-ar-empty-cell"><p><strong>' . esc_html__( 'Create your first agent', 'acl-agent-rooms' ) . '</strong></p><p class="description">' . esc_html__( 'Agents define the provider route, model, and system prompt used for room replies.', 'acl-agent-rooms' ) . '</p><p><a class="button button-primary" href="' . esc_url( admin_url( 'admin.php?page=acl-agent-rooms-agents&action=add' ) ) . '">' . esc_html__( 'Create Agent', 'acl-agent-rooms' ) . '</a></p></td></tr>';
		}

		foreach ( $agents as $agent ) {
			$edit_url   = add_query_arg(
				array(
					'page'   => 'acl-agent-rooms-agents',
					'action' => 'edit',
					'id'     => (int) $agent['id'],
				),
				admin_url( 'admin.php' )
			);
			$test_url   = wp_nonce_url(
				add_query_arg(
					array(
						'page'          => 'acl-agent-rooms-agents',
						'acl_ar_action' => 'test_agent',
						'id'            => (int) $agent['id'],
					),
					admin_url( 'admin.php' )
				),
				'acl_ar_test_agent_' . (int) $agent['id']
			);
			$delete_url = wp_nonce_url(
				add_query_arg(
					array(
						'page'          => 'acl-agent-rooms-agents',
						'acl_ar_action' => 'delete_agent',
						'id'            => (int) $agent['id'],
					),
					admin_url( 'admin.php' )
				),
				'acl_ar_delete_agent_' . (int) $agent['id']
			);

			echo '<tr>';
			echo '<td><strong>' . esc_html( $agent['name'] ) . '</strong></td>';
			echo '<td>' . wp_kses( $this->agent_avatar_html( $agent, 'small' ), array( 'span' => array( 'class' => true ), 'img' => array( 'src' => true, 'alt' => true ) ) ) . '<code>@' . esc_html( $agent['slug'] ) . '</code></td>';
			$config = $this->resolver->resolve( $agent );
			$brain  = 'brain' === (string) ( $agent['execution_mode'] ?? 'independent' ) ? $this->brains->find( (int) ( $agent['brain_id'] ?? 0 ) ) : null;
			if ( $brain ) {
				/* translators: %s: Shared Brain display name. */
				$config_label = sprintf( __( 'Shared Brain: %s', 'acl-agent-rooms' ), (string) $brain['name'] );
			} elseif ( 'shared' === (string) $config['mode'] ) {
				/* translators: %s: Shared configuration display name. */
				$config_label = sprintf( __( 'Shared config: %s', 'acl-agent-rooms' ), (string) $config['shared_config_name'] );
			} else {
				$config_label = __( 'Independent', 'acl-agent-rooms' );
			}
			echo '<td>' . esc_html( $config_label ) . '</td>';
			echo '<td>' . esc_html( $brain ? (string) $brain['provider'] : (string) $config['provider_route'] ) . '</td>';
			echo '<td>' . esc_html( $brain ? (string) $brain['model'] : (string) $config['model'] ) . '</td>';
			echo '<td>' . ( $agent['enabled'] ? esc_html__( 'Yes', 'acl-agent-rooms' ) : esc_html__( 'No', 'acl-agent-rooms' ) ) . '</td>';
			echo '<td><a href="' . esc_url( $edit_url ) . '">' . esc_html__( 'Edit', 'acl-agent-rooms' ) . '</a> | ';
			echo '<a href="' . esc_url( $test_url ) . '">' . esc_html__( 'Test Agent', 'acl-agent-rooms' ) . '</a> | ';
			echo '<a class="acl-ar-danger" href="' . esc_url( $delete_url ) . '">' . esc_html__( 'Delete', 'acl-agent-rooms' ) . '</a></td>';
			echo '</tr>';
		}

		echo '</tbody></table></section>';
	}

	private function render_form( ?array $agent ): void {
		$settings       = $this->switchboard->settings();
		$status         = $this->switchboard->status();
		$shared_configs = $this->shared_configs->all( array( 'enabled' => true ) );
		$brains         = $this->brains->all( array( 'enabled' => true ) );
		$agent          = wp_parse_args(
			$agent ?: array(),
			array(
				'id'                                 => 0,
				'name'                               => '',
				'slug'                               => '',
				'description'                        => '',
				'avatar_attachment_id'               => 0,
				'avatar_url'                         => '',
				'avatar_alt'                         => '',
				'config_mode'                        => 'independent',
				'shared_config_id'                   => 0,
				'execution_mode'                     => 'independent',
				'brain_id'                           => 0,
				'provider_route'                     => (string) $settings['default_provider_route'],
				'model'                              => (string) $settings['default_model'],
				'system_prompt'                      => '',
				'temperature'                        => (float) $settings['default_temperature'],
				'max_tokens'                         => (int) $settings['default_max_tokens'],
				'natural_participation_chance'       => 60,
				'natural_question_tendency'          => 20,
				'natural_delay_min_ms'               => null,
				'natural_delay_max_ms'               => null,
				'natural_cooldown_seconds'           => 20,
				'natural_max_auto_responses_per_10m' => 4,
				'natural_conversation_role'          => 'balanced',
				'visibility'                         => 'private',
				'enabled'                            => true,
			)
		);

		echo '<section class="acl-ar-panel">';
		echo '<h2>' . esc_html( $agent['id'] ? __( 'Edit Agent', 'acl-agent-rooms' ) : __( 'Add Agent', 'acl-agent-rooms' ) ) . '</h2>';
		if ( ! empty( $status['warning'] ) ) {
			echo '<p class="notice notice-warning inline"><span>' . esc_html( $status['warning'] ) . '</span></p>';
		}
		echo '<form method="post">';
		wp_nonce_field( 'acl_ar_save_agent', 'acl_ar_agent_nonce' );
		echo '<input type="hidden" name="acl_ar_agent_action" value="save">';
		echo '<input type="hidden" name="agent_id" value="' . esc_attr( (string) $agent['id'] ) . '">';
		echo '<table class="form-table" role="presentation"><tbody>';
		$this->text_row( 'name', __( 'Name', 'acl-agent-rooms' ), (string) $agent['name'], true );
		$this->text_row( 'slug', __( 'Slug', 'acl-agent-rooms' ), (string) $agent['slug'], false, 'acl-ar-slug-source' );
		echo '<tr><th scope="row"><label for="description">' . esc_html__( 'Description', 'acl-agent-rooms' ) . '</label></th><td><textarea id="description" name="description" rows="3" class="large-text">' . esc_textarea( (string) $agent['description'] ) . '</textarea></td></tr>';
		$this->avatar_row( $agent );
		echo '<tr><th scope="row"><label for="execution_mode">' . esc_html__( 'Execution mode', 'acl-agent-rooms' ) . '</label></th><td><select id="execution_mode" name="execution_mode" data-acl-ar-execution-mode><option value="independent" ' . selected( (string) $agent['execution_mode'], 'independent', false ) . '>' . esc_html__( 'Independent', 'acl-agent-rooms' ) . '</option><option value="brain" ' . selected( (string) $agent['execution_mode'], 'brain', false ) . '>' . esc_html__( 'Shared Brain', 'acl-agent-rooms' ) . '</option></select><p class="description">' . esc_html__( 'Identity and persona stay distinct. Shared Brain mode inherits provider, model, and generation settings without erasing the stored independent configuration.', 'acl-agent-rooms' ) . '</p></td></tr>';
		echo '<tr data-acl-ar-brain-row><th scope="row"><label for="brain_id">' . esc_html__( 'Brain', 'acl-agent-rooms' ) . '</label></th><td><select id="brain_id" name="brain_id" data-acl-ar-brain-select required><option value="0">' . esc_html__( 'Select a Brain', 'acl-agent-rooms' ) . '</option>';
		foreach ( $brains as $brain ) {
			echo '<option value="' . esc_attr( (string) $brain['id'] ) . '" data-provider="' . esc_attr( (string) $brain['provider'] ) . '" data-model="' . esc_attr( (string) $brain['model'] ) . '" ' . selected( (int) $agent['brain_id'], (int) $brain['id'], false ) . '>' . esc_html( (string) $brain['name'] ) . '</option>';
		}echo '</select><p class="description" data-acl-ar-brain-runtime></p></td></tr>';
		echo '<tr><th scope="row"><label for="config_mode">' . esc_html__( 'AI Config Mode', 'acl-agent-rooms' ) . '</label></th><td><select id="config_mode" name="config_mode">';
		foreach ( array(
			'independent' => __( 'Independent settings', 'acl-agent-rooms' ),
			'shared'      => __( 'Shared configuration', 'acl-agent-rooms' ),
		) as $value => $label ) {
			echo '<option value="' . esc_attr( $value ) . '" ' . selected( (string) $agent['config_mode'], $value, false ) . '>' . esc_html( $label ) . '</option>';
		}
		echo '</select></td></tr>';
		echo '<tr><th scope="row"><label for="shared_config_id">' . esc_html__( 'Legacy shared configuration', 'acl-agent-rooms' ) . '</label></th><td><select id="shared_config_id" name="shared_config_id">';
		echo '<option value="0">' . esc_html__( 'None', 'acl-agent-rooms' ) . '</option>';
		foreach ( $shared_configs as $config ) {
			echo '<option value="' . esc_attr( (string) $config['id'] ) . '" ' . selected( (int) $agent['shared_config_id'], (int) $config['id'], false ) . '>' . esc_html( (string) $config['name'] ) . '</option>';
		}
		echo '</select><p class="description">' . esc_html__( 'Used only by the legacy shared runtime mode. Shared Brain execution ignores this field and inherits runtime settings from the selected Brain.', 'acl-agent-rooms' ) . '</p></td></tr>';
		$this->provider_row( 'provider_route', __( 'Provider Route', 'acl-agent-rooms' ), (string) $agent['provider_route'], $status['providers'] );
		$this->model_row( 'model', __( 'Model', 'acl-agent-rooms' ), (string) $agent['model'], $status['models'], (string) $agent['provider_route'] );
		echo '<tr><th scope="row"><label for="system_prompt">' . esc_html__( 'System Prompt', 'acl-agent-rooms' ) . '</label></th><td><textarea id="system_prompt" name="system_prompt" rows="8" class="large-text" required>' . esc_textarea( (string) $agent['system_prompt'] ) . '</textarea></td></tr>';
		echo '<tr><th colspan="2"><h3>' . esc_html__( 'Agent Conversation', 'acl-agent-rooms' ) . '</h3><p class="description">' . esc_html__( 'These values influence Natural Conversation selection and pacing. Direct mentions, /ask, and manual replies bypass automatic frequency limits.', 'acl-agent-rooms' ) . '</p></th></tr>';
		echo '<tr><th scope="row"><label for="natural_conversation_role">' . esc_html__( 'Conversation role', 'acl-agent-rooms' ) . '</label></th><td><select id="natural_conversation_role" name="natural_conversation_role" data-acl-ar-natural-role>';
		foreach ( array( 'quiet', 'balanced', 'talkative', 'facilitator' ) as $role ) {
			echo '<option value="' . esc_attr( $role ) . '" ' . selected( (string) $agent['natural_conversation_role'], $role, false ) . '>' . esc_html( ucfirst( $role ) ) . '</option>';
		} echo '</select><p class="description acl-ar-natural-presets">';
		foreach ( array(
			'quiet'       => 'Quiet',
			'balanced'    => 'Balanced',
			'talkative'   => 'Talkative',
			'facilitator' => 'Facilitator',
		) as $role => $label ) {
			echo '<button type="button" class="button button-small" data-acl-ar-natural-preset="' . esc_attr( $role ) . '">' . esc_html( $label ) . '</button> ';
		} echo '</p></td></tr>';
		echo '<tr><th scope="row"><label for="natural_participation_chance">' . esc_html__( 'Participation chance (%)', 'acl-agent-rooms' ) . '</label></th><td><input id="natural_participation_chance" name="natural_participation_chance" type="number" min="0" max="100" value="' . esc_attr( (string) $agent['natural_participation_chance'] ) . '" data-acl-ar-natural-field="participation"></td></tr>';
		echo '<tr><th scope="row"><label for="natural_question_tendency">' . esc_html__( 'Question tendency (%)', 'acl-agent-rooms' ) . '</label></th><td><input id="natural_question_tendency" name="natural_question_tendency" type="number" min="0" max="100" value="' . esc_attr( (string) $agent['natural_question_tendency'] ) . '" data-acl-ar-natural-field="question"></td></tr>';
		echo '<tr><th scope="row">' . esc_html__( 'Reply delay override (seconds)', 'acl-agent-rooms' ) . '</th><td><label>' . esc_html__( 'Minimum', 'acl-agent-rooms' ) . ' <input name="natural_delay_min_seconds" type="number" min="0" max="60" step="0.1" value="' . esc_attr( null === $agent['natural_delay_min_ms'] ? '' : (string) ( $agent['natural_delay_min_ms'] / 1000 ) ) . '"></label> <label>' . esc_html__( 'Maximum', 'acl-agent-rooms' ) . ' <input name="natural_delay_max_seconds" type="number" min="0" max="60" step="0.1" value="' . esc_attr( null === $agent['natural_delay_max_ms'] ? '' : (string) ( $agent['natural_delay_max_ms'] / 1000 ) ) . '"></label><p class="description">' . esc_html__( 'Leave both blank to use the room first-response delay.', 'acl-agent-rooms' ) . '</p></td></tr>';
		echo '<tr><th scope="row"><label for="natural_cooldown_seconds">' . esc_html__( 'Cooldown after speaking (seconds)', 'acl-agent-rooms' ) . '</label></th><td><input id="natural_cooldown_seconds" name="natural_cooldown_seconds" type="number" min="0" max="3600" value="' . esc_attr( (string) $agent['natural_cooldown_seconds'] ) . '" data-acl-ar-natural-field="cooldown"></td></tr>';
		echo '<tr><th scope="row"><label for="natural_max_auto_responses_per_10m">' . esc_html__( 'Maximum automatic replies per 10 minutes', 'acl-agent-rooms' ) . '</label></th><td><input id="natural_max_auto_responses_per_10m" name="natural_max_auto_responses_per_10m" type="number" min="0" max="20" value="' . esc_attr( (string) $agent['natural_max_auto_responses_per_10m'] ) . '" data-acl-ar-natural-field="limit"></td></tr>';
		echo '<tr><th scope="row"><label for="temperature">' . esc_html__( 'Temperature', 'acl-agent-rooms' ) . '</label></th><td><input id="temperature" name="temperature" type="number" step="0.01" min="0" max="2" value="' . esc_attr( (string) $agent['temperature'] ) . '"></td></tr>';
		echo '<tr><th scope="row"><label for="max_tokens">' . esc_html__( 'Max Tokens', 'acl-agent-rooms' ) . '</label></th><td><input id="max_tokens" name="max_tokens" type="number" min="1" value="' . esc_attr( (string) $agent['max_tokens'] ) . '"></td></tr>';
		echo '<tr><th scope="row"><label for="visibility">' . esc_html__( 'Visibility', 'acl-agent-rooms' ) . '</label></th><td><select id="visibility" name="visibility">';
		foreach ( array( 'private', 'public' ) as $visibility ) {
			echo '<option value="' . esc_attr( $visibility ) . '" ' . selected( $agent['visibility'], $visibility, false ) . '>' . esc_html( ucfirst( $visibility ) ) . '</option>';
		}
		echo '</select></td></tr>';
		echo '<tr><th scope="row">' . esc_html__( 'Enabled', 'acl-agent-rooms' ) . '</th><td><label><input type="checkbox" name="enabled" value="1" ' . checked( ! empty( $agent['enabled'] ), true, false ) . '> ' . esc_html__( 'Agent is available for rooms', 'acl-agent-rooms' ) . '</label></td></tr>';
		echo '</tbody></table>';
		submit_button( $agent['id'] ? __( 'Update Agent', 'acl-agent-rooms' ) : __( 'Create Agent', 'acl-agent-rooms' ) );
		echo '</form></section>';
	}

	private function render_shared_configs(): void {
		$settings = $this->switchboard->settings();
		$status   = $this->switchboard->status();
		$configs  = $this->shared_configs->all();
		$editing  = null;

		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- This read-only filter changes no state.
		if ( isset( $_GET['action'], $_GET['id'] ) && 'edit_shared_config' === sanitize_key( wp_unslash( $_GET['action'] ) ) ) {
			$editing = $this->shared_configs->find( absint( $_GET['id'] ) );
		}
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		$config = wp_parse_args(
			$editing ?: array(),
			array(
				'id'             => 0,
				'name'           => '',
				'slug'           => '',
				'provider_route' => (string) $settings['default_provider_route'],
				'model'          => (string) $settings['default_model'],
				'system_prompt'  => '',
				'temperature'    => (float) $settings['default_temperature'],
				'max_tokens'     => (int) $settings['default_max_tokens'],
				'enabled'        => true,
			)
		);

		echo '<section class="acl-ar-panel acl-ar-panel-wide">';
		echo '<h2>' . esc_html__( 'Legacy Shared Configurations', 'acl-agent-rooms' ) . '</h2>';
		echo '<p class="description">' . esc_html__( 'Compatibility settings for pre-1.1.0 agents. Use Brains for one-call multi-agent orchestration.', 'acl-agent-rooms' ) . '</p>';
		echo '<table class="widefat striped"><thead><tr>';
		echo '<th>' . esc_html__( 'Name', 'acl-agent-rooms' ) . '</th>';
		echo '<th>' . esc_html__( 'Provider Route', 'acl-agent-rooms' ) . '</th>';
		echo '<th>' . esc_html__( 'Model', 'acl-agent-rooms' ) . '</th>';
		echo '<th>' . esc_html__( 'Enabled', 'acl-agent-rooms' ) . '</th>';
		echo '<th>' . esc_html__( 'Actions', 'acl-agent-rooms' ) . '</th>';
		echo '</tr></thead><tbody>';
		if ( empty( $configs ) ) {
			echo '<tr><td colspan="5" class="acl-ar-empty-cell">' . esc_html__( 'No shared configurations yet.', 'acl-agent-rooms' ) . '</td></tr>';
		}
		foreach ( $configs as $item ) {
			$edit_url   = add_query_arg(
				array(
					'page'   => 'acl-agent-rooms-agents',
					'action' => 'edit_shared_config',
					'id'     => (int) $item['id'],
				),
				admin_url( 'admin.php' )
			);
			$delete_url = wp_nonce_url(
				add_query_arg(
					array(
						'page'          => 'acl-agent-rooms-agents',
						'acl_ar_action' => 'delete_shared_config',
						'id'            => (int) $item['id'],
					),
					admin_url( 'admin.php' )
				),
				'acl_ar_delete_shared_config_' . (int) $item['id']
			);
			echo '<tr>';
			echo '<td><strong>' . esc_html( (string) $item['name'] ) . '</strong><br><code>' . esc_html( (string) $item['slug'] ) . '</code></td>';
			echo '<td>' . esc_html( (string) $item['provider_route'] ) . '</td>';
			echo '<td>' . esc_html( (string) $item['model'] ) . '</td>';
			echo '<td>' . ( $item['enabled'] ? esc_html__( 'Yes', 'acl-agent-rooms' ) : esc_html__( 'No', 'acl-agent-rooms' ) ) . '</td>';
			echo '<td><a href="' . esc_url( $edit_url ) . '">' . esc_html__( 'Edit', 'acl-agent-rooms' ) . '</a> | ';
			echo '<a class="acl-ar-danger" href="' . esc_url( $delete_url ) . '">' . esc_html__( 'Delete', 'acl-agent-rooms' ) . '</a></td>';
			echo '</tr>';
		}
		echo '</tbody></table>';

		echo '<h3>' . esc_html( $config['id'] ? __( 'Edit Legacy Shared Configuration', 'acl-agent-rooms' ) : __( 'Add Legacy Shared Configuration', 'acl-agent-rooms' ) ) . '</h3>';
		echo '<form method="post">';
		wp_nonce_field( 'acl_ar_save_shared_config', 'acl_ar_shared_config_nonce' );
		echo '<input type="hidden" name="acl_ar_shared_config_action" value="save">';
		echo '<input type="hidden" name="shared_config_id" value="' . esc_attr( (string) $config['id'] ) . '">';
		echo '<table class="form-table" role="presentation"><tbody>';
		$this->text_row( 'shared_config_name', __( 'Name', 'acl-agent-rooms' ), (string) $config['name'], true );
		$this->text_row( 'shared_config_slug', __( 'Slug', 'acl-agent-rooms' ), (string) $config['slug'] );
		$this->provider_row( 'shared_config_provider_route', __( 'Provider Route', 'acl-agent-rooms' ), (string) $config['provider_route'], $status['providers'] );
		$this->model_row( 'shared_config_model', __( 'Model', 'acl-agent-rooms' ), (string) $config['model'], $status['models'], (string) $config['provider_route'], 'shared_config_provider_route' );
		echo '<tr><th scope="row"><label for="shared_config_system_prompt">' . esc_html__( 'Master Prompt', 'acl-agent-rooms' ) . '</label></th><td><textarea id="shared_config_system_prompt" name="shared_config_system_prompt" rows="8" class="large-text" required>' . esc_textarea( (string) $config['system_prompt'] ) . '</textarea></td></tr>';
		echo '<tr><th scope="row"><label for="shared_config_temperature">' . esc_html__( 'Temperature', 'acl-agent-rooms' ) . '</label></th><td><input id="shared_config_temperature" name="shared_config_temperature" type="number" step="0.01" min="0" max="2" value="' . esc_attr( (string) $config['temperature'] ) . '"></td></tr>';
		echo '<tr><th scope="row"><label for="shared_config_max_tokens">' . esc_html__( 'Max Tokens', 'acl-agent-rooms' ) . '</label></th><td><input id="shared_config_max_tokens" name="shared_config_max_tokens" type="number" min="1" value="' . esc_attr( (string) $config['max_tokens'] ) . '"></td></tr>';
		echo '<tr><th scope="row">' . esc_html__( 'Enabled', 'acl-agent-rooms' ) . '</th><td><label><input type="checkbox" name="shared_config_enabled" value="1" ' . checked( ! empty( $config['enabled'] ), true, false ) . '> ' . esc_html__( 'Shared config can be used by agents', 'acl-agent-rooms' ) . '</label></td></tr>';
		echo '</tbody></table>';
		submit_button( $config['id'] ? __( 'Update Legacy Shared Configuration', 'acl-agent-rooms' ) : __( 'Create Legacy Shared Configuration', 'acl-agent-rooms' ) );
		echo '</form></section>';
	}

	private function provider_row( string $name, string $label, string $value, array $providers ): void {
		if ( empty( $providers ) ) {
			$this->text_row( $name, $label, $value, true );
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

	private function model_row( string $name, string $label, string $value, array $models, string $provider_route, string $provider_field = 'provider_route' ): void {
		if ( empty( $models ) ) {
			$this->text_row( $name, $label, $value, true );
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
		echo '<select id="' . esc_attr( $name ) . '" name="' . esc_attr( $name ) . '" class="regular-text acl-ar-model-select" data-provider-field="' . esc_attr( $provider_field ) . '" data-custom-field="' . esc_attr( $name . '_custom' ) . '" data-provider-filter="' . esc_attr( 'none' === $provider_ownership ? '0' : '1' ) . '" data-initial-model="' . esc_attr( $value ) . '">';
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

	private function text_row( string $name, string $label, string $value, bool $required = false, string $class = '' ): void {
		echo '<tr><th scope="row"><label for="' . esc_attr( $name ) . '">' . esc_html( $label ) . '</label></th><td><input id="' . esc_attr( $name ) . '" name="' . esc_attr( $name ) . '" type="text" class="regular-text ' . esc_attr( $class ) . '" value="' . esc_attr( $value ) . '"' . ( $required ? ' required' : '' ) . '></td></tr>';
	}

	private function url_row( string $name, string $label, string $value ): void {
		echo '<tr><th scope="row"><label for="' . esc_attr( $name ) . '">' . esc_html( $label ) . '</label></th><td><input id="' . esc_attr( $name ) . '" name="' . esc_attr( $name ) . '" type="url" class="regular-text" value="' . esc_attr( $value ) . '"></td></tr>';
	}

	private function avatar_row( array $agent ): void {
		$has_avatar = '' !== (string) ( $agent['avatar_url'] ?? '' );

		echo '<tr><th scope="row">' . esc_html__( 'Avatar', 'acl-agent-rooms' ) . '</th><td>';
		echo '<div class="acl-ar-avatar-field" data-acl-ar-avatar-field>';
		echo '<input type="hidden" name="avatar_attachment_id" value="' . esc_attr( (string) (int) ( $agent['avatar_attachment_id'] ?? 0 ) ) . '" data-acl-ar-avatar-id>';
		echo '<input type="hidden" name="avatar_remove" value="0" data-acl-ar-avatar-remove>';
		echo '<div class="acl-ar-avatar-preview" data-acl-ar-avatar-preview>';
		if ( $has_avatar ) {
			echo '<img src="' . esc_url( (string) $agent['avatar_url'] ) . '" alt="' . esc_attr( (string) ( $agent['avatar_alt'] ?? $agent['name'] ?? '' ) ) . '">';
		} else {
			echo '<span>' . esc_html( $this->agent_initials( (string) ( $agent['name'] ?? '' ) ) ) . '</span>';
		}
		echo '</div>';
		echo '<div class="acl-ar-avatar-actions">';
		echo '<button type="button" class="button" data-acl-ar-avatar-select>' . esc_html__( 'Select Avatar', 'acl-agent-rooms' ) . '</button> ';
		echo '<button type="button" class="button" data-acl-ar-avatar-remove-button ' . disabled( ! $has_avatar, true, false ) . '>' . esc_html__( 'Remove Avatar', 'acl-agent-rooms' ) . '</button>';
		echo '</div>';
		echo '<p class="description">' . esc_html__( 'Choose or upload an image from the WordPress Media Library. Agent Rooms stores the attachment ID, not provider credentials or external image data.', 'acl-agent-rooms' ) . '</p>';
		echo '</div></td></tr>';
	}

	private function agent_avatar_html( array $agent, string $size = 'medium' ): string {
		$class = 'acl-ar-agent-avatar acl-ar-agent-avatar-' . sanitize_html_class( $size );
		if ( '' !== (string) ( $agent['avatar_url'] ?? '' ) ) {
			return '<span class="' . esc_attr( $class ) . '"><img src="' . esc_url( (string) $agent['avatar_url'] ) . '" alt="' . esc_attr( (string) ( $agent['avatar_alt'] ?? $agent['name'] ?? '' ) ) . '"></span> ';
		}

		return '<span class="' . esc_attr( $class ) . '"><span>' . esc_html( $this->agent_initials( (string) ( $agent['name'] ?? '' ) ) ) . '</span></span> ';
	}

	private function agent_initials( string $name ): string {
		$name = trim( wp_strip_all_tags( $name ) );
		if ( '' === $name ) {
			return 'A';
		}

		$words  = preg_split( '/\s+/', $name ) ?: array();
		$first  = strtoupper( substr( (string) ( $words[0] ?? 'A' ), 0, 1 ) );
		$second = strtoupper( substr( (string) ( $words[1] ?? '' ), 0, 1 ) );

		return substr( $first . $second, 0, 2 );
	}
}
