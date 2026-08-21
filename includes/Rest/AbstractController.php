<?php
/**
 * Shared REST helpers.
 *
 * @package ACL_Agent_Rooms
 */

namespace ACL\AgentRooms\Rest;

use ACL\AgentRooms\Capabilities;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

abstract class AbstractController {
	protected const NAMESPACE = 'acl-agent-rooms/v1';

	protected function verify_nonce( \WP_REST_Request $request ) {
		$nonce = (string) $request->get_header( 'X-WP-Nonce' );
		if ( '' === $nonce ) {
			$nonce = (string) $request->get_param( '_wpnonce' );
		}

		if ( ! wp_verify_nonce( $nonce, 'wp_rest' ) ) {
			return new \WP_Error( 'acl_ar_rest_nonce', __( 'Invalid REST nonce.', 'acl-agent-rooms' ), array( 'status' => 403 ) );
		}

		return true;
	}

	protected function require_room_user() {
		if ( ! is_user_logged_in() || ! current_user_can( 'acl_ar_use_rooms' ) ) {
			return new \WP_Error( 'acl_ar_rest_forbidden', __( 'You do not have access to agent rooms.', 'acl-agent-rooms' ), array( 'status' => 403 ) );
		}

		return true;
	}

	protected function require_capability( string $capability ) {
		if ( ! is_user_logged_in() || ! Capabilities::current_user_can( $capability ) ) {
			return new \WP_Error( 'acl_ar_rest_forbidden', __( 'You do not have permission for this agent rooms action.', 'acl-agent-rooms' ), array( 'status' => 403 ) );
		}

		return true;
	}

	protected function mutating_capability( \WP_REST_Request $request, string $capability ) {
		$nonce = $this->verify_nonce( $request );
		if ( is_wp_error( $nonce ) ) {
			return $nonce;
		}

		return $this->require_capability( $capability );
	}

	protected function bool_param( $value ): bool {
		return rest_sanitize_boolean( $value );
	}
}
