<?php
/**
 * Agent model normalizer.
 *
 * @package ACL_Agent_Rooms
 */

namespace ACL\AgentRooms\Models;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Agent {
	public static function from_row( $row ): array {
		$row                  = (array) $row;
		$avatar_attachment_id = absint( $row['avatar_attachment_id'] ?? 0 );
		$avatar_url           = (string) ( $row['avatar_url'] ?? '' );
		$avatar_alt           = '';

		if ( $avatar_attachment_id > 0 && function_exists( 'wp_attachment_is_image' ) && wp_attachment_is_image( $avatar_attachment_id ) ) {
			$resolved_url = function_exists( 'wp_get_attachment_image_url' ) ? wp_get_attachment_image_url( $avatar_attachment_id, 'thumbnail' ) : '';
			$avatar_url   = is_string( $resolved_url ) ? $resolved_url : '';
			$meta_alt     = function_exists( 'get_post_meta' ) ? get_post_meta( $avatar_attachment_id, '_wp_attachment_image_alt', true ) : '';
			$avatar_alt   = is_string( $meta_alt ) ? $meta_alt : '';
		}

		return array(
			'id'                                 => (int) ( $row['id'] ?? 0 ),
			'owner_user_id'                      => isset( $row['owner_user_id'] ) ? (int) $row['owner_user_id'] : null,
			'name'                               => (string) ( $row['name'] ?? '' ),
			'slug'                               => (string) ( $row['slug'] ?? '' ),
			'description'                        => (string) ( $row['description'] ?? '' ),
			'avatar_attachment_id'               => $avatar_attachment_id,
			'avatar_url'                         => $avatar_url,
			'avatar_alt'                         => '' !== $avatar_alt ? $avatar_alt : (string) ( $row['name'] ?? '' ),
			'config_mode'                        => (string) ( $row['config_mode'] ?? 'independent' ),
			'shared_config_id'                   => isset( $row['shared_config_id'] ) ? (int) $row['shared_config_id'] : null,
			'execution_mode'                     => 'brain' === (string) ( $row['execution_mode'] ?? 'independent' ) ? 'brain' : 'independent',
			'brain_id'                           => ! empty( $row['brain_id'] ) ? (int) $row['brain_id'] : null,
			'provider_route'                     => (string) ( $row['provider_route'] ?? '' ),
			'model'                              => (string) ( $row['model'] ?? '' ),
			'system_prompt'                      => (string) ( $row['system_prompt'] ?? '' ),
			'temperature'                        => (float) ( $row['temperature'] ?? 0.7 ),
			'max_tokens'                         => (int) ( $row['max_tokens'] ?? 1200 ),
			'natural_participation_chance'       => (int) ( $row['natural_participation_chance'] ?? 60 ),
			'natural_question_tendency'          => (int) ( $row['natural_question_tendency'] ?? 20 ),
			'natural_delay_min_ms'               => isset( $row['natural_delay_min_ms'] ) ? (int) $row['natural_delay_min_ms'] : null,
			'natural_delay_max_ms'               => isset( $row['natural_delay_max_ms'] ) ? (int) $row['natural_delay_max_ms'] : null,
			'natural_cooldown_seconds'           => (int) ( $row['natural_cooldown_seconds'] ?? 20 ),
			'natural_max_auto_responses_per_10m' => (int) ( $row['natural_max_auto_responses_per_10m'] ?? 4 ),
			'natural_conversation_role'          => in_array( (string) ( $row['natural_conversation_role'] ?? 'balanced' ), array( 'quiet', 'balanced', 'talkative', 'facilitator' ), true ) ? (string) ( $row['natural_conversation_role'] ?? 'balanced' ) : 'balanced',
			'visibility'                         => (string) ( $row['visibility'] ?? 'private' ),
			'enabled'                            => ! empty( $row['enabled'] ),
			'created_at'                         => (string) ( $row['created_at'] ?? '' ),
			'updated_at'                         => (string) ( $row['updated_at'] ?? '' ),
			'sort_order'                         => (int) ( $row['sort_order'] ?? 0 ),
			'participation_state'                => in_array( (string) ( $row['participation_state'] ?? 'active' ), array( 'active', 'paused' ), true ) ? (string) ( $row['participation_state'] ?? 'active' ) : 'active',
			'auto_muted'                         => ! empty( $row['auto_muted'] ),
			'state_updated_at'                   => isset( $row['state_updated_at'] ) ? (string) $row['state_updated_at'] : null,
		);
	}
}
