<?php
/**
 * Switchboard client contract.
 *
 * @package ACL_Agent_Rooms
 */

namespace ACL\AgentRooms\Contracts;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

interface SwitchboardClientInterface {
	/**
	 * Send a normalized provider request.
	 *
	 * @param array<string,mixed> $request Request data.
	 * @return array<string,mixed>|\WP_Error
	 */
	public function send( array $request );
}
