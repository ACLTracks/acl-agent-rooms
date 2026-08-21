<?php
/**
 * Message model normalizer.
 *
 * @package ACL_Agent_Rooms
 */

namespace ACL\AgentRooms\Models;

use ACL\AgentRooms\Support\Json;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Message {
	public static function from_row( $row ): array {
		$row = (array) $row;

		return array(
			'id'                => (int) ( $row['id'] ?? 0 ),
			'room_id'           => (int) ( $row['room_id'] ?? 0 ),
			'sender_type'       => (string) ( $row['sender_type'] ?? '' ),
			'sender_user_id'    => isset( $row['sender_user_id'] ) ? (int) $row['sender_user_id'] : null,
			'sender_agent_id'   => isset( $row['sender_agent_id'] ) ? (int) $row['sender_agent_id'] : null,
			'content'           => (string) ( $row['content'] ?? '' ),
			'status'            => (string) ( $row['status'] ?? 'sent' ),
			'client_request_id' => (string) ( $row['client_request_id'] ?? '' ),
			'response_job_id'   => isset( $row['response_job_id'] ) ? (int) $row['response_job_id'] : null,
			'brain_run_id'      => isset( $row['brain_run_id'] ) ? (int) $row['brain_run_id'] : null,
			'brain_agent_id'    => isset( $row['brain_agent_id'] ) ? (int) $row['brain_agent_id'] : null,
			'metadata'          => Json::decode( $row['metadata_json'] ?? null ),
			'provider_route'    => (string) ( $row['provider_route'] ?? '' ),
			'model'             => (string) ( $row['model'] ?? '' ),
			'prompt_tokens'     => (int) ( $row['prompt_tokens'] ?? 0 ),
			'completion_tokens' => (int) ( $row['completion_tokens'] ?? 0 ),
			'total_tokens'      => (int) ( $row['total_tokens'] ?? 0 ),
			'created_at'        => (string) ( $row['created_at'] ?? '' ),
		);
	}
}
