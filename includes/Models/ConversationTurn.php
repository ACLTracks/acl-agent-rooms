<?php
/** Durable Natural Conversation turn DTO. @package ACL_Agent_Rooms */
namespace ACL\AgentRooms\Models;

if ( ! defined( 'ABSPATH' ) ) {
	exit; }

class ConversationTurn {
	public const SOURCES  = array( 'brain', 'independent' );
	public const STATUSES = array( 'pending', 'typing', 'publishing', 'published', 'canceled', 'failed' );
	public const PURPOSES = array( 'reply', 'follow_up', 'steer' );

	public static function from_row( $row ): array {
		$row = (array) $row;
		return array(
			'id'                 => (int) ( $row['id'] ?? 0 ),
			'room_id'            => (int) ( $row['room_id'] ?? 0 ),
			'trigger_event_id'   => (int) ( $row['trigger_event_id'] ?? 0 ),
			'agent_id'           => (int) ( $row['agent_id'] ?? 0 ),
			'brain_run_id'       => isset( $row['brain_run_id'] ) ? (int) $row['brain_run_id'] : null,
			'job_id'             => isset( $row['job_id'] ) ? (int) $row['job_id'] : null,
			'source_type'        => (string) ( $row['source_type'] ?? '' ),
			'status'             => (string) ( $row['status'] ?? 'pending' ),
			'purpose'            => (string) ( $row['purpose'] ?? 'reply' ),
			'content'            => isset( $row['content'] ) ? (string) $row['content'] : null,
			'due_at'             => (string) ( $row['due_at'] ?? '' ),
			'typing_at'          => $row['typing_at'] ?? null,
			'published_event_id' => isset( $row['published_event_id'] ) ? (int) $row['published_event_id'] : null,
			'idempotency_key'    => (string) ( $row['idempotency_key'] ?? '' ),
			'cancel_reason'      => isset( $row['cancel_reason'] ) ? (string) $row['cancel_reason'] : null,
			'created_at'         => (string) ( $row['created_at'] ?? '' ),
			'updated_at'         => (string) ( $row['updated_at'] ?? '' ),
		);
	}
}
