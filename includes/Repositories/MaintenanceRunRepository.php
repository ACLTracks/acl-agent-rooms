<?php
// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange -- This file operates on plugin-owned operational tables and requires uncached transaction/read-after-write truth.
// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- Identifiers are fixed $wpdb->prefix suffixes, fixed internal schema fragments, or allowlisted names; runtime values use placeholders or wpdb mutation arrays.
// phpcs:disable PluginCheck.Security.DirectDB.UnescapedDBParameter -- PHPCS cannot model the fixed identifier provenance above; request data is never used as an SQL identifier.
/** Maintenance run records. @package ACL_Agent_Rooms */
namespace ACL\AgentRooms\Repositories;

use ACL\AgentRooms\Support\Json;
use ACL\AgentRooms\Support\Time;
if ( ! defined( 'ABSPATH' ) ) {
	exit;}
class MaintenanceRunRepository {
	private function table(): string {
		global $wpdb;
		return $wpdb->prefix . 'acl_ar_maintenance_runs';}
	public function start( string $task ): int {
		global $wpdb;
		$wpdb->insert(
			$this->table(),
			array(
				'task'       => sanitize_key( $task ),
				'status'     => 'running',
				'started_at' => Time::mysql_gmt(),
			),
			array( '%s', '%s', '%s' )
		);
		return (int) $wpdb->insert_id;}
	public function finish( int $id, string $status, int $scanned, int $changed, array $details = array() ): bool {
		global $wpdb;
		return false !== $wpdb->update(
			$this->table(),
			array(
				'status'        => sanitize_key( $status ),
				'finished_at'   => Time::mysql_gmt(),
				'items_scanned' => max( 0, $scanned ),
				'items_changed' => max( 0, $changed ),
				'details_json'  => Json::encode( $details ),
			),
			array( 'id' => $id ),
			array( '%s', '%s', '%d', '%d', '%s' ),
			array( '%d' )
		);}
	public function latest( string $task = '' ): ?array {
		global $wpdb;
		$where = $task !== '' ? $wpdb->prepare( ' WHERE task=%s', $task ) : '';
		$row   = $wpdb->get_row( 'SELECT * FROM ' . $this->table() . $where . ' ORDER BY id DESC LIMIT 1', ARRAY_A );
		return $row ?: null;}
}
