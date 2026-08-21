<?php
// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange -- This file operates on plugin-owned operational tables and requires uncached transaction/read-after-write truth.
// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- Identifiers are fixed $wpdb->prefix suffixes, fixed internal schema fragments, or allowlisted names; runtime values use placeholders or wpdb mutation arrays.
// phpcs:disable PluginCheck.Security.DirectDB.UnescapedDBParameter -- PHPCS cannot model the fixed identifier provenance above; request data is never used as an SQL identifier.
/** Shared Brain wp-admin CRUD. @package ACL_Agent_Rooms */
namespace ACL\AgentRooms\Admin;

use ACL\AgentRooms\Capabilities;
use ACL\AgentRooms\Repositories\BrainRepository;
use ACL\AgentRooms\Repositories\BrainRunRepository;
use ACL\AgentRooms\Services\BrainConfigService;
use ACL\AgentRooms\Services\SwitchboardAdminService;

if ( ! defined( 'ABSPATH' ) ) {
	exit;}
class BrainsPage {
	private BrainRepository $brains;
	private BrainRunRepository $runs;
	private SwitchboardAdminService $switchboard;
	private BrainConfigService $config;
	public function __construct() {
		$this->brains      = new BrainRepository();
		$this->runs        = new BrainRunRepository();
		$this->switchboard = new SwitchboardAdminService();
		$this->config      = new BrainConfigService( $this->brains, $this->switchboard ); }
	public function process_request(): void {
		if ( ! Capabilities::current_user_can( Capabilities::MANAGE_AGENTS ) ) {
			return;
		}if ( ! empty( $_POST['acl_ar_brain_action'] ) ) {
			check_admin_referer( 'acl_ar_save_brain', 'acl_ar_brain_nonce' );
			$id   = absint( $_POST['brain_id'] ?? 0 );
			$old  = $id ? $this->brains->find( $id ) : null;
			$data = array(
				'owner_user_id'        => (int) ( $old['owner_user_id'] ?? get_current_user_id() ),
				'name'                 => sanitize_text_field( wp_unslash( $_POST['name'] ?? '' ) ),
				'slug'                 => sanitize_title( wp_unslash( $_POST['slug'] ?? '' ) ),
				'description'          => sanitize_textarea_field( wp_unslash( $_POST['description'] ?? '' ) ),
				'provider'             => sanitize_text_field( wp_unslash( $_POST['provider'] ?? '' ) ),
				'model'                => $this->switchboard->sanitize_model_from_request( $_POST, 'model', (string) ( $old['model'] ?? '' ) ),
				'orchestration_prompt' => sanitize_textarea_field( wp_unslash( $_POST['orchestration_prompt'] ?? '' ) ),
				'temperature'          => (float) sanitize_text_field( wp_unslash( $_POST['temperature'] ?? 0.7 ) ),
				'max_tokens_per_agent' => absint( $_POST['max_tokens_per_agent'] ?? 600 ),
				'max_total_tokens'     => absint( $_POST['max_total_tokens'] ?? 6000 ),
				'settings'             => array(),
				'enabled'              => ! empty( $_POST['enabled'] ),
			);
			if ( '' === $data['slug'] ) {
				$data['slug'] = sanitize_title( $data['name'] );
			}$valid = $this->config->validate_pair( $data['provider'], $data['model'] );
			$result = is_wp_error( $valid ) ? $valid : ( $id ? $this->brains->update( $id, $data ) : $this->brains->create( $data ) );
			if ( $id && empty( $data['enabled'] ) && ! is_wp_error( $result ) ) {
				$this->runs->cancel_for_brain( $id );
			}$notice = is_wp_error( $result ) ? 'error' : 'saved';
			wp_safe_redirect( add_query_arg( 'acl_ar_brain_notice', $notice, admin_url( 'admin.php?page=acl-agent-rooms-brains' ) ) );
			exit;
		}if ( isset( $_GET['acl_ar_action'], $_GET['id'] ) && 'delete' === sanitize_key( wp_unslash( $_GET['acl_ar_action'] ) ) ) {
			$id = absint( $_GET['id'] );
			check_admin_referer( 'acl_ar_delete_brain_' . $id );
			$result = $this->brains->delete( $id );
			$notice = is_wp_error( $result ) ? 'in_use' : 'deleted';
			wp_safe_redirect( add_query_arg( 'acl_ar_brain_notice', $notice, admin_url( 'admin.php?page=acl-agent-rooms-brains' ) ) );
			exit;}}
	public function render(): void {
		if ( ! Capabilities::current_user_can( Capabilities::MANAGE_AGENTS ) ) {
			wp_die( esc_html__( 'You cannot manage Brains.', 'acl-agent-rooms' ) );
		}$editing = null;
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- This read-only filter changes no state.
		if ( isset( $_GET['action'], $_GET['id'] ) && 'edit' === sanitize_key( wp_unslash( $_GET['action'] ) ) ) {
			$editing = $this->brains->find( absint( $_GET['id'] ) );
		}
		// phpcs:enable WordPress.Security.NonceVerification.Recommended
		$status = $this->switchboard->status();
		$brain   = wp_parse_args(
			$editing ?: array(),
			array(
				'id'                   => 0,
				'name'                 => '',
				'slug'                 => '',
				'description'          => '',
				'provider'             => '',
				'model'                => '',
				'orchestration_prompt' => '',
				'temperature'          => 0.7,
				'max_tokens_per_agent' => 600,
				'max_total_tokens'     => 6000,
				'enabled'              => true,
			)
		);
		echo '<div class="wrap acl-ar-admin"><h1>' . esc_html__( 'ACL Agent Rooms - Brains', 'acl-agent-rooms' ) . '</h1>';
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- This read-only post-redirect notice changes no state.
		$notice = sanitize_key( wp_unslash( $_GET['acl_ar_brain_notice'] ?? '' ) );
		// phpcs:enable WordPress.Security.NonceVerification.Recommended
		if ( $notice ) {
			$messages = array(
				'saved'   => __( 'Brain saved.', 'acl-agent-rooms' ),
				'deleted' => __( 'Brain deleted.', 'acl-agent-rooms' ),
				'in_use'  => __( 'Brain cannot be deleted while agents reference it.', 'acl-agent-rooms' ),
				'error'   => __( 'Brain could not be saved. Verify the provider/model and unique slug.', 'acl-agent-rooms' ),
			);
			echo '<div class="notice ' . ( 'saved' === $notice || 'deleted' === $notice ? 'notice-success' : 'notice-error' ) . '"><p>' . esc_html( $messages[ $notice ] ?? '' ) . '</p></div>';
		}echo '<div class="acl-ar-admin-grid"><section class="acl-ar-panel"><h2>' . esc_html__( 'Brains', 'acl-agent-rooms' ) . '</h2><table class="widefat striped"><thead><tr><th>' . esc_html__( 'Name', 'acl-agent-rooms' ) . '</th><th>' . esc_html__( 'Runtime', 'acl-agent-rooms' ) . '</th><th>' . esc_html__( 'Agents', 'acl-agent-rooms' ) . '</th><th>' . esc_html__( 'Status', 'acl-agent-rooms' ) . '</th><th>' . esc_html__( 'Actions', 'acl-agent-rooms' ) . '</th></tr></thead><tbody>';
		foreach ( $this->brains->all() as $item ) {
			$edit   = add_query_arg(
				array(
					'page'   => 'acl-agent-rooms-brains',
					'action' => 'edit',
					'id'     => $item['id'],
				),
				admin_url( 'admin.php' )
			);
			$delete = wp_nonce_url(
				add_query_arg(
					array(
						'page'          => 'acl-agent-rooms-brains',
						'acl_ar_action' => 'delete',
						'id'            => $item['id'],
					),
					admin_url( 'admin.php' )
				),
				'acl_ar_delete_brain_' . $item['id']
			);
			echo '<tr><td>' . esc_html( $item['name'] ) . '</td><td>' . esc_html( $item['provider'] . ' / ' . $item['model'] ) . '</td><td>' . esc_html( (string) $this->brains->referenced_count( (int) $item['id'] ) ) . '</td><td>' . esc_html( $item['enabled'] ? __( 'Enabled', 'acl-agent-rooms' ) : __( 'Disabled', 'acl-agent-rooms' ) ) . '</td><td><a href="' . esc_url( $edit ) . '">' . esc_html__( 'Edit', 'acl-agent-rooms' ) . '</a> | <a class="acl-ar-danger" href="' . esc_url( $delete ) . '">' . esc_html__( 'Delete', 'acl-agent-rooms' ) . '</a></td></tr>';
		}$usage = $this->usage_summary();
		/* translators: 1: Provider request count. 2: Total token count. 3: Estimated cost. */
		echo '</tbody></table><h3>' . esc_html__( 'Shared Brain usage', 'acl-agent-rooms' ) . '</h3><p>' . esc_html( sprintf( __( 'Provider requests: %1$d; total tokens: %2$d; estimated cost: %3$.8f. Brain usage is counted once per Brain run and is not attributed in full to each agent.', 'acl-agent-rooms' ), $usage['requests'], $usage['total_tokens'], $usage['estimated_cost'] ) ) . '</p></section><section class="acl-ar-panel"><h2>' . esc_html( $brain['id'] ? __( 'Edit Brain', 'acl-agent-rooms' ) : __( 'Add Brain', 'acl-agent-rooms' ) ) . '</h2><form method="post">';
		wp_nonce_field( 'acl_ar_save_brain', 'acl_ar_brain_nonce' );
		echo '<input type="hidden" name="acl_ar_brain_action" value="save"><input type="hidden" name="brain_id" value="' . esc_attr( (string) $brain['id'] ) . '"><table class="form-table"><tbody>';
		$this->text( 'name', __( 'Name', 'acl-agent-rooms' ), $brain['name'], true );
		$this->text( 'slug', __( 'Slug', 'acl-agent-rooms' ), $brain['slug'] );
		echo '<tr><th><label for="description">' . esc_html__( 'Description', 'acl-agent-rooms' ) . '</label></th><td><textarea class="large-text" id="description" name="description">' . esc_textarea( $brain['description'] ) . '</textarea></td></tr>';
		$this->select(
			'provider',
			__( 'Provider', 'acl-agent-rooms' ),
			$brain['provider'],
			array_map(
				static fn( $p )=>array(
					'value' => $p['slug'],
					'label' => $p['label'],
				),
				$status['providers']
			)
		);
		$models = array_values( array_filter( $status['models'], static fn( $m )=>'' === $brain['provider'] || $m['provider'] === $brain['provider'] ) );
		$this->select(
			'model',
			__( 'Model', 'acl-agent-rooms' ),
			$brain['model'],
			array_map(
				static fn( $m )=>array(
					'value' => $m['model'],
					'label' => $m['label'],
				),
				$models
			)
		);
		echo '<tr><th><label for="orchestration_prompt">' . esc_html__( 'Orchestration instructions', 'acl-agent-rooms' ) . '</label></th><td><textarea class="large-text" rows="6" id="orchestration_prompt" name="orchestration_prompt">' . esc_textarea( $brain['orchestration_prompt'] ) . '</textarea></td></tr>';
		$this->number( 'temperature', __( 'Temperature', 'acl-agent-rooms' ), $brain['temperature'], '0.001', 0, 2 );
		$this->number( 'max_tokens_per_agent', __( 'Max tokens per agent', 'acl-agent-rooms' ), $brain['max_tokens_per_agent'], '1', 64, 8000 );
		$this->number( 'max_total_tokens', __( 'Max total tokens', 'acl-agent-rooms' ), $brain['max_total_tokens'], '1', 64, 32000 );
		echo '<tr><th>' . esc_html__( 'Enabled', 'acl-agent-rooms' ) . '</th><td><label><input type="checkbox" name="enabled" value="1" ' . checked( ! empty( $brain['enabled'] ), true, false ) . '> ' . esc_html__( 'Brain can execute connected agents', 'acl-agent-rooms' ) . '</label></td></tr></tbody></table>';
		submit_button( $brain['id'] ? __( 'Update Brain', 'acl-agent-rooms' ) : __( 'Create Brain', 'acl-agent-rooms' ) );
		echo '</form></section></div></div>';}
	private function usage_summary(): array {
		global$wpdb;
		$row = $wpdb->get_row( 'SELECT COUNT(*) AS requests,COALESCE(SUM(total_tokens),0) AS total_tokens,COALESCE(SUM(estimated_cost),0) AS estimated_cost FROM ' . $wpdb->prefix . 'acl_ar_usage WHERE brain_run_id IS NOT NULL', ARRAY_A ) ?: array();
		return array(
			'requests'       => (int) ( $row['requests'] ?? 0 ),
			'total_tokens'   => (int) ( $row['total_tokens'] ?? 0 ),
			'estimated_cost' => (float) ( $row['estimated_cost'] ?? 0 ),
		);}
	private function text( $name, $label, $value, $required = false ): void {
		echo '<tr><th><label for="' . esc_attr( $name ) . '">' . esc_html( $label ) . '</label></th><td><input class="regular-text" id="' . esc_attr( $name ) . '" name="' . esc_attr( $name ) . '" value="' . esc_attr( (string) $value ) . '" ' . ( $required ? 'required' : '' ) . '></td></tr>';}
	private function number( $name, $label, $value, $step, $min, $max ): void {
		echo '<tr><th><label for="' . esc_attr( $name ) . '">' . esc_html( $label ) . '</label></th><td><input type="number" id="' . esc_attr( $name ) . '" name="' . esc_attr( $name ) . '" value="' . esc_attr( (string) $value ) . '" step="' . esc_attr( $step ) . '" min="' . esc_attr( (string) $min ) . '" max="' . esc_attr( (string) $max ) . '"></td></tr>';}
	private function select( $name, $label, $value, $options ): void {
		echo '<tr><th><label for="' . esc_attr( $name ) . '">' . esc_html( $label ) . '</label></th><td><select id="' . esc_attr( $name ) . '" name="' . esc_attr( $name ) . '">';
		if ( $value && ! in_array( $value, array_column( $options, 'value' ), true ) ) {
			echo '<option value="' . esc_attr( $value ) . '" selected>' . esc_html( $value ) . '</option>';
		}foreach ( $options as $option ) {
			echo '<option value="' . esc_attr( $option['value'] ) . '" ' . selected( $value, $option['value'], false ) . '>' . esc_html( $option['label'] ) . '</option>';
		}echo '</select></td></tr>';}
}
