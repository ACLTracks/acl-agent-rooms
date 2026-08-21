<?php
// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange -- This file operates on plugin-owned operational tables and requires uncached transaction/read-after-write truth.
// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- Identifiers are fixed $wpdb->prefix suffixes, fixed internal schema fragments, or allowlisted names; runtime values use placeholders or wpdb mutation arrays.
// phpcs:disable PluginCheck.Security.DirectDB.UnescapedDBParameter -- PHPCS cannot model the fixed identifier provenance above; request data is never used as an SQL identifier.
/** Transactional moderation command service. @package ACL_Agent_Rooms */
namespace ACL\AgentRooms\Services;

use ACL\AgentRooms\Models\RoomEvent;
use ACL\AgentRooms\Repositories\EventRepository;
use ACL\AgentRooms\Repositories\MessageRepository;
use ACL\AgentRooms\Repositories\RoomRestrictionRepository;

if ( ! defined( 'ABSPATH' ) ) {
	exit; }

class ModerationService {
	public const PLACEHOLDER = 'Message removed by a moderator.';

	private AccessService $access;
	private ModerationPolicy $policy;
	private RoomRestrictionRepository $restrictions;
	private RoomEventService $events;
	private EventRepository $event_rows;
	private MessageRepository $messages;

	public function __construct( ?AccessService $access = null ) {
		$this->access       = $access ?: new AccessService();
		$this->restrictions = new RoomRestrictionRepository();
		$this->policy       = new ModerationPolicy( null, $this->restrictions );
		$this->events       = new RoomEventService();
		$this->event_rows   = new EventRepository();
		$this->messages     = new MessageRepository();
	}

	public function restrict( int $room_id, int $target_id, string $action, string $reason = '', ?string $expires_at = null ) {
		$actor = get_current_user_id();
		if ( ! $this->access->can_manage_room( $room_id, $actor ) ) {
			return new \WP_Error( 'acl_ar_moderation_forbidden', __( 'You cannot moderate this room.', 'acl-agent-rooms' ), array( 'status' => 403 ) );
		}

		$allowed = array(
			'mute'   => 'mute',
			'ban'    => 'ban',
			'unmute' => 'mute',
			'unban'  => 'ban',
		);
		if ( ! isset( $allowed[ $action ] ) ) {
			return new \WP_Error( 'acl_ar_moderation_action', __( 'Invalid moderation action.', 'acl-agent-rooms' ), array( 'status' => 400 ) );
		}

		$target_allowed = $this->policy->can_target( $room_id, $actor, $target_id );
		if ( is_wp_error( $target_allowed ) ) {
			return $target_allowed; }

		global $wpdb;
		$wpdb->query( 'START TRANSACTION' );
		$type   = $allowed[ $action ];
		$result = str_starts_with( $action, 'un' )
			? $this->restrictions->revoke( $room_id, $target_id, $type, $actor )
			: $this->restrictions->impose( $room_id, $target_id, $type, $actor, $reason, $expires_at );

		if ( is_wp_error( $result ) ) {
			$wpdb->query( 'ROLLBACK' );
			return $result;
		}

		$event = $this->events->create(
			array(
				'room_id'         => $room_id,
				'event_type'      => RoomEvent::TYPE_MODERATION,
				'actor_type'      => 'user',
				'actor_id'        => $actor,
				'target_type'     => 'user',
				'target_id'       => $target_id,
				'audience_type'   => 'moderators',
				'idempotency_key' => hash( 'sha256', 'moderation|' . $room_id . '|' . $target_id . '|' . $action . '|' . microtime( true ) ),
				'metadata'        => array(
					'action'     => $action,
					'reason'     => sanitize_textarea_field( $reason ),
					'expires_at' => $expires_at,
				),
			)
		);

		if ( is_wp_error( $event ) ) {
			$wpdb->query( 'ROLLBACK' );
			return $event;
		}

		$wpdb->query( 'COMMIT' );
		do_action( 'acl_ar_moderation_changed', $room_id, $target_id, $action );
		return array(
			'action'         => $action,
			'target_user_id' => $target_id,
			'event'          => $event,
		);
	}

	public function remove_message( int $room_id, int $event_id, string $reason = '' ) {
		$actor = get_current_user_id();
		if ( ! $this->access->can_manage_room( $room_id, $actor ) ) {
			return new \WP_Error( 'acl_ar_moderation_forbidden', __( 'You cannot moderate this room.', 'acl-agent-rooms' ), array( 'status' => 403 ) );
		}

		$event = $this->event_rows->find( $event_id );
		if ( ! $event || (int) $event['room_id'] !== $room_id || 'message' !== $event['event_type'] || ( new RoomCutoffPolicy() )->event_is_cleared( $event ) ) {
			return new \WP_Error( 'acl_ar_event_not_found', __( 'Message event not found.', 'acl-agent-rooms' ), array( 'status' => 404 ) );
		}
		if ( ! empty( $event['deleted_at'] ) ) {
			return array(
				'removed'   => true,
				'event_id'  => $event_id,
				'duplicate' => true,
			);
		}

		global $wpdb;
		$wpdb->query( 'START TRANSACTION' );
		$metadata = array(
			'removed_by' => $actor,
			'reason'     => sanitize_textarea_field( $reason ),
		);

		$ok = $this->event_rows->soft_delete( $event_id, self::PLACEHOLDER, $metadata );
		if ( $ok && ! empty( $event['legacy_message_id'] ) ) {
			$ok = $this->messages->redact( (int) $event['legacy_message_id'], self::PLACEHOLDER );
		}
		if ( $ok ) {
			$ok = ( new EventSearchIndexer() )->delete( $event_id );
		}
		if ( $ok ) {
			$ok = false !== $wpdb->delete( $wpdb->prefix . 'acl_ar_event_reactions', array( 'event_id' => $event_id ), array( '%d' ) );
		}

		$delete_event = $ok ? $this->events->create(
			array(
				'room_id'         => $room_id,
				'event_type'      => RoomEvent::TYPE_MESSAGE_DELETE,
				'actor_type'      => 'user',
				'actor_id'        => $actor,
				'audience_type'   => 'room',
				'parent_event_id' => $event_id,
				'idempotency_key' => hash( 'sha256', 'message-delete|' . $event_id ),
				'content'         => self::PLACEHOLDER,
				'metadata'        => array( 'reason' => 'moderator_removed' ),
			)
		) : new \WP_Error( 'acl_ar_message_remove_failed', __( 'The message could not be removed.', 'acl-agent-rooms' ) );

		if ( is_wp_error( $delete_event ) ) {
			$wpdb->query( 'ROLLBACK' );
			return $delete_event;
		}

		$wpdb->query( 'COMMIT' );
		return array(
			'removed'  => true,
			'event_id' => $event_id,
			'event'    => $delete_event,
		);
	}
}
