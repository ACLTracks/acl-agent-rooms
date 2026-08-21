<?php
/** Server-authoritative Natural Conversation timing. @package ACL_Agent_Rooms */
namespace ACL\AgentRooms\Services;

if ( ! defined( 'ABSPATH' ) ) {
	exit; }

class NaturalDelayCalculator {
	private $random;
	private $clock;
	public function __construct( ?callable $random = null, ?callable $clock = null ) {
		$this->random = $random ?: array( $this, 'secure_random' );
		$this->clock  = $clock ?: 'time'; }
	public function schedule( array $room, array $agents ): array {
		$now = (int) call_user_func( $this->clock );
		$due = $now;
		$out = array();
		foreach ( array_values( $agents ) as $index => $agent ) {
			if ( 0 === $index ) {
				list( $min, $max ) = $this->initial_bounds( $room, $agent );
				$delay             = $this->between( $min, $max ); } else {
				$min    = max( 0, (int) $room['natural_inter_turn_delay_min_ms'] );
				$max    = max( $min, (int) $room['natural_inter_turn_delay_max_ms'] );
				$typing = min( 2500, max( 0, (int) ( strlen( wp_strip_all_tags( (string) ( $agent['description'] ?? '' ) ) ) * 8 ) ) );
				$delay  = $this->between( $min, $max ) + $typing + $this->between( 0, 350 ); }
				$seconds = max( 1, (int) ceil( $delay / 1000 ) );
				$due     = max( $due + $seconds, $now + $index + 1 );
				$due     = min( $due, $now + 120 );
				$out[]   = array(
					'agent'     => $agent,
					'due_at'    => gmdate( 'Y-m-d H:i:s', $due ),
					'typing_at' => gmdate( 'Y-m-d H:i:s', max( $now, $due - 2 ) ),
				);
		}
		return $out;
	}
	private function initial_bounds( array $room, array $agent ): array {
		$min       = (int) $room['natural_initial_delay_min_ms'];
		$max       = (int) $room['natural_initial_delay_max_ms'];
		$agent_min = $agent['natural_delay_min_ms'] ?? null;
		$agent_max = $agent['natural_delay_max_ms'] ?? null;
		if ( null !== $agent_min && null !== $agent_max && $agent_min >= 0 && $agent_max >= $agent_min && $agent_max <= 60000 ) {
			return array( (int) $agent_min, (int) $agent_max );
		} return array( max( 0, $min ), max( max( 0, $min ), $max ) ); }
	private function between( int $min, int $max ): int {
		return (int) call_user_func( $this->random, $min, max( $min, $max ) ); }
	public function secure_random( int $min, int $max ): int {
		try {
			return random_int( $min, $max );
		} catch ( \Throwable $e ) {
			return wp_rand( $min, $max ); } }
}
