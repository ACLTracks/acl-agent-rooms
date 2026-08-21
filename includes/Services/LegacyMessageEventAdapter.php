<?php
/** Converts legacy messages into normalized event input. @package ACL_Agent_Rooms */

namespace ACL\AgentRooms\Services;

use ACL\AgentRooms\Models\RoomEvent;

if ( ! defined( 'ABSPATH' ) ) {
	exit; }

class LegacyMessageEventAdapter {
	public function convert( array $message ): array {
		$sender     = (string) ( $message['sender_type'] ?? '' );
		$actor_type = 'user';
		$actor_id   = isset( $message['sender_user_id'] ) ? (int) $message['sender_user_id'] : null;
		$event_type = RoomEvent::TYPE_MESSAGE;
		if ( 'agent' === $sender ) {
			$actor_type = 'agent';
			$actor_id   = isset( $message['sender_agent_id'] ) ? (int) $message['sender_agent_id'] : null;
		} elseif ( 'user' !== $sender ) {
			$actor_type = 'system';
			$actor_id   = null;
			$event_type = RoomEvent::TYPE_SYSTEM_NOTICE;
		}

		$metadata = array_filter(
			array(
				'legacy_sender_type' => $sender,
				'status'             => (string) ( $message['status'] ?? '' ),
				'provider_route'     => (string) ( $message['provider_route'] ?? '' ),
				'model'              => (string) ( $message['model'] ?? '' ),
				'prompt_tokens'      => (int) ( $message['prompt_tokens'] ?? 0 ),
				'completion_tokens'  => (int) ( $message['completion_tokens'] ?? 0 ),
				'total_tokens'       => (int) ( $message['total_tokens'] ?? 0 ),
				'legacy_metadata'    => is_array( $message['metadata'] ?? null ) ? $message['metadata'] : array(),
			),
			static fn( $value ): bool => is_array( $value ) ? ! empty( $value ) : '' !== $value && 0 !== $value
		);

		return array(
			'room_id'           => (int) ( $message['room_id'] ?? 0 ),
			'event_type'        => $event_type,
			'actor_type'        => $actor_type,
			'actor_id'          => $actor_id,
			'audience_type'     => 'room',
			'legacy_message_id' => (int) ( $message['id'] ?? 0 ),
			'parent_event_id'   => ! empty( $message['metadata']['reply_to_event_id'] ) ? (int) $message['metadata']['reply_to_event_id'] : null,
			'job_id'            => ! empty( $message['response_job_id'] ) ? (int) $message['response_job_id'] : null,
			'content'           => wp_strip_all_tags( (string) ( $message['content'] ?? '' ) ),
			'content_format'    => 'plain',
			'metadata'          => $metadata,
			'created_at'        => (string) ( $message['created_at'] ?? '' ),
		);
	}
}
