<?php
/**
 * Authorization and throttling for provider-costing actions.
 *
 * @package ACL_Agent_Rooms
 */

namespace ACL\AgentRooms\Services;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class AgentExecutionPolicy {
	private AccessService $access;
	private RateLimiter $rate_limiter;

	public function __construct( ?AccessService $access = null, ?RateLimiter $rate_limiter = null ) {
		$this->access       = $access ?: new AccessService();
		$this->rate_limiter = $rate_limiter ?: new RateLimiter();
	}

	public function authorize( int $user_id, int $room_id, string $action, array $context = array() ) {
		$allowed = $this->access->can_access_room( $room_id, $user_id, true );
		$allowed = (bool) apply_filters( 'acl_ar_can_execute_agent', $allowed, $user_id, $room_id, sanitize_key( $action ), $context );
		if ( ! $allowed ) {
			return new \WP_Error( 'acl_ar_agent_execution_forbidden', __( 'You cannot run agents in this room.', 'acl-agent-rooms' ), array( 'status' => 403 ) );
		}

		return $this->rate_limiter->can_user_execute_agent( $user_id, $room_id, $action );
	}
}
