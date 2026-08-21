<?php
// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange -- This file operates on plugin-owned operational tables and requires uncached transaction/read-after-write truth.
// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- Identifiers are fixed $wpdb->prefix suffixes, fixed internal schema fragments, or allowlisted names; runtime values use placeholders or wpdb mutation arrays.
// phpcs:disable PluginCheck.Security.DirectDB.UnescapedDBParameter -- PHPCS cannot model the fixed identifier provenance above; request data is never used as an SQL identifier.
/** Immutable edit plus legacy and search reconciliation. @package ACL_Agent_Rooms */
namespace ACL\AgentRooms\Services;

use ACL\AgentRooms\Repositories\EventRepository;
use ACL\AgentRooms\Repositories\MessageRepository;

if ( ! defined( 'ABSPATH' ) ) {
	exit; }

class MessageEditService {
	private MessageInteractionPolicy $policy;
	private EventRepository $events;
	private MessageRepository $messages;
	private RoomEventService $room_events;
	private MessagePolicy $messages_policy;

	public function __construct( ?MessageInteractionPolicy $policy = null, ?EventRepository $events = null, ?MessageRepository $messages = null ) {
		$this->events          = $events ?: new EventRepository();
		$this->messages        = $messages ?: new MessageRepository();
		$this->policy          = $policy ?: new MessageInteractionPolicy( null, null, $this->events );
		$this->room_events     = new RoomEventService( $this->events );
		$this->messages_policy = new MessagePolicy();
	}

	public function edit( int $room_id, int $event_id, int $user_id, string $raw, string $request_id ) {
		$original = $this->policy->target( $room_id, $event_id, $user_id, 'edit' );
		if ( is_wp_error( $original ) ) {
			return $original; }

		$content = $this->messages_policy->normalize( $raw, $user_id, $room_id );
		if ( is_wp_error( $content ) ) {
			return $content; }

		$request_id = $this->messages_policy->client_request_id( $request_id );
		if ( is_wp_error( $request_id ) || '' === $request_id ) {
			return is_wp_error( $request_id ) ? $request_id : new \WP_Error( 'acl_ar_invalid_client_request_id', __( 'Client request ID is required.', 'acl-agent-rooms' ), array( 'status' => 400 ) );
		}

		$key      = hash( 'sha256', 'message-edit:' . $room_id . ':' . $event_id . ':' . $user_id . ':' . $request_id );
		$existing = $this->events->find_by_idempotency_key( $key );
		global $wpdb;
		$wpdb->query( 'START TRANSACTION' );

		$edit = $existing ?: $this->room_events->create(
			array(
				'room_id'         => $room_id,
				'event_type'      => 'message_edit',
				'actor_type'      => 'user',
				'actor_id'        => $user_id,
				'audience_type'   => $original['audience_type'],
				'audience_id'     => $original['audience_id'],
				'parent_event_id' => $event_id,
				'idempotency_key' => $key,
				'content'         => $content,
				'content_format'  => 'plain',
			)
		);

		if ( $existing ) {
			$content = (string) $existing['content']; }
		$search_event            = $original;
		$search_event['content'] = $content;

		if (
			is_wp_error( $edit )
			|| empty( $original['legacy_message_id'] )
			|| apply_filters( 'acl_ar_interaction_mutation_fail', false, 'edit_event', $event_id )
			|| ! $this->messages->update_content( (int) $original['legacy_message_id'], $content )
			|| ! ( new EventSearchIndexer() )->index_with_content( $search_event, $content )
			|| apply_filters( 'acl_ar_interaction_mutation_fail', false, 'edit_legacy', $event_id )
		) {
			$wpdb->query( 'ROLLBACK' );
			return new \WP_Error( 'acl_ar_event_edit_failed', __( 'Message edit failed and was rolled back.', 'acl-agent-rooms' ), array( 'status' => 500 ) );
		}

		$wpdb->query( 'COMMIT' );
		return array(
			'event'     => $this->events->find( (int) $edit['id'] ),
			'original'  => $original,
			'content'   => $content,
			'duplicate' => (bool) $existing,
		);
	}
}
