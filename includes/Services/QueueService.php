<?php
// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange -- This file operates on plugin-owned operational tables and requires uncached transaction/read-after-write truth.
// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- Identifiers are fixed $wpdb->prefix suffixes, fixed internal schema fragments, or allowlisted names; runtime values use placeholders or wpdb mutation arrays.
// phpcs:disable PluginCheck.Security.DirectDB.UnescapedDBParameter -- PHPCS cannot model the fixed identifier provenance above; request data is never used as an SQL identifier.
/**
 * Agent job queue seam.
 *
 * @package ACL_Agent_Rooms
 */

namespace ACL\AgentRooms\Services;

use ACL\AgentRooms\Repositories\JobRepository;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class QueueService {
	public const PENDING_HOOK         = 'acl_ar_run_pending_agent_jobs';
	public const SINGLE_HOOK          = 'acl_ar_run_agent_job';
	public const BRAIN_HOOK           = 'acl_ar_run_brain_run';
	public const BACKFILL_HOOK        = 'acl_ar_continue_event_backfill';
	public const SEARCH_BACKFILL_HOOK = 'acl_ar_continue_search_backfill';
	public const MAINTENANCE_HOOK     = 'acl_ar_run_maintenance';
	public const TURN_HOOK            = 'acl_ar_publish_conversation_turn';
	public const TYPING_HOOK          = 'acl_ar_type_conversation_turn';
	public const TURN_WORKER_HOOK     = 'acl_ar_run_conversation_turns';

	public function register(): void {
		add_filter( 'cron_schedules', array( $this, 'schedules' ) );
		add_action( self::PENDING_HOOK, array( $this, 'run_pending' ) );
		add_action( self::SINGLE_HOOK, array( $this, 'run_single' ) );
		add_action( self::BRAIN_HOOK, array( $this, 'run_brain' ) );
		add_action( self::BACKFILL_HOOK, array( $this, 'run_event_backfill' ) );
		add_action( self::SEARCH_BACKFILL_HOOK, array( $this, 'run_search_backfill' ) );
		add_action( self::MAINTENANCE_HOOK, array( $this, 'run_maintenance' ) );
		add_action( self::TURN_HOOK, array( $this, 'run_turn' ) );
		add_action( self::TYPING_HOOK, array( $this, 'run_typing' ) );
		add_action( self::TURN_WORKER_HOOK, array( $this, 'run_turns' ) );
		add_action( 'init', array( $this, 'ensure_recurring_events' ) );
	}

	public function ensure_recurring_events(): void {
		if ( ! wp_next_scheduled( self::PENDING_HOOK ) ) {
			wp_schedule_event( time() + 300, 'acl_ar_every_five_minutes', self::PENDING_HOOK );
		}
		if ( ! wp_next_scheduled( self::MAINTENANCE_HOOK ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', self::MAINTENANCE_HOOK ); }
		if ( ! wp_next_scheduled( self::TURN_WORKER_HOOK ) ) {
			wp_schedule_event( time() + 60, 'acl_ar_every_minute', self::TURN_WORKER_HOOK ); }
	}

	public function activate(): void {
		add_filter( 'cron_schedules', array( $this, 'schedules' ) );
		$this->ensure_recurring_events();
		remove_filter( 'cron_schedules', array( $this, 'schedules' ) );
	}

	public function schedules( array $schedules ): array {
		$schedules['acl_ar_every_five_minutes'] = array(
			'interval' => 300,
			'display'  => __( 'Every five minutes for ACL Agent Rooms', 'acl-agent-rooms' ),
		);
		$schedules['acl_ar_every_minute']       = array(
			'interval' => 60,
			'display'  => __( 'Every minute for ACL Agent Rooms conversations', 'acl-agent-rooms' ),
		);

		return $schedules;
	}

	public function enqueue_job( int $job_id ): bool {
		if ( function_exists( 'as_enqueue_async_action' ) ) {
			if ( $this->action_is_scheduled( self::SINGLE_HOOK, array( $job_id ) ) ) {
				return true; }
			$action_id = (int) as_enqueue_async_action( self::SINGLE_HOOK, array( $job_id ), 'acl-agent-rooms', true );
			return $action_id > 0 || $this->action_is_scheduled( self::SINGLE_HOOK, array( $job_id ) );
		}

		if ( wp_next_scheduled( self::SINGLE_HOOK, array( $job_id ) ) ) {
			return true;
		}
		return true === wp_schedule_single_event( time() + 1, self::SINGLE_HOOK, array( $job_id ) );
	}

	public function enqueue_job_retry( int $job_id, int $delay = 30 ): bool {
		$args = array( $job_id );
		$due  = time() + max( 1, $delay );
		if ( function_exists( 'as_schedule_single_action' ) ) {
			return (int) as_schedule_single_action( $due, self::SINGLE_HOOK, $args, 'acl-agent-rooms', false ) > 0;
		}
		return $this->schedule_wp_cron_retry( self::SINGLE_HOOK, $args, $due );
	}

	public function run_single( int $job_id ): void {
		( new AgentRuntime() )->run_job( $job_id );
	}
	public function enqueue_brain_run( int $run_id ): bool {
		if ( function_exists( 'as_enqueue_async_action' ) ) {
			if ( $this->action_is_scheduled( self::BRAIN_HOOK, array( $run_id ) ) ) {
				return true; }
			$action_id = (int) as_enqueue_async_action( self::BRAIN_HOOK, array( $run_id ), 'acl-agent-rooms', true );
			return $action_id > 0 || $this->action_is_scheduled( self::BRAIN_HOOK, array( $run_id ) );
		}
		if ( wp_next_scheduled( self::BRAIN_HOOK, array( $run_id ) ) ) {
			return true; }
		return true === wp_schedule_single_event( time() + 1, self::BRAIN_HOOK, array( $run_id ) );
	}
	public function enqueue_brain_retry( int $run_id, int $delay = 30 ): bool {
		$args = array( $run_id );
		$due = time() + max( 1, $delay );
		if ( function_exists( 'as_schedule_single_action' ) ) {
			return (int) as_schedule_single_action( $due, self::BRAIN_HOOK, $args, 'acl-agent-rooms', false ) > 0;
		}
		return $this->schedule_wp_cron_retry( self::BRAIN_HOOK, $args, $due );
	}
	private function schedule_wp_cron_retry( string $hook, array $args, int $due ): bool {
		wp_clear_scheduled_hook( $hook, $args );
		return true === wp_schedule_single_event( $due, $hook, $args );
	}
	public function run_brain( int $run_id ): void {
		( new BrainRuntime() )->run( $run_id ); }
	public function enqueue_turn( ?array $turn ): bool {
		if ( ! $turn || empty( $turn['id'] ) ) {
			return false; }
		$id     = (int) $turn['id'];
		$due    = max( time(), strtotime( (string) $turn['due_at'] . ' UTC' ) ?: time() );
		$typing = ! empty( $turn['typing_at'] ) ? ( strtotime( (string) $turn['typing_at'] . ' UTC' ) ?: $due ) : $due;
		if ( function_exists( 'as_schedule_single_action' ) ) {
			$typing_args = array( $id );
			$turn_args   = array( $id );
			$typing_ok   = $this->action_is_scheduled( self::TYPING_HOOK, $typing_args );
			$turn_ok     = $this->action_is_scheduled( self::TURN_HOOK, $turn_args );
			if ( ! $typing_ok ) {
				$typing_ok = (int) as_schedule_single_action( max( time(), $typing ), self::TYPING_HOOK, $typing_args, 'acl-agent-rooms', true ) > 0 || $this->action_is_scheduled( self::TYPING_HOOK, $typing_args ); }
			if ( ! $turn_ok ) {
				$turn_ok = (int) as_schedule_single_action( $due, self::TURN_HOOK, $turn_args, 'acl-agent-rooms', true ) > 0 || $this->action_is_scheduled( self::TURN_HOOK, $turn_args ); }
			return $typing_ok && $turn_ok;
		}
		$typing_ok = wp_next_scheduled( self::TYPING_HOOK, array( $id ) ) ? true : true === wp_schedule_single_event( max( time(), $typing ), self::TYPING_HOOK, array( $id ) );
		$turn_ok   = wp_next_scheduled( self::TURN_HOOK, array( $id ) ) ? true : true === wp_schedule_single_event( $due, self::TURN_HOOK, array( $id ) );
		return $typing_ok && $turn_ok;
	}
	public function reschedule_turn( ?array $turn ): bool {
		if ( ! $turn || empty( $turn['id'] ) ) {
			return false;
		}
		$args = array( (int) $turn['id'] );
		if ( function_exists( 'as_unschedule_all_actions' ) ) {
			as_unschedule_all_actions( self::TYPING_HOOK, $args, 'acl-agent-rooms' );
			as_unschedule_all_actions( self::TURN_HOOK, $args, 'acl-agent-rooms' );
		}
		wp_clear_scheduled_hook( self::TYPING_HOOK, $args );
		wp_clear_scheduled_hook( self::TURN_HOOK, $args );
		return $this->enqueue_turn( $turn );
	}
	public function run_typing( int $turn_id ): void {
		( new ConversationTurnService() )->mark_typing( $turn_id ); }
	public function run_turn( int $turn_id ): void {
		( new ConversationTurnService() )->publish( $turn_id ); }
	public function run_turns(): void {
		( new ConversationTurnService() )->run_due(); }

	public function run_pending(): void {
		$jobs = ( new JobRepository() )->pending( 5 );
		foreach ( $jobs as $job ) {
			( new AgentRuntime() )->run_job( (int) $job['id'] );
		}
		foreach ( ( new \ACL\AgentRooms\Repositories\BrainRunRepository() )->pending( 5 ) as $run ) {
			( new BrainRuntime() )->run( (int) $run['id'] ); }
		$this->run_turns();
		$reconciliation = ( new EventBackfillService() )->run_batch( 100 );
		if ( is_array( $reconciliation ) && ! empty( $reconciliation['has_more'] ) ) {
			$this->enqueue_event_backfill();
		}
		( new PresenceAggregationService() )->cleanup();
		global $wpdb;
		$room_ids = $wpdb->get_col( "SELECT id FROM {$wpdb->prefix}acl_ar_rooms ORDER BY id LIMIT 100" );
		foreach ( (array) $room_ids as $room_id ) {
			( new AgentStateReconciler() )->reconcile_room( (int) $room_id );}
	}

	public function enqueue_event_backfill(): void {
		if ( ! wp_next_scheduled( self::BACKFILL_HOOK ) ) {
			wp_schedule_single_event( time() + 30, self::BACKFILL_HOOK );
		}
	}

	public function run_event_backfill(): void {
		$result = ( new EventBackfillService() )->run_batch( 100 );
		if ( is_array( $result ) && ! empty( $result['has_more'] ) ) {
			$this->enqueue_event_backfill();
		}
	}
	public function enqueue_search_backfill(): void {
		if ( ! wp_next_scheduled( self::SEARCH_BACKFILL_HOOK ) ) {
			wp_schedule_single_event( time() + 45, self::SEARCH_BACKFILL_HOOK );} }
	public function run_search_backfill(): void {
		$result = ( new EventSearchBackfillService() )->run_batch( 100 );
		if ( ! empty( $result['has_more'] ) ) {
			$this->enqueue_search_backfill();} }
	public function run_maintenance(): void {
		$service = new MaintenanceService();
		$service->run( 'search_backfill', 100 );
		$service->run( 'retention', 100 );
		$service->run( 'presence_cleanup', 100 );
		$service->run( 'conversation_turns', 100 ); }

	private function action_is_scheduled( string $hook, array $args ): bool {
		if ( function_exists( 'as_has_scheduled_action' ) ) {
			return (bool) as_has_scheduled_action( $hook, $args, 'acl-agent-rooms' );
		}
		if ( function_exists( 'as_next_scheduled_action' ) ) {
			return (bool) as_next_scheduled_action( $hook, $args, 'acl-agent-rooms' );
		}
		return false;
	}
}
