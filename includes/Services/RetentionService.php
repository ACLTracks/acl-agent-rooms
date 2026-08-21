<?php
// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange -- This file operates on plugin-owned operational tables and requires uncached transaction/read-after-write truth.
// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- Identifiers are fixed $wpdb->prefix suffixes, fixed internal schema fragments, or allowlisted names; runtime values use placeholders or wpdb mutation arrays.
// phpcs:disable PluginCheck.Security.DirectDB.UnescapedDBParameter -- PHPCS cannot model the fixed identifier provenance above; request data is never used as an SQL identifier.
/** Conservative archived-room retention. @package ACL_Agent_Rooms */
namespace ACL\AgentRooms\Services;

use ACL\AgentRooms\Repositories\EventRepository;
use ACL\AgentRooms\Repositories\MessageRepository;
if ( ! defined( 'ABSPATH' ) ) {
	exit;}
class RetentionService {
	public const PLACEHOLDER = '[Message content expired by retention policy]';
	public function run( int $limit = 100 ): array {
		$settings = (array) get_option( 'acl_ar_settings', array() );
		$days     = absint( $settings['data_retention_days'] ?? 0 );
		if ( $days <= 0 ) {
			return array(
				'disabled' => true,
				'scanned'  => 0,
				'changed'  => 0,
				'has_more' => false,
			);
		}$limit = max( 1, min( 500, $limit ) );
		global $wpdb;
		$room_ids = $wpdb->get_col( "SELECT id FROM {$wpdb->prefix}acl_ar_rooms WHERE status='archived' ORDER BY id LIMIT 100" );
		$before   = gmdate( 'Y-m-d H:i:s', time() - DAY_IN_SECONDS * $days );
		$events   = new EventRepository();
		$messages = new MessageRepository();
		$indexer  = new EventSearchIndexer();
		$scanned  = 0;
		$changed  = 0;
		foreach ( (array) $room_ids as $room_id ) {
			foreach ( $events->expired_candidates( (int) $room_id, $before, $limit - $scanned ) as $event ) {
				++$scanned;
				if ( $events->soft_delete( (int) $event['id'], self::PLACEHOLDER, array( 'retention_expired' => true ) ) ) {
					++$changed;
					if ( ! empty( $event['legacy_message_id'] ) ) {
						$messages->redact( (int) $event['legacy_message_id'], self::PLACEHOLDER, 'expired' );
					}$indexer->delete( (int) $event['id'] );
					$wpdb->delete( $wpdb->prefix . 'acl_ar_event_reactions', array( 'event_id' => (int) $event['id'] ), array( '%d' ) );
				}if ( $scanned >= $limit ) {
					break 2;
				}
			}
		}return array(
			'disabled' => false,
			'scanned'  => $scanned,
			'changed'  => $changed,
			'has_more' => $scanned === $limit,
			'days'     => $days,
		);}
}
