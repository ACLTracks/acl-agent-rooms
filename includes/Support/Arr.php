<?php
/**
 * Array helpers.
 *
 * @package ACL_Agent_Rooms
 */

namespace ACL\AgentRooms\Support;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Arr {
	public static function get( array $array, string $key, $default = null ) {
		return array_key_exists( $key, $array ) ? $array[ $key ] : $default;
	}

	public static function ids( array $values ): array {
		return array_values(
			array_unique(
				array_filter(
					array_map( 'absint', $values )
				)
			)
		);
	}
}
