<?php
/** Plain-text room actions. @package ACL_Agent_Rooms */
namespace ACL\AgentRooms\Services;

use ACL\AgentRooms\Repositories\EventRepository;
if ( ! defined( 'ABSPATH' ) ) {
	exit;}
class RoomActionService {
	private EventRepository $events;
	private RoomEventService $writer;
	private MessagePolicy $policy;
	public function __construct( ?EventRepository $events = null ) {
		$this->events = $events ?: new EventRepository();
		$this->writer = new RoomEventService( $this->events );
		$this->policy = new MessagePolicy();
	}public function create( int $room_id, int $user_id, string $raw, string $request_id ) {
		$content = $this->policy->normalize( $raw, $user_id, $room_id );
		if ( is_wp_error( $content ) ) {
			return $content;
		}$key     = hash( 'sha256', 'room-action:' . $room_id . ':' . $user_id . ':' . $request_id );
		$existing = $this->events->find_by_idempotency_key( $key );
		if ( $existing ) {
			return array(
				'event'     => $existing,
				'duplicate' => true,
			);
		}$event = $this->writer->create(
			array(
				'room_id'         => $room_id,
				'event_type'      => 'action',
				'actor_type'      => 'user',
				'actor_id'        => $user_id,
				'audience_type'   => 'room',
				'idempotency_key' => $key,
				'content'         => $content,
				'content_format'  => 'plain',
			)
		);
		return is_wp_error( $event ) ? $event : array(
			'event'     => $event,
			'duplicate' => false,
		);}
}
