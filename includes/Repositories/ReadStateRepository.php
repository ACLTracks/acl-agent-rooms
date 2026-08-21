<?php
// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange -- This file operates on plugin-owned operational tables and requires uncached transaction/read-after-write truth.
// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- Identifiers are fixed $wpdb->prefix suffixes, fixed internal schema fragments, or allowlisted names; runtime values use placeholders or wpdb mutation arrays.
// phpcs:disable PluginCheck.Security.DirectDB.UnescapedDBParameter -- PHPCS cannot model the fixed identifier provenance above; request data is never used as an SQL identifier.
/** Persistent room read boundary. @package ACL_Agent_Rooms */
namespace ACL\AgentRooms\Repositories;

if ( ! defined( 'ABSPATH' ) ) {
	exit;}
class ReadStateRepository {
	private function table(): string {
		global $wpdb;
		return $wpdb->prefix . 'acl_ar_room_reads';}
	public function get( int $room_id, int $user_id ): int {
		global $wpdb;
		return (int) $wpdb->get_var( $wpdb->prepare( 'SELECT last_read_event_id FROM ' . $this->table() . ' WHERE room_id=%d AND user_id=%d', $room_id, $user_id ) );}
	public function advance( int $room_id, int $user_id, int $event_id ): int {
		global $wpdb;
		$sql = $wpdb->prepare( 'INSERT INTO ' . $this->table() . ' (room_id,user_id,last_read_event_id,updated_at) VALUES (%d,%d,%d,%s) ON DUPLICATE KEY UPDATE updated_at=IF(VALUES(last_read_event_id)>last_read_event_id,VALUES(updated_at),updated_at),last_read_event_id=GREATEST(last_read_event_id,VALUES(last_read_event_id))', $room_id, $user_id, max( 0, $event_id ), current_time( 'mysql', true ) );
		$wpdb->query( $sql );
		return $this->get( $room_id, $user_id );}
}
