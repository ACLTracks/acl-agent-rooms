<?php
/**
 * Time helpers.
 *
 * @package ACL_Agent_Rooms
 */

namespace ACL\AgentRooms\Support;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Time {
	public static function mysql_gmt(): string {
		return current_time( 'mysql', true );
	}
}
