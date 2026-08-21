<?php
/** Secure server-side coin flips. @package ACL_Agent_Rooms */
namespace ACL\AgentRooms\Services;

use ACL\AgentRooms\Repositories\EventRepository;
if ( ! defined( 'ABSPATH' ) ) {
	exit;}
class CoinFlipService {
	private EventRepository $events;
	private RoomEventService $writer;
	public function __construct( ?EventRepository $events = null ) {
		$this->events = $events ?: new EventRepository();
		$this->writer = new RoomEventService( $this->events );
	}public function flip( int $room_id, int $user_id, string $request_id ) {
		$key      = hash( 'sha256', 'coin-flip:' . $room_id . ':' . $user_id . ':' . $request_id );
		$existing = $this->events->find_by_idempotency_key( $key );
		if ( $existing ) {
			return array(
				'event'     => $existing,
				'duplicate' => true,
			);
		}$result = random_int( 0, 1 ) ? 'heads' : 'tails';
		$event   = $this->writer->create(
			array(
				'room_id'         => $room_id,
				'event_type'      => 'coin_flip',
				'actor_type'      => 'user',
				'actor_id'        => $user_id,
				'audience_type'   => 'room',
				'idempotency_key' => $key,
				'content'         => null,
				'content_format'  => 'plain',
				'metadata'        => array( 'result' => $result ),
			)
		);
		return is_wp_error( $event ) ? $event : array(
			'event'     => $event,
			'duplicate' => false,
		);}
}
