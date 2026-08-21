<?php
// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange -- This file operates on plugin-owned operational tables and requires uncached transaction/read-after-write truth.
// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- Identifiers are fixed $wpdb->prefix suffixes, fixed internal schema fragments, or allowlisted names; runtime values use placeholders or wpdb mutation arrays.
// phpcs:disable PluginCheck.Security.DirectDB.UnescapedDBParameter -- PHPCS cannot model the fixed identifier provenance above; request data is never used as an SQL identifier.
/** Recorded bounded maintenance. @package ACL_Agent_Rooms */
namespace ACL\AgentRooms\Services;

use ACL\AgentRooms\Repositories\MaintenanceRunRepository;
if ( ! defined( 'ABSPATH' ) ) {
	exit;}
class MaintenanceService {
	private MaintenanceRunRepository $runs;
	public function __construct() {
		$this->runs = new MaintenanceRunRepository(); }
	public function run( string $task, int $limit = 100 ): array {
		$allowed = array( 'search_backfill', 'retention', 'presence_cleanup', 'event_backfill', 'conversation_turns', 'room_files' );
		if ( ! in_array( $task, $allowed, true ) ) {
			return array( 'error' => 'invalid_task' );
		}$id = $this->runs->start( $task );
		try {
			switch ( $task ) {
				case 'search_backfill':
																																																																									$result = ( new EventSearchBackfillService() )->run_batch( $limit );
					break;
				case 'retention':
																																																																								$result = ( new RetentionService() )->run( $limit );
					break;
				case 'presence_cleanup':
																																																																								$result = (array) ( new PresenceAggregationService() )->cleanup();
					break;
				case 'conversation_turns':
																																																																								$result = ( new ConversationTurnService() )->recover( $limit );
					break;
				case 'room_files':
																																																																								$result = $this->room_files( $limit );
					break;
				default:
																																																																								$result = ( new EventBackfillService() )->run_batch( $limit );
			} $scanned = (int) ( $result['scanned'] ?? 0 );
			$changed   = (int) ( $result['changed'] ?? 0 );
			$this->runs->finish( $id, 'completed', $scanned, $changed, $result );
			return array(
				'run_id' => $id,
				'task'   => $task,
			) + $result;
		} catch ( \Throwable $e ) {
			$this->runs->finish( $id, 'failed', 0, 0, array( 'error' => 'maintenance_failed' ) );
			return array(
				'run_id' => $id,
				'task'   => $task,
				'error'  => 'maintenance_failed',
			);}}
	private function room_files( int $limit ): array {
		global $wpdb;
		$repo      = new \ACL\AgentRooms\Repositories\RoomFileRepository();
		$extractor = new RoomFileExtractionService();
		$storage   = new StorageBridge();
		$rooms     = $wpdb->prefix . 'acl_ar_rooms';
		$table     = $wpdb->prefix . 'acl_ar_room_files';
		$rows      = (array) $wpdb->get_results( $wpdb->prepare( "SELECT f.* FROM {$table} f LEFT JOIN {$rooms} r ON r.id=f.room_id WHERE f.removed_at IS NULL ORDER BY f.id ASC LIMIT %d", max( 1, min( 500, $limit ) ) ), ARRAY_A );
		$changed   = 0;
		$failed    = 0;
		foreach ( $rows as $row ) {
			$room_exists = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$rooms} WHERE id=%d", (int) $row['room_id'] ) );
			$asset       = $storage->metadata( (int) $row['storage_asset_id'], (int) $row['storage_owner_user_id'] );
			if ( ! $room_exists || is_wp_error( $asset ) ) {
				$repo->clear_version_content( (int) $row['id'] );
				$repo->remove( (int) $row['id'] );
				++$changed;
				continue;
			}if ( ! hash_equals( (string) $row['content_hash'], strtolower( (string) ( $asset['checksum'] ?? '' ) ) ) || (int) $row['file_size'] !== (int) ( $asset['size'] ?? 0 ) ) {
				$refreshed = $repo->replace( (int) $row['id'], $asset, (int) $row['added_by_user_id'] );
				if ( is_wp_error( $refreshed ) ) {
					++$failed;
					continue;
				}$result = $extractor->process( (int) $row['id'] );
				if ( is_wp_error( $result ) ) {
					++$failed;
				} else {
					++$changed;
				}continue;
			}if ( 'failed' === (string) $row['extraction_status'] || 'failed' === (string) $row['indexing_status'] ) {
				$result = $extractor->process( (int) $row['id'] );
				if ( is_wp_error( $result ) ) {
						++$failed;
				} else {
					++$changed;
				}
			}
		}return array(
			'scanned' => count( $rows ),
			'changed' => $changed,
			'failed'  => $failed,
		);}
}
