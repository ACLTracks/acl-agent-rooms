<?php
/** Central public command definitions. @package ACL_Agent_Rooms */
namespace ACL\AgentRooms\Services;

if ( ! defined( 'ABSPATH' ) ) {
	exit;}
class CommandRegistry {
	public function all(): array {
		$defs     = array(
			'help'    => array(
				'name'          => 'help',
				'aliases'       => array(),
				'description'   => __( 'Show room command help.', 'acl-agent-rooms' ),
				'usage'         => '/help',
				'sends_event'   => true,
				'invokes_agent' => false,
				'can_cost'      => false,
				'permission'    => 'read',
				'handler'       => 'help',
			),
			'agents'  => array(
				'name'          => 'agents',
				'aliases'       => array(),
				'description'   => __( 'List assigned agents.', 'acl-agent-rooms' ),
				'usage'         => '/agents',
				'sends_event'   => true,
				'invokes_agent' => false,
				'can_cost'      => false,
				'permission'    => 'read',
				'handler'       => 'agents',
			),
			'ask'     => array(
				'name'          => 'ask',
				'aliases'       => array(),
				'description'   => __( 'Ask one or more assigned agents.', 'acl-agent-rooms' ),
				'usage'         => '/ask agent-slug[,agent-slug...] message',
				'sends_event'   => true,
				'invokes_agent' => true,
				'can_cost'      => true,
				'permission'    => 'write',
				'handler'       => 'ask',
			),
			'roll'    => array(
				'name'          => 'roll',
				'aliases'       => array(),
				'description'   => __( 'Roll server-authoritative dice.', 'acl-agent-rooms' ),
				'usage'         => '/roll [2d6+3]',
				'sends_event'   => true,
				'invokes_agent' => false,
				'can_cost'      => false,
				'permission'    => 'write',
				'handler'       => 'roll',
			),
			'coin'    => array(
				'name'          => 'coin',
				'aliases'       => array(),
				'description'   => __( 'Flip a server-authoritative coin.', 'acl-agent-rooms' ),
				'usage'         => '/coin',
				'sends_event'   => true,
				'invokes_agent' => false,
				'can_cost'      => false,
				'permission'    => 'write',
				'handler'       => 'coin',
			),
			'me'      => array(
				'name'          => 'me',
				'aliases'       => array(),
				'description'   => __( 'Post a room action.', 'acl-agent-rooms' ),
				'usage'         => '/me action',
				'sends_event'   => true,
				'invokes_agent' => false,
				'can_cost'      => false,
				'permission'    => 'write',
				'handler'       => 'me',
			),
			'whisper' => array(
				'name'          => 'whisper',
				'aliases'       => array( 'w' ),
				'description'   => __( 'Send a private room whisper.', 'acl-agent-rooms' ),
				'usage'         => '/whisper "Display Name" message',
				'sends_event'   => true,
				'invokes_agent' => false,
				'can_cost'      => false,
				'permission'    => 'write',
				'handler'       => 'whisper',
			),
		);
		$filtered = apply_filters( 'acl_ar_command_registry', $defs );
		return is_array( $filtered ) ? $this->sanitize( $filtered, $defs ) : $defs;}
	private function sanitize( array $filtered, array $defaults ): array {
		$out = array();
		foreach ( $defaults as $name => $base ) {
			$row = $filtered[ $name ] ?? $base;
			if ( ! is_array( $row ) ) {
				continue;
			}$row                 = array_merge( $base, $row );
			$row['name']          = $name;
			$row['handler']       = $base['handler'];
			$row['permission']    = $base['permission'];
			$row['invokes_agent'] = $base['invokes_agent'];
			$row['can_cost']      = $base['can_cost'];
			$row['aliases']       = array_values( array_intersect( $base['aliases'], array_map( 'sanitize_key', (array) $row['aliases'] ) ) );
			$row['description']   = sanitize_text_field( (string) $row['description'] );
			$row['usage']         = sanitize_text_field( (string) $row['usage'] );
			$out[ $name ]         = $row;
		}return $out;}
	public function resolve( string $name ): ?array {
		$name = sanitize_key( $name );
		foreach ( $this->all() as $def ) {
			if ( $name === $def['name'] || in_array( $name, $def['aliases'], true ) ) {
				return $def;
			}
		}return null;}
	public function public_definitions(): array {
		return array_values( array_map( static fn( $d )=>array_intersect_key( $d, array_flip( array( 'name', 'aliases', 'description', 'usage', 'invokes_agent', 'can_cost' ) ) ), $this->all() ) );}
}
