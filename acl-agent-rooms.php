<?php
/**
 * Plugin Name: ACL Agent Rooms
 * Description: Room-based conversations with provider-routed agents for WordPress.
 * Version:     1.5.0
 * Author:      ACL
 * Text Domain: acl-agent-rooms
 * Requires at least: 6.0
 * Tested up to: 7.1
 * Requires PHP: 8.0
 * License:     GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 *
 * @package ACL_Agent_Rooms
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'ACL_AR_VERSION', '1.5.0' );
define( 'ACL_AR_DB_VERSION', '1.4.1' );
define( 'ACL_AR_FILE', __FILE__ );
define( 'ACL_AR_DIR', plugin_dir_path( __FILE__ ) );
define( 'ACL_AR_URL', plugin_dir_url( __FILE__ ) );

spl_autoload_register(
	static function ( string $class ): void {
		$prefix = 'ACL\\AgentRooms\\';

		if ( 0 !== strpos( $class, $prefix ) ) {
			return;
		}

		$relative = substr( $class, strlen( $prefix ) );
		$file     = ACL_AR_DIR . 'includes/' . str_replace( '\\', '/', $relative ) . '.php';

		if ( is_readable( $file ) ) {
			require_once $file;
		}
	}
);

register_activation_hook( __FILE__, array( ACL\AgentRooms\Activator::class, 'activate' ) );
register_deactivation_hook( __FILE__, array( ACL\AgentRooms\Deactivator::class, 'deactivate' ) );

add_action(
	'plugins_loaded',
	static function (): void {
		ACL\AgentRooms\Plugin::instance()->init();
	}
);
