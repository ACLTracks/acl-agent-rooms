<?php
// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange -- This file operates on plugin-owned operational tables and requires uncached transaction/read-after-write truth.
// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- Identifiers are fixed $wpdb->prefix suffixes, fixed internal schema fragments, or allowlisted names; runtime values use placeholders or wpdb mutation arrays.
// phpcs:disable PluginCheck.Security.DirectDB.UnescapedDBParameter -- PHPCS cannot model the fixed identifier provenance above; request data is never used as an SQL identifier.
/** Search index persistence. @package ACL_Agent_Rooms */
namespace ACL\AgentRooms\Repositories;

use ACL\AgentRooms\Support\Time;
if ( ! defined( 'ABSPATH' ) ) {
	exit;}
class EventSearchRepository {
	private function table(): string {
		global $wpdb;
		return $wpdb->prefix . 'acl_ar_event_search';}
	public function upsert( int $event_id, int $room_id, string $text, string $actor_label, string $created_at ): bool {
		global $wpdb;
		$now = Time::mysql_gmt();
		$sql = $wpdb->prepare( 'INSERT INTO ' . $this->table() . ' (event_id,room_id,searchable_text,actor_label,created_at,updated_at) VALUES (%d,%d,%s,%s,%s,%s) ON DUPLICATE KEY UPDATE room_id=VALUES(room_id),searchable_text=VALUES(searchable_text),actor_label=VALUES(actor_label),updated_at=VALUES(updated_at)', $event_id, $room_id, $text, $actor_label, $created_at, $now );
		return false !== $wpdb->query( $sql );}
	public function delete( int $event_id ): bool {
		global $wpdb;
		return false !== $wpdb->delete( $this->table(), array( 'event_id' => $event_id ), array( '%d' ) );}
	public function candidates( int $room_id, string $query, int $before_id, int $limit, int $cutoff = 0 ): array {
		global $wpdb;
		$events   = $wpdb->prefix . 'acl_ar_events';
		$limit    = max( 1, min( 500, $limit ) );
		$boundary = $before_id > 0 ? $wpdb->prepare( ' AND s.event_id<%d', $before_id ) : '';
		$like     = '%' . $wpdb->esc_like( $query ) . '%';
		$rows     = $wpdb->get_results( $wpdb->prepare( 'SELECT e.*,s.actor_label FROM ' . $this->table() . ' s INNER JOIN ' . $events . ' e ON e.id=s.event_id WHERE s.room_id=%d AND s.event_id>%d' . $boundary . ' AND s.searchable_text LIKE %s ORDER BY s.event_id DESC LIMIT %d', $room_id, max( 0, $cutoff ), $like, $limit ), ARRAY_A );
		return array_map( array( \ACL\AgentRooms\Models\RoomEvent::class, 'from_row' ), is_array( $rows ) ? $rows : array() );}
	public function count(): int {
		global $wpdb;
		return (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . $this->table() );}
}
