<?php
/** Safe slash-command parser. @package ACL_Agent_Rooms */
namespace ACL\AgentRooms\Services;

if ( ! defined( 'ABSPATH' ) ) {
	exit;}
class CommandParser {
	public function parse( string $raw ) {
		$raw = str_replace( array( "\r\n", "\r" ), "\n", trim( $raw ) );
		if ( '' === $raw || '/' !== $raw[0] ) {
			return new \WP_Error( 'acl_ar_invalid_command', __( 'Enter a slash command.', 'acl-agent-rooms' ), array( 'status' => 400 ) );
		}if ( strlen( $raw ) > 12000 ) {
			return new \WP_Error( 'acl_ar_invalid_command', __( 'Command input is too long.', 'acl-agent-rooms' ), array( 'status' => 400 ) );
		}if ( ! preg_match( '/^\/([A-Za-z][A-Za-z0-9_-]*)(?:\s+(.*))?$/us', $raw, $m ) ) {
			return new \WP_Error( 'acl_ar_invalid_command', __( 'Command syntax is invalid.', 'acl-agent-rooms' ), array( 'status' => 400 ) );
		}return array(
			'name'      => strtolower( $m[1] ),
			'arguments' => trim( (string) ( $m[2] ?? '' ) ),
			'raw'       => $raw,
		);}
	public function whisper_arguments( string $arguments ) {
		$arguments = trim( $arguments );
		if ( '' === $arguments ) {
			return new \WP_Error( 'acl_ar_whisper_recipient_required', __( 'Choose a whisper recipient.', 'acl-agent-rooms' ), array( 'status' => 400 ) );
		}if ( '"' === $arguments[0] ) {
			if ( ! preg_match( '/^"([^"\r\n]{1,100})"\s+(.+)$/us', $arguments, $m ) ) {
				return new \WP_Error( 'acl_ar_invalid_command', __( 'Use /whisper "Display Name" message.', 'acl-agent-rooms' ), array( 'status' => 400 ) );
			}return array(
				'recipient' => trim( $m[1] ),
				'message'   => trim( $m[2] ),
			);
		}if ( ! preg_match( '/^(\S+)\s+(.+)$/us', $arguments, $m ) ) {
			return new \WP_Error( 'acl_ar_whisper_recipient_required', __( 'Choose a recipient and enter a message.', 'acl-agent-rooms' ), array( 'status' => 400 ) );
		}return array(
			'recipient' => trim( $m[1] ),
			'message'   => trim( $m[2] ),
		);}
}
