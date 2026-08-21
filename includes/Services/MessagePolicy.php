<?php
/**
 * Human message validation and normalization.
 *
 * @package ACL_Agent_Rooms
 */

namespace ACL\AgentRooms\Services;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class MessagePolicy {
	public const DEFAULT_CHARACTER_LIMIT = 12000;
	public const HARD_BYTE_LIMIT         = 49152;

	public function normalize( string $raw, int $user_id = 0, int $room_id = 0 ) {
		$content = str_replace( array( "\r\n", "\r" ), "\n", $raw );
		if ( strlen( $content ) > self::HARD_BYTE_LIMIT ) {
			return new \WP_Error( 'acl_ar_message_payload_too_large', __( 'Message payload exceeds the 48 KiB hard limit.', 'acl-agent-rooms' ), array( 'status' => 413 ) );
		}
		$content = trim( wp_strip_all_tags( $content ) );

		if ( '' === $content ) {
			return new \WP_Error( 'acl_ar_empty_message', __( 'Message content is required.', 'acl-agent-rooms' ), array( 'status' => 400 ) );
		}

		if ( strlen( $content ) > self::HARD_BYTE_LIMIT ) {
			return new \WP_Error( 'acl_ar_message_payload_too_large', __( 'Message payload exceeds the 48 KiB hard limit.', 'acl-agent-rooms' ), array( 'status' => 413 ) );
		}

		$limit  = max( 1, (int) apply_filters( 'acl_ar_message_character_limit', self::DEFAULT_CHARACTER_LIMIT, $user_id, $room_id ) );
		$length = function_exists( 'mb_strlen' ) ? mb_strlen( $content, 'UTF-8' ) : strlen( $content );
		if ( $length > $limit ) {
			return new \WP_Error(
				'acl_ar_message_too_long',
				/* translators: %d: Maximum number of message characters. */
				sprintf( __( 'Message exceeds the %d character limit.', 'acl-agent-rooms' ), $limit ),
				array(
					'status' => 413,
					'limit'  => $limit,
				)
			);
		}

		return $content;
	}

	public function client_request_id( $value ) {
		$value = trim( (string) $value );
		if ( '' === $value ) {
			return '';
		}

		if ( strlen( $value ) < 8 || strlen( $value ) > 64 || ! preg_match( '/^[A-Za-z0-9][A-Za-z0-9._:\-]*$/', $value ) ) {
			return new \WP_Error( 'acl_ar_invalid_client_request_id', __( 'Client request ID must be an 8-64 character opaque identifier.', 'acl-agent-rooms' ), array( 'status' => 400 ) );
		}

		return $value;
	}
}
