<?php
/**
 * Message rate limiting.
 *
 * @package ACL_Agent_Rooms
 */

namespace ACL\AgentRooms\Services;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class RateLimiter {
	public function can_user_send_message( int $user_id, int $room_id ) {
		$settings = get_option( 'acl_ar_settings', array() );
		$limit    = (int) apply_filters( 'acl_ar_rate_limit_count', (int) ( $settings['rate_limit_count'] ?? 30 ), $user_id, $room_id );
		$window   = (int) apply_filters( 'acl_ar_rate_limit_window', (int) ( $settings['rate_limit_window'] ?? 600 ), $user_id, $room_id );

		$limit  = max( 1, $limit );
		$window = max( 60, $window );
		$key    = (string) apply_filters( 'acl_ar_rate_limit_key', 'acl_ar_rate_' . $user_id . '_' . $room_id, $user_id, $room_id );
		return $this->consume( $key, $limit, $window, __( 'Please wait before sending another message.', 'acl-agent-rooms' ) );
	}

	public function can_user_execute_agent( int $user_id, int $room_id, string $action = '' ) {
		$settings = get_option( 'acl_ar_settings', array() );
		$limit    = max( 1, (int) apply_filters( 'acl_ar_agent_rate_limit_count', (int) ( $settings['agent_rate_limit_count'] ?? 12 ), $user_id, $room_id, $action ) );
		$window   = max( 60, (int) apply_filters( 'acl_ar_agent_rate_limit_window', (int) ( $settings['agent_rate_limit_window'] ?? 600 ), $user_id, $room_id, $action ) );
		$key      = 'acl_ar_agent_rate_' . $user_id . '_' . $room_id;

		return $this->consume( $key, $limit, $window, __( 'Please wait before running another agent action.', 'acl-agent-rooms' ) );
	}

	public function can_user_execute_command( int $user_id, int $room_id, string $command = '' ) {
		$limit  = max( 1, (int) apply_filters( 'acl_ar_command_rate_limit_count', 30, $user_id, $room_id, $command ) );
		$window = max( 60, (int) apply_filters( 'acl_ar_command_rate_limit_window', 60, $user_id, $room_id, $command ) );
		return $this->consume( 'acl_ar_command_rate_' . $user_id . '_' . $room_id, $limit, $window, __( 'Please wait before running another room command.', 'acl-agent-rooms' ) );
	}

	public function can_user_whisper( int $user_id, int $room_id ) {
		$limit  = max( 1, (int) apply_filters( 'acl_ar_whisper_rate_limit_count', 20, $user_id, $room_id ) );
		$window = max( 60, (int) apply_filters( 'acl_ar_whisper_rate_limit_window', 60, $user_id, $room_id ) );
		return $this->consume( 'acl_ar_whisper_rate_' . $user_id . '_' . $room_id, $limit, $window, __( 'Please wait before sending another whisper.', 'acl-agent-rooms' ) );
	}

	public function can_heartbeat( int $user_id, int $room_id, string $session_scope ) {
		$limit = max( 2, min( 60, (int) apply_filters( 'acl_ar_presence_rate_limit_count', 8, $user_id, $room_id ) ) );
		return $this->consume( 'acl_ar_presence_rate_' . $user_id . '_' . $room_id . '_' . substr( hash( 'sha256', $session_scope ), 0, 16 ), $limit, 60, __( 'Please wait before sending another presence update.', 'acl-agent-rooms' ) );
	}
	public function can_search( int $user_id, int $room_id ) {
		return $this->consume( 'acl_ar_search_rate_' . $user_id . '_' . $room_id, 30, 60, __( 'Please wait before searching again.', 'acl-agent-rooms' ) ); }
	public function can_moderate( int $user_id, int $room_id ) {
		return $this->consume( 'acl_ar_moderation_rate_' . $user_id . '_' . $room_id, 20, 300, __( 'Please wait before applying another moderation action.', 'acl-agent-rooms' ) ); }
	public function can_maintain( int $user_id ) {
		return $this->consume( 'acl_ar_maintenance_rate_' . $user_id, 5, 300, __( 'Please wait before running maintenance again.', 'acl-agent-rooms' ) ); }
	public function can_clear_room( int $user_id, int $room_id ) {
		return $this->consume( 'acl_ar_clear_rate_' . $user_id . '_' . $room_id, 5, 300, __( 'Please wait before clearing this room again.', 'acl-agent-rooms' ) ); }

	private function consume( string $key, int $limit, int $window, string $message ) {
		$state = get_transient( $key );

		if ( ! is_array( $state ) || time() >= (int) ( $state['reset'] ?? 0 ) ) {
			$state = array(
				'count' => 0,
				'reset' => time() + $window,
			);
		}

		if ( (int) $state['count'] >= $limit ) {
			$retry_after = max( 1, (int) $state['reset'] - time() );
			return new \WP_Error(
				'acl_ar_rate_limited',
				$message,
				array(
					'status'      => 429,
					'retry_after' => $retry_after,
				)
			);
		}

		$state['count'] = (int) $state['count'] + 1;
		set_transient( $key, $state, $window );

		return true;
	}
}
