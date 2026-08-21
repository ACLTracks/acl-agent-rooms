<?php
/** Local, no-provider Natural Conversation selection director. @package ACL_Agent_Rooms */
namespace ACL\AgentRooms\Services;

use ACL\AgentRooms\Repositories\ConversationTurnRepository;
use ACL\AgentRooms\Repositories\RoomRepository;

if ( ! defined( 'ABSPATH' ) ) {
	exit; }

class NaturalConversationDirector {
	private ConversationTurnRepository $turns;
	private RoomRepository $rooms;
	private NaturalDelayCalculator $delays;
	private $random;
	public function __construct( ?ConversationTurnRepository $turns = null, ?RoomRepository $rooms = null, ?callable $random = null, ?callable $clock = null ) {
		$this->turns  = $turns ?: new ConversationTurnRepository();
		$this->rooms  = $rooms ?: new RoomRepository();
		$this->random = $random ?: array( $this, 'secure_random' );
		$this->delays = new NaturalDelayCalculator( $this->random, $clock ); }
	public function plan( array $room, array $agents, array $forced_ids = array(), bool $automatic = true ): array {
		$forced_ids = array_values( array_unique( array_filter( array_map( 'absint', $forced_ids ) ) ) );
		$eligible   = array();
		foreach ( $agents as $agent ) {
			$id         = (int) $agent['id'];
			$assignment = $this->rooms->get_assignment( (int) $room['id'], $id );
			if ( empty( $agent['enabled'] ) || ! $assignment || 'paused' === (string) ( $assignment['participation_state'] ?? 'active' ) ) {
				continue;
			} $forced = in_array( $id, $forced_ids, true );
			if ( $automatic && ! $forced ) {
				if ( ! empty( $assignment['auto_muted'] ) ) {
					continue;
				} $latest = $this->turns->latest_published_at( (int) $room['id'], $id );
				$cooldown = max( 0, (int) ( $agent['natural_cooldown_seconds'] ?? 20 ) );
				if ( $latest && strtotime( $latest . ' UTC' ) > time() - $cooldown ) {
					continue;
				} $limit = max( 0, (int) ( $agent['natural_max_auto_responses_per_10m'] ?? 4 ) );
				if ( 0 === $limit || $this->turns->recent_published_count( (int) $room['id'], $id ) >= $limit ) {
					continue; }
			}
			$eligible[ $id ] = $agent;
		}
		$forced = array();
		foreach ( $forced_ids as $id ) {
			if ( isset( $eligible[ $id ] ) ) {
				$forced[] = $eligible[ $id ];
				unset( $eligible[ $id ] ); }
		}
		// An explicit mention or /ask is authoritative. If every requested agent is
		// unavailable, remain silent instead of substituting an unrelated agent.
		if ( $forced_ids && ! $forced ) {
			return array(
				'targets' => array(),
				'turns'   => array(),
				'silent'  => true,
			); }
		if ( ! $forced && $automatic && ! empty( $room['natural_allow_silence'] ) && $this->roll( 100 ) <= (int) $room['natural_silence_chance'] ) {
			return array(
				'targets' => array(),
				'turns'   => array(),
				'silent'  => true,
			); }
		$min = max( 0, min( 10, (int) $room['natural_min_responders'] ) );
		$max = max( $min, min( 10, (int) $room['natural_max_responders'] ) );
		if ( ! $forced ) {
			$capacity = max( 0, (int) $room['natural_max_pending_turns'] - $this->turns->pending_count( (int) $room['id'] ) );
			if ( 0 === $capacity ) {
				return array(
					'targets' => array(),
					'turns'   => array(),
					'silent'  => true,
				);
			} $max = min( $max, $capacity );
			$min   = min( $min, $max ); }
		$count      = $forced ? max( count( $forced ), min( $max, count( $forced ) ) ) : $this->between( $min, $max );
		$count      = min( count( $forced ) + count( $eligible ), max( count( $forced ), $count ) );
		$candidates = array();
		foreach ( $eligible as $agent ) {
			if ( $automatic && $this->roll( 100 ) > (int) ( $agent['natural_participation_chance'] ?? 60 ) ) {
				continue;
			} $candidates[] = $agent; }
		if ( count( $forced ) + count( $candidates ) < $count ) {
			foreach ( $eligible as $agent ) {
				if ( ! in_array( (int) $agent['id'], array_map( static fn( $a ) => (int) $a['id'], $candidates ), true ) ) {
					$candidates[] = $agent; }
			}
		}
		$selected = $forced;
		while ( count( $selected ) < $count && $candidates ) {
			$index      = $this->weighted_index( $room, $candidates );
			$selected[] = $candidates[ $index ];
			array_splice( $candidates, $index, 1 ); }
		$steer_id  = $this->steering_agent( $room, $selected );
		$scheduled = $this->delays->schedule( $room, $selected );
		foreach ( $scheduled as &$turn ) {
			$turn['purpose'] = $steer_id === (int) $turn['agent']['id'] ? 'steer' : 'reply';
		} unset( $turn );
		return array(
			'targets' => $selected,
			'turns'   => $scheduled,
			'silent'  => empty( $selected ),
		);
	}
	private function weighted_index( array $room, array $agents ): int {
		$weights = array();
		$total   = 0;
		foreach ( $agents as $agent ) {
			$role   = (string) ( $agent['natural_conversation_role'] ?? 'balanced' );
			$weight = array(
				'quiet'       => 45,
				'balanced'    => 100,
				'talkative'   => 155,
				'facilitator' => 110,
			)[ $role ] ?? 100;
			$latest = $this->turns->latest_published_at( (int) $room['id'], (int) $agent['id'] );
			if ( $latest && strtotime( $latest . ' UTC' ) >= time() - 600 ) {
				$weight = max( 10, (int) floor( $weight * 0.45 ) );
			} $weights[] = $weight;
			$total      += $weight;
		} $roll = $this->between( 1, max( 1, $total ) );
		foreach ( $weights as $index => $weight ) {
			$roll -= $weight;
			if ( $roll <= 0 ) {
				return $index;
			}
		} return count( $agents ) - 1; }
	private function steering_agent( array $room, array $selected ): int {
		if ( ! $selected || $this->roll( 100 ) > (int) $room['natural_steering_question_bias'] ) {
			return 0;
		} $best = 0;
		$score  = -1;
		foreach ( $selected as $agent ) {
			$value = (int) ( $agent['natural_question_tendency'] ?? 20 ) + ( 'facilitator' === (string) ( $agent['natural_conversation_role'] ?? '' ) ? 30 : 0 );
			if ( $value > $score ) {
				$score = $value;
				$best  = (int) $agent['id'];
			}
		} return $best; }
	private function roll( int $max ): int {
		return $this->between( 1, max( 1, $max ) ); }
	private function between( int $min, int $max ): int {
		return (int) call_user_func( $this->random, $min, $max ); }
	public function secure_random( int $min, int $max ): int {
		try {
			return random_int( $min, $max );
		} catch ( \Throwable $e ) {
			return wp_rand( $min, $max ); } }
}
