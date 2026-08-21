<?php
// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange -- This file operates on plugin-owned operational tables and requires uncached transaction/read-after-write truth.
// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- Identifiers are fixed $wpdb->prefix suffixes, fixed internal schema fragments, or allowlisted names; runtime values use placeholders or wpdb mutation arrays.
// phpcs:disable PluginCheck.Security.DirectDB.UnescapedDBParameter -- PHPCS cannot model the fixed identifier provenance above; request data is never used as an SQL identifier.
/** Current reaction state persistence. @package ACL_Agent_Rooms */
namespace ACL\AgentRooms\Repositories;

if ( ! defined( 'ABSPATH' ) ) {
	exit;}
class ReactionRepository {
	private function table(): string {
		global $wpdb;
		return $wpdb->prefix . 'acl_ar_event_reactions';}
	public function add( int $event_id, int $user_id, string $reaction ): bool {
		global $wpdb;
		$sql = $wpdb->prepare( 'INSERT INTO ' . $this->table() . ' (event_id,user_id,reaction,created_at) VALUES (%d,%d,%s,%s) ON DUPLICATE KEY UPDATE reaction=VALUES(reaction)', $event_id, $user_id, $reaction, current_time( 'mysql', true ) );
		return false !== $wpdb->query( $sql );}
	public function remove( int $event_id, int $user_id, string $reaction ): bool {
		global $wpdb;
		return false !== $wpdb->delete(
			$this->table(),
			array(
				'event_id' => $event_id,
				'user_id'  => $user_id,
				'reaction' => $reaction,
			),
			array( '%d', '%d', '%s' )
		);}
	public function has( int $event_id, int $user_id, string $reaction ): bool {
		global $wpdb;
		return (bool) $wpdb->get_var( $wpdb->prepare( 'SELECT 1 FROM ' . $this->table() . ' WHERE event_id=%d AND user_id=%d AND reaction=%s', $event_id, $user_id, $reaction ) );}
	public function summaries( array $event_ids, int $user_id ): array {
		global $wpdb;
		$ids = array_values( array_unique( array_filter( array_map( 'absint', $event_ids ) ) ) );
		if ( ! $ids ) {
			return array();
		}$marks = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
		$rows   = $wpdb->get_results( $wpdb->prepare( 'SELECT event_id,reaction,COUNT(*) count,MAX(user_id=%d) mine FROM ' . $this->table() . ' WHERE event_id IN (' . $marks . ') GROUP BY event_id,reaction', $user_id, ...$ids ), ARRAY_A );
		$out    = array();
		foreach ( $rows as $row ) {
			$out[ (int) $row['event_id'] ][] = array(
				'reaction'                => (string) $row['reaction'],
				'count'                   => (int) $row['count'],
				'reacted_by_current_user' => (bool) $row['mine'],
			);
		}return $out;}
	public function delete_for_event( int $event_id ): bool {
		global $wpdb;
		return false !== $wpdb->delete( $this->table(), array( 'event_id' => $event_id ), array( '%d' ) );}
}
