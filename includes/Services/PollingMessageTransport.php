<?php
/**
 * Polling message transport.
 *
 * @package ACL_Agent_Rooms
 */

namespace ACL\AgentRooms\Services;

use ACL\AgentRooms\Contracts\MessageTransportInterface;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class PollingMessageTransport implements MessageTransportInterface {
	public function mode( array $room ): string {
		return (string) apply_filters( 'acl_ar_message_transport_mode', 'polling', $room );
	}

	public function config( array $room ): array {
		return (array) apply_filters(
			'acl_ar_message_transport_config',
			array(
				'active_poll_delay'  => 3000,
				'idle_poll_delay'    => 6000,
				'maximum_idle_delay' => 24000,
				'page_size'          => 50,
				'capabilities'       => array(
					'events'  => true,
					'history' => true,
				),
			),
			$room
		);
	}
}
