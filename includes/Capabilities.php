<?php
/**
 * Capability registration.
 *
 * @package ACL_Agent_Rooms
 */

namespace ACL\AgentRooms;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Capabilities {
	public const USE_ROOMS        = 'acl_ar_use_rooms';
	public const CREATE_ROOMS     = 'acl_ar_create_rooms';
	public const MANAGE_OWN_ROOMS = 'acl_ar_manage_own_rooms';
	public const MANAGE_AGENTS    = 'acl_ar_manage_agents';
	public const MANAGE_ALL_ROOMS = 'acl_ar_manage_all_rooms';
	public const MANAGE_SETTINGS  = 'acl_ar_manage_settings';

	public static function all(): array {
		return array(
			self::USE_ROOMS,
			self::CREATE_ROOMS,
			self::MANAGE_OWN_ROOMS,
			self::MANAGE_AGENTS,
			self::MANAGE_ALL_ROOMS,
			self::MANAGE_SETTINGS,
		);
	}

	public static function add(): void {
		$admin = get_role( 'administrator' );
		if ( $admin ) {
			foreach ( self::all() as $capability ) {
				$admin->add_cap( $capability );
			}
		}

		foreach ( array( 'subscriber', 'customer', 'member' ) as $role_name ) {
			$role = get_role( $role_name );
			if ( $role ) {
				$role->add_cap( self::USE_ROOMS );
			}
		}
	}

	public static function repair_admin_caps(): void {
		$admin = get_role( 'administrator' );
		if ( ! $admin ) {
			return;
		}

		foreach ( self::all() as $capability ) {
			if ( ! $admin->has_cap( $capability ) ) {
				$admin->add_cap( $capability );
			}
		}
	}

	public static function current_user_can( string $capability ): bool {
		return current_user_can( $capability ) || current_user_can( 'manage_options' );
	}

	public static function menu_cap( string $capability ): string {
		if ( current_user_can( $capability ) ) {
			return $capability;
		}

		return current_user_can( 'manage_options' ) ? 'manage_options' : $capability;
	}
}
