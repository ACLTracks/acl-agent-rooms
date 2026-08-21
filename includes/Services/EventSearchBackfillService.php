<?php
// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange -- This file operates on plugin-owned operational tables and requires uncached transaction/read-after-write truth.
// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- Identifiers are fixed $wpdb->prefix suffixes, fixed internal schema fragments, or allowlisted names; runtime values use placeholders or wpdb mutation arrays.
// phpcs:disable PluginCheck.Security.DirectDB.UnescapedDBParameter -- PHPCS cannot model the fixed identifier provenance above; request data is never used as an SQL identifier.
/** Bounded search backfill and reconciliation. @package ACL_Agent_Rooms */
namespace ACL\AgentRooms\Services;

use ACL\AgentRooms\Models\RoomEvent;

if ( ! defined( 'ABSPATH' ) ) {
	exit; }

class EventSearchBackfillService {
	public function run_batch( int $limit = 100 ): array {
		global $wpdb;

		$limit        = max( 1, min( 500, $limit ) );
		$events_table = $wpdb->prefix . 'acl_ar_events';
		$search_table = $wpdb->prefix . 'acl_ar_event_search';
		$scanned      = 0;
		$changed      = 0;

		$stale_ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT s.event_id
				FROM {$search_table} s
				LEFT JOIN {$events_table} e ON e.id=s.event_id
				WHERE e.id IS NULL
					OR e.deleted_at IS NOT NULL
					OR e.event_type NOT IN ('message','system_notice','action')
					OR TRIM(e.content)=''
				ORDER BY s.event_id ASC
				LIMIT %d",
				$limit
			)
		);

		$stale_ids = array_values( array_filter( array_map( 'absint', (array) $stale_ids ) ) );
		if ( $stale_ids ) {
			$deleted = $wpdb->query( 'DELETE FROM ' . $search_table . ' WHERE event_id IN (' . implode( ',', $stale_ids ) . ')' );
			if ( false === $deleted ) {
				throw new \RuntimeException( 'Search reconciliation could not remove stale rows.' );
			}
			$scanned += count( $stale_ids );
			$changed += (int) $deleted;
		}

		$remaining = $limit - $scanned;
		if ( $remaining > 0 ) {
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT e.*,COALESCE(latest_edit.content,e.content) effective_content
					FROM {$events_table} e
					LEFT JOIN (
						SELECT edit.parent_event_id,edit.content
						FROM {$events_table} edit
						INNER JOIN (
							SELECT parent_event_id,MAX(id) latest_id
							FROM {$events_table}
							WHERE event_type='message_edit' AND parent_event_id IS NOT NULL
							GROUP BY parent_event_id
						) latest ON latest.latest_id=edit.id
					) latest_edit ON latest_edit.parent_event_id=e.id
					LEFT JOIN {$search_table} s ON s.event_id=e.id
					WHERE e.event_type IN ('message','system_notice','action')
						AND e.deleted_at IS NULL
						AND TRIM(COALESCE(latest_edit.content,e.content))<>''
						AND (
							s.event_id IS NULL
							OR s.room_id<>e.room_id
							OR s.searchable_text<>TRIM(COALESCE(latest_edit.content,e.content))
						)
					ORDER BY e.id ASC
					LIMIT %d",
					$remaining
				),
				ARRAY_A
			);

			$indexer = new EventSearchIndexer();
			foreach ( (array) $rows as $row ) {
				++$scanned;
				$event = RoomEvent::from_row( $row );
				if ( $indexer->index_with_content( $event, (string) $row['effective_content'] ) ) {
					++$changed;
				}
			}
		}

		return array(
			'scanned'  => $scanned,
			'changed'  => $changed,
			'has_more' => $scanned === $limit,
		);
	}
}
