<?php
// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange -- This file operates on plugin-owned operational tables and requires uncached transaction/read-after-write truth.
// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- Identifiers are fixed $wpdb->prefix suffixes, fixed internal schema fragments, or allowlisted names; runtime values use placeholders or wpdb mutation arrays.
// phpcs:disable PluginCheck.Security.DirectDB.UnescapedDBParameter -- PHPCS cannot model the fixed identifier provenance above; request data is never used as an SQL identifier.
/** Room-scoped browser presence session persistence. @package ACL_Agent_Rooms */
namespace ACL\AgentRooms\Repositories;

use ACL\AgentRooms\Support\Time;
if ( ! defined( 'ABSPATH' ) ) {
	exit; }
class PresenceSessionRepository {
	private function table(): string {
		global $wpdb;
		return $wpdb->prefix . 'acl_ar_room_presence_sessions'; }
	public function upsert( int $room_id, int $user_id, string $hash, string $visibility, string $activity, int $ttl ): bool {
		global $wpdb;
		$now     = Time::mysql_gmt();
		$expires = gmdate( 'Y-m-d H:i:s', time() + $ttl );
		$active  = 'active' === $activity ? $now : null;
		$table   = $this->table();
		$sql     = $wpdb->prepare( "INSERT INTO {$table} (room_id,user_id,session_hash,visibility_state,activity_state,last_seen_at,last_active_at,expires_at,created_at,updated_at) VALUES (%d,%d,%s,%s,%s,%s,%s,%s,%s,%s) ON DUPLICATE KEY UPDATE visibility_state=VALUES(visibility_state),activity_state=VALUES(activity_state),last_seen_at=VALUES(last_seen_at),last_active_at=IF(VALUES(last_active_at) IS NULL,last_active_at,VALUES(last_active_at)),expires_at=VALUES(expires_at),updated_at=VALUES(updated_at)", $room_id, $user_id, $hash, $visibility, $activity, $now, $active, $expires, $now, $now );
		return false !== $wpdb->query( $sql );
	}
	public function active_for_room( int $room_id, int $limit = 500 ): array {
		global $wpdb;
		$limit = max( 1, min( 500, $limit ) );
		$rows  = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . $this->table() . ' WHERE room_id=%d AND expires_at>%s ORDER BY user_id,id LIMIT %d', $room_id, Time::mysql_gmt(), $limit ), ARRAY_A );
		return is_array( $rows ) ? $rows : array(); }
	public function active_for_user( int $room_id, int $user_id ): array {
		global $wpdb;
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . $this->table() . ' WHERE room_id=%d AND user_id=%d AND expires_at>%s ORDER BY id', $room_id, $user_id, Time::mysql_gmt() ), ARRAY_A );
		return is_array( $rows ) ? $rows : array(); }
	public function delete_for_user( int $room_id, int $user_id, string $hash ): bool {
		global $wpdb;
		return false !== $wpdb->delete(
			$this->table(),
			array(
				'room_id'      => $room_id,
				'user_id'      => $user_id,
				'session_hash' => $hash,
			),
			array( '%d', '%d', '%s' )
		); }
	public function cleanup_expired( int $limit = 200 ): int {
		global $wpdb;
		$limit = max( 1, min( 500, $limit ) );
		$table = $this->table();
		return (int) $wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE expires_at<=%s ORDER BY id LIMIT %d", Time::mysql_gmt(), $limit ) ); }
}
