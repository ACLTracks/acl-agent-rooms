<?php
// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange -- This file operates on plugin-owned operational tables and requires uncached transaction/read-after-write truth.
// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- Identifiers are fixed $wpdb->prefix suffixes, fixed internal schema fragments, or allowlisted names; runtime values use placeholders or wpdb mutation arrays.
// phpcs:disable PluginCheck.Security.DirectDB.UnescapedDBParameter -- PHPCS cannot model the fixed identifier provenance above; request data is never used as an SQL identifier.
/** Aggregated actor presence projection persistence. @package ACL_Agent_Rooms */
namespace ACL\AgentRooms\Repositories;

use ACL\AgentRooms\Support\Time;
if ( ! defined( 'ABSPATH' ) ) {
	exit; }
class PresenceRepository {
	private function table(): string {
		global $wpdb;
		return $wpdb->prefix . 'acl_ar_room_presence'; }
	public function upsert( int $room_id, string $actor_type, int $actor_id, string $state, ?string $last_seen, int $last_event_id = 0, ?string $expires = null, array $metadata = array() ): bool {
		global $wpdb;
		$table = $this->table();
		$now   = Time::mysql_gmt();
		$json  = $metadata ? wp_json_encode( $metadata ) : null;
		$sql   = $wpdb->prepare( "INSERT INTO {$table} (room_id,actor_type,actor_id,state,last_seen_at,metadata_json,last_event_id,expires_at,updated_at) VALUES (%d,%s,%d,%s,%s,%s,%d,%s,%s) ON DUPLICATE KEY UPDATE state=VALUES(state),last_seen_at=IF(VALUES(last_seen_at) IS NULL,last_seen_at,GREATEST(COALESCE(last_seen_at,VALUES(last_seen_at)),VALUES(last_seen_at))),metadata_json=VALUES(metadata_json),last_event_id=GREATEST(last_event_id,VALUES(last_event_id)),expires_at=VALUES(expires_at),updated_at=VALUES(updated_at)", $room_id, $actor_type, $actor_id, $state, $last_seen, $json, $last_event_id, $expires, $now );
		return false !== $wpdb->query( $sql ); }
	public function find( int $room_id, string $type, int $id ): ?array {
		global $wpdb;
		$r = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . $this->table() . ' WHERE room_id=%d AND actor_type=%s AND actor_id=%d', $room_id, $type, $id ), ARRAY_A );
		return $r ?: null; }
	public function for_room( int $room_id ): array {
		global $wpdb;
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . $this->table() . ' WHERE room_id=%d ORDER BY actor_type,actor_id', $room_id ), ARRAY_A );
		return is_array( $rows ) ? $rows : array(); }
	public function delete_old_offline( int $seconds, int $limit = 200 ): int {
		global $wpdb;
		$before = gmdate( 'Y-m-d H:i:s', time() - $seconds );
		$table  = $this->table();
		return (int) $wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE actor_type='user' AND state='offline' AND updated_at<%s ORDER BY id LIMIT %d", $before, max( 1, min( 500, $limit ) ) ) ); }
}
