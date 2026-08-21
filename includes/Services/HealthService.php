<?php
// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange -- This file operates on plugin-owned operational tables and requires uncached transaction/read-after-write truth.
// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- Identifiers are fixed $wpdb->prefix suffixes, fixed internal schema fragments, or allowlisted names; runtime values use placeholders or wpdb mutation arrays.
// phpcs:disable PluginCheck.Security.DirectDB.UnescapedDBParameter -- PHPCS cannot model the fixed identifier provenance above; request data is never used as an SQL identifier.
/** Safe operational health snapshot. @package ACL_Agent_Rooms */
namespace ACL\AgentRooms\Services;

use ACL\AgentRooms\Repositories\MaintenanceRunRepository;
use ACL\AgentRooms\Repositories\RoomRestrictionRepository;
if ( ! defined( 'ABSPATH' ) ) {
	exit;}
class HealthService {
	public function snapshot(): array {
		global $wpdb;
		$tables = array( 'rooms', 'events', 'messages', 'agent_jobs', 'brains', 'brain_runs', 'usage', 'event_search', 'room_restrictions', 'maintenance_runs', 'conversation_turns', 'room_files', 'room_file_versions' );
		$counts = array();
		foreach ( $tables as $name ) {
			$counts[ $name ] = (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . $wpdb->prefix . 'acl_ar_' . $name );}
		$brain_statuses = array();
		foreach ( (array) $wpdb->get_results( 'SELECT status,COUNT(*) AS total FROM ' . $wpdb->prefix . 'acl_ar_brain_runs GROUP BY status', ARRAY_A ) as $row ) {
			$brain_statuses[ (string) $row['status'] ] = (int) $row['total'];
		}$brain_usage       = $wpdb->get_row( 'SELECT COUNT(*) AS requests,COALESCE(SUM(total_tokens),0) AS total_tokens,COALESCE(SUM(estimated_cost),0) AS estimated_cost FROM ' . $wpdb->prefix . 'acl_ar_usage WHERE brain_run_id IS NOT NULL', ARRAY_A ) ?: array();
		$settings           = (array) get_option( 'acl_ar_settings', array() );
		$rooms              = $wpdb->prefix . 'acl_ar_rooms';
		$events             = $wpdb->prefix . 'acl_ar_events';
		$users              = $wpdb->users;
		$turns              = $wpdb->prefix . 'acl_ar_conversation_turns';
		$agents             = $wpdb->prefix . 'acl_ar_agents';
		$runs               = $wpdb->prefix . 'acl_ar_brain_runs';
		$jobs               = $wpdb->prefix . 'acl_ar_agent_jobs';
		$now                = gmdate( 'Y-m-d H:i:s' );
		$stale              = gmdate( 'Y-m-d H:i:s', time() - 180 );
		$invalid_cutoffs    = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$rooms} r LEFT JOIN {$events} e ON e.id=r.cleared_through_event_id AND e.room_id=r.id WHERE r.cleared_through_event_id IS NOT NULL AND r.cleared_through_event_id>0 AND e.id IS NULL" );
		$impossible_cutoffs = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$rooms} r LEFT JOIN (SELECT room_id,MAX(id) max_id FROM {$events} GROUP BY room_id) x ON x.room_id=r.id WHERE r.cleared_through_event_id IS NOT NULL AND r.cleared_through_event_id>COALESCE(x.max_id,0)" );
		$missing_actors     = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$rooms} r LEFT JOIN {$users} u ON u.ID=r.chat_cleared_by_user_id WHERE r.chat_cleared_by_user_id IS NOT NULL AND u.ID IS NULL" );
		$clear_health       = (array) get_option( 'acl_ar_clear_health', array() );
		$turn_health        = array(
			'pending'            => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$turns} WHERE status='pending'" ),
			'overdue'            => (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$turns} WHERE status IN ('pending','typing') AND due_at<%s", $now ) ),
			'stale_typing'       => (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$turns} WHERE status='typing' AND due_at<%s", $stale ) ),
			'failed'             => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$turns} WHERE status='failed'" ),
			'canceled'           => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$turns} WHERE status='canceled'" ),
			'rooms_over_limit'   => (int) $wpdb->get_var( "SELECT COUNT(*) FROM (SELECT t.room_id FROM {$turns} t INNER JOIN {$rooms} r ON r.id=t.room_id WHERE t.status IN ('pending','typing','publishing') GROUP BY t.room_id,r.natural_max_pending_turns HAVING COUNT(*)>r.natural_max_pending_turns) x" ),
			'missing_rooms'      => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$turns} t LEFT JOIN {$rooms} r ON r.id=t.room_id WHERE r.id IS NULL" ),
			'missing_agents'     => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$turns} t LEFT JOIN {$agents} a ON a.id=t.agent_id WHERE a.id IS NULL" ),
			'missing_brain_runs' => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$turns} t LEFT JOIN {$runs} b ON b.id=t.brain_run_id WHERE t.brain_run_id IS NOT NULL AND b.id IS NULL" ),
			'missing_jobs'       => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$turns} t LEFT JOIN {$jobs} j ON j.id=t.job_id WHERE t.job_id IS NOT NULL AND j.id IS NULL" ),
		);
		$degraded           = $wpdb->last_error || $impossible_cutoffs || $turn_health['overdue'] || $turn_health['stale_typing'] || $turn_health['failed'] || $turn_health['missing_rooms'] || $turn_health['missing_agents'] || $turn_health['missing_brain_runs'] || $turn_health['missing_jobs'];
		return array(
			'status'              => $degraded ? 'degraded' : 'ok',
			'version'             => defined( 'ACL_AR_VERSION' ) ? ACL_AR_VERSION : 'unknown',
			'db_version'          => (string) get_option( 'acl_ar_db_version', '' ),
			'counts'              => $counts,
			'brain_runs'          => $brain_statuses,
			'brain_usage'         => array(
				'requests'       => (int) ( $brain_usage['requests'] ?? 0 ),
				'total_tokens'   => (int) ( $brain_usage['total_tokens'] ?? 0 ),
				'estimated_cost' => (float) ( $brain_usage['estimated_cost'] ?? 0 ),
			),
			'conversation_turns'  => $turn_health,
			'orchestration'       => ( new OrchestrationDiagnosticService() )->snapshot(),
			'room_files'          => ( new RoomFileHealthService() )->snapshot(),
			'clear_chat'          => array(
				'invalid_cutoff_references' => $invalid_cutoffs,
				'impossible_cutoffs'        => $impossible_cutoffs,
				'missing_actor_references'  => $missing_actors,
				'failed_operations'         => absint( $clear_health['failed'] ?? 0 ),
				'duplicate_retries'         => absint( $clear_health['duplicates'] ?? 0 ),
			),
			'active_restrictions' => ( new RoomRestrictionRepository() )->count_active(),
			'retention_days'      => absint( $settings['data_retention_days'] ?? 0 ),
			'cron'                => array(
				'pending_jobs'       => (bool) wp_next_scheduled( QueueService::PENDING_HOOK ),
				'conversation_turns' => (bool) wp_next_scheduled( QueueService::TURN_WORKER_HOOK ),
				'maintenance'        => (bool) wp_next_scheduled( QueueService::MAINTENANCE_HOOK ),
			),
			'latest_maintenance'  => ( new MaintenanceRunRepository() )->latest(),
			'generated_at'        => gmdate( 'c' ),
		);
	}
}
