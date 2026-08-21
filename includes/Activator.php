<?php
/**
 * Activation tasks.
 *
 * @package ACL_Agent_Rooms
 */

namespace ACL\AgentRooms;

use ACL\AgentRooms\Services\QueueService;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Activator {
	public static function activate(): void {
		Installer::install();
		Capabilities::add();
		( new QueueService() )->activate();
	}
}
