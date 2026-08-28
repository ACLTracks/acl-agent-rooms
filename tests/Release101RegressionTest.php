<?php
/** Focused regression coverage for the 1.0.1 release-blocker repair. */
class ACL_AR_Release101RegressionTest extends ACL_AR_TestCase {
	public function run(): void {
		$this->version_and_admin_lifecycle();
		$this->room_format_mapping();
		$this->scheduling_lifecycle();
	}

	private function version_and_admin_lifecycle(): void {
		$root     = dirname( __DIR__ );
		$menu     = (string) file_get_contents( $root . '/includes/Admin/AdminMenu.php' );
		$agents   = (string) file_get_contents( $root . '/includes/Admin/AgentsPage.php' );
		$rooms    = (string) file_get_contents( $root . '/includes/Admin/RoomsPage.php' );
		$settings = (string) file_get_contents( $root . '/includes/Admin/SettingsPage.php' );

		$this->assert_same( '1.5.7', ACL_AR_VERSION, 'Current plugin version does not preserve the 1.0.1 repair.' );
		$this->assert_same( '1.4.1', ACL_AR_DB_VERSION, 'Current database version does not preserve the 1.0.1 repair.' );
		\ACL\AgentRooms\Installer::install();
		$this->assert_same( '1.4.1', (string) get_option( 'acl_ar_db_version' ), 'Installed database version is not current.' );
		$this->assert_same( 1, substr_count( $menu, "add_action( 'load-' . \$hook_suffix, \$callback )" ), 'Page load hook registration is duplicated or missing.' );
		foreach ( array( $agents, $rooms, $settings ) as $source ) {
			$this->assert_true( false !== strpos( $source, 'public function process_request(): void' ), 'Admin page lacks a public pre-render dispatcher.' );
			$render_start = strpos( $source, 'public function render(): void' );
			$process_start = strpos( $source, 'public function process_request(): void' );
			$render_source = substr( $source, $render_start, $process_start - $render_start );
			$this->assert_true( false === strpos( $render_source, 'process_request(' ) && false === strpos( $render_source, 'handle_request(' ), 'Render callback still dispatches a mutation.' );
			$this->assert_true( false !== strpos( $source, 'wp_doing_ajax()' ) && false !== strpos( $source, 'REST_REQUEST' ), 'Dispatcher lacks AJAX/REST isolation.' );
			$this->assert_true( false !== strpos( $source, 'Capabilities::current_user_can' ), 'Dispatcher lacks a capability check.' );
		}
		$this->assert_true( false !== strpos( $agents, 'check_admin_referer' ) && false !== strpos( $rooms, 'check_admin_referer' ) && false !== strpos( $settings, 'check_admin_referer' ), 'Nonce checks were not preserved.' );
		$this->assert_true( substr_count( $agents . $rooms . $settings, 'wp_safe_redirect' ) >= 8, 'Safe local redirect contracts are missing.' );
		$this->assert_true( false === strpos( $agents . $rooms . $settings, 'ob_start' ), 'Output buffering was introduced.' );
		$this->assert_true( false === strpos( $agents . $rooms . $settings, 'window.location' ), 'JavaScript redirect was introduced.' );

		if ( ! function_exists( 'add_menu_page' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		$administrator_ids = get_users( array( 'role' => 'administrator', 'fields' => 'ids', 'number' => 1 ) );
		$this->assert_true( ! empty( $administrator_ids ), 'No administrator is available for admin-hook verification.' );
		wp_set_current_user( (int) $administrator_ids[0] );
		\ACL\AgentRooms\Capabilities::add();
		$admin_menu = new \ACL\AgentRooms\Admin\AdminMenu();
		$admin_menu->register();
		foreach ( array( 'rooms', 'agents', 'settings' ) as $page ) {
			$hook = 'acl-agent-rooms_page_acl-agent-rooms-' . $page;
			$this->assert_true( false !== has_action( 'load-' . $hook ), 'Page-specific load handler did not register for ' . $page . '.' );
		}
	}

	private function room_format_mapping(): void {
		global $wpdb;
		$source = (string) file_get_contents( dirname( __DIR__ ) . '/includes/Repositories/RoomRepository.php' );
		$this->assert_true( false !== strpos( $source, 'formats_for_storage( $stored )' ) && false !== strpos( $source, "'natural_max_pending_turns'" ), 'Room create does not use the audited schema-aware format map.' );
		$repo   = new \ACL\AgentRooms\Repositories\RoomRepository();
		$prefix = 'release-101-' . gmdate( 'YmdHis' ) . '-' . wp_generate_password( 5, false, false );
		$id     = $repo->create( array( 'owner_user_id' => get_current_user_id(), 'title' => $prefix, 'slug' => $prefix, 'type' => 'private', 'visibility' => 'private', 'status' => 'active', 'agent_reply_mode' => 'manual', 'max_context_messages' => 37, 'max_agents_per_turn' => 4 ) );
		$this->assert_true( is_int( $id ) && $id > 0, 'Room create failed.' );
		$row = $repo->find( (int) $id );
		$this->assert_same( 'manual', $row['agent_reply_mode'], 'Manual reply mode was corrupted.' );
		$this->assert_same( 37, $row['max_context_messages'], 'Context count neighboring type was corrupted.' );
		$this->assert_same( 4, $row['max_agents_per_turn'], 'Agent count neighboring type was corrupted.' );
		foreach ( array( 'auto', 'mention', 'slash', 'manual' ) as $mode ) {
			$this->assert_true( true === $repo->update( (int) $id, array( 'agent_reply_mode' => $mode ) ), 'Room update failed for ' . $mode . '.' );
			$this->assert_same( $mode, $repo->find( (int) $id )['agent_reply_mode'], 'Reply mode did not persist: ' . $mode . '.' );
		}
		$repo->update( (int) $id, array( 'agent_reply_mode' => 'invalid-mode' ) );
		$this->assert_same( 'manual', $repo->find( (int) $id )['agent_reply_mode'], 'Invalid mode did not normalize to manual.' );
		$this->assert_true( false !== $wpdb->delete( $wpdb->prefix . 'acl_ar_room_members', array( 'room_id' => (int) $id ), array( '%d' ) ), 'Room fixture membership cleanup failed.' );
		$this->assert_true( true === $repo->delete( (int) $id ), 'Room fixture cleanup failed.' );
	}

	private function scheduling_lifecycle(): void {
		$root   = dirname( __DIR__ );
		$source = (string) file_get_contents( $root . '/includes/Services/QueueService.php' );
		$method = new ReflectionMethod( \ACL\AgentRooms\Services\QueueService::class, 'register' );
		$lines  = file( $method->getFileName() );
		$body   = implode( '', array_slice( $lines, $method->getStartLine() - 1, $method->getEndLine() - $method->getStartLine() + 1 ) );
		$this->assert_true( false === strpos( $body, 'wp_schedule_event' ) && false === strpos( $body, "__( 'Every five" ), 'Register still schedules or translates during plugins_loaded.' );
		$this->assert_true( false !== strpos( $body, "add_action( 'init'" ), 'Recurring scheduling is not deferred to init.' );
		$this->assert_true( false !== strpos( $source, 'public function activate(): void' ), 'Activation scheduling seam is missing.' );
		$this->assert_true( did_action( 'init' ) > 0, 'Regression harness did not reach init.' );

		$before_tables = $this->schema_fingerprint();
		\ACL\AgentRooms\Installer::install();
		\ACL\AgentRooms\Installer::install();
		$this->assert_same( $before_tables, $this->schema_fingerprint(), 'Installer twice changed the schema fingerprint.' );

		\ACL\AgentRooms\Deactivator::deactivate();
		$this->assert_true( ! wp_next_scheduled( \ACL\AgentRooms\Services\QueueService::PENDING_HOOK ), 'Pending hook survived deactivation.' );
		$this->assert_true( ! wp_next_scheduled( \ACL\AgentRooms\Services\QueueService::MAINTENANCE_HOOK ), 'Maintenance hook survived deactivation.' );
		\ACL\AgentRooms\Activator::activate();
		\ACL\AgentRooms\Activator::activate();
		$this->assert_same( 1, $this->scheduled_count( \ACL\AgentRooms\Services\QueueService::PENDING_HOOK ), 'Pending hook duplicated on second activation.' );
		$this->assert_same( 1, $this->scheduled_count( \ACL\AgentRooms\Services\QueueService::MAINTENANCE_HOOK ), 'Maintenance hook duplicated on second activation.' );
		$this->assert_same( '1.4.1', (string) get_option( 'acl_ar_db_version' ), 'Activation did not retain database version 1.1.0.' );
	}

	private function scheduled_count( string $hook ): int {
		$count = 0;
		foreach ( (array) _get_cron_array() as $hooks ) {
			$count += isset( $hooks[ $hook ] ) ? count( $hooks[ $hook ] ) : 0;
		}
		return $count;
	}

	private function schema_fingerprint(): string {
		global $wpdb;
		$like = $wpdb->esc_like( $wpdb->prefix . 'acl_ar_' ) . '%';
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT TABLE_NAME,COLUMN_NAME,ORDINAL_POSITION,COLUMN_TYPE,IS_NULLABLE,COLUMN_DEFAULT,EXTRA FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=%s AND TABLE_NAME LIKE %s ORDER BY TABLE_NAME,ORDINAL_POSITION', DB_NAME, $like ), ARRAY_A );
		return hash( 'sha256', wp_json_encode( $rows ) );
	}
}
