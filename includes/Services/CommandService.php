<?php
/** Central command orchestration. @package ACL_Agent_Rooms */
namespace ACL\AgentRooms\Services;

use ACL\AgentRooms\Repositories\EventRepository;
use ACL\AgentRooms\Repositories\RoomRepository;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class CommandService {
	private CommandRegistry $registry;
	private CommandParser $parser;
	private RoomRepository $rooms;
	private AccessService $access;
	private RateLimiter $limiter;
	private EventRepository $events;
	private EventProjectionService $projection;

	public function __construct( ?RoomRepository $rooms = null, ?AccessService $access = null, ?EventRepository $events = null ) {
		$this->registry   = new CommandRegistry();
		$this->parser     = new CommandParser();
		$this->rooms      = $rooms ?: new RoomRepository();
		$this->access     = $access ?: new AccessService( $this->rooms );
		$this->events     = $events ?: new EventRepository();
		$this->limiter    = new RateLimiter();
		$this->projection = new EventProjectionService( null, $this->events );
	}

	public function definitions(): array {
		return $this->registry->public_definitions();
	}

	public function parse( string $input ) {
		$parsed = $this->parser->parse( $input );
		if ( is_wp_error( $parsed ) ) {
			return $parsed;
		}
		$definition = $this->registry->resolve( $parsed['name'] );
		if ( ! $definition ) {
			return new \WP_Error( 'acl_ar_unknown_command', __( 'Command not recognized.', 'acl-agent-rooms' ), array( 'status' => 400 ) );
		}
		$parsed['definition'] = $definition;
		$parsed['name']       = $definition['name'];
		return $parsed;
	}

	public function execute( int $room_id, int $user_id, string $input, string $request_id, int $recipient_id = 0 ) {
		$room = $this->rooms->find( $room_id );
		if ( ! $room ) {
			return new \WP_Error( 'acl_ar_room_not_found', __( 'Room not found.', 'acl-agent-rooms' ), array( 'status' => 404 ) );
		}

		$parsed = $this->parse( $input );
		if ( is_wp_error( $parsed ) ) {
			return $parsed;
		}
		$write = 'write' === $parsed['definition']['permission'];
		if ( ! $this->access->can_access_room( $room_id, $user_id, $write ) || ( $write && 'active' !== (string) $room['status'] ) ) {
			return new \WP_Error( 'acl_ar_command_forbidden', __( 'You cannot run that command in this room.', 'acl-agent-rooms' ), array( 'status' => 403 ) );
		}

		$request_id = ( new MessagePolicy() )->client_request_id( $request_id );
		if ( is_wp_error( $request_id ) || '' === $request_id ) {
			return is_wp_error( $request_id ) ? $request_id : new \WP_Error( 'acl_ar_invalid_client_request_id', __( 'Client request ID is required.', 'acl-agent-rooms' ), array( 'status' => 400 ) );
		}
		if ( 'ask' === $parsed['name'] ) {
			return array(
				'delegate'          => 'ask',
				'parsed'            => $parsed,
				'client_request_id' => $request_id,
			);
		}

		$whisper = null;
		if ( 'whisper' === $parsed['name'] ) {
			$whisper = $this->resolve_whisper( $room_id, $user_id, $parsed['arguments'], $recipient_id );
			if ( is_wp_error( $whisper ) ) {
				return $whisper;
			}
		}

		// A retry must return its durable result even when the caller has since
		// exhausted the command rate limit. This lookup never creates an event.
		$key      = $this->idempotency_key( $parsed['name'], $room_id, $user_id, $request_id, $whisper['recipient_id'] ?? 0 );
		$existing = $this->events->find_by_idempotency_key( $key );
		if ( $existing ) {
			return $this->response( $parsed['name'], $request_id, $existing, true, $room_id, $user_id );
		}

		$rate = 'whisper' === $parsed['name']
			? $this->limiter->can_user_whisper( $user_id, $room_id )
			: $this->limiter->can_user_execute_command( $user_id, $room_id, $parsed['name'] );
		if ( is_wp_error( $rate ) ) {
			return new \WP_Error( 'acl_ar_command_rate_limited', $rate->get_error_message(), $rate->get_error_data() );
		}

		$result = null;
		switch ( $parsed['name'] ) {
			case 'roll':
				$result = ( new DiceService( $this->events ) )->roll( $room_id, $user_id, $parsed['arguments'], $request_id );
				break;
			case 'coin':
				if ( '' !== $parsed['arguments'] ) {
					return new \WP_Error( 'acl_ar_invalid_command', __( 'The coin command does not accept an outcome.', 'acl-agent-rooms' ), array( 'status' => 400 ) );
				}
				$result = ( new CoinFlipService( $this->events ) )->flip( $room_id, $user_id, $request_id );
				break;
			case 'me':
				$result = ( new RoomActionService( $this->events ) )->create( $room_id, $user_id, $parsed['arguments'], $request_id );
				break;
			case 'whisper':
				$result = ( new WhisperService( $this->events ) )->send( $room_id, $user_id, $whisper['recipient_id'], '', $whisper['message'], $request_id );
				break;
			case 'help':
				$result = $this->notice( $room_id, $user_id, 'help', $this->help_text(), $request_id );
				break;
			case 'agents':
				$result = $this->notice( $room_id, $user_id, 'agents', $this->agents_text( $room_id ), $request_id );
				break;
		}

		if ( is_wp_error( $result ) ) {
			return $result;
		}
		return $this->response( $parsed['name'], $request_id, $result['event'], (bool) $result['duplicate'], $room_id, $user_id );
	}

	private function resolve_whisper( int $room_id, int $user_id, string $arguments, int $recipient_id ) {
		$parts = $this->parser->whisper_arguments( $arguments );
		if ( is_wp_error( $parts ) && ! $recipient_id ) {
			return $parts;
		}
		$message  = $recipient_id ? $arguments : $parts['message'];
		$name     = $recipient_id ? '' : $parts['recipient'];
		$resolved = ( new WhisperRecipientResolver( $this->rooms, $this->access, $this->events ) )->resolve( $room_id, $user_id, $recipient_id, $name );
		if ( is_wp_error( $resolved ) ) {
			return $resolved;
		}
		return array(
			'recipient_id' => $resolved,
			'message'      => $message,
		);
	}

	private function idempotency_key( string $name, int $room_id, int $user_id, string $request_id, int $recipient_id = 0 ): string {
		$prefixes = array(
			'roll'    => 'dice-roll',
			'coin'    => 'coin-flip',
			'me'      => 'room-action',
			'whisper' => 'whisper',
		);
		if ( isset( $prefixes[ $name ] ) ) {
			$parts = array( $prefixes[ $name ], $room_id, $user_id );
			if ( 'whisper' === $name ) {
				$parts[] = $recipient_id;
			}
			$parts[] = $request_id;
			return hash( 'sha256', implode( ':', $parts ) );
		}
		return hash( 'sha256', 'command:' . $room_id . ':' . $user_id . ':' . $name . ':' . $request_id );
	}

	private function response( string $name, string $request_id, array $event, bool $duplicate, int $room_id, int $user_id ): array {
		return array(
			'command' => array(
				'name'              => $name,
				'client_request_id' => $request_id,
				'event_id'          => (int) $event['id'],
			),
			'result'  => array(
				'status'    => 'completed',
				'duplicate' => $duplicate,
			),
			'event'   => $this->projection->project_page( array( $event ), $this->access->can_manage_room( $room_id, $user_id ), $user_id )[0],
		);
	}

	private function notice( int $room_id, int $user_id, string $name, string $content, string $request_id ) {
		$key      = hash( 'sha256', 'command:' . $room_id . ':' . $user_id . ':' . $name . ':' . $request_id );
		$existing = $this->events->find_by_idempotency_key( $key );
		if ( $existing ) {
			return array(
				'event'     => $existing,
				'duplicate' => true,
			);
		}
		$event = ( new RoomEventService( $this->events ) )->create(
			array(
				'room_id'         => $room_id,
				'event_type'      => 'system_notice',
				'actor_type'      => 'system',
				'audience_type'   => 'room',
				'idempotency_key' => $key,
				'content'         => $content,
				'content_format'  => 'plain',
			)
		);
		return is_wp_error( $event ) ? $event : array(
			'event'     => $event,
			'duplicate' => false,
		);
	}

	private function help_text(): string {
		$parts = array();
		foreach ( $this->registry->all() as $definition ) {
			$parts[] = $definition['usage'] . ' - ' . $definition['description'];
		}
		return implode( "\n", $parts );
	}

	private function agents_text( int $room_id ): string {
		$agents = $this->rooms->get_agents( $room_id );
		return $agents
			? sprintf(
				/* translators: %s: Comma-separated list of assigned agents and participation states. */
				__( 'Assigned agents: %s', 'acl-agent-rooms' ),
				implode(
					', ',
					array_map(
						static function ( $agent ) {
							$flags = array( (string) ( $agent['participation_state'] ?? 'active' ) );
							if ( ! empty( $agent['auto_muted'] ) ) {
								$flags[] = 'auto replies muted';
							}return '@' . $agent['slug'] . ' (' . implode( ', ', $flags ) . ')';},
						$agents
					)
				)
			)
			: __( 'No agents are assigned to this room.', 'acl-agent-rooms' );
	}
}
