<?php
/**
 * Secret-safe public error boundary.
 *
 * @package ACL_Agent_Rooms
 */

namespace ACL\AgentRooms\Services;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class PublicError {
	public static function message( string $message, string $fallback = '' ): string {
		$fallback = '' !== $fallback ? $fallback : __( 'The agent action could not be completed.', 'acl-agent-rooms' );
		$message  = trim( preg_replace( '/\s+/', ' ', wp_strip_all_tags( $message ) ) ?: '' );
		$patterns = array(
			'/(bearer\s+)[a-z0-9._\-]+/i'                  => '$1[redacted]',
			'/\bsk-[a-z0-9_\-]+\b/i'                       => 'sk-[redacted]',
			'/(api[_-]?key|authorization|secret|token|password)(\s*[:=]\s*)[^\s,;]+/i' => '$1$2[redacted]',
			'/([?&](?:key|token|signature|sig)=)[^&\s]+/i' => '$1[redacted]',
		);

		foreach ( $patterns as $pattern => $replacement ) {
			$message = preg_replace( $pattern, $replacement, $message ) ?: $message;
		}

		return '' !== $message ? self::cut( $message, 240 ) : $fallback;
	}

	public static function from_error( \WP_Error $error, string $fallback = '' ): string {
		return self::message( $error->get_error_message(), $fallback );
	}

	private static function cut( string $value, int $length ): string {
		return function_exists( 'mb_substr' ) ? mb_substr( $value, 0, $length, 'UTF-8' ) : substr( $value, 0, $length );
	}
}
