<?php
/**
 * Deactivation tasks.
 *
 * @package ACL_Agent_Rooms
 */

namespace ACL\AgentRooms;

use ACL\AgentRooms\Services\QueueService;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Deactivator {
	public static function deactivate(): void {
		wp_clear_scheduled_hook( QueueService::PENDING_HOOK );
		wp_clear_scheduled_hook( QueueService::SINGLE_HOOK );
		wp_clear_scheduled_hook( QueueService::BACKFILL_HOOK );
		wp_clear_scheduled_hook( QueueService::SEARCH_BACKFILL_HOOK );
		wp_clear_scheduled_hook( QueueService::MAINTENANCE_HOOK );
		wp_clear_scheduled_hook( QueueService::TURN_HOOK );
		wp_clear_scheduled_hook( QueueService::TYPING_HOOK );
		wp_clear_scheduled_hook( QueueService::TURN_WORKER_HOOK );
	}
}
