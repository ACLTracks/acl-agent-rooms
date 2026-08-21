<?php
/**
 * Shared AI config model normalizer.
 *
 * @package ACL_Agent_Rooms
 */

namespace ACL\AgentRooms\Models;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SharedConfig {
	public static function from_row( $row ): array {
		$row = (array) $row;

		return array(
			'id'             => (int) ( $row['id'] ?? 0 ),
			'owner_user_id'  => isset( $row['owner_user_id'] ) ? (int) $row['owner_user_id'] : null,
			'name'           => (string) ( $row['name'] ?? '' ),
			'slug'           => (string) ( $row['slug'] ?? '' ),
			'provider_route' => (string) ( $row['provider_route'] ?? '' ),
			'model'          => (string) ( $row['model'] ?? '' ),
			'system_prompt'  => (string) ( $row['system_prompt'] ?? '' ),
			'temperature'    => (float) ( $row['temperature'] ?? 0.7 ),
			'max_tokens'     => (int) ( $row['max_tokens'] ?? 1200 ),
			'enabled'        => ! empty( $row['enabled'] ),
			'created_at'     => (string) ( $row['created_at'] ?? '' ),
			'updated_at'     => (string) ( $row['updated_at'] ?? '' ),
		);
	}
}
