<?php
/** Bounded manager-only diagnostics for post-persistence dispatch failures. @package ACL_Agent_Rooms */

namespace ACL\AgentRooms\Services;

if ( ! defined( 'ABSPATH' ) ) {
	exit; }

class OrchestrationDiagnosticService {
	public const OPTION = 'acl_ar_orchestration_diagnostics';
	private const LIMIT = 20;

	public function record( int $room_id, int $event_id, string $code ): void {
		$code    = sanitize_key( $code ) ?: 'acl_ar_orchestration_failed';
		$items   = array_values( array_filter( (array) get_option( self::OPTION, array() ), 'is_array' ) );
		$items[] = array(
			'room_id'     => $room_id,
			'event_id'    => $event_id,
			'code'        => $code,
			'recorded_at' => gmdate( 'c' ),
		);
		update_option( self::OPTION, array_slice( $items, -self::LIMIT ), false );
		// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- This redacted server-side operational failure contains no message, prompt, credential, or user content.
		error_log( sprintf( '[ACL Agent Rooms] Post-persistence orchestration issue (%s) for room %d event %d.', $code, $room_id, $event_id ) );
		do_action( 'acl_ar_orchestration_diagnostic_recorded', $code, $room_id, $event_id );
	}

	public function snapshot(): array {
		$items  = array_values( array_filter( (array) get_option( self::OPTION, array() ), 'is_array' ) );
		$latest = $items ? end( $items ) : null;
		return array(
			'failure_count' => count( $items ),
			'latest'        => $latest ? array(
				'room_id'     => absint( $latest['room_id'] ?? 0 ),
				'event_id'    => absint( $latest['event_id'] ?? 0 ),
				'code'        => sanitize_key( (string) ( $latest['code'] ?? '' ) ),
				'recorded_at' => sanitize_text_field( (string) ( $latest['recorded_at'] ?? '' ) ),
			) : null,
		);
	}
}
