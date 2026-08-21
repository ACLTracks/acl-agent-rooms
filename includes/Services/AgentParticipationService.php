<?php
// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange -- This file operates on plugin-owned operational tables and requires uncached transaction/read-after-write truth.
// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- Identifiers are fixed $wpdb->prefix suffixes, fixed internal schema fragments, or allowlisted names; runtime values use placeholders or wpdb mutation arrays.
// phpcs:disable PluginCheck.Security.DirectDB.UnescapedDBParameter -- PHPCS cannot model the fixed identifier provenance above; request data is never used as an SQL identifier.
/** Central durable room-agent participation policy. @package ACL_Agent_Rooms */
namespace ACL\AgentRooms\Services;

use ACL\AgentRooms\Repositories\JobRepository;
use ACL\AgentRooms\Repositories\RoomRepository;
use ACL\AgentRooms\Support\Time;
if ( ! defined( 'ABSPATH' ) ) {
	exit;}
class AgentParticipationService {
	private RoomRepository $rooms;
	private JobRepository $jobs;
	private RoomEventService $events;
	public function __construct( ?RoomRepository $rooms = null, ?JobRepository $jobs = null, ?RoomEventService $events = null ) {
		$this->rooms  = $rooms ?: new RoomRepository();
		$this->jobs   = $jobs ?: new JobRepository();
		$this->events = $events ?: new RoomEventService();}
	public function policy( int $room_id, int $agent_id ): ?array {
		return $this->rooms->get_assignment( $room_id, $agent_id );}
	public function enforce( int $room_id, int $agent_id, string $trigger = 'explicit' ) {
		$p = $this->policy( $room_id, $agent_id );
		if ( ! $p ) {
			return new \WP_Error( 'acl_ar_agent_not_assigned', __( 'That agent is not assigned to this room.', 'acl-agent-rooms' ), array( 'status' => 400 ) );
		}if ( 'paused' === (string) $p['participation_state'] ) {
			return new \WP_Error( 'acl_ar_agent_paused', __( 'That agent is paused in this room.', 'acl-agent-rooms' ), array( 'status' => 423 ) );
		}if ( 'automatic' === $trigger && ! empty( $p['auto_muted'] ) ) {
			return new \WP_Error( 'acl_ar_agent_auto_muted', __( 'Automatic replies are muted for that agent.', 'acl-agent-rooms' ), array( 'status' => 409 ) );
		}return true;}
	public function filter_targets( int $room_id, array $agents, bool $automatic ): array {
		return array_values(
			array_filter(
				$agents,
				function ( $a ) use ( $room_id, $automatic ) {
					return ! is_wp_error( $this->enforce( $room_id, (int) $a['id'], $automatic ? 'automatic' : 'explicit' ) );
				}
			)
		);}
	public function change( int $room_id, int $agent_id, string $state, bool $muted, int $user_id, string $request_id ) {
		global $wpdb;
		$p = $this->policy( $room_id, $agent_id );
		if ( ! $p ) {
			return new \WP_Error( 'acl_ar_agent_not_assigned', __( 'That agent is not assigned to this room.', 'acl-agent-rooms' ), array( 'status' => 404 ) );
		}$state   = in_array( $state, array( 'active', 'paused' ), true ) ? $state : 'active';
		$key      = hash( 'sha256', 'agent-participation:' . $room_id . ':' . $agent_id . ':' . $request_id );
		$existing = ( new \ACL\AgentRooms\Repositories\EventRepository() )->find_by_idempotency_key( $key );
		if ( $existing ) {
			return $this->project( $room_id, $agent_id );
		}$table = $wpdb->prefix . 'acl_ar_room_agents';
		$wpdb->query( 'START TRANSACTION' );
		$ok = $wpdb->update(
			$table,
			array(
				'participation_state' => $state,
				'auto_muted'          => $muted ? 1 : 0,
				'state_updated_at'    => Time::mysql_gmt(),
			),
			array( 'id' => (int) $p['id'] ),
			array( '%s', '%d', '%s' ),
			array( '%d' )
		);
		if ( false === $ok ) {
			$wpdb->query( 'ROLLBACK' );
			return new \WP_Error( 'acl_ar_agent_participation_failed', __( 'Agent participation could not be updated.', 'acl-agent-rooms' ), array( 'status' => 500 ) );
		}if ( 'paused' === $state ) {
			$this->jobs->cancel_for_assignment( $room_id, $agent_id );
			( new ConversationTurnService() )->cancel_for_agent( $room_id, $agent_id, 'agent_paused' );
		}$event = $this->events->create(
			array(
				'room_id'         => $room_id,
				'event_type'      => 'presence_change',
				'actor_type'      => 'user',
				'actor_id'        => $user_id,
				'target_type'     => 'agent',
				'target_id'       => $agent_id,
				'audience_type'   => 'room',
				'idempotency_key' => $key,
				'content'         => null,
				'metadata'        => array(
					'participation_state' => $state,
					'auto_muted'          => $muted,
				),
			)
		);
		if ( is_wp_error( $event ) ) {
			$wpdb->query( 'ROLLBACK' );
			return $event;
		}$wpdb->query( 'COMMIT' );
		( new AgentStateReconciler() )->reconcile_assignment( $room_id, $agent_id );
		return $this->project( $room_id, $agent_id );}
	public function project( int $room_id, int $agent_id ): array {
		$p = $this->policy( $room_id, $agent_id ) ?: array();
		return array(
			'agent_id'      => $agent_id,
			'participation' => array(
				'state'             => (string) ( $p['participation_state'] ?? 'active' ),
				'auto_muted'        => ! empty( $p['auto_muted'] ),
				'can_manual_invoke' => 'paused' !== (string) ( $p['participation_state'] ?? 'active' ),
			),
		);}
}
