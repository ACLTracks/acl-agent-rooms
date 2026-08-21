<?php
/** Shared Brain configuration DTO. @package ACL_Agent_Rooms */
namespace ACL\AgentRooms\Models;

use ACL\AgentRooms\Support\Json;

if ( ! defined( 'ABSPATH' ) ) {
	exit; }

class Brain {
	public static function from_row( $row ): array {
		$row = (array) $row;
		return array(
			'id'                   => (int) ( $row['id'] ?? 0 ),
			'owner_user_id'        => (int) ( $row['owner_user_id'] ?? 0 ),
			'name'                 => (string) ( $row['name'] ?? '' ),
			'slug'                 => (string) ( $row['slug'] ?? '' ),
			'description'          => (string) ( $row['description'] ?? '' ),
			'provider'             => (string) ( $row['provider'] ?? '' ),
			'model'                => (string) ( $row['model'] ?? '' ),
			'orchestration_prompt' => (string) ( $row['orchestration_prompt'] ?? '' ),
			'temperature'          => null === ( $row['temperature'] ?? null ) ? null : (float) $row['temperature'],
			'max_tokens_per_agent' => (int) ( $row['max_tokens_per_agent'] ?? 600 ),
			'max_total_tokens'     => (int) ( $row['max_total_tokens'] ?? 6000 ),
			'settings'             => Json::decode( $row['settings_json'] ?? null ),
			'enabled'              => ! empty( $row['enabled'] ),
			'created_at'           => (string) ( $row['created_at'] ?? '' ),
			'updated_at'           => (string) ( $row['updated_at'] ?? '' ),
		);
	}
}
