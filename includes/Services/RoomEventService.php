<?php
/** Canonical normalized room event creation service. @package ACL_Agent_Rooms */

namespace ACL\AgentRooms\Services;

use ACL\AgentRooms\Models\RoomEvent;
use ACL\AgentRooms\Repositories\EventRepository;
use ACL\AgentRooms\Support\Time;

if ( ! defined( 'ABSPATH' ) ) {
	exit; }

class RoomEventService {
	private EventRepository $events;
	private LegacyMessageEventAdapter $adapter;

	public function __construct( ?EventRepository $events = null, ?LegacyMessageEventAdapter $adapter = null ) {
		$this->events  = $events ?: new EventRepository();
		$this->adapter = $adapter ?: new LegacyMessageEventAdapter();
	}

	public function create( array $input ) {
		$data = $this->validate( $input );
		if ( is_wp_error( $data ) ) {
			return $data; }
		$existing = ! empty( $data['legacy_message_id'] ) ? $this->events->find_by_legacy_message_id( (int) $data['legacy_message_id'] ) : null;
		$existing = $existing ?: ( ! empty( $data['idempotency_key'] ) ? $this->events->find_by_idempotency_key( (string) $data['idempotency_key'] ) : null );
		if ( $existing ) {
			return $existing; }
		$id = $this->events->create( $data );
		if ( is_wp_error( $id ) ) {
			return $id; }
		$event = $this->events->find( (int) $id );
		if ( $event ) {
			( new EventSearchIndexer() )->index( $event ); }
		return $event;
	}

	public function create_from_legacy_message( array $message ) {
		$input                    = $this->adapter->convert( $message );
		$input['idempotency_key'] = $this->key( 'legacy-message:' . (int) ( $message['id'] ?? 0 ) );
		return $this->create( $input );
	}

	public function create_message_event( array $message ) {
		return $this->create_from_legacy_message( $message ); }

	public function create_agent_lifecycle( array $job, string $event_type, array $metadata = array() ) {
		if ( ! in_array( $event_type, array( RoomEvent::TYPE_AGENT_QUEUED, RoomEvent::TYPE_AGENT_THINKING, RoomEvent::TYPE_AGENT_RESPONDING, RoomEvent::TYPE_AGENT_COMPLETED, RoomEvent::TYPE_AGENT_FAILED ), true ) ) {
			return new \WP_Error( 'acl_ar_event_invalid_lifecycle', __( 'Invalid agent lifecycle event type.', 'acl-agent-rooms' ) );
		}
		$key_source = 'agent-job:' . (int) $job['id'] . ':' . $event_type;
		if ( in_array( $event_type, array( RoomEvent::TYPE_AGENT_THINKING, RoomEvent::TYPE_AGENT_RESPONDING, RoomEvent::TYPE_AGENT_FAILED ), true ) && (int) ( $job['attempts'] ?? 0 ) > 1 ) {
			$key_source .= ':attempt-' . (int) $job['attempts'];
		}
		$key            = $this->key( $key_source );
		$existing       = $this->events->find_by_idempotency_key( $key );
		$clean_metadata = $this->normalize_metadata( $metadata );
		if ( $existing ) {
			if ( ! empty( $clean_metadata ) ) {
				$this->events->update_metadata( (int) $existing['id'], $clean_metadata ); }
			return $this->events->find( (int) $existing['id'] );
		}
		$event = $this->create(
			array(
				'room_id'         => (int) $job['room_id'],
				'event_type'      => $event_type,
				'actor_type'      => 'agent',
				'actor_id'        => (int) $job['agent_id'],
				'audience_type'   => 'room',
				'job_id'          => (int) $job['id'],
				'idempotency_key' => $key,
				'content'         => '',
				'content_format'  => 'plain',
				'metadata'        => $clean_metadata,
			)
		);
		if ( ! is_wp_error( $event ) && is_array( $event ) ) {
			( new AgentStateProjector() )->project_event( $event ); }
		return $event;
	}

	public function reconcile_job( array $job, ?array $response_message = null ) {
		if ( $response_message ) {
			$event = $this->create_from_legacy_message( $response_message );
			if ( is_wp_error( $event ) ) {
				return $event; }
		}
		if ( 'completed' === (string) ( $job['status'] ?? '' ) ) {
			return $this->create_agent_lifecycle( $job, RoomEvent::TYPE_AGENT_COMPLETED, array( 'response_message_id' => (int) ( $job['response_message_id'] ?? ( $response_message['id'] ?? 0 ) ) ) );
		}
		if ( 'failed' === (string) ( $job['status'] ?? '' ) ) {
			return $this->create_agent_lifecycle(
				$job,
				RoomEvent::TYPE_AGENT_FAILED,
				array(
					'attempt'   => (int) ( $job['attempts'] ?? 0 ),
					'attempts'  => (int) ( $job['attempts'] ?? 0 ),
					'retryable' => ! empty( $job['retryable'] ),
					'error'     => PublicError::message( (string) ( $job['public_error'] ?? '' ), __( 'Agent reply failed.', 'acl-agent-rooms' ) ),
				)
			);
		}
		return true;
	}

	private function validate( array $input ) {
		$room_id       = absint( $input['room_id'] ?? 0 );
		$event_type    = sanitize_key( (string) ( $input['event_type'] ?? '' ) );
		$actor_type    = sanitize_key( (string) ( $input['actor_type'] ?? '' ) );
		$audience_type = sanitize_key( (string) ( $input['audience_type'] ?? 'room' ) );
		$format        = sanitize_key( (string) ( $input['content_format'] ?? 'plain' ) );
		if ( $room_id <= 0 || ! in_array( $event_type, RoomEvent::EVENT_TYPES, true ) || ! in_array( $actor_type, RoomEvent::ACTOR_TYPES, true ) || ! in_array( $audience_type, RoomEvent::AUDIENCE_TYPES, true ) || ! in_array( $format, RoomEvent::CONTENT_FORMATS, true ) ) {
			return new \WP_Error( 'acl_ar_event_invalid', __( 'Room event data is invalid.', 'acl-agent-rooms' ) );
		}
		$actor_id = ! empty( $input['actor_id'] ) ? absint( $input['actor_id'] ) : null;
		if ( 'system' !== $actor_type && ! $actor_id ) {
			return new \WP_Error( 'acl_ar_event_actor_required', __( 'Room event actor ID is required.', 'acl-agent-rooms' ) ); }
		if ( 'system' === $actor_type ) {
			$actor_id = null; }
		$target_type = ! empty( $input['target_type'] ) ? sanitize_key( (string) $input['target_type'] ) : null;
		if ( $target_type && ! in_array( $target_type, RoomEvent::TARGET_TYPES, true ) ) {
			return new \WP_Error( 'acl_ar_event_target_invalid', __( 'Room event target type is invalid.', 'acl-agent-rooms' ) ); }
		$target_id = ! empty( $input['target_id'] ) ? absint( $input['target_id'] ) : null;
		if ( $target_type && ! $target_id ) {
			return new \WP_Error( 'acl_ar_event_target_required', __( 'Room event target ID is required.', 'acl-agent-rooms' ) ); }
		$audience_id = ! empty( $input['audience_id'] ) ? absint( $input['audience_id'] ) : null;
		if ( in_array( $audience_type, array( 'user', 'agent' ), true ) && ! $audience_id ) {
			return new \WP_Error( 'acl_ar_event_audience_required', __( 'Room event audience ID is required.', 'acl-agent-rooms' ) ); }
		if ( in_array( $audience_type, array( 'room', 'moderators' ), true ) ) {
			$audience_id = null; }
		$key = ! empty( $input['idempotency_key'] ) ? strtolower( preg_replace( '/[^a-f0-9]/', '', (string) $input['idempotency_key'] ) ?: '' ) : null;
		if ( $key && 64 !== strlen( $key ) ) {
			return new \WP_Error( 'acl_ar_event_key_invalid', __( 'Room event idempotency key is invalid.', 'acl-agent-rooms' ) ); }
		return array(
			'room_id'           => $room_id,
			'event_type'        => $event_type,
			'actor_type'        => $actor_type,
			'actor_id'          => $actor_id,
			'target_type'       => $target_type,
			'target_id'         => $target_id,
			'audience_type'     => $audience_type,
			'audience_id'       => $audience_id,
			'parent_event_id'   => ! empty( $input['parent_event_id'] ) ? absint( $input['parent_event_id'] ) : null,
			'legacy_message_id' => ! empty( $input['legacy_message_id'] ) ? absint( $input['legacy_message_id'] ) : null,
			'job_id'            => ! empty( $input['job_id'] ) ? absint( $input['job_id'] ) : null,
			'idempotency_key'   => $key,
			'content'           => in_array( $event_type, array( 'reaction', 'dice_roll', 'coin_flip', 'room_clear' ), true ) ? null : wp_strip_all_tags( (string) ( $input['content'] ?? '' ) ),
			'content_format'    => $format,
			'metadata'          => $this->normalize_metadata( is_array( $input['metadata'] ?? null ) ? $input['metadata'] : array() ),
			'created_at'        => (string) ( $input['created_at'] ?? Time::mysql_gmt() ),
			'edited_at'         => null,
			'deleted_at'        => null,
		);
	}

	private function normalize_metadata( array $metadata, int $depth = 0 ): array {
		if ( $depth > 4 ) {
			return array(); }
		$clean = array();
		foreach ( $metadata as $key => $value ) {
			$key = sanitize_key( (string) $key );
			if ( '' === $key || preg_match( '/credential|authorization|api[_-]?key|secret|password|raw[_-]?(payload|result|request)/i', $key ) ) {
				continue; }
			if ( is_array( $value ) ) {
				$clean[ $key ] = $this->normalize_metadata( $value, $depth + 1 ); } elseif ( is_bool( $value ) || is_int( $value ) || is_float( $value ) ) {
				$clean[ $key ] = $value; } elseif ( is_scalar( $value ) ) {
					$clean[ $key ] = sanitize_textarea_field( (string) $value ); }
		}
		return $clean;
	}

	private function key( string $source ): string {
		return hash( 'sha256', $source ); }
}
