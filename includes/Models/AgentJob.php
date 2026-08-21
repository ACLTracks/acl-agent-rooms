<?php
/**
 * Agent job model normalizer.
 *
 * @package ACL_Agent_Rooms
 */

namespace ACL\AgentRooms\Models;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class AgentJob {
	public static function from_row( $row ): array {
		$row = (array) $row;

		return array(
			'id'                  => (int) ( $row['id'] ?? 0 ),
			'room_id'             => (int) ( $row['room_id'] ?? 0 ),
			'trigger_message_id'  => (int) ( $row['trigger_message_id'] ?? 0 ),
			'agent_id'            => (int) ( $row['agent_id'] ?? 0 ),
			'request_key'         => (string) ( $row['request_key'] ?? '' ),
			'status'              => (string) ( $row['status'] ?? 'pending' ),
			'attempts'            => (int) ( $row['attempts'] ?? 0 ),
			'retryable'           => ! empty( $row['retryable'] ),
			'error_code'          => (string) ( $row['error_code'] ?? '' ),
			'error_message'       => (string) ( $row['error_message'] ?? '' ),
			'public_error'        => (string) ( $row['public_error'] ?? '' ),
			'response_message_id' => isset( $row['response_message_id'] ) ? (int) $row['response_message_id'] : null,
			'lease_token'         => (string) ( $row['lease_token'] ?? '' ),
			'locked_at'           => (string) ( $row['locked_at'] ?? '' ),
			'lease_expires_at'    => (string) ( $row['lease_expires_at'] ?? '' ),
			'next_attempt_at'     => (string) ( $row['next_attempt_at'] ?? '' ),
			'completed_at'        => (string) ( $row['completed_at'] ?? '' ),
			'created_at'          => (string) ( $row['created_at'] ?? '' ),
			'updated_at'          => (string) ( $row['updated_at'] ?? '' ),
		);
	}
}
