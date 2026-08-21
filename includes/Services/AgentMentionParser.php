<?php
/**
 * Agent mention and slash parsing.
 *
 * @package ACL_Agent_Rooms
 */

namespace ACL\AgentRooms\Services;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class AgentMentionParser {
	public function mentioned_agents( string $content, array $agents ): array {
		$by_slug = array();
		foreach ( $agents as $agent ) {
			if ( ! empty( $agent['slug'] ) ) {
				$by_slug[ sanitize_title( (string) $agent['slug'] ) ] = $agent;
			}
		}

		preg_match_all( '/(^|\\s)@([a-z0-9][a-z0-9_-]*)/i', $content, $matches );
		$mentioned = array();

		foreach ( $matches[2] ?? array() as $raw_slug ) {
			$slug = sanitize_title( (string) $raw_slug );
			if ( isset( $by_slug[ $slug ] ) ) {
				$mentioned[ (int) $by_slug[ $slug ]['id'] ] = $by_slug[ $slug ];
			}
		}

		return array_values( $mentioned );
	}

	public function parse_slash_command( string $content ): array {
		$content = trim( $content );
		if ( '' === $content || '/' !== $content[0] ) {
			return array( 'command' => '' );
		}

		if ( preg_match( '/^\\/ask\\s+([a-z0-9][a-z0-9_-]*(?:\\s*,\\s*[a-z0-9][a-z0-9_-]*)*)\\s+(.+)$/i', $content, $matches ) ) {
			$slugs = array_values( array_unique( array_filter( array_map( 'sanitize_title', preg_split( '/\\s*,\\s*/', (string) $matches[1] ) ) ) ) );
			return array(
				'command'     => 'ask',
				'agent_slug'  => (string) ( $slugs[0] ?? '' ),
				'agent_slugs' => $slugs,
				'message'     => trim( (string) $matches[2] ),
			);
		}

		if ( preg_match( '/^\\/(agents|help)\\b/i', $content, $matches ) ) {
			return array( 'command' => sanitize_key( (string) $matches[1] ) );
		}

		return array( 'command' => 'unknown' );
	}

	public function agent_by_slug( string $slug, array $agents ): ?array {
		$slug = sanitize_title( $slug );
		foreach ( $agents as $agent ) {
			if ( $slug === sanitize_title( (string) ( $agent['slug'] ?? '' ) ) ) {
				return $agent;
			}
		}

		return null;
	}
}
