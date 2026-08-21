<?php
// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange -- This file operates on plugin-owned operational tables and requires uncached transaction/read-after-write truth.
// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- Identifiers are fixed $wpdb->prefix suffixes, fixed internal schema fragments, or allowlisted names; runtime values use placeholders or wpdb mutation arrays.
// phpcs:disable PluginCheck.Security.DirectDB.UnescapedDBParameter -- PHPCS cannot model the fixed identifier provenance above; request data is never used as an SQL identifier.
/** Schedules, projects, publishes, and supersedes Natural Conversation turns. @package ACL_Agent_Rooms */
namespace ACL\AgentRooms\Services;

use ACL\AgentRooms\Repositories\AgentRepository;
use ACL\AgentRooms\Repositories\BrainRunRepository;
use ACL\AgentRooms\Repositories\ConversationTurnRepository;
use ACL\AgentRooms\Repositories\EventRepository;
use ACL\AgentRooms\Repositories\JobRepository;
use ACL\AgentRooms\Repositories\MessageRepository;
use ACL\AgentRooms\Repositories\PresenceRepository;
use ACL\AgentRooms\Repositories\RoomRepository;
use ACL\AgentRooms\Support\Time;

if ( ! defined( 'ABSPATH' ) ) {
	exit; }

class ConversationTurnService {
	private ConversationTurnRepository $turns;
	private RoomRepository $rooms;
	private AgentRepository $agents;
	private JobRepository $jobs;
	private BrainRunRepository $runs;
	private MessageRepository $messages;
	private EventRepository $events;
	private PresenceRepository $presence;
	private RoomEventService $room_events;
	public function __construct( ?ConversationTurnRepository $turns = null ) {
		$this->turns       = $turns ?: new ConversationTurnRepository();
		$this->rooms       = new RoomRepository();
		$this->agents      = new AgentRepository();
		$this->jobs        = new JobRepository();
		$this->runs        = new BrainRunRepository();
		$this->messages    = new MessageRepository();
		$this->events      = new EventRepository();
		$this->presence    = new PresenceRepository();
		$this->room_events = new RoomEventService( $this->events ); }

	public function activate_trigger( array $room, int $trigger_event_id ): int {
		global $wpdb;
		$wpdb->update(
			$wpdb->prefix . 'acl_ar_rooms',
			array(
				'natural_active_trigger_event_id' => $trigger_event_id,
				'updated_at'                      => Time::mysql_gmt(),
			),
			array( 'id' => (int) $room['id'] ),
			array( '%d', '%s' ),
			array( '%d' )
		);
		$older    = ! empty( $room['natural_cancel_pending_on_new_message'] ) ? $this->turns->active_older_than( (int) $room['id'], $trigger_event_id ) : array();
		$canceled = ! empty( $room['natural_cancel_pending_on_new_message'] ) ? $this->turns->cancel_older_triggers( (int) $room['id'], $trigger_event_id ) : 0;
		foreach ( $older as $turn ) {
			if ( ! empty( $turn['job_id'] ) ) {
				$this->jobs->cancel( (int) $turn['job_id'], 'superseded' ); }
		}
		if ( $canceled ) {
			$this->clear_inactive_typing( (int) $room['id'] );
			$this->finalize_ready_brains( (int) $room['id'] ); }
		return $canceled;
	}

	public function create( array $room, array $trigger_event, array $planned, string $source, int $brain_run_id = 0, int $job_id = 0 ) {
		$agent = (array) ( $planned['agent'] ?? array() );
		$turn  = $this->turns->create(
			array(
				'room_id'          => (int) $room['id'],
				'trigger_event_id' => (int) $trigger_event['id'],
				'agent_id'         => (int) $agent['id'],
				'brain_run_id'     => $brain_run_id ?: null,
				'job_id'           => $job_id ?: null,
				'source_type'      => $source,
				'purpose'          => (string) ( $planned['purpose'] ?? 'reply' ),
				'due_at'           => (string) $planned['due_at'],
				'typing_at'        => $planned['typing_at'] ?? null,
			)
		);
		if ( ! is_wp_error( $turn ) && in_array( (string) ( $turn['status'] ?? '' ), array( 'pending', 'typing' ), true ) && ! ( new QueueService() )->enqueue_turn( $turn ) ) {
			return new \WP_Error( 'acl_ar_conversation_turn_enqueue_failed', __( 'The conversation turn was saved but could not be scheduled immediately.', 'acl-agent-rooms' ) );
		}
		return $turn;
	}

	public function mark_typing( int $turn_id ): void {
		$turn = $this->turns->find( $turn_id );
		if ( ! $turn || 'pending' !== $turn['status'] || ! $this->valid( $turn ) ) {
			return;
		} if ( $this->turns->mark_typing( $turn_id ) ) {
			$this->presence->upsert(
				(int) $turn['room_id'],
				'agent',
				(int) $turn['agent_id'],
				'typing',
				null,
				0,
				(string) $turn['due_at'],
				array(
					'conversation_turn_id' => $turn_id,
					'runtime'              => 'natural',
				)
			); } }

	public function publish( int $turn_id ) {
		$turn = $this->turns->find( $turn_id );
		if ( ! $turn || in_array( $turn['status'], array( 'published', 'canceled', 'failed' ), true ) ) {
			return $turn; }
		if ( ! $this->valid( $turn ) ) {
			$this->turns->cancel( $turn_id, $this->invalid_reason( $turn ) );
			$this->clear_agent_typing( $turn );
			$this->finalize_brain( (int) ( $turn['brain_run_id'] ?? 0 ) );
			return new \WP_Error( 'acl_ar_turn_canceled', __( 'The scheduled conversation turn is no longer current.', 'acl-agent-rooms' ) ); }
		if ( ! $this->turns->acquire( $turn_id ) ) {
			return $this->turns->find( $turn_id ); }
		$turn = $this->turns->find( $turn_id );
		if ( 'brain' === $turn['source_type'] ) {
			$result = $this->publish_brain( $turn ); } else {
			$result = $this->publish_independent( $turn ); }
			$this->clear_agent_typing( $turn );
			$this->finalize_brain( (int) ( $turn['brain_run_id'] ?? 0 ) );
			return $result;
	}

	public function run_due( int $limit = 20 ): array {
		$scanned = 0;
		$changed = 0;
		foreach ( $this->turns->typing_due( $limit ) as $turn ) {
			++$scanned;
			$before = (string) $turn['status'];
			$this->mark_typing( (int) $turn['id'] );
			$after = $this->turns->find( (int) $turn['id'] );
			if ( $after && $before !== (string) $after['status'] ) {
				++$changed;
			}
		} foreach ( $this->turns->due( $limit ) as $turn ) {
			++$scanned;
			$before = (string) $turn['status'];
			$this->publish( (int) $turn['id'] );
			$after = $this->turns->find( (int) $turn['id'] );
			if ( $after && $before !== (string) $after['status'] ) {
				++$changed;
			}
		} return array(
			'scanned' => $scanned,
			'changed' => $changed,
		); }
	public function recover( int $limit = 100 ): array {
		$result = $this->run_due( min( 50, $limit ) );
		foreach ( $this->turns->active( $limit ) as $turn ) {
			++$result['scanned'];
			if ( $this->valid( $turn ) ) {
				continue;
			} $reason = $this->invalid_reason( $turn );
			if ( $this->turns->cancel( (int) $turn['id'], $reason ) ) {
				++$result['changed'];
				if ( ! empty( $turn['job_id'] ) ) {
					$this->jobs->cancel( (int) $turn['job_id'], $reason );
				} $this->clear_agent_typing( $turn );
				$this->finalize_brain( (int) ( $turn['brain_run_id'] ?? 0 ) );
			}
		} return $result; }
	public function cancel_for_cutoff( int $room_id, int $cutoff ): int {
		$count = $this->turns->cancel_for_cutoff( $room_id, $cutoff );
		$this->clear_inactive_typing( $room_id );
		$this->finalize_ready_brains( $room_id );
		return $count; }
	public function cancel_for_agent( int $room_id, int $agent_id, string $reason = 'agent_paused' ): int {
		$count = $this->turns->cancel_for_agent( $room_id, $agent_id, $reason );
		$this->clear_inactive_typing( $room_id );
		$this->finalize_ready_brains( $room_id );
		return $count; }

	private function publish_brain( array $turn ) {
		if ( null === $turn['content'] || '' === trim( (string) $turn['content'] ) ) {
			$run = $this->runs->find( (int) $turn['brain_run_id'] );
			if ( $run && in_array( $run['status'], array( 'pending', 'running' ), true ) ) {
				$this->turns->postpone( (int) $turn['id'], 2 );
				( new QueueService() )->enqueue_turn( $this->turns->find( (int) $turn['id'] ) );
				return $turn;
			} $this->turns->fail( (int) $turn['id'], 'content_unavailable' );
			return new \WP_Error( 'acl_ar_turn_content_unavailable', __( 'Validated conversation content is unavailable.', 'acl-agent-rooms' ) ); }
		global $wpdb;
		$wpdb->query( 'START TRANSACTION' );
		$locked = $this->rooms->find_for_update( (int) $turn['room_id'] );
		if ( ! $locked || ! $this->valid( $turn, $locked ) ) {
			$wpdb->query( 'ROLLBACK' );
			$this->turns->cancel( (int) $turn['id'], 'superseded' );
			return new \WP_Error( 'acl_ar_turn_superseded', __( 'The scheduled turn was superseded.', 'acl-agent-rooms' ) ); }
		$run        = $this->runs->find( (int) $turn['brain_run_id'] );
		$message_id = $this->messages->create(
			array(
				'room_id'         => (int) $turn['room_id'],
				'sender_type'     => 'agent',
				'sender_agent_id' => (int) $turn['agent_id'],
				'content'         => (string) $turn['content'],
				'brain_run_id'    => (int) $turn['brain_run_id'],
				'brain_agent_id'  => (int) $turn['agent_id'],
				'metadata'        => array(
					'brain_run_id'         => (int) $turn['brain_run_id'],
					'brain_id'             => (int) ( $run['brain_id'] ?? 0 ),
					'runtime'              => 'brain',
					'conversation_turn_id' => (int) $turn['id'],
					'purpose'              => (string) $turn['purpose'],
				),
				'provider_route'  => (string) ( $run['provider'] ?? '' ),
				'model'           => (string) ( $run['model'] ?? '' ),
			)
		);
		if ( is_wp_error( $message_id ) ) {
			$wpdb->query( 'ROLLBACK' );
			$this->turns->fail( (int) $turn['id'] );
			return $message_id; }
		$event = $this->room_events->create_message_event( $this->messages->find( (int) $message_id ) );
		if ( is_wp_error( $event ) || ! $this->turns->publish( (int) $turn['id'], (int) $event['id'] ) ) {
			$wpdb->query( 'ROLLBACK' );
			return is_wp_error( $event ) ? $event : new \WP_Error( 'acl_ar_turn_publish_failed', __( 'Conversation turn publication will be recovered.', 'acl-agent-rooms' ) ); }
		$wpdb->query( 'COMMIT' );
		return $this->turns->find( (int) $turn['id'] );
	}

	private function publish_independent( array $turn ) {
		$job_id = (int) ( $turn['job_id'] ?? 0 );
		if ( ! $job_id ) {
			$this->turns->fail( (int) $turn['id'], 'job_missing' );
			return new \WP_Error( 'acl_ar_turn_job_missing', __( 'The scheduled agent job is unavailable.', 'acl-agent-rooms' ) );
		} $result = ( new AgentRuntime() )->run_job( $job_id );
		$job      = $this->jobs->find( $job_id );
		if ( is_wp_error( $result ) || ! $job || 'completed' !== $job['status'] ) {
			$this->turns->fail( (int) $turn['id'], is_wp_error( $result ) ? $result->get_error_code() : 'job_failed' );
			return $result;
		} $message = $this->messages->find( (int) $job['response_message_id'] );
		$event     = $message ? $this->events->find_by_legacy_message_id( (int) $message['id'] ) : null;
		if ( ! $event ) {
			$this->turns->fail( (int) $turn['id'], 'event_missing' );
			return new \WP_Error( 'acl_ar_turn_event_missing', __( 'The agent reply event could not be confirmed.', 'acl-agent-rooms' ) );
		} $this->turns->publish( (int) $turn['id'], (int) $event['id'] );
		return $this->turns->find( (int) $turn['id'] ); }

	private function valid( array $turn, ?array $room = null ): bool {
		$room       = $room ?: $this->rooms->find( (int) $turn['room_id'] );
		$agent      = $this->agents->find( (int) $turn['agent_id'] );
		$assignment = $this->rooms->get_assignment( (int) $turn['room_id'], (int) $turn['agent_id'] );
		return $room && 'active' === (string) $room['status'] && 'natural' === (string) $room['conversation_mode'] && (int) ( $room['natural_active_trigger_event_id'] ?? 0 ) === (int) $turn['trigger_event_id'] && (int) $turn['trigger_event_id'] > (int) ( $room['cleared_through_event_id'] ?? 0 ) && $agent && ! empty( $agent['enabled'] ) && $assignment && 'paused' !== (string) ( $assignment['participation_state'] ?? 'active' ); }
	private function invalid_reason( array $turn ): string {
		$room = $this->rooms->find( (int) $turn['room_id'] );
		if ( ! $room || 'active' !== (string) $room['status'] ) {
			return 'room_unavailable';
		} if ( (int) $turn['trigger_event_id'] <= (int) ( $room['cleared_through_event_id'] ?? 0 ) ) {
			return 'chat_cleared';
		} if ( (int) ( $room['natural_active_trigger_event_id'] ?? 0 ) !== (int) $turn['trigger_event_id'] ) {
			return 'superseded';
		} return 'agent_unavailable'; }
	private function clear_agent_typing( array $turn ): void {
		( new AgentStateReconciler() )->reconcile_assignment( (int) $turn['room_id'], (int) $turn['agent_id'] ); }
	private function clear_inactive_typing( int $room_id ): void {
		( new AgentStateReconciler() )->reconcile_room( $room_id ); }
	private function finalize_ready_brains( int $room_id ): void {
		global $wpdb;
		$ids = $wpdb->get_col( $wpdb->prepare( "SELECT DISTINCT brain_run_id FROM {$wpdb->prefix}acl_ar_conversation_turns WHERE room_id=%d AND brain_run_id IS NOT NULL", $room_id ) );
		foreach ( (array) $ids as $id ) {
			$this->finalize_brain( (int) $id ); } }
	private function finalize_brain( int $run_id ): void {
		if ( ! $run_id ) {
			return;
		} $run = $this->runs->find( $run_id );
		if ( ! $run || ! in_array( $run['status'], array( 'response_saved', 'completed' ), true ) ) {
			return;
		} $turns = $this->turns->for_brain_run( $run_id );
		foreach ( $turns as $turn ) {
			if ( in_array( $turn['status'], array( 'pending', 'typing', 'publishing' ), true ) ) {
				return;
			}
		} $published = array_values( array_filter( array_map( static fn( $turn ) => 'published' === $turn['status'] ? (int) $turn['published_event_id'] : 0, $turns ) ) );
		$canceled    = count( array_filter( $turns, static fn( $turn ) => 'canceled' === $turn['status'] ) );
		$event       = $this->room_events->create(
			array(
				'room_id'         => (int) $run['room_id'],
				'event_type'      => 'brain_run',
				'actor_type'      => 'system',
				'target_type'     => 'brain',
				'target_id'       => (int) $run['brain_id'],
				'audience_type'   => 'moderators',
				'idempotency_key' => hash( 'sha256', 'brain-run:' . $run_id . ':terminal' ),
				'content'         => null,
				'metadata'        => array(
					'status'          => 'completed',
					'agent_ids'       => $run['target_agent_ids'],
					'response_count'  => count( $published ),
					'published_count' => count( $published ),
					'canceled_count'  => $canceled,
				),
			)
		);
		if ( ! is_wp_error( $event ) ) {
			$this->runs->complete( $run_id, $published ); } }
}
