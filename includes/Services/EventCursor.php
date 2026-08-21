<?php
/** Opaque signed event cursors. @package ACL_Agent_Rooms */

namespace ACL\AgentRooms\Services;

if ( ! defined( 'ABSPATH' ) ) {
	exit; }

class EventCursor {
	private const VERSION    = 1;
	private const DIRECTIONS = array( 'before', 'after' );

	public function encode( int $room_id, string $direction, int $event_id, int $cutoff = 0 ): string {
		$payload = wp_json_encode(
			array(
				'v'         => self::VERSION,
				'room_id'   => $room_id,
				'direction' => $direction,
				'event_id'  => max( 0, $event_id ),
				'cutoff'    => max( 0, $cutoff ),
			)
		);
		$encoded = $this->base64url_encode( (string) $payload );
		return $encoded . '.' . $this->base64url_encode( hash_hmac( 'sha256', $encoded, $this->key(), true ) );
	}

	public function decode( string $cursor, int $room_id, string $direction, ?int $cutoff = null ) {
		if ( ! in_array( $direction, self::DIRECTIONS, true ) ) {
			return $this->error( 'acl_ar_cursor_direction_mismatch', __( 'The event cursor direction is invalid.', 'acl-agent-rooms' ) );
		}
		$parts = explode( '.', trim( $cursor ) );
		if ( 2 !== count( $parts ) || '' === $parts[0] || '' === $parts[1] ) {
			return $this->invalid(); }
		$expected = $this->base64url_encode( hash_hmac( 'sha256', $parts[0], $this->key(), true ) );
		if ( ! hash_equals( $expected, $parts[1] ) ) {
			return $this->invalid(); }
		$json = $this->base64url_decode( $parts[0] );
		$data = is_string( $json ) ? json_decode( $json, true ) : null;
		if ( ! is_array( $data ) || self::VERSION !== (int) ( $data['v'] ?? 0 ) || ! isset( $data['room_id'], $data['direction'], $data['event_id'] ) ) {
			return $this->invalid(); }
		if ( (int) $data['room_id'] !== $room_id ) {
			return $this->error( 'acl_ar_cursor_room_mismatch', __( 'The event cursor does not belong to this room.', 'acl-agent-rooms' ) );
		}
		if ( (string) $data['direction'] !== $direction || ! in_array( (string) $data['direction'], self::DIRECTIONS, true ) ) {
			return $this->error( 'acl_ar_cursor_direction_mismatch', __( 'The event cursor direction does not match this request.', 'acl-agent-rooms' ) );
		}
		if ( null !== $cutoff && (int) ( $data['cutoff'] ?? 0 ) !== max( 0, $cutoff ) ) {
			return $this->invalid(); }
		if ( ! is_int( $data['event_id'] ) && ! ctype_digit( (string) $data['event_id'] ) ) {
			return $this->invalid(); }
		$data['event_id'] = max( 0, (int) $data['event_id'] );
		return $data;
	}

	private function key(): string {
		return hash_hmac( 'sha256', 'acl-agent-rooms:event-cursor:v1', wp_salt( 'auth' ), true ); }
	private function invalid(): \WP_Error {
		return $this->error( 'acl_ar_invalid_cursor', __( 'The event cursor is invalid.', 'acl-agent-rooms' ) ); }
	private function error( string $code, string $message ): \WP_Error {
		return new \WP_Error( $code, $message, array( 'status' => 400 ) ); }
	private function base64url_encode( string $value ): string {
		return rtrim( strtr( base64_encode( $value ), '+/', '-_' ), '=' ); }
	private function base64url_decode( string $value ) {
		if ( ! preg_match( '/^[A-Za-z0-9_-]+$/', $value ) ) {
			return false; }
		$padding = strlen( $value ) % 4;
		if ( $padding ) {
			$value .= str_repeat( '=', 4 - $padding ); }
		return base64_decode( strtr( $value, '-_', '+/' ), true );
	}
}
