<?php
// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange -- This file operates on plugin-owned operational tables and requires uncached transaction/read-after-write truth.
// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- Identifiers are fixed $wpdb->prefix suffixes, fixed internal schema fragments, or allowlisted names; runtime values use placeholders or wpdb mutation arrays.
// phpcs:disable PluginCheck.Security.DirectDB.UnescapedDBParameter -- PHPCS cannot model the fixed identifier provenance above; request data is never used as an SQL identifier.
/** Durable Natural Conversation turn persistence. @package ACL_Agent_Rooms */
namespace ACL\AgentRooms\Repositories;

use ACL\AgentRooms\Models\ConversationTurn;
use ACL\AgentRooms\Support\Time;

if ( ! defined( 'ABSPATH' ) ) {
	exit; }

class ConversationTurnRepository {
	private function table(): string {
		global $wpdb;
		return $wpdb->prefix . 'acl_ar_conversation_turns'; }

	public static function key( int $room_id, int $trigger_event_id, int $agent_id, string $source ): string {
		return hash( 'sha256', 'natural-turn|' . $room_id . '|' . $trigger_event_id . '|' . $agent_id . '|' . sanitize_key( $source ) );
	}

	public function create( array $data ) {
		global $wpdb;
		$source   = in_array( (string) ( $data['source_type'] ?? '' ), ConversationTurn::SOURCES, true ) ? (string) $data['source_type'] : '';
		$purpose  = in_array( (string) ( $data['purpose'] ?? 'reply' ), ConversationTurn::PURPOSES, true ) ? (string) $data['purpose'] : 'reply';
		$key      = (string) ( $data['idempotency_key'] ?? self::key( (int) $data['room_id'], (int) $data['trigger_event_id'], (int) $data['agent_id'], $source ) );
		$existing = $this->find_by_key( $key );
		if ( $existing ) {
			return $existing; }
		$now = Time::mysql_gmt();
		$ok  = $wpdb->insert(
			$this->table(),
			array(
				'room_id'          => absint( $data['room_id'] ?? 0 ),
				'trigger_event_id' => absint( $data['trigger_event_id'] ?? 0 ),
				'agent_id'         => absint( $data['agent_id'] ?? 0 ),
				'brain_run_id'     => ! empty( $data['brain_run_id'] ) ? absint( $data['brain_run_id'] ) : null,
				'job_id'           => ! empty( $data['job_id'] ) ? absint( $data['job_id'] ) : null,
				'source_type'      => $source,
				'status'           => 'pending',
				'purpose'          => $purpose,
				'content'          => isset( $data['content'] ) ? (string) $data['content'] : null,
				'due_at'           => (string) $data['due_at'],
				'typing_at'        => $data['typing_at'] ?? null,
				'idempotency_key'  => $key,
				'created_at'       => $now,
				'updated_at'       => $now,
			)
		);
		if ( false === $ok ) {
			$existing = $this->find_by_key( $key );
			return $existing ?: new \WP_Error( 'acl_ar_turn_not_created', $wpdb->last_error ?: __( 'Conversation turn could not be created.', 'acl-agent-rooms' ) ); }
		return $this->find( (int) $wpdb->insert_id );
	}

	public function find( int $id ): ?array {
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . $this->table() . ' WHERE id=%d', $id ), ARRAY_A );
		return $row ? ConversationTurn::from_row( $row ) : null; }
	public function find_by_key( string $key ): ?array {
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . $this->table() . ' WHERE idempotency_key=%s', $key ), ARRAY_A );
		return $row ? ConversationTurn::from_row( $row ) : null; }
	public function find_by_job( int $job_id ): ?array {
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . $this->table() . ' WHERE job_id=%d ORDER BY id DESC LIMIT 1', $job_id ), ARRAY_A );
		return $row ? ConversationTurn::from_row( $row ) : null; }
	public function for_brain_run( int $run_id ): array {
		global $wpdb;
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . $this->table() . ' WHERE brain_run_id=%d ORDER BY due_at,id', $run_id ), ARRAY_A );
		return array_map( array( ConversationTurn::class, 'from_row' ), is_array( $rows ) ? $rows : array() ); }
	public function for_trigger( int $trigger_event_id ): array {
		global $wpdb;
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . $this->table() . ' WHERE trigger_event_id=%d ORDER BY due_at,id', $trigger_event_id ), ARRAY_A );
		return array_map( array( ConversationTurn::class, 'from_row' ), is_array( $rows ) ? $rows : array() ); }
	public function active_older_than( int $room_id, int $trigger_id ): array {
		global $wpdb;
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$this->table()} WHERE room_id=%d AND trigger_event_id<%d AND status IN ('pending','typing','publishing') ORDER BY id", $room_id, $trigger_id ), ARRAY_A );
		return array_map( array( ConversationTurn::class, 'from_row' ), is_array( $rows ) ? $rows : array() ); }
	public function active( int $limit = 50 ): array {
		global $wpdb;
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$this->table()} WHERE status IN ('pending','typing','publishing') ORDER BY due_at,id LIMIT %d", max( 1, min( 200, $limit ) ) ), ARRAY_A );
		return array_map( array( ConversationTurn::class, 'from_row' ), is_array( $rows ) ? $rows : array() ); }
	public function due( int $limit = 10 ): array {
		global $wpdb;
		$now   = Time::mysql_gmt();
		$stale = gmdate( 'Y-m-d H:i:s', time() - 180 );
		$rows  = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$this->table()} WHERE ((status IN ('pending','typing') AND due_at<=%s) OR (status='publishing' AND updated_at<=%s)) ORDER BY due_at,id LIMIT %d", $now, $stale, max( 1, min( 50, $limit ) ) ), ARRAY_A );
		return array_map( array( ConversationTurn::class, 'from_row' ), is_array( $rows ) ? $rows : array() ); }
	public function typing_due( int $limit = 20 ): array {
		global $wpdb;
		$now  = Time::mysql_gmt();
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$this->table()} WHERE status='pending' AND typing_at IS NOT NULL AND typing_at<=%s AND due_at>%s ORDER BY typing_at,id LIMIT %d", $now, $now, max( 1, min( 50, $limit ) ) ), ARRAY_A );
		return array_map( array( ConversationTurn::class, 'from_row' ), is_array( $rows ) ? $rows : array() ); }

	public function mark_typing( int $id ): bool {
		global $wpdb;
		$wpdb->query( $wpdb->prepare( "UPDATE {$this->table()} SET status='typing',updated_at=%s WHERE id=%d AND status='pending'", Time::mysql_gmt(), $id ) );
		return $wpdb->rows_affected > 0; }
	public function acquire( int $id ): bool {
		global $wpdb;
		$now   = Time::mysql_gmt();
		$stale = gmdate( 'Y-m-d H:i:s', time() - 180 );
		$wpdb->query( $wpdb->prepare( "UPDATE {$this->table()} SET status='publishing',updated_at=%s WHERE id=%d AND due_at<=%s AND (status IN ('pending','typing') OR (status='publishing' AND updated_at<=%s))", $now, $id, $now, $stale ) );
		return $wpdb->rows_affected > 0; }
	public function postpone( int $id, int $seconds = 2 ): bool {
		global $wpdb;
		$due = gmdate( 'Y-m-d H:i:s', time() + max( 1, min( 30, $seconds ) ) );
		return false !== $wpdb->update(
			$this->table(),
			array(
				'status'     => 'pending',
				'due_at'     => $due,
				'typing_at'  => Time::mysql_gmt(),
				'updated_at' => Time::mysql_gmt(),
			),
			array(
				'id'     => $id,
				'status' => 'publishing',
			)
		); }
	public function save_brain_content( int $run_id, array $turns ): bool {
		global $wpdb;
		$stored   = $this->for_brain_run( $run_id );
		$by_agent = array();
		foreach ( $stored as $turn ) {
			$by_agent[ (int) $turn['agent_id'] ] = $turn; }
		foreach ( $turns as $item ) {
			$id = absint( $item['agent_id'] ?? 0 );
			if ( empty( $by_agent[ $id ] ) ) {
				return false;
			} $content = trim( wp_strip_all_tags( (string) ( $item['content'] ?? '' ) ) );
			$purpose   = in_array( (string) ( $item['purpose'] ?? 'reply' ), ConversationTurn::PURPOSES, true ) ? (string) $item['purpose'] : 'reply';
			if ( '' === $content ) {
				return false;
			} $wpdb->query( $wpdb->prepare( "UPDATE {$this->table()} SET content=%s,purpose=%s,updated_at=%s WHERE id=%d AND status IN ('pending','typing')", $content, $purpose, Time::mysql_gmt(), (int) $by_agent[ $id ]['id'] ) );
			if ( $wpdb->rows_affected < 1 ) {
				return false; }
		}
		return true;
	}
	public function publish( int $id, int $event_id ): bool {
		global $wpdb;
		return false !== $wpdb->update(
			$this->table(),
			array(
				'status'             => 'published',
				'published_event_id' => $event_id,
				'content'            => null,
				'updated_at'         => Time::mysql_gmt(),
			),
			array(
				'id'     => $id,
				'status' => 'publishing',
			)
		); }
	public function fail( int $id, string $reason = 'publish_failed' ): bool {
		global $wpdb;
		return false !== $wpdb->update(
			$this->table(),
			array(
				'status'        => 'failed',
				'cancel_reason' => sanitize_key( $reason ),
				'content'       => null,
				'updated_at'    => Time::mysql_gmt(),
			),
			array( 'id' => $id )
		); }
	public function cancel( int $id, string $reason ): bool {
		global $wpdb;
		$wpdb->query( $wpdb->prepare( "UPDATE {$this->table()} SET status='canceled',cancel_reason=%s,content=NULL,updated_at=%s WHERE id=%d AND status IN ('pending','typing','publishing')", sanitize_key( $reason ), Time::mysql_gmt(), $id ) );
		return $wpdb->rows_affected > 0; }
	public function cancel_older_triggers( int $room_id, int $trigger_id ): int {
		global $wpdb;
		$wpdb->query( $wpdb->prepare( "UPDATE {$this->table()} SET status='canceled',cancel_reason='superseded',content=NULL,updated_at=%s WHERE room_id=%d AND trigger_event_id<%d AND status IN ('pending','typing','publishing')", Time::mysql_gmt(), $room_id, $trigger_id ) );
		return (int) $wpdb->rows_affected; }
	public function cancel_for_cutoff( int $room_id, int $cutoff ): int {
		global $wpdb;
		$wpdb->query( $wpdb->prepare( "UPDATE {$this->table()} SET status='canceled',cancel_reason='chat_cleared',content=NULL,updated_at=%s WHERE room_id=%d AND trigger_event_id<=%d AND status IN ('pending','typing','publishing')", Time::mysql_gmt(), $room_id, $cutoff ) );
		return (int) $wpdb->rows_affected; }
	public function cancel_for_agent( int $room_id, int $agent_id, string $reason = 'agent_paused' ): int {
		global $wpdb;
		$wpdb->query( $wpdb->prepare( "UPDATE {$this->table()} SET status='canceled',cancel_reason=%s,content=NULL,updated_at=%s WHERE room_id=%d AND agent_id=%d AND status IN ('pending','typing','publishing')", sanitize_key( $reason ), Time::mysql_gmt(), $room_id, $agent_id ) );
		return (int) $wpdb->rows_affected; }
	public function cancel_for_brain( int $run_id, string $reason ): int {
		global $wpdb;
		$wpdb->query( $wpdb->prepare( "UPDATE {$this->table()} SET status='canceled',cancel_reason=%s,content=NULL,updated_at=%s WHERE brain_run_id=%d AND status IN ('pending','typing','publishing')", sanitize_key( $reason ), Time::mysql_gmt(), $run_id ) );
		return (int) $wpdb->rows_affected; }
	public function pending_count( int $room_id ): int {
		global $wpdb;
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$this->table()} WHERE room_id=%d AND status IN ('pending','typing','publishing')", $room_id ) ); }
	public function active_for_assignment( int $room_id, int $agent_id ): ?array {
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$this->table()} WHERE room_id=%d AND agent_id=%d AND status IN ('pending','typing','publishing') ORDER BY due_at,id LIMIT 1", $room_id, $agent_id ), ARRAY_A );
		return $row ? ConversationTurn::from_row( $row ) : null; }
	public function recent_published_count( int $room_id, int $agent_id, int $seconds = 600 ): int {
		global $wpdb;
		$since = gmdate( 'Y-m-d H:i:s', time() - max( 1, $seconds ) );
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$this->table()} WHERE room_id=%d AND agent_id=%d AND status='published' AND updated_at>=%s", $room_id, $agent_id, $since ) ); }
	public function latest_published_at( int $room_id, int $agent_id ): ?string {
		global $wpdb;
		$value = $wpdb->get_var( $wpdb->prepare( "SELECT updated_at FROM {$this->table()} WHERE room_id=%d AND agent_id=%d AND status='published' ORDER BY updated_at DESC,id DESC LIMIT 1", $room_id, $agent_id ) );
		return is_string( $value ) && '' !== $value ? $value : null; }
	public function distribution(): array {
		global $wpdb;
		$rows = $wpdb->get_results( "SELECT status,COUNT(*) AS count FROM {$this->table()} GROUP BY status ORDER BY status", ARRAY_A );
		return is_array( $rows ) ? $rows : array(); }
	public function counts_for_brain( int $run_id ): array {
		global $wpdb;
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT status,COUNT(*) AS count FROM {$this->table()} WHERE brain_run_id=%d GROUP BY status", $run_id ), ARRAY_A );
		$out  = array();
		foreach ( (array) $rows as $row ) {
			$out[ (string) $row['status'] ] = (int) $row['count'];
		} return $out; }
	public function delete_for_room( int $room_id ): bool {
		global $wpdb;
		return false !== $wpdb->delete( $this->table(), array( 'room_id' => $room_id ), array( '%d' ) ); }
}
