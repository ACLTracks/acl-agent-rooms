<?php
// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange -- This file operates on plugin-owned operational tables and requires uncached transaction/read-after-write truth.
// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- Identifiers are fixed $wpdb->prefix suffixes, fixed internal schema fragments, or allowlisted names; runtime values use placeholders or wpdb mutation arrays.
// phpcs:disable PluginCheck.Security.DirectDB.UnescapedDBParameter -- PHPCS cannot model the fixed identifier provenance above; request data is never used as an SQL identifier.
/** Private Room Files diagnostics without file contents. @package ACL_Agent_Rooms */
namespace ACL\AgentRooms\Services;

use ACL\AgentRooms\Repositories\RoomFileRepository;

if ( ! defined( 'ABSPATH' ) ) {
	exit; }

class RoomFileHealthService {
	public function snapshot(): array {
		global $wpdb;
		$files        = $wpdb->prefix . 'acl_ar_room_files';
		$versions     = $wpdb->prefix . 'acl_ar_room_file_versions';
		$rooms        = $wpdb->prefix . 'acl_ar_rooms';
		$storage      = new StorageBridge();
		$missing      = 0;
		$inaccessible = 0;
		$rows         = (array) $wpdb->get_results( "SELECT * FROM {$files} WHERE removed_at IS NULL ORDER BY id ASC LIMIT 500", ARRAY_A );
		if ( $storage->available() ) {
			foreach ( $rows as $row ) {
				$asset = $storage->metadata( (int) $row['storage_asset_id'], (int) $row['storage_owner_user_id'] );
				if ( is_wp_error( $asset ) ) {
					++$missing;
				}
			}
		} else {
			$inaccessible = count( $rows );
		}$supported = RoomFileExtractionService::supported_extensions();
		$quoted     = "'" . implode( "','", array_map( 'esc_sql', $supported ) ) . "'";
		return array(
			'storage'                => $storage->status(),
			'associations'           => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$files} WHERE removed_at IS NULL" ),
			'versions'               => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$versions}" ),
			'missing_storage_assets' => $missing,
			'inaccessible_assets'    => $inaccessible,
			'extraction_failures'    => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$files} WHERE removed_at IS NULL AND extraction_status='failed'" ),
			'indexing_failures'      => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$files} WHERE removed_at IS NULL AND indexing_status='failed'" ),
			'stale_hashes'           => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$files} f INNER JOIN {$versions} v ON v.id=f.active_version_id WHERE f.removed_at IS NULL AND f.content_hash<>v.content_hash" ),
			'orphan_associations'    => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$files} f LEFT JOIN {$rooms} r ON r.id=f.room_id WHERE r.id IS NULL" ),
			'disabled_with_indexes'  => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$files} WHERE removed_at IS NULL AND context_enabled=0 AND indexing_status='ready'" ),
			'oversized'              => (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$files} WHERE removed_at IS NULL AND file_size>%d", RoomFileExtractionService::MAX_SOURCE_BYTES ) ),
			'unsupported_types'      => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$files} WHERE removed_at IS NULL AND file_extension NOT IN ({$quoted})" ),
		);}
}
