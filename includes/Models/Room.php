<?php
/**
 * Room model normalizer.
 *
 * @package ACL_Agent_Rooms
 */

namespace ACL\AgentRooms\Models;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Room {
	public static function from_row( $row ): array {
		$row = (array) $row;

		return array(
			'id'                                    => (int) ( $row['id'] ?? 0 ),
			'owner_user_id'                         => (int) ( $row['owner_user_id'] ?? 0 ),
			'title'                                 => (string) ( $row['title'] ?? '' ),
			'slug'                                  => (string) ( $row['slug'] ?? '' ),
			'description'                           => (string) ( $row['description'] ?? '' ),
			'top_context'                           => (string) ( $row['top_context'] ?? '' ),
			'type'                                  => (string) ( $row['type'] ?? 'solo' ),
			'visibility'                            => (string) ( $row['visibility'] ?? 'private' ),
			'status'                                => (string) ( $row['status'] ?? 'active' ),
			'agent_reply_mode'                      => (string) ( $row['agent_reply_mode'] ?? 'manual' ),
			'max_context_messages'                  => (int) ( $row['max_context_messages'] ?? 20 ),
			'max_agents_per_turn'                   => (int) ( $row['max_agents_per_turn'] ?? 1 ),
			'conversation_mode'                     => 'natural' === (string) ( $row['conversation_mode'] ?? 'immediate' ) ? 'natural' : 'immediate',
			'natural_min_responders'                => (int) ( $row['natural_min_responders'] ?? 1 ),
			'natural_max_responders'                => (int) ( $row['natural_max_responders'] ?? 2 ),
			'natural_initial_delay_min_ms'          => (int) ( $row['natural_initial_delay_min_ms'] ?? 1500 ),
			'natural_initial_delay_max_ms'          => (int) ( $row['natural_initial_delay_max_ms'] ?? 4500 ),
			'natural_inter_turn_delay_min_ms'       => (int) ( $row['natural_inter_turn_delay_min_ms'] ?? 2500 ),
			'natural_inter_turn_delay_max_ms'       => (int) ( $row['natural_inter_turn_delay_max_ms'] ?? 8000 ),
			'natural_allow_silence'                 => ! empty( $row['natural_allow_silence'] ),
			'natural_silence_chance'                => (int) ( $row['natural_silence_chance'] ?? 10 ),
			'natural_cancel_pending_on_new_message' => ! empty( $row['natural_cancel_pending_on_new_message'] ),
			'natural_max_pending_turns'             => (int) ( $row['natural_max_pending_turns'] ?? 4 ),
			'natural_steering_question_bias'        => (int) ( $row['natural_steering_question_bias'] ?? 35 ),
			'natural_active_trigger_event_id'       => isset( $row['natural_active_trigger_event_id'] ) ? (int) $row['natural_active_trigger_event_id'] : null,
			'allow_chat_clear'                      => ! empty( $row['allow_chat_clear'] ),
			'cleared_through_event_id'              => isset( $row['cleared_through_event_id'] ) ? (int) $row['cleared_through_event_id'] : null,
			'chat_cleared_at'                       => isset( $row['chat_cleared_at'] ) ? (string) $row['chat_cleared_at'] : null,
			'chat_cleared_by_user_id'               => isset( $row['chat_cleared_by_user_id'] ) ? (int) $row['chat_cleared_by_user_id'] : null,
			'project_instructions'                  => (string) ( $row['project_instructions'] ?? '' ),
			'room_files_enabled'                    => ! empty( $row['room_files_enabled'] ),
			'room_files_agent_access'               => ! empty( $row['room_files_agent_access'] ),
			'file_context_mode'                     => in_array( (string) ( $row['file_context_mode'] ?? 'hybrid' ), array( 'manual', 'automatic', 'hybrid' ), true ) ? (string) $row['file_context_mode'] : 'hybrid',
			'file_context_max_files'                => (int) ( $row['file_context_max_files'] ?? 5 ),
			'file_context_max_chars'                => (int) ( $row['file_context_max_chars'] ?? 12000 ),
			'created_at'                            => (string) ( $row['created_at'] ?? '' ),
			'updated_at'                            => (string) ( $row['updated_at'] ?? '' ),
		);
	}
}
