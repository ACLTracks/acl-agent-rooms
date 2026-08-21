<?php
// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange -- This file operates on plugin-owned operational tables and requires uncached transaction/read-after-write truth.
// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- Identifiers are fixed $wpdb->prefix suffixes, fixed internal schema fragments, or allowlisted names; runtime values use placeholders or wpdb mutation arrays.
// phpcs:disable PluginCheck.Security.DirectDB.UnescapedDBParameter -- PHPCS cannot model the fixed identifier provenance above; request data is never used as an SQL identifier.
/** Durable room restrictions. @package ACL_Agent_Rooms */
namespace ACL\AgentRooms\Repositories;

use ACL\AgentRooms\Support\Time;
if ( ! defined( 'ABSPATH' ) ) {
	exit; }
class RoomRestrictionRepository {
	private function table(): string {
		global $wpdb;
		return $wpdb->prefix . 'acl_ar_room_restrictions'; }
	public function active( int $room_id, int $user_id, string $type = '' ): array {
		global $wpdb;
		$where = $type !== '' ? $wpdb->prepare( ' AND restriction_type=%s', $type ) : '';
		$rows  = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . $this->table() . ' WHERE room_id=%d AND user_id=%d AND revoked_at IS NULL AND (expires_at IS NULL OR expires_at>%s)' . $where . ' ORDER BY id DESC', $room_id, $user_id, Time::mysql_gmt() ), ARRAY_A );
		return is_array( $rows ) ? $rows : array(); }
	public function has( int $room_id, int $user_id, string $type ): bool {
		return ! empty( $this->active( $room_id, $user_id, $type ) ); }
	public function impose( int $room_id, int $user_id, string $type, int $actor_id, string $reason = '', ?string $expires_at = null ) {
		global $wpdb;
		if ( $this->has( $room_id, $user_id, $type ) ) {
			return true;
		} $ok = $wpdb->insert(
			$this->table(),
			array(
				'room_id'          => $room_id,
				'user_id'          => $user_id,
				'restriction_type' => $type,
				'reason'           => sanitize_textarea_field( $reason ),
				'created_by'       => $actor_id,
				'created_at'       => Time::mysql_gmt(),
				'expires_at'       => $expires_at,
			),
			array( '%d', '%d', '%s', '%s', '%d', '%s', '%s' )
		);
		return false === $ok ? new \WP_Error( 'acl_ar_restriction_failed', __( 'Restriction could not be saved.', 'acl-agent-rooms' ) ) : (int) $wpdb->insert_id; }
	public function revoke( int $room_id, int $user_id, string $type, int $actor_id ): int {
		global $wpdb;
		return (int) $wpdb->query( $wpdb->prepare( 'UPDATE ' . $this->table() . ' SET revoked_by=%d,revoked_at=%s WHERE room_id=%d AND user_id=%d AND restriction_type=%s AND revoked_at IS NULL', $actor_id, Time::mysql_gmt(), $room_id, $user_id, $type ) ); }
	public function count_active( string $type = '' ): int {
		global $wpdb;
		$where = $type !== '' ? $wpdb->prepare( ' AND restriction_type=%s', $type ) : '';
		return (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . $this->table() . ' WHERE revoked_at IS NULL AND (expires_at IS NULL OR expires_at>%s)' . $where, Time::mysql_gmt() ) ); }
}
