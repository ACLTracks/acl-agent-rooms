<?php
/** Signed opaque user/query-bound search cursors. @package ACL_Agent_Rooms */
namespace ACL\AgentRooms\Services;

if ( ! defined( 'ABSPATH' ) ) {
	exit; }

class SearchCursor {
	public function encode( int $room_id, int $user_id, string $query, int $event_id, int $cutoff = 0 ): string {
		$payload = array(
			'r' => $room_id,
			'u' => $user_id,
			'q' => $this->query_hash( $query ),
			'e' => $event_id,
			'c' => max( 0, $cutoff ),
		);
		$json    = wp_json_encode( $payload );
		$mac     = hash_hmac( 'sha256', $json, wp_salt( 'auth' ) );
		return rtrim( strtr( base64_encode( $json . '.' . $mac ), '+/', '-_' ), '=' );
	}

	public function decode( string $cursor, int $room_id, int $user_id, string $query, ?int $cutoff = null ) {
		$raw = base64_decode( strtr( $cursor, '-_', '+/' ), true );
		if ( false === $raw || ! str_contains( $raw, '.' ) ) {
			return $this->invalid(); }
		$position = strrpos( $raw, '.' );
		$json     = substr( $raw, 0, $position );
		$mac      = substr( $raw, $position + 1 );
		if ( ! hash_equals( hash_hmac( 'sha256', $json, wp_salt( 'auth' ) ), $mac ) ) {
			return $this->invalid(); }

		$data = json_decode( $json, true );
		if (
			! is_array( $data )
			|| (int) ( $data['r'] ?? 0 ) !== $room_id
			|| (int) ( $data['u'] ?? 0 ) !== $user_id
			|| ! hash_equals( (string) ( $data['q'] ?? '' ), $this->query_hash( $query ) )
			|| ( null !== $cutoff && (int) ( $data['c'] ?? 0 ) !== max( 0, $cutoff ) )
		) {
			return $this->invalid();
		}
		return absint( $data['e'] ?? 0 );
	}

	private function query_hash( string $query ): string {
		$normalized = function_exists( 'mb_strtolower' ) ? mb_strtolower( $query, 'UTF-8' ) : strtolower( $query );
		return hash( 'sha256', $normalized );
	}

	private function invalid() {
		return new \WP_Error( 'acl_ar_search_cursor_invalid', __( 'The search cursor is invalid or does not belong to this query.', 'acl-agent-rooms' ), array( 'status' => 400 ) );
	}
}
