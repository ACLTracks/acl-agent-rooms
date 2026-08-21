<?php
/** Durable private room whispers. @package ACL_Agent_Rooms */
namespace ACL\AgentRooms\Services;

use ACL\AgentRooms\Repositories\EventRepository;
if ( ! defined( 'ABSPATH' ) ) {
	exit;}
class WhisperService {
	private EventRepository $events;
	private RoomEventService $writer;
	private MessagePolicy $policy;
	private WhisperRecipientResolver $resolver;
	public function __construct( ?EventRepository $events = null, ?WhisperRecipientResolver $resolver = null ) {
		$this->events   = $events ?: new EventRepository();
		$this->writer   = new RoomEventService( $this->events );
		$this->policy   = new MessagePolicy();
		$this->resolver = $resolver ?: new WhisperRecipientResolver( null, null, $this->events );
	}public function send( int $room_id, int $sender_id, int $recipient_id, string $recipient_name, string $raw, string $request_id ) {
		$recipient = $this->resolver->resolve( $room_id, $sender_id, $recipient_id, $recipient_name );
		if ( is_wp_error( $recipient ) ) {
			return $recipient;
		}$content = $this->policy->normalize( $raw, $sender_id, $room_id );
		if ( is_wp_error( $content ) ) {
			return $content;
		}$key     = hash( 'sha256', 'whisper:' . $room_id . ':' . $sender_id . ':' . $recipient . ':' . $request_id );
		$existing = $this->events->find_by_idempotency_key( $key );
		if ( $existing ) {
			return array(
				'event'     => $existing,
				'duplicate' => true,
			);
		}$event = $this->writer->create(
			array(
				'room_id'         => $room_id,
				'event_type'      => 'whisper',
				'actor_type'      => 'user',
				'actor_id'        => $sender_id,
				'audience_type'   => 'user',
				'audience_id'     => $recipient,
				'idempotency_key' => $key,
				'content'         => $content,
				'content_format'  => 'plain',
				'metadata'        => array(),
			)
		);
		return is_wp_error( $event ) ? $event : array(
			'event'     => $event,
			'duplicate' => false,
		);}
}
