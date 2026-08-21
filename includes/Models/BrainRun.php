<?php
/** Shared Brain run DTO. @package ACL_Agent_Rooms */
namespace ACL\AgentRooms\Models;

use ACL\AgentRooms\Support\Json;

if ( ! defined( 'ABSPATH' ) ) {
	exit; }

class BrainRun {
	public const STATUSES = array( 'pending', 'running', 'response_saved', 'completed', 'failed', 'canceled' );
	public static function from_row( $row ): array {
		$row = (array) $row;
		return array(
			'id'                  => (int) ( $row['id'] ?? 0 ),
			'room_id'             => (int) ( $row['room_id'] ?? 0 ),
			'brain_id'            => (int) ( $row['brain_id'] ?? 0 ),
			'trigger_event_id'    => (int) ( $row['trigger_event_id'] ?? 0 ),
			'request_key'         => (string) ( $row['request_key'] ?? '' ),
			'status'              => (string) ( $row['status'] ?? 'pending' ),
			'target_agent_ids'    => array_values( array_map( 'absint', Json::decode( $row['target_agent_ids_json'] ?? null ) ) ),
			'validated_responses' => Json::decode( $row['validated_response_json'] ?? null ),
			'response_event_ids'  => array_values( array_map( 'absint', Json::decode( $row['response_event_ids_json'] ?? null ) ) ),
			'provider'            => (string) ( $row['provider'] ?? '' ),
			'model'               => (string) ( $row['model'] ?? '' ),
			'attempts'            => (int) ( $row['attempts'] ?? 0 ),
			'lease_token'         => (string) ( $row['lease_token'] ?? '' ),
			'locked_at'           => $row['locked_at'] ?? null,
			'next_attempt_at'     => $row['next_attempt_at'] ?? null,
			'prompt_tokens'       => (int) ( $row['prompt_tokens'] ?? 0 ),
			'completion_tokens'   => (int) ( $row['completion_tokens'] ?? 0 ),
			'total_tokens'        => (int) ( $row['total_tokens'] ?? 0 ),
			'estimated_cost'      => (float) ( $row['estimated_cost'] ?? 0 ),
			'error_code'          => (string) ( $row['error_code'] ?? '' ),
			'public_error'        => (string) ( $row['public_error'] ?? '' ),
			'created_at'          => (string) ( $row['created_at'] ?? '' ),
			'started_at'          => $row['started_at'] ?? null,
			'completed_at'        => $row['completed_at'] ?? null,
			'updated_at'          => (string) ( $row['updated_at'] ?? '' ),
		);
	}
}
