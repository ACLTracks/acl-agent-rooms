<?php
// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange -- This file operates on plugin-owned operational tables and requires uncached transaction/read-after-write truth.
// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- Identifiers are fixed $wpdb->prefix suffixes, fixed internal schema fragments, or allowlisted names; runtime values use placeholders or wpdb mutation arrays.
// phpcs:disable PluginCheck.Security.DirectDB.UnescapedDBParameter -- PHPCS cannot model the fixed identifier provenance above; request data is never used as an SQL identifier.
/** Durable Shared Brain run persistence and leases. @package ACL_Agent_Rooms */
namespace ACL\AgentRooms\Repositories;

use ACL\AgentRooms\Models\BrainRun;
use ACL\AgentRooms\Support\Json;
use ACL\AgentRooms\Support\Time;

if ( ! defined( 'ABSPATH' ) ) {
	exit; }

class BrainRunRepository {
	public const MAX_ATTEMPTS = 3;
	private function table(): string {
		global $wpdb;
		return $wpdb->prefix . 'acl_ar_brain_runs'; }
	public static function request_key( int $room_id, int $brain_id, int $trigger_event_id, array $agent_ids ): string {
		return hash( 'sha256', 'brain-run:' . $room_id . ':' . $brain_id . ':' . $trigger_event_id . ':' . implode( ',', array_map( 'absint', $agent_ids ) ) ); }
	public function create( int $room_id, int $brain_id, int $trigger_event_id, array $agent_ids, string $provider, string $model ) {
		global $wpdb;
		$ids      = array_values( array_unique( array_filter( array_map( 'absint', $agent_ids ) ) ) );
		$key      = self::request_key( $room_id, $brain_id, $trigger_event_id, $ids );
		$existing = $this->find_by_request_key( $key );
		if ( $existing ) {
			return $existing;
		}$now = Time::mysql_gmt();
		$ok   = $wpdb->insert(
			$this->table(),
			array(
				'room_id'               => $room_id,
				'brain_id'              => $brain_id,
				'trigger_event_id'      => $trigger_event_id,
				'request_key'           => $key,
				'status'                => 'pending',
				'target_agent_ids_json' => Json::encode( $ids ),
				'provider'              => sanitize_text_field( $provider ),
				'model'                 => sanitize_text_field( $model ),
				'attempts'              => 0,
				'next_attempt_at'       => $now,
				'created_at'            => $now,
				'updated_at'            => $now,
			),
			array( '%d', '%d', '%d', '%s', '%s', '%s', '%s', '%s', '%d', '%s', '%s', '%s' )
		);
		if ( false === $ok ) {
			$existing = $this->find_by_request_key( $key );
			return $existing ?: new \WP_Error( 'acl_ar_brain_run_not_created', $wpdb->last_error ?: __( 'Brain run could not be created.', 'acl-agent-rooms' ) );
		}return $this->find( (int) $wpdb->insert_id ); }
	public function find( int $id ): ?array {
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . $this->table() . ' WHERE id=%d', $id ), ARRAY_A );
		return $row ? BrainRun::from_row( $row ) : null; }
	public function find_by_request_key( string $key ): ?array {
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . $this->table() . ' WHERE request_key=%s', $key ), ARRAY_A );
		return $row ? BrainRun::from_row( $row ) : null; }
	public function for_room( int $room_id, int $limit = 50 ): array {
		global $wpdb;
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . $this->table() . ' WHERE room_id=%d ORDER BY id DESC LIMIT %d', $room_id, max( 1, min( 200, $limit ) ) ), ARRAY_A );
		return array_map( array( BrainRun::class, 'from_row' ), is_array( $rows ) ? $rows : array() ); }
	public function for_trigger_event( int $event_id ): array {
		global $wpdb;
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . $this->table() . ' WHERE trigger_event_id=%d ORDER BY id', $event_id ), ARRAY_A );
		return array_map( array( BrainRun::class, 'from_row' ), is_array( $rows ) ? $rows : array() ); }
	public function pending( int $limit = 5 ): array {
		global $wpdb;
		$now   = Time::mysql_gmt();
		$stale = gmdate( 'Y-m-d H:i:s', time() - 180 );
		$rows  = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$this->table()} WHERE status='response_saved' OR (attempts<%d AND ((status='pending' AND (next_attempt_at IS NULL OR next_attempt_at<=%s)) OR (status='failed' AND next_attempt_at IS NOT NULL AND next_attempt_at<=%s) OR (status='running' AND locked_at<=%s))) ORDER BY id LIMIT %d", self::MAX_ATTEMPTS, $now, $now, $stale, max( 1, min( 20, $limit ) ) ), ARRAY_A );
		return array_map( array( BrainRun::class, 'from_row' ), is_array( $rows ) ? $rows : array() ); }
	public function acquire( int $id, string $token, int $lease_seconds = 180, bool $intentional = false ): bool {
		global $wpdb;
		$now    = Time::mysql_gmt();
		$stale  = gmdate( 'Y-m-d H:i:s', time() - max( 30, $lease_seconds ) );
		$failed = $intentional ? "status='failed'" : "status='failed' AND next_attempt_at IS NOT NULL AND next_attempt_at<=" . $wpdb->prepare( '%s', $now );
		$sql    = $wpdb->prepare( "UPDATE {$this->table()} SET status='running',attempts=attempts+1,lease_token=%s,locked_at=%s,started_at=COALESCE(started_at,%s),updated_at=%s WHERE id=%d AND attempts<%d AND ((status='pending' AND (next_attempt_at IS NULL OR next_attempt_at<=%s)) OR ({$failed}) OR (status='running' AND locked_at<=%s))", $token, $now, $now, $now, $id, self::MAX_ATTEMPTS, $now, $stale );
		$wpdb->query( $sql );
		return $wpdb->rows_affected > 0; }
	public function update_targets( int $id, array $ids, string $token ): bool {
		global $wpdb;
		return false !== $wpdb->update(
			$this->table(),
			array(
				'target_agent_ids_json' => Json::encode( array_values( array_map( 'absint', $ids ) ) ),
				'updated_at'            => Time::mysql_gmt(),
			),
			array(
				'id'          => $id,
				'lease_token' => $token,
			),
			array( '%s', '%s' ),
			array( '%d', '%s' )
		); }
	public function save_response( int $id, array $responses, array $usage, string $token ): bool {
		global $wpdb;
		return false !== $wpdb->update(
			$this->table(),
			array(
				'status'                  => 'response_saved',
				'validated_response_json' => Json::encode( $responses ),
				'prompt_tokens'           => absint( $usage['prompt_tokens'] ?? 0 ),
				'completion_tokens'       => absint( $usage['completion_tokens'] ?? 0 ),
				'total_tokens'            => absint( $usage['total_tokens'] ?? 0 ),
				'estimated_cost'          => (float) ( $usage['estimated_cost'] ?? 0 ),
				'lease_token'             => null,
				'locked_at'               => null,
				'next_attempt_at'         => null,
				'updated_at'              => Time::mysql_gmt(),
			),
			array(
				'id'          => $id,
				'lease_token' => $token,
			),
			array( '%s', '%s', '%d', '%d', '%d', '%f', '%s', '%s', '%s', '%s' ),
			array( '%d', '%s' )
		); }
	public function complete( int $id, array $event_ids ): bool {
		global $wpdb;
		$now = Time::mysql_gmt();
		return false !== $wpdb->update(
			$this->table(),
			array(
				'status'                  => 'completed',
				'validated_response_json' => null,
				'response_event_ids_json' => Json::encode( array_values( array_map( 'absint', $event_ids ) ) ),
				'lease_token'             => null,
				'locked_at'               => null,
				'next_attempt_at'         => null,
				'error_code'              => null,
				'public_error'            => null,
				'completed_at'            => $now,
				'updated_at'              => $now,
			),
			array( 'id' => $id )
		); }
	public function fail( int $id, string $code, string $public, bool $retryable, int $delay, string $token = '' ): bool {
		global $wpdb;
		$where = array( 'id' => $id );
		if ( $token ) {
			$where['lease_token'] = $token;
		}$current = $this->find( $id );
		$next     = $retryable && $current && $current['attempts'] < self::MAX_ATTEMPTS ? gmdate( 'Y-m-d H:i:s', time() + max( 1, $delay ) ) : null;
		return false !== $wpdb->update(
			$this->table(),
			array(
				'status'          => 'failed',
				'error_code'      => sanitize_key( $code ),
				'public_error'    => sanitize_text_field( $public ),
				'lease_token'     => null,
				'locked_at'       => null,
				'next_attempt_at' => $next,
				'updated_at'      => Time::mysql_gmt(),
			),
			$where
		); }
	public function cancel( int $id, string $code = 'brain_unavailable', string $public = '' ): bool {
		global $wpdb;
		return false !== $wpdb->update(
			$this->table(),
			array(
				'status'          => 'canceled',
				'error_code'      => sanitize_key( $code ),
				'public_error'    => sanitize_text_field( $public ?: __( 'Shared Brain execution was canceled.', 'acl-agent-rooms' ) ),
				'lease_token'     => null,
				'locked_at'       => null,
				'next_attempt_at' => null,
				'completed_at'    => Time::mysql_gmt(),
				'updated_at'      => Time::mysql_gmt(),
			),
			array( 'id' => $id )
		); }
	public function active_for_assignment( int $room_id, int $agent_id ): ?array {
		foreach ( $this->for_room( $room_id, 100 ) as $run ) {
			if ( in_array( $run['status'], array( 'pending', 'running', 'response_saved' ), true ) && in_array( $agent_id, $run['target_agent_ids'], true ) ) {
				return $run;
			}
		}return null; }
	public function cancel_for_brain( int $brain_id ): int {
		global $wpdb;
		$now = Time::mysql_gmt();
		$wpdb->query( $wpdb->prepare( "UPDATE {$this->table()} SET status='canceled',error_code='brain_disabled',public_error='The Shared Brain is disabled.',lease_token=NULL,locked_at=NULL,next_attempt_at=NULL,completed_at=%s,updated_at=%s WHERE brain_id=%d AND status IN ('pending','failed')", $now, $now, $brain_id ) );
		return (int) $wpdb->rows_affected; }
	public function cancel_for_room_cutoff( int $room_id, int $cutoff ): int {
		global $wpdb;
		$now = Time::mysql_gmt();
		$wpdb->query( $wpdb->prepare( "UPDATE {$this->table()} SET status='canceled',error_code='chat_cleared',public_error='The triggering message was cleared.',lease_token=NULL,locked_at=NULL,next_attempt_at=NULL,completed_at=%s,updated_at=%s WHERE room_id=%d AND trigger_event_id<=%d AND (status IN ('pending','running','response_saved') OR (status='failed' AND next_attempt_at IS NOT NULL))", $now, $now, $room_id, $cutoff ) );
		return (int) $wpdb->rows_affected; }
	public function delete_for_room( int $room_id ): bool {
		global $wpdb;
		return false !== $wpdb->delete( $this->table(), array( 'room_id' => $room_id ), array( '%d' ) ); }
}
