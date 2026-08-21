<?php
/**
 * Normalized room event DTO.
 *
 * @package ACL_Agent_Rooms
 */

namespace ACL\AgentRooms\Models;

use ACL\AgentRooms\Support\Json;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class RoomEvent {
	public const ACTOR_TYPES           = array( 'user', 'agent', 'system' );
	public const AUDIENCE_TYPES        = array( 'room', 'user', 'agent', 'moderators' );
	public const CONTENT_FORMATS       = array( 'plain' );
	public const TYPE_MESSAGE          = 'message';
	public const TYPE_SYSTEM_NOTICE    = 'system_notice';
	public const TYPE_AGENT_QUEUED     = 'agent_queued';
	public const TYPE_AGENT_THINKING   = 'agent_thinking';
	public const TYPE_AGENT_RESPONDING = 'agent_responding';
	public const TYPE_AGENT_COMPLETED  = 'agent_completed';
	public const TYPE_AGENT_FAILED     = 'agent_failed';
	public const TYPE_MESSAGE_EDIT     = 'message_edit';
	public const TYPE_REACTION         = 'reaction';
	public const TYPE_ACTION           = 'action';
	public const TYPE_DICE_ROLL        = 'dice_roll';
	public const TYPE_COIN_FLIP        = 'coin_flip';
	public const TYPE_WHISPER          = 'whisper';
	public const TYPE_PRESENCE_CHANGE  = 'presence_change';
	public const TYPE_MODERATION       = 'moderation';
	public const TYPE_MESSAGE_DELETE   = 'message_delete';
	public const TYPE_BRAIN_RUN        = 'brain_run';
	public const TYPE_ROOM_CLEAR       = 'room_clear';
	public const TARGET_TYPES          = array( 'user', 'agent', 'system', 'brain', 'room' );
	public const EVENT_TYPES           = array(
		self::TYPE_MESSAGE,
		self::TYPE_SYSTEM_NOTICE,
		self::TYPE_AGENT_QUEUED,
		self::TYPE_AGENT_THINKING,
		self::TYPE_AGENT_RESPONDING,
		self::TYPE_AGENT_COMPLETED,
		self::TYPE_AGENT_FAILED,
		self::TYPE_MESSAGE_EDIT,
		self::TYPE_REACTION,
		self::TYPE_ACTION,
		self::TYPE_DICE_ROLL,
		self::TYPE_COIN_FLIP,
		self::TYPE_WHISPER,
		self::TYPE_PRESENCE_CHANGE,
		self::TYPE_MODERATION,
		self::TYPE_MESSAGE_DELETE,
		self::TYPE_BRAIN_RUN,
		self::TYPE_ROOM_CLEAR,
	);

	public static function from_row( $row ): array {
		$row = (array) $row;
		return array(
			'id'                => (int) ( $row['id'] ?? 0 ),
			'room_id'           => (int) ( $row['room_id'] ?? 0 ),
			'event_type'        => (string) ( $row['event_type'] ?? '' ),
			'actor_type'        => (string) ( $row['actor_type'] ?? '' ),
			'actor_id'          => isset( $row['actor_id'] ) ? (int) $row['actor_id'] : null,
			'target_type'       => isset( $row['target_type'] ) ? (string) $row['target_type'] : null,
			'target_id'         => isset( $row['target_id'] ) ? (int) $row['target_id'] : null,
			'audience_type'     => (string) ( $row['audience_type'] ?? 'room' ),
			'audience_id'       => isset( $row['audience_id'] ) ? (int) $row['audience_id'] : null,
			'parent_event_id'   => isset( $row['parent_event_id'] ) ? (int) $row['parent_event_id'] : null,
			'legacy_message_id' => isset( $row['legacy_message_id'] ) ? (int) $row['legacy_message_id'] : null,
			'job_id'            => isset( $row['job_id'] ) ? (int) $row['job_id'] : null,
			'idempotency_key'   => (string) ( $row['idempotency_key'] ?? '' ),
			'content'           => (string) ( $row['content'] ?? '' ),
			'content_format'    => (string) ( $row['content_format'] ?? 'plain' ),
			'metadata'          => Json::decode( $row['metadata_json'] ?? null ),
			'created_at'        => (string) ( $row['created_at'] ?? '' ),
			'edited_at'         => isset( $row['edited_at'] ) ? (string) $row['edited_at'] : null,
			'deleted_at'        => isset( $row['deleted_at'] ) ? (string) $row['deleted_at'] : null,
		);
	}
}
