<?php
/**
 * Admin menu registration.
 *
 * @package ACL_Agent_Rooms
 */

namespace ACL\AgentRooms\Admin;

use ACL\AgentRooms\Capabilities;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class AdminMenu {
	private DashboardPage $dashboard_page;
	private RoomsPage $rooms_page;
	private AgentsPage $agents_page;
	private BrainsPage $brains_page;
	private SettingsPage $settings_page;
	private HealthPage $health_page;

	public function __construct() {
		$this->dashboard_page = new DashboardPage();
		$this->rooms_page     = new RoomsPage();
		$this->agents_page    = new AgentsPage();
		$this->brains_page    = new BrainsPage();
		$this->settings_page  = new SettingsPage();
		$this->health_page    = new HealthPage();
	}

	public function register(): void {
		add_menu_page(
			__( 'ACL Agent Rooms', 'acl-agent-rooms' ),
			__( 'ACL Agent Rooms', 'acl-agent-rooms' ),
			Capabilities::menu_cap( Capabilities::MANAGE_ALL_ROOMS ),
			'acl-agent-rooms',
			array( $this->dashboard_page, 'render' ),
			'dashicons-groups',
			58
		);

		add_submenu_page(
			'acl-agent-rooms',
			__( 'Dashboard', 'acl-agent-rooms' ),
			__( 'Dashboard', 'acl-agent-rooms' ),
			Capabilities::menu_cap( Capabilities::MANAGE_ALL_ROOMS ),
			'acl-agent-rooms',
			array( $this->dashboard_page, 'render' )
		);

		$rooms_hook = add_submenu_page(
			'acl-agent-rooms',
			__( 'Rooms', 'acl-agent-rooms' ),
			__( 'Rooms', 'acl-agent-rooms' ),
			Capabilities::menu_cap( Capabilities::MANAGE_ALL_ROOMS ),
			'acl-agent-rooms-rooms',
			array( $this->rooms_page, 'render' )
		);

		$agents_hook = add_submenu_page(
			'acl-agent-rooms',
			__( 'Agents', 'acl-agent-rooms' ),
			__( 'Agents', 'acl-agent-rooms' ),
			Capabilities::menu_cap( Capabilities::MANAGE_AGENTS ),
			'acl-agent-rooms-agents',
			array( $this->agents_page, 'render' )
		);

		$brains_hook = add_submenu_page(
			'acl-agent-rooms',
			__( 'Brains', 'acl-agent-rooms' ),
			__( 'Brains', 'acl-agent-rooms' ),
			Capabilities::menu_cap( Capabilities::MANAGE_AGENTS ),
			'acl-agent-rooms-brains',
			array( $this->brains_page, 'render' )
		);

		$settings_hook = add_submenu_page(
			'acl-agent-rooms',
			__( 'Settings', 'acl-agent-rooms' ),
			__( 'Settings', 'acl-agent-rooms' ),
			Capabilities::menu_cap( Capabilities::MANAGE_SETTINGS ),
			'acl-agent-rooms-settings',
			array( $this->settings_page, 'render' )
		);

		add_submenu_page( 'acl-agent-rooms', __( 'Health', 'acl-agent-rooms' ), __( 'Health', 'acl-agent-rooms' ), Capabilities::menu_cap( Capabilities::MANAGE_SETTINGS ), 'acl-agent-rooms-health', array( $this->health_page, 'render' ) );

		foreach (
			array(
				$rooms_hook    => array( $this->rooms_page, 'process_request' ),
				$agents_hook   => array( $this->agents_page, 'process_request' ),
				$brains_hook   => array( $this->brains_page, 'process_request' ),
				$settings_hook => array( $this->settings_page, 'process_request' ),
			) as $hook_suffix => $callback
		) {
			if ( is_string( $hook_suffix ) && '' !== $hook_suffix ) {
				add_action( 'load-' . $hook_suffix, $callback );
			}
		}

		/**
		 * Fires after the Free admin menu is available for add-on submenus.
		 *
		 * @param string                             $parent_slug Parent menu slug.
		 * @param \ACL\AgentRooms\ExtensionApi $api         Stable extension contract.
		 */
		do_action( 'acl_agent_rooms_admin_menu_registered', 'acl-agent-rooms', \ACL\AgentRooms\ExtensionApi::instance() );
	}

	public function enqueue_assets( string $hook ): void {
		if ( false === strpos( $hook, 'acl-agent-rooms' ) ) {
			return;
		}

		wp_enqueue_style( 'acl-agent-rooms-admin', ACL_AR_URL . 'assets/css/admin.css', array(), ACL_AR_VERSION );
		wp_enqueue_script( 'acl-agent-rooms-admin', ACL_AR_URL . 'assets/js/admin.js', array(), ACL_AR_VERSION, true );
		if ( false !== strpos( $hook, 'acl-agent-rooms-rooms' ) ) {
			wp_enqueue_script( 'acl-agent-rooms-room-files-admin', ACL_AR_URL . 'assets/js/admin-room-files.js', array( 'acl-agent-rooms-admin' ), ACL_AR_VERSION, true );
		}

		if ( false !== strpos( $hook, 'acl-agent-rooms-agents' ) ) {
			wp_enqueue_media();
		}
	}
}
