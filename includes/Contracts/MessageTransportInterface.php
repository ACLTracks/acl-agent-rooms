<?php
/**
 * Message transport contract.
 *
 * @package ACL_Agent_Rooms
 */

namespace ACL\AgentRooms\Contracts;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

interface MessageTransportInterface {
	public function mode( array $room ): string;

	public function config( array $room ): array;
}
