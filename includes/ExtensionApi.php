<?php
/**
 * Public extension contract for ACL Agent Rooms add-ons.
 *
 * @package ACL_Agent_Rooms
 */

namespace ACL\AgentRooms;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Narrow, versioned access to stable integration metadata.
 *
 * Add-ons should use this contract and the documented extension hooks instead
 * of reaching into private coordinator state.
 */
final class ExtensionApi {
	public const API_VERSION = 1;

	private static ?self $instance = null;

	/** @var array<string,string> */
	private const REPORTING_TABLES = array(
		'agents'           => 'acl_ar_agents',
		'brain_runs'       => 'acl_ar_brain_runs',
		'events'           => 'acl_ar_events',
		'jobs'             => 'acl_ar_agent_jobs',
		'maintenance_runs' => 'acl_ar_maintenance_runs',
		'restrictions'     => 'acl_ar_room_restrictions',
		'rooms'            => 'acl_ar_rooms',
		'usage'            => 'acl_ar_usage',
	);

	public static function instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	public function api_version(): int {
		return self::API_VERSION;
	}

	public function plugin_version(): string {
		return ACL_AR_VERSION;
	}

	public function database_version(): string {
		return ACL_AR_DB_VERSION;
	}

	public function rest_namespace(): string {
		return 'acl-agent-rooms/v1';
	}

	public function admin_parent_slug(): string {
		return 'acl-agent-rooms';
	}

	public function capability( string $name ): string {
		$capabilities = array(
			'use_rooms'        => Capabilities::USE_ROOMS,
			'manage_agents'    => Capabilities::MANAGE_AGENTS,
			'manage_all_rooms' => Capabilities::MANAGE_ALL_ROOMS,
			'manage_settings'  => Capabilities::MANAGE_SETTINGS,
		);

		if ( ! isset( $capabilities[ $name ] ) ) {
			throw new \InvalidArgumentException( 'Unknown ACL Agent Rooms capability contract.' );
		}

		return $capabilities[ $name ];
	}

	public function current_user_can( string $name ): bool {
		return Capabilities::current_user_can( $this->capability( $name ) );
	}

	public function reporting_table( string $name ): string {
		if ( ! isset( self::REPORTING_TABLES[ $name ] ) ) {
			throw new \InvalidArgumentException( 'Unknown ACL Agent Rooms reporting table contract.' );
		}

		global $wpdb;
		return $wpdb->prefix . self::REPORTING_TABLES[ $name ];
	}

	private function __construct() {}
}
