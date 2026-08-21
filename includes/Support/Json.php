<?php
/**
 * JSON helpers.
 *
 * @package ACL_Agent_Rooms
 */

namespace ACL\AgentRooms\Support;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Json {
	public static function encode( array $value ): string {
		$encoded = wp_json_encode( $value );
		return is_string( $encoded ) ? $encoded : '{}';
	}

	public static function decode( ?string $value ): array {
		if ( ! is_string( $value ) || '' === trim( $value ) ) {
			return array();
		}

		$decoded = json_decode( $value, true );
		return is_array( $decoded ) ? $decoded : array();
	}
}
