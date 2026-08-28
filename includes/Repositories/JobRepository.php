<?php
// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange -- This file operates on plugin-owned operational tables and requires uncached transaction/read-after-write truth.
// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- Identifiers are fixed $wpdb->prefix suffixes, fixed internal schema fragments, or allowlisted names; runtime values use placeholders or wpdb mutation arrays.
// phpcs:disable PluginCheck.Security.DirectDB.UnescapedDBParameter -- PHPCS cannot model the fixed identifier provenance above; request data is never used as an SQL identifier.
/**
 * Agent job persistence with bounded leases and retries.
 *
 * @package ACL_Agent_Rooms
 */

namespace ACL\AgentRooms\Repositories;

use ACL\AgentRooms\Models\AgentJob;
use ACL\AgentRooms\Services\JobRetryPolicy;
use ACL\AgentRooms\Support\Time;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class JobRepository {
	private function table(): string {
		global $wpdb;
		return $wpdb->prefix . 'acl_ar_agent_jobs';
	}

	public static function request_key( string $scope, int $room_id, int $trigger_message_id, int $agent_id ): string {
		return hash( 'sha256', sanitize_key( $scope ) . '|' . $room_id . '|' . $trigger_message_id . '|' . $agent_id );
	}

	public function create( int $room_id, int $trigger_message_id, int $agent_id, string $request_key = '' ) {
		global $wpdb;
		$request_key = $this->normalize_request_key( $request_key );
		if ( '' !== $request_key ) {
			$existing = $this->find_by_request_key( $request_key );
			if ( $existing ) {
				return (int) $existing['id'];
			}
		}

		$now      = Time::mysql_gmt();
		$inserted = $wpdb->insert(
			$this->table(),
			array(
				'room_id'            => $room_id,
				'trigger_message_id' => $trigger_message_id,
				'agent_id'           => $agent_id,
				'request_key'        => '' !== $request_key ? $request_key : null,
				'status'             => 'pending',
				'attempts'           => 0,
				'retryable'          => 0,
				'next_attempt_at'    => $now,
				'created_at'         => $now,
				'updated_at'         => $now,
			),
			array( '%d', '%d', '%d', '%s', '%s', '%d', '%d', '%s', '%s', '%s' )
		);
		if ( false === $inserted ) {
			$existing = '' !== $request_key ? $this->find_by_request_key( $request_key ) : null;
			return $existing ? (int) $existing['id'] : new \WP_Error( 'acl_ar_job_not_created', $wpdb->last_error ?: __( 'Agent job could not be created.', 'acl-agent-rooms' ) );
		}
		return (int) $wpdb->insert_id;
	}

	public function find( int $id ): ?array {
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . $this->table() . ' WHERE id = %d', $id ), ARRAY_A );
		return $row ? AgentJob::from_row( $row ) : null;
	}

	public function find_for_update( int $id ): ?array {
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . $this->table() . ' WHERE id = %d FOR UPDATE', $id ), ARRAY_A );
		return $row ? AgentJob::from_row( $row ) : null;
	}

	public function find_by_request_key( string $request_key ): ?array {
		global $wpdb;
		$request_key = $this->normalize_request_key( $request_key );
		if ( '' === $request_key ) {
			return null;
		}
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . $this->table() . ' WHERE request_key = %s', $request_key ), ARRAY_A );
		return $row ? AgentJob::from_row( $row ) : null;
	}

	public function schedule( int $id, string $due_at ): bool {
		global $wpdb;
		return false !== $wpdb->update(
			$this->table(),
			array(
				'next_attempt_at' => $due_at,
				'updated_at'      => Time::mysql_gmt(),
			),
			array(
				'id'     => $id,
				'status' => 'pending',
			),
			array( '%s', '%s' ),
			array( '%d', '%s' )
		);
	}

	public function for_trigger_message( int $message_id ): array {
		global $wpdb;
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . $this->table() . ' WHERE trigger_message_id = %d ORDER BY id ASC', $message_id ), ARRAY_A );
		return array_map( array( AgentJob::class, 'from_row' ), is_array( $rows ) ? $rows : array() );
	}

	public function missing_lifecycle_batch( int $limit = 100 ): array {
		global $wpdb;
		$limit  = max( 1, min( 500, $limit ) );
		$jobs   = $this->table();
		$events = $wpdb->prefix . 'acl_ar_events';
		$sql    = $wpdb->prepare(
			"SELECT DISTINCT j.* FROM {$jobs} j
			LEFT JOIN {$events} q ON q.job_id = j.id AND q.event_type = 'agent_queued'
			LEFT JOIN {$events} c ON c.job_id = j.id AND c.event_type = 'agent_completed'
			LEFT JOIN {$events} f ON f.job_id = j.id AND f.event_type = 'agent_failed'
				AND f.idempotency_key = SHA2(CONCAT('agent-job:',j.id,':agent_failed',CASE WHEN j.attempts>1 THEN CONCAT(':attempt-',j.attempts) ELSE '' END),256)
			WHERE q.id IS NULL OR (j.status = 'completed' AND c.id IS NULL) OR (j.status = 'failed' AND f.id IS NULL)
			ORDER BY j.id ASC LIMIT %d",
			$limit
		);
		$rows   = $wpdb->get_results( $sql, ARRAY_A );
		return array_map( array( AgentJob::class, 'from_row' ), is_array( $rows ) ? $rows : array() );
	}

	public function count_missing_lifecycle(): int {
		global $wpdb;
		$jobs   = $this->table();
		$events = $wpdb->prefix . 'acl_ar_events';
		return (int) $wpdb->get_var(
			"SELECT COUNT(DISTINCT j.id) FROM {$jobs} j
			LEFT JOIN {$events} q ON q.job_id = j.id AND q.event_type = 'agent_queued'
			LEFT JOIN {$events} c ON c.job_id = j.id AND c.event_type = 'agent_completed'
			LEFT JOIN {$events} f ON f.job_id = j.id AND f.event_type = 'agent_failed'
				AND f.idempotency_key = SHA2(CONCAT('agent-job:',j.id,':agent_failed',CASE WHEN j.attempts>1 THEN CONCAT(':attempt-',j.attempts) ELSE '' END),256)
			WHERE q.id IS NULL OR (j.status = 'completed' AND c.id IS NULL) OR (j.status = 'failed' AND f.id IS NULL)"
		);
	}

	public function pending( int $limit = 5 ): array {
		global $wpdb;
		$limit = max( 1, min( 20, $limit ) );
		$now   = Time::mysql_gmt();
		$table = $this->table();
		$sql   = $wpdb->prepare(
			"SELECT * FROM {$table} WHERE attempts < %d AND ((status = 'pending' AND (next_attempt_at IS NULL OR next_attempt_at <= %s)) OR (status = 'failed' AND retryable = 1 AND next_attempt_at <= %s) OR (status = 'running' AND (lease_expires_at IS NULL OR lease_expires_at <= %s))) ORDER BY id ASC LIMIT %d",
			JobRetryPolicy::MAX_ATTEMPTS,
			$now,
			$now,
			$now,
			$limit
		);
		$rows  = $wpdb->get_results( $sql, ARRAY_A );
		return array_map( array( AgentJob::class, 'from_row' ), is_array( $rows ) ? $rows : array() );
	}

	public function acquire( int $id, string $lease_token, int $lease_seconds = 120, bool $intentional_retry = false ): bool {
		global $wpdb;
		$now           = Time::mysql_gmt();
		$expires       = gmdate( 'Y-m-d H:i:s', time() + max( 30, $lease_seconds ) );
		$table         = $this->table();
		$failed_clause = $intentional_retry ? "status = 'failed'" : "status = 'failed' AND retryable = 1 AND (next_attempt_at IS NULL OR next_attempt_at <= %s)";
		$sql           = "UPDATE {$table} SET status = %s, attempts = attempts + 1, retryable = 0, lease_token = %s, locked_at = %s, lease_expires_at = %s, updated_at = %s WHERE id = %d AND attempts < %d AND ((status = 'pending' AND (next_attempt_at IS NULL OR next_attempt_at <= %s)) OR ({$failed_clause}) OR (status = 'running' AND (lease_expires_at IS NULL OR lease_expires_at <= %s)))";
		if ( $intentional_retry ) {
			$params = array( 'running', $lease_token, $now, $expires, $now, $id, JobRetryPolicy::MAX_ATTEMPTS, $now, $now );
		} else {
			$params = array( 'running', $lease_token, $now, $expires, $now, $id, JobRetryPolicy::MAX_ATTEMPTS, $now, $now, $now );
		}
		return false !== $wpdb->query( $wpdb->prepare( $sql, ...$params ) ) && $wpdb->rows_affected > 0;
	}

	/** Backward-compatible lock wrapper. */
	public function lock( int $id ): bool {
		return $this->acquire( $id, hash( 'sha256', wp_generate_uuid4() ), 120, false );
	}

	public function complete( int $id, int $response_message_id = 0, string $lease_token = '' ): bool {
		global $wpdb;
		$now   = Time::mysql_gmt();
		$data  = array(
			'status'              => 'completed',
			'retryable'           => 0,
			'error_code'          => null,
			'error_message'       => null,
			'public_error'        => null,
			'response_message_id' => $response_message_id ?: null,
			'lease_token'         => null,
			'lease_expires_at'    => null,
			'next_attempt_at'     => null,
			'completed_at'        => $now,
			'updated_at'          => $now,
		);
		$where = array( 'id' => $id );
		if ( '' !== $lease_token ) {
			$where['lease_token'] = $lease_token;
		} else {
			$current = $this->find( $id );
			if ( ! $current ) {
				return false;
			}
			if ( 'completed' === (string) $current['status'] ) {
				return (int) $current['response_message_id'] === $response_message_id;
			}
			$recoverable = in_array( (string) $current['status'], array( 'pending', 'running' ), true ) || ( 'failed' === (string) $current['status'] && ! empty( $current['retryable'] ) );
			if ( ! $recoverable || ! empty( $current['response_message_id'] ) ) {
				return false;
			}
			$where['status']              = (string) $current['status'];
			$where['response_message_id'] = null;
			if ( 'failed' === (string) $current['status'] ) {
				$where['retryable'] = 1;
			}
		}
		return 1 === $wpdb->update( $this->table(), $data, $where );
	}

	public function fail( int $id, string $message, string $code = '', string $public = '', bool $retryable = false, int $delay = 0, string $lease_token = '' ): bool {
		global $wpdb;
		$now   = Time::mysql_gmt();
		$next  = $retryable ? gmdate( 'Y-m-d H:i:s', time() + max( 1, $delay ) ) : null;
		$where = array( 'id' => $id );
		if ( '' !== $lease_token ) {
			$where['lease_token'] = $lease_token;
		}
		return 1 === $wpdb->update(
			$this->table(),
			array(
				'status'           => 'failed',
				'retryable'        => $retryable ? 1 : 0,
				'error_code'       => sanitize_key( $code ),
				'error_message'    => sanitize_text_field( $message ),
				'public_error'     => sanitize_text_field( $public ),
				'lease_token'      => null,
				'lease_expires_at' => null,
				'next_attempt_at'  => $next,
				'updated_at'       => $now,
			),
			$where
		);
	}

	public function cancel_for_assignment( int $room_id, int $agent_id ): int {
		global $wpdb;
		$table = $this->table();
		$now   = Time::mysql_gmt();
		$sql   = $wpdb->prepare( "UPDATE {$table} SET status='canceled',retryable=0,error_code='agent_paused',error_message='Agent participation paused.',public_error='Agent is paused.',lease_token=NULL,locked_at=NULL,lease_expires_at=NULL,next_attempt_at=NULL,updated_at=%s WHERE room_id=%d AND agent_id=%d AND response_message_id IS NULL AND (status='pending' OR (status='failed' AND retryable=1) OR (status='running' AND lease_expires_at<=%s))", $now, $room_id, $agent_id, $now );
		$wpdb->query( $sql );
		return (int) $wpdb->rows_affected;
	}
	public function cancel_for_room_cutoff( int $room_id, int $cutoff ): int {
		global $wpdb;
		$jobs   = $this->table();
		$events = $wpdb->prefix . 'acl_ar_events';
		$now    = Time::mysql_gmt();
		$sql    = $wpdb->prepare( "UPDATE {$jobs} j INNER JOIN {$events} e ON e.legacy_message_id=j.trigger_message_id SET j.status='canceled',j.retryable=0,j.error_code='chat_cleared',j.error_message='The triggering message was cleared.',j.public_error='The room transcript was cleared.',j.lease_token=NULL,j.locked_at=NULL,j.lease_expires_at=NULL,j.next_attempt_at=NULL,j.completed_at=%s,j.updated_at=%s WHERE j.room_id=%d AND e.room_id=%d AND e.id<=%d AND (j.status IN ('pending','running') OR (j.status='failed' AND j.retryable=1))", $now, $now, $room_id, $room_id, $cutoff );
		$wpdb->query( $sql );
		return (int) $wpdb->rows_affected;
	}
	public function cancel( int $id, string $code = 'agent_paused' ): bool {
		global $wpdb;
		$table      = $this->table();
		$now        = Time::mysql_gmt();
		$code       = sanitize_key( $code );
		$cleared    = 'chat_cleared' === $code;
		$superseded = 'superseded' === $code;
		$message    = $cleared ? 'The triggering message was cleared.' : ( $superseded ? 'The triggering message was superseded.' : 'Agent participation paused.' );
		$public     = $cleared ? 'The room transcript was cleared.' : ( $superseded ? 'The conversation advanced before this response completed.' : 'Agent is paused.' );
		$sql        = $wpdb->prepare(
			"UPDATE {$table} SET status='canceled',retryable=0,error_code=%s,error_message=%s,public_error=%s,lease_token=NULL,locked_at=NULL,lease_expires_at=NULL,next_attempt_at=NULL,completed_at=%s,updated_at=%s WHERE id=%d AND response_message_id IS NULL AND (status IN ('pending','running') OR (status='failed' AND retryable=1))",
			$code,
			$message,
			$public,
			$now,
			$now,
			$id
		);
		$updated    = $wpdb->query( $sql );
		if ( false === $updated ) {
			return false;
		}
		if ( $updated > 0 ) {
			return true;
		}
		$current = $this->find( $id );
		return $current && ( in_array( (string) $current['status'], array( 'completed', 'canceled' ), true ) || ( 'failed' === (string) $current['status'] && empty( $current['retryable'] ) ) ); }

	public function has_running( int $room_id, int $agent_id ): bool {
		global $wpdb;
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$this->table()} WHERE room_id=%d AND agent_id=%d AND status='running' AND response_message_id IS NULL AND lease_expires_at>%s", $room_id, $agent_id, Time::mysql_gmt() ) ) > 0; }
	public function active_for_assignment( int $room_id, int $agent_id ): ?array {
		global $wpdb;
		$now = Time::mysql_gmt();
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$this->table()} WHERE room_id=%d AND agent_id=%d AND response_message_id IS NULL AND (status IN ('pending','running') OR (status='failed' AND retryable=1 AND next_attempt_at IS NOT NULL AND attempts<%d)) ORDER BY CASE WHEN status='running' AND lease_expires_at IS NOT NULL AND lease_expires_at>%s THEN 0 ELSE 1 END,id DESC LIMIT 1", $room_id, $agent_id, JobRetryPolicy::MAX_ATTEMPTS, $now ), ARRAY_A );
		return $row ? AgentJob::from_row( $row ) : null; }
	public function latest_for_assignment( int $room_id, int $agent_id ): ?array {
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . $this->table() . ' WHERE room_id=%d AND agent_id=%d ORDER BY id DESC LIMIT 1', $room_id, $agent_id ), ARRAY_A );
		return $row ? AgentJob::from_row( $row ) : null; }

	private function normalize_request_key( string $request_key ): string {
		$request_key = strtolower( preg_replace( '/[^a-f0-9]/', '', $request_key ) ?: '' );
		return 64 === strlen( $request_key ) ? $request_key : '';
	}
}
