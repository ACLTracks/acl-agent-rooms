<?php
/**
 * Plugin coordinator.
 *
 * @package ACL_Agent_Rooms
 */

namespace ACL\AgentRooms;

use ACL\AgentRooms\Admin\AdminMenu;
use ACL\AgentRooms\Rest\RestServiceProvider;
use ACL\AgentRooms\Services\QueueService;
use ACL\AgentRooms\Shortcodes\AgentRoomShortcode;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Plugin {
	private static ?self $instance = null;

	private bool $initialized = false;

	public static function instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	public function init(): void {
		if ( $this->initialized ) {
			return;
		}

		$this->initialized = true;

		Installer::maybe_upgrade();

		add_action( 'init', array( $this, 'register_shortcodes' ) );
		add_action( 'rest_api_init', array( new RestServiceProvider(), 'register_routes' ) );
		( new QueueService() )->register();

		if ( is_admin() ) {
			$admin = new AdminMenu();
			add_action( 'admin_init', array( Capabilities::class, 'repair_admin_caps' ), 1 );
			add_action( 'admin_init', array( $this, 'register_privacy_policy_content' ) );
			add_action( 'admin_menu', array( $admin, 'register' ) );
			add_action( 'admin_enqueue_scripts', array( $admin, 'enqueue_assets' ) );
		}

		/**
		 * Fires once after the complete Free runtime has registered its core hooks.
		 *
		 * @param ExtensionApi $api Stable public extension contract.
		 */
		do_action( 'acl_agent_rooms_loaded', ExtensionApi::instance() );
	}

	public function register_shortcodes(): void {
		( new AgentRoomShortcode() )->register();
	}

	public function register_privacy_policy_content(): void {
		if ( ! function_exists( 'wp_add_privacy_policy_content' ) ) {
			return;
		}

		$content  = '<p>' . esc_html__( 'ACL Agent Rooms stores room, membership, message, event, presence, moderation, usage, and operational data in this WordPress database.', 'acl-agent-rooms' ) . '</p>';
		$content .= '<p>' . esc_html__( 'When an agent reply is requested, ACL Agent Rooms sends the configured agent instructions, room name and context, the relevant visible message history, the triggering message, selected project-file excerpts when enabled, provider and model identifiers, and operational identifiers to ACL Switchboard. ACL Switchboard may transmit that material to the AI provider configured by the site administrator. Shared Brain history can identify participants by their local numeric WordPress user IDs. Provider credentials remain in ACL Switchboard and are not stored by ACL Agent Rooms.', 'acl-agent-rooms' ) . '</p>';
		$content .= '<p>' . esc_html__( 'Private whispers are stored as audience-restricted events and are not included in the legacy message history used for agent context. Site administrators should document the privacy terms and retention behavior of every AI provider they configure through ACL Switchboard.', 'acl-agent-rooms' ) . '</p>';

		wp_add_privacy_policy_content( __( 'ACL Agent Rooms', 'acl-agent-rooms' ), wp_kses_post( $content ) );
	}

	private function __construct() {}

	private function __clone() {}

	public function __wakeup(): void {
		throw new \RuntimeException( 'Cannot deserialize ACL Agent Rooms.' );
	}
}
