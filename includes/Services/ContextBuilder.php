<?php
/**
 * Agent context builder.
 *
 * @package ACL_Agent_Rooms
 */

namespace ACL\AgentRooms\Services;

use ACL\AgentRooms\Repositories\MessageRepository;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ContextBuilder {
	private MessageRepository $messages;

	public function __construct( ?MessageRepository $messages = null ) {
		$this->messages = $messages ?: new MessageRepository();
	}

	public function build( array $room, array $agent ): array {
		$limit      = max( 1, (int) ( $room['max_context_messages'] ?? 20 ) );
		$rows       = $this->messages->context_for_room( (int) $room['id'], $limit );
		$items      = array();
		$max_bytes  = max( 1024, (int) apply_filters( 'acl_ar_agent_context_max_bytes', 65536, $room, $agent ) );
		$used_bytes = 0;

		foreach ( array_reverse( $rows ) as $message ) {
			if ( 'system' === (string) $message['sender_type'] ) {
				continue;
			}
			$content   = wp_strip_all_tags( (string) $message['content'] );
			$remaining = $max_bytes - $used_bytes;
			if ( $remaining <= 0 ) {
				break;
			}
			if ( strlen( $content ) > $remaining ) {
				$content = function_exists( 'mb_strcut' ) ? mb_strcut( $content, 0, $remaining, 'UTF-8' ) : substr( $content, 0, $remaining );
			}
			if ( '' === $content ) {
				continue;
			}

			$items[]     = array(
				'role'    => 'agent' === (string) $message['sender_type'] ? 'assistant' : 'user',
				'content' => $content,
			);
			$used_bytes += strlen( $content );
		}

		return apply_filters( 'acl_ar_agent_context_messages', array_reverse( $items ), $room, $agent );
	}
}
