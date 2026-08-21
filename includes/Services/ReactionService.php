<?php
// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange -- This file operates on plugin-owned operational tables and requires uncached transaction/read-after-write truth.
// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- Identifiers are fixed $wpdb->prefix suffixes, fixed internal schema fragments, or allowlisted names; runtime values use placeholders or wpdb mutation arrays.
// phpcs:disable PluginCheck.Security.DirectDB.UnescapedDBParameter -- PHPCS cannot model the fixed identifier provenance above; request data is never used as an SQL identifier.
/** Reaction state and mutation event orchestration. @package ACL_Agent_Rooms */
namespace ACL\AgentRooms\Services;

use ACL\AgentRooms\Repositories\EventRepository;
use ACL\AgentRooms\Repositories\ReactionRepository;
if ( ! defined( 'ABSPATH' ) ) {
	exit;}
class ReactionService {
	private MessageInteractionPolicy $policy;
	private EventRepository $events;
	private ReactionRepository $reactions;
	private RoomEventService $room_events;
	private MessagePolicy $messages;
	public function __construct( ?MessageInteractionPolicy $policy = null, ?EventRepository $events = null, ?ReactionRepository $reactions = null ) {
		$this->events      = $events ?: new EventRepository();
		$this->reactions   = $reactions ?: new ReactionRepository();
		$this->policy      = $policy ?: new MessageInteractionPolicy( null, null, $this->events );
		$this->room_events = new RoomEventService( $this->events );
		$this->messages    = new MessagePolicy();}
	public function mutate( int $room_id, int $event_id, int $user_id, string $reaction, string $operation, string $request_id ) {
		if ( ! in_array( $reaction, MessageInteractionPolicy::reactions(), true ) ) {
			return new \WP_Error( 'acl_ar_invalid_reaction', __( 'That reaction is not allowed.', 'acl-agent-rooms' ), array( 'status' => 400 ) );
		}$target = $this->policy->target( $room_id, $event_id, $user_id, 'reaction' );
		if ( is_wp_error( $target ) ) {
			return $target;
		}$request_id = $this->messages->client_request_id( $request_id );
		if ( is_wp_error( $request_id ) || '' === $request_id ) {
			return is_wp_error( $request_id ) ? $request_id : new \WP_Error( 'acl_ar_invalid_client_request_id', __( 'Client request ID is required.', 'acl-agent-rooms' ), array( 'status' => 400 ) );
		}$operation = 'remove' === $operation ? 'remove' : 'add';
		$key        = hash( 'sha256', 'reaction:' . $room_id . ':' . $event_id . ':' . $user_id . ':' . $reaction . ':' . $operation . ':' . $request_id );
		$existing   = $this->events->find_by_idempotency_key( $key );
		global $wpdb;
		$wpdb->query( 'START TRANSACTION' );
		$ok       = 'add' === $operation ? $this->reactions->add( $event_id, $user_id, $reaction ) : $this->reactions->remove( $event_id, $user_id, $reaction );
		$mutation = $existing ?: $this->room_events->create(
			array(
				'room_id'         => $room_id,
				'event_type'      => 'reaction',
				'actor_type'      => 'user',
				'actor_id'        => $user_id,
				'audience_type'   => $target['audience_type'],
				'audience_id'     => $target['audience_id'],
				'parent_event_id' => $event_id,
				'idempotency_key' => $key,
				'content'         => null,
				'content_format'  => 'plain',
				'metadata'        => array(
					'reaction'  => $reaction,
					'operation' => $operation,
				),
			)
		);
		if ( ! $ok || is_wp_error( $mutation ) || apply_filters( 'acl_ar_interaction_mutation_fail', false, 'reaction', $event_id ) ) {
			$wpdb->query( 'ROLLBACK' );
			return new \WP_Error( 'acl_ar_reaction_failed', __( 'Reaction update failed and was rolled back.', 'acl-agent-rooms' ), array( 'status' => 500 ) );
		}$wpdb->query( 'COMMIT' );
		return array(
			'event'     => $this->events->find( (int) $mutation['id'] ),
			'reactions' => $this->reactions->summaries( array( $event_id ), $user_id )[ $event_id ] ?? array(),
			'duplicate' => (bool) $existing,
		);}
}
