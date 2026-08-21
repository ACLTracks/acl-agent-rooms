<?php
/** Strict Shared Brain structured-response parser. @package ACL_Agent_Rooms */
namespace ACL\AgentRooms\Services;

if ( ! defined( 'ABSPATH' ) ) {
	exit; }

class BrainResponseParser {
	public function parse( string $raw, array $ordered_agent_ids, bool $natural = false ) {
		$raw = trim( str_replace( array( "\r\n", "\r" ), "\n", $raw ) );
		if ( preg_match( '/^```(?:json)?\s*\n([\s\S]*?)\n```$/i', $raw, $m ) ) {
			$raw = trim( $m[1] );
		} elseif ( str_starts_with( $raw, '```' ) ) {
			return $this->error( 'acl_ar_brain_response_prose', __( 'The Shared Brain returned text outside the JSON response.', 'acl-agent-rooms' ) );}
		$data = json_decode( $raw, true );
		$root = $natural ? 'turns' : 'responses';
		if ( ! is_array( $data ) || array_keys( $data ) !== array( $root ) || ! is_array( $data[ $root ] ) ) {
			return $this->error( 'acl_ar_brain_response_invalid', __( 'The Shared Brain returned an invalid response contract.', 'acl-agent-rooms' ) );}
		$expected = array_values( array_map( 'absint', $ordered_agent_ids ) );
		$found    = array();
		$steers   = 0;
		foreach ( $data[ $root ] as $item ) {
			$keys = $natural ? array( 'agent_id', 'content', 'purpose' ) : array( 'agent_id', 'content' );
			if ( ! is_array( $item ) || array_keys( $item ) !== $keys || ! is_int( $item['agent_id'] ) || ! is_string( $item['content'] ) || ( $natural && ( ! is_string( $item['purpose'] ) || ! in_array( $item['purpose'], array( 'reply', 'follow_up', 'steer' ), true ) ) ) ) {
				return $this->error( 'acl_ar_brain_response_invalid', __( 'The Shared Brain response fields were invalid.', 'acl-agent-rooms' ) );
			}$id = $item['agent_id'];
			if ( ! in_array( $id, $expected, true ) ) {
				return $this->error( 'acl_ar_brain_response_unknown_agent', __( 'The Shared Brain returned an unknown agent.', 'acl-agent-rooms' ) );
			}if ( isset( $found[ $id ] ) ) {
				return $this->error( 'acl_ar_brain_response_duplicate_agent', __( 'The Shared Brain returned an agent more than once.', 'acl-agent-rooms' ) );
			}$content = trim( str_replace( array( "\r\n", "\r" ), "\n", $item['content'] ) );
			if ( '' === $content ) {
				return $this->error( 'acl_ar_brain_response_empty', __( 'The Shared Brain returned an empty agent response.', 'acl-agent-rooms' ) );
			}if ( preg_match( '/<\/?[a-z][^>]*>/i', $content ) ) {
				return $this->error( 'acl_ar_brain_response_html', __( 'The Shared Brain returned HTML instead of plain text.', 'acl-agent-rooms' ) );
			}if ( strlen( $content ) > MessagePolicy::HARD_BYTE_LIMIT ) {
				return $this->error( 'acl_ar_brain_response_too_large', __( 'A Shared Brain response exceeded the message size limit.', 'acl-agent-rooms' ) );
			}$limit = max( 1, (int) apply_filters( 'acl_ar_message_character_limit', MessagePolicy::DEFAULT_CHARACTER_LIMIT, 0, 0 ) );
			$length = function_exists( 'mb_strlen' ) ? mb_strlen( $content, 'UTF-8' ) : strlen( $content );
			if ( $length > $limit ) {
				return $this->error( 'acl_ar_brain_response_too_large', __( 'A Shared Brain response exceeded the message character limit.', 'acl-agent-rooms' ) );
			}$purpose = $natural ? (string) $item['purpose'] : 'reply';
			if ( 'steer' === $purpose && ++$steers > 1 ) {
				return $this->error( 'acl_ar_brain_response_too_many_steers', __( 'The Shared Brain returned more than one steering turn.', 'acl-agent-rooms' ) );
			}$found[ $id ] = array(
				'agent_id' => $id,
				'content'  => wp_strip_all_tags( $content ),
				'purpose'  => $purpose,
			);}
		if ( count( $found ) !== count( $expected ) ) {
			return $this->error( 'acl_ar_brain_response_missing_agent', __( 'The Shared Brain omitted an agent response.', 'acl-agent-rooms' ) );
		}if ( $natural && array_values( array_map( 'intval', array_keys( $found ) ) ) !== $expected ) {
			return $this->error( 'acl_ar_brain_response_order', __( 'The Shared Brain changed the selected speaking order.', 'acl-agent-rooms' ) );
		}$out = array();
		foreach ( $expected as $id ) {
			if ( ! isset( $found[ $id ] ) ) {
				return $this->error( 'acl_ar_brain_response_missing_agent', __( 'The Shared Brain omitted an agent response.', 'acl-agent-rooms' ) );
			}if ( ! $natural ) {
				unset( $found[ $id ]['purpose'] );
			}$out[] = $found[ $id ];
		}return $out;
	}
	private function error( string $code, string $message ): \WP_Error {
		return new \WP_Error( $code, $message, array( 'status' => 502 ) );}
}
