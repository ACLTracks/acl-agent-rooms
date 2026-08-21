<?php
/** Secure server-side dice. @package ACL_Agent_Rooms */
namespace ACL\AgentRooms\Services;

use ACL\AgentRooms\Repositories\EventRepository;
if ( ! defined( 'ABSPATH' ) ) {
	exit;}
class DiceService {
	private EventRepository $events;
	private RoomEventService $writer;
	public function __construct( ?EventRepository $events = null ) {
		$this->events = $events ?: new EventRepository();
		$this->writer = new RoomEventService( $this->events );}
	public function parse( string $notation ) {
		$notation = strtolower( trim( $notation ?: '1d20' ) );
		if ( strlen( $notation ) > 32 || ! preg_match( '/^(\d*)d(\d+)([+-]\d+)?$/', $notation, $m ) ) {
			return new \WP_Error( 'acl_ar_invalid_dice_notation', __( 'Dice notation is invalid.', 'acl-agent-rooms' ), array( 'status' => 400 ) );
		}$count       = '' === $m[1] ? 1 : (int) $m[1];
		$sides        = (int) $m[2];
		$modifier     = isset( $m[3] ) ? (int) $m[3] : 0;
		$limits       = apply_filters(
			'acl_ar_dice_limits',
			array(
				'count_max'    => 20,
				'sides_max'    => 1000,
				'modifier_max' => 10000,
			)
		);
		$count_max    = max( 1, min( 100, (int) ( $limits['count_max'] ?? 20 ) ) );
		$sides_max    = max( 2, min( 100000, (int) ( $limits['sides_max'] ?? 1000 ) ) );
		$modifier_max = max( 0, min( 1000000, (int) ( $limits['modifier_max'] ?? 10000 ) ) );
		if ( $count < 1 || $count > $count_max || $sides < 2 || $sides > $sides_max || abs( $modifier ) > $modifier_max ) {
			return new \WP_Error( 'acl_ar_invalid_dice_notation', __( 'Dice notation is outside the allowed limits.', 'acl-agent-rooms' ), array( 'status' => 400 ) );
		}return array(
			'notation' => $count . 'd' . $sides . ( $modifier > 0 ? '+' . $modifier : ( $modifier < 0 ? (string) $modifier : '' ) ),
			'count'    => $count,
			'sides'    => $sides,
			'modifier' => $modifier,
		);}
	public function roll( int $room_id, int $user_id, string $notation, string $request_id ) {
		$key      = hash( 'sha256', 'dice-roll:' . $room_id . ':' . $user_id . ':' . $request_id );
		$existing = $this->events->find_by_idempotency_key( $key );
		if ( $existing ) {
			return array(
				'event'     => $existing,
				'duplicate' => true,
			);
		}$spec = $this->parse( $notation );
		if ( is_wp_error( $spec ) ) {
			return $spec;
		}$rolls = array();for ( $i = 0;$i < $spec['count'];$i++ ) {
			$rolls[] = random_int( 1, $spec['sides'] );
		}$subtotal = array_sum( $rolls );
		$total     = $subtotal + $spec['modifier'];
		$event     = $this->writer->create(
			array(
				'room_id'         => $room_id,
				'event_type'      => 'dice_roll',
				'actor_type'      => 'user',
				'actor_id'        => $user_id,
				'audience_type'   => 'room',
				'idempotency_key' => $key,
				'content'         => null,
				'content_format'  => 'plain',
				'metadata'        => array_merge(
					$spec,
					array(
						'rolls'    => $rolls,
						'subtotal' => $subtotal,
						'total'    => $total,
					)
				),
			)
		);
		return is_wp_error( $event ) ? $event : array(
			'event'     => $event,
			'duplicate' => false,
		);}
}
