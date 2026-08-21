<?php
/** WordPress bootstrap for the deterministic Local integration harness. */

define( 'ACL_AR_TESTING', true );
$wp_load = dirname( __DIR__, 4 ) . '/wp-load.php';
if ( ! is_readable( $wp_load ) ) {
	fwrite( STDERR, "Unable to locate wp-load.php at {$wp_load}\n" );
	exit( 2 );
}
require_once $wp_load;
if ( ! function_exists( 'wp_delete_user' ) ) {
	require_once ABSPATH . 'wp-admin/includes/user.php';
}

if ( ! class_exists( 'ACL\\AgentRooms\\Plugin' ) ) {
	fwrite( STDERR, "ACL Agent Rooms is not active.\n" );
	exit( 2 );
}
