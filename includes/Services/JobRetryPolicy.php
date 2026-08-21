<?php
/**
 * Authoritative bounded job retry policy.
 *
 * @package ACL_Agent_Rooms
 */

namespace ACL\AgentRooms\Services;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class JobRetryPolicy {
	public const MAX_ATTEMPTS = 3;

	public function classify( \WP_Error $error, int $attempts ): array {
		$code           = sanitize_key( (string) $error->get_error_code() );
		$data           = $error->get_error_data();
		$status         = is_array( $data ) ? (int) ( $data['status'] ?? 0 ) : 0;
		$terminal_codes = array(
			'acl_ar_job_data_missing',
			'acl_ar_empty_agent_response',
			'acl_ar_room_inactive',
			'acl_ar_agent_not_assigned',
			'invalid_model_selection',
			'invalid_provider_model',
			'model_service_unsupported',
			'explicit_provider_unavailable',
			'provider_unsupported_service',
			'credentials_missing',
			'provider_disabled',
			'acl_ar_rest_forbidden',
		);
		$retryable      = $attempts < self::MAX_ATTEMPTS
			&& ! in_array( $code, $terminal_codes, true )
			&& ( 0 === $status || 408 === $status || 429 === $status || $status >= 500 );

		return array(
			'retryable' => $retryable,
			'code'      => $code ?: 'acl_ar_agent_job_failed',
			'public'    => PublicError::from_error( $error, __( 'Agent reply failed.', 'acl-agent-rooms' ) ),
			'delay'     => $retryable ? $this->delay_for_attempt( $attempts ) : 0,
		);
	}

	public function can_attempt( array $job, bool $intentional = false ): bool {
		if ( (int) ( $job['attempts'] ?? 0 ) >= self::MAX_ATTEMPTS ) {
			return false;
		}

		$status = (string) ( $job['status'] ?? '' );
		if ( 'pending' === $status ) {
			return true;
		}
		if ( 'failed' === $status ) {
			return $intentional || ! empty( $job['retryable'] );
		}
		if ( 'running' === $status ) {
			return empty( $job['lease_expires_at'] ) || strtotime( (string) $job['lease_expires_at'] . ' UTC' ) <= time();
		}

		return false;
	}

	private function delay_for_attempt( int $attempts ): int {
		$delays = array( 30, 120, 300 );
		return (int) ( $delays[ max( 0, min( count( $delays ) - 1, $attempts - 1 ) ) ] ?? 300 );
	}
}
