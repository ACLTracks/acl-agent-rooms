<?php
/**
 * Message REST controller.
 *
 * @package ACL_Agent_Rooms
 */

namespace ACL\AgentRooms\Rest;

use ACL\AgentRooms\Repositories\JobRepository;
use ACL\AgentRooms\Repositories\AgentRepository;
use ACL\AgentRooms\Repositories\MessageRepository;
use ACL\AgentRooms\Repositories\RoomRepository;
use ACL\AgentRooms\Services\AccessService;
use ACL\AgentRooms\Services\AgentMentionParser;
use ACL\AgentRooms\Services\AgentRuntime;
use ACL\AgentRooms\Services\AgentExecutionPolicy;
use ACL\AgentRooms\Services\MessagePolicy;
use ACL\AgentRooms\Services\MessageInteractionPolicy;
use ACL\AgentRooms\Repositories\EventRepository;
use ACL\AgentRooms\Services\PublicError;
use ACL\AgentRooms\Services\PublicJob;
use ACL\AgentRooms\Services\RoomEventService;
use ACL\AgentRooms\Services\QueueService;
use ACL\AgentRooms\Services\RateLimiter;
use ACL\AgentRooms\Services\CommandService;
use ACL\AgentRooms\Services\AgentParticipationService;
use ACL\AgentRooms\Services\BrainRunService;
use ACL\AgentRooms\Repositories\BrainRunRepository;
use ACL\AgentRooms\Repositories\ConversationTurnRepository;
use ACL\AgentRooms\Services\NaturalConversationDirector;
use ACL\AgentRooms\Services\ConversationTurnService;
use ACL\AgentRooms\Services\EventProjectionService;
use ACL\AgentRooms\Services\HumanMessageService;
use ACL\AgentRooms\Services\OrchestrationDiagnosticService;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class MessagesController extends AbstractController {
	private RoomRepository $rooms;
	private AgentRepository $agents;
	private MessageRepository $messages;
	private JobRepository $jobs;
	private AccessService $access;
	private AgentRuntime $runtime;
	private AgentMentionParser $parser;
	private RateLimiter $rate_limiter;
	private MessagePolicy $message_policy;
	private AgentExecutionPolicy $execution_policy;
	private PublicJob $public_jobs;
	private RoomEventService $room_events;
	private MessageInteractionPolicy $interaction_policy;
	private EventRepository $events;
	private AgentParticipationService $participation;
	private EventProjectionService $projection;
	private OrchestrationDiagnosticService $diagnostics;

	public function __construct(
		RoomRepository $rooms,
		AgentRepository $agents,
		MessageRepository $messages,
		JobRepository $jobs,
		AccessService $access,
		AgentRuntime $runtime
	) {
		$this->rooms              = $rooms;
		$this->agents             = $agents;
		$this->messages           = $messages;
		$this->jobs               = $jobs;
		$this->access             = $access;
		$this->runtime            = $runtime;
		$this->parser             = new AgentMentionParser();
		$this->rate_limiter       = new RateLimiter();
		$this->message_policy     = new MessagePolicy();
		$this->execution_policy   = new AgentExecutionPolicy( $access, $this->rate_limiter );
		$this->public_jobs        = new PublicJob( $agents );
		$this->room_events        = new RoomEventService();
		$this->events             = new EventRepository();
		$this->interaction_policy = new MessageInteractionPolicy( $rooms, $access, $this->events );
		$this->participation      = new AgentParticipationService( $rooms, $jobs, $this->room_events );
		$this->projection         = new EventProjectionService( $agents, $this->events );
		$this->diagnostics        = new OrchestrationDiagnosticService();
	}

	public function register_routes(): void {
		register_rest_route(
			self::NAMESPACE,
			'/rooms/(?P<id>[\d]+)/messages',
			array(
				array(
					'methods'             => 'GET',
					'callback'            => array( $this, 'index' ),
					'permission_callback' => array( $this, 'read_permissions' ),
				),
				array(
					'methods'             => 'POST',
					'callback'            => array( $this, 'create' ),
					'permission_callback' => array( $this, 'write_permissions' ),
					'args'                => array(
						'content'           => array(
							'required' => true,
							'type'     => 'string',
						),
						'client_request_id' => array(
							'required' => false,
							'type'     => 'string',
						),
						'reply_to_event_id' => array(
							'required'          => false,
							'sanitize_callback' => 'absint',
							'validate_callback' => static fn( $value ) => null === $value || '' === $value || absint( $value ) > 0,
						),
						'room_file_ids'     => array(
							'required' => false,
							'type'     => 'array',
							'items'    => array( 'type' => 'integer' ),
						),
					),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/rooms/(?P<id>[\d]+)/agents/(?P<agent_id>[\d]+)/reply',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'manual_reply' ),
				'permission_callback' => array( $this, 'manual_reply_permissions' ),
			)
		);
	}

	public function read_permissions( \WP_REST_Request $request ) {
		$allowed = $this->require_room_user();
		if ( is_wp_error( $allowed ) ) {
			return $allowed;
		}

		$room_id = absint( $request['id'] );
		if ( ! $this->rooms->find( $room_id ) ) {
			return new \WP_Error( 'acl_ar_room_not_found', __( 'Room not found.', 'acl-agent-rooms' ), array( 'status' => 404 ) );
		}

		return $this->access->can_access_room( $room_id )
			? true
			: new \WP_Error( 'acl_ar_room_forbidden', __( 'You cannot access this room.', 'acl-agent-rooms' ), array( 'status' => 403 ) );
	}

	public function write_permissions( \WP_REST_Request $request ) {
		$nonce = $this->verify_nonce( $request );
		if ( is_wp_error( $nonce ) ) {
			return $nonce;
		}

		$room_id = absint( $request['id'] );
		if ( ! $this->rooms->find( $room_id ) ) {
			return new \WP_Error( 'acl_ar_room_not_found', __( 'Room not found.', 'acl-agent-rooms' ), array( 'status' => 404 ) );
		}

		return $this->access->can_access_room( $room_id, get_current_user_id(), true )
			? true
			: new \WP_Error( 'acl_ar_room_forbidden', __( 'You cannot send messages in this room.', 'acl-agent-rooms' ), array( 'status' => 403 ) );
	}

	public function manual_reply_permissions( \WP_REST_Request $request ) {
		return $this->write_permissions( $request );
	}

	public function index( \WP_REST_Request $request ): \WP_REST_Response {
		$room_id  = absint( $request['id'] );
		$after_id = absint( $request->get_param( 'after_id' ) );
		$limit    = absint( $request->get_param( 'limit' ) ?: 50 );

		return new \WP_REST_Response(
			array(
				'messages' => $this->prepare_messages( $this->messages->for_room( $room_id, $limit, $after_id ) ),
			),
			200
		);
	}

	public function create( \WP_REST_Request $request ) {
		$room_id = absint( $request['id'] );
		$room    = $this->rooms->find( $room_id );
		if ( ! $room ) {
			return new \WP_Error( 'acl_ar_room_not_found', __( 'Room not found.', 'acl-agent-rooms' ), array( 'status' => 404 ) );
		}
		if ( 'active' !== (string) ( $room['status'] ?? 'active' ) ) {
			return new \WP_Error( 'acl_ar_room_inactive', __( 'This room is not accepting new messages.', 'acl-agent-rooms' ), array( 'status' => 423 ) );
		}

		$client_request_id = $this->message_policy->client_request_id( $request->get_param( 'client_request_id' ) );
		if ( is_wp_error( $client_request_id ) ) {
			return $client_request_id;
		}
		$reply_to_event_id = absint( $request->get_param( 'reply_to_event_id' ) );
		if ( $reply_to_event_id ) {
			$reply_target = $this->interaction_policy->target( $room_id, $reply_to_event_id, get_current_user_id(), 'reply' );
			if ( is_wp_error( $reply_target ) ) {
				return $reply_target; }
		}
		$existing_message = null;
		if ( '' !== $client_request_id ) {
			$existing_message = $this->messages->find_by_client_request( $room_id, get_current_user_id(), $client_request_id );
			if ( $existing_message ) {
				$existing_event = $this->events->find_by_legacy_message_id( (int) $existing_message['id'] );
				if ( $reply_to_event_id && $existing_event && empty( $existing_event['parent_event_id'] ) ) {
					$this->events->update_parent_if_empty( (int) $existing_event['id'], $reply_to_event_id ); }
				if ( $reply_to_event_id && $existing_event && ! empty( $existing_event['parent_event_id'] ) && (int) $existing_event['parent_event_id'] !== $reply_to_event_id ) {
					return new \WP_Error( 'acl_ar_event_not_editable', __( 'Duplicate request reply target does not match.', 'acl-agent-rooms' ), array( 'status' => 409 ) ); }
			}
		}
		$raw_content = (string) $request->get_param( 'content' );
		$content     = $this->message_policy->normalize( $raw_content, get_current_user_id(), $room_id );
		if ( is_wp_error( $content ) ) {
			return $content;
		}
		if ( isset( $content[0] ) && '/' === $content[0] ) {
			$commands = new CommandService( $this->rooms, $this->access, $this->events );
			$parsed   = $commands->parse( $content );
			if ( is_wp_error( $parsed ) ) {
				return $parsed; }
			if ( 'ask' !== (string) $parsed['name'] ) {
				$result = $commands->execute( $room_id, get_current_user_id(), $content, $client_request_id );
				return is_wp_error( $result ) ? $result : new \WP_REST_Response( $result, 200 );
			}
		}
		if ( ! $existing_message ) {
			$rate = $this->rate_limiter->can_user_send_message( get_current_user_id(), $room_id );
			if ( is_wp_error( $rate ) ) {
				return $rate;
			}
		}

		$agents       = $this->rooms->get_agents( $room_id );
		$command      = $this->parser->parse_slash_command( $content );
		$targets      = array();
		$metadata     = array();
		$trigger_mode = 'explicit';
		$forced_ids   = array();
		if ( $reply_to_event_id ) {
			$metadata['reply_to_event_id'] = $reply_to_event_id; }
		$message_content = $content;
		$system_notice   = '';

		if ( 'ask' === (string) $command['command'] ) {
			$requested_slugs = array_values( array_unique( array_filter( array_map( 'sanitize_title', (array) ( $command['agent_slugs'] ?? array( $command['agent_slug'] ?? '' ) ) ) ) ) );
			foreach ( $requested_slugs as $slug ) {
				$agent = $this->parser->agent_by_slug( $slug, $agents );
				if ( ! $agent ) {
					return new \WP_Error( 'acl_ar_agent_not_assigned', __( 'One of those agents is not assigned to this room.', 'acl-agent-rooms' ), array( 'status' => 400 ) ); }
				$eligible = $this->participation->enforce( $room_id, (int) $agent['id'], 'explicit' );
				if ( is_wp_error( $eligible ) ) {
					return $eligible; }
				$targets[]    = $agent;
				$forced_ids[] = (int) $agent['id'];
			}
			$message_content = (string) $command['message'];
			$metadata        = array(
				'slash_command'     => 'ask',
				'agent_slug'        => (string) ( $requested_slugs[0] ?? '' ),
				'agent_slugs'       => $requested_slugs,
				'reply_to_event_id' => $reply_to_event_id ?: null,
			);
		} elseif ( 'agents' === (string) $command['command'] ) {
			$system_notice = $this->assigned_agents_notice( $agents );
		} elseif ( 'help' === (string) $command['command'] ) {
			$system_notice = __( 'Use @agent-slug, /ask agent-slug[,agent-slug...] message, or /agents.', 'acl-agent-rooms' );
		} elseif ( 'unknown' === (string) $command['command'] ) {
			$system_notice = __( 'Command not recognized.', 'acl-agent-rooms' );
		} else {
			$targets      = $this->targets_for_room_mode( $room, $agents, $content );
			$trigger_mode = 'auto' === (string) ( $room['agent_reply_mode'] ?? '' ) ? 'automatic' : 'explicit';
			if ( 'natural' === (string) ( $room['conversation_mode'] ?? 'immediate' ) ) {
				$mentioned = $this->parser->mentioned_agents( $content, $agents );
				foreach ( $mentioned as $agent ) {
					$eligible = $this->participation->enforce( $room_id, (int) $agent['id'], 'explicit' );
					if ( is_wp_error( $eligible ) ) {
						return $eligible;
					} $forced_ids[] = (int) $agent['id']; }
			}
		}
		if ( is_wp_error( $targets ) ) {
			return $targets; }
		if ( ! $existing_message && ! empty( $targets ) ) {
			$authorization = $this->execution_policy->authorize( get_current_user_id(), $room_id, 'message-trigger', array( 'targets' => count( $targets ) ) );
			if ( is_wp_error( $authorization ) ) {
				return $authorization;
			}
		}
		$metadata['brain_trigger_mode'] = $trigger_mode;
		$selected_files                 = ( new \ACL\AgentRooms\Services\RoomFileService( $this->rooms, null, $this->access ) )->validate_selection( $room, (array) $request->get_param( 'room_file_ids' ), get_current_user_id() );
		if ( is_wp_error( $selected_files ) ) {
			return $selected_files; }
		if ( ! empty( $selected_files ) ) {
			$metadata['room_file_ids'] = $selected_files; }

		$advance_natural_trigger = 'natural' === (string) ( $room['conversation_mode'] ?? 'immediate' ) && ( 'automatic' === $trigger_mode || ! empty( $forced_ids ) );
		$persisted                = ( new HumanMessageService( $this->rooms, $this->messages, $this->events ) )->persist( $room_id, get_current_user_id(), $message_content, $client_request_id, $metadata, $advance_natural_trigger );
		if ( is_wp_error( $persisted ) ) {
			return $persisted; }
		$message_id    = (int) $persisted['message']['id'];
		$message_event = $persisted['event'];
		$was_created   = ! empty( $persisted['created'] );

		if ( '' !== $system_notice ) {
			$notice_id = $this->messages->create(
				array(
					'room_id'     => $room_id,
					'sender_type' => 'system',
					'content'     => $system_notice,
				)
			);
			if ( is_wp_error( $notice_id ) ) {
				$this->diagnostics->record( $room_id, (int) $message_event['id'], $notice_id->get_error_code() );
			} else {
				$notice_event = $this->room_events->create_message_event( $this->messages->find( (int) $notice_id ) );
				if ( is_wp_error( $notice_event ) ) {
					$this->diagnostics->record( $room_id, (int) $message_event['id'], $notice_event->get_error_code() ); }
			}
		}

		$dispatch = $this->dispatch_after_persistence( $room, $agents, $targets, $forced_ids, $trigger_mode, $message_id, $message_event );
		return new \WP_REST_Response(
			array(
				'message_id'           => (int) $message_id,
				'duplicate'            => ! $was_created,
				'event'                => $this->project_event( $message_event, $room_id, get_current_user_id() ),
				'jobs'                 => $dispatch['jobs'],
				'brain_runs'           => $dispatch['brain_runs'],
				'scheduled_turn_count' => $dispatch['scheduled_turn_count'],
				'orchestration'        => array(
					'status'    => $dispatch['degraded'] ? 'degraded' : 'queued',
					'scheduled' => $dispatch['scheduled'],
				),
			),
			$was_created ? 201 : 200
		);
	}

	private function dispatch_after_persistence( array $room, array $agents, array $targets, array $forced_ids, string $trigger_mode, int $message_id, array $message_event ): array {
		$room_id      = (int) $room['id'];
		$jobs         = array();
		$brain_result = array(
			'brain_runs'  => array(),
			'independent' => array(),
		);
		$planned      = array();
		$degraded     = false;
		$scheduled    = 0;
		try {
			do_action( 'acl_ar_before_downstream_dispatch', $room_id, (int) $message_event['id'], $message_id );
			if ( apply_filters( 'acl_ar_force_orchestration_dispatch_failure', false, $room_id, (int) $message_event['id'] ) ) {
				throw new \RuntimeException( 'Injected orchestration dispatch failure.' );
			}
			$natural = 'natural' === (string) ( $room['conversation_mode'] ?? 'immediate' );
			if ( $natural && ( 'automatic' === $trigger_mode || ! empty( $forced_ids ) ) ) {
				( new ConversationTurnService() )->activate_trigger( $room, (int) $message_event['id'] );
				$plan    = ( new NaturalConversationDirector() )->plan( $room, $agents, $forced_ids, 'automatic' === $trigger_mode && empty( $forced_ids ) );
				$targets = $plan['targets'];
				$planned = $plan['turns'];
			} elseif ( ! $natural ) {
				$targets = array_slice( $targets, 0, max( 1, (int) ( $room['max_agents_per_turn'] ?? 1 ) ) );
			}

			// Provider-bearing work is never executed in the human-message request.
			$brain_result = ( new BrainRunService() )->create_for_targets( $room, $message_event, $targets, false, $planned );
			foreach ( $brain_result['brain_runs'] as $item ) {
				if ( isset( $item['error'] ) && is_wp_error( $item['error'] ) ) {
					$degraded = true;
					$this->diagnostics->record( $room_id, (int) $message_event['id'], $item['error']->get_error_code() ); }
				if ( isset( $item['queued'] ) && ! $item['queued'] ) {
					$degraded = true;
					$this->diagnostics->record( $room_id, (int) $message_event['id'], 'acl_ar_brain_enqueue_failed' ); }
				foreach ( (array) ( $item['turn_errors'] ?? array() ) as $error ) {
					if ( is_wp_error( $error ) ) {
						$degraded = true;
						$this->diagnostics->record( $room_id, (int) $message_event['id'], $error->get_error_code() ); }
				}
				if ( ! empty( $item['run']['id'] ) ) {
					++$scheduled; }
			}

			$jobs  = $this->create_jobs_for_targets( $room, $message_id, $brain_result['independent'], $planned, $natural, $message_event );
			$queue = new QueueService();
			foreach ( $jobs as $job ) {
				if ( ! empty( $job['scheduling_error'] ) && is_wp_error( $job['scheduling_error'] ) ) {
					$degraded = true;
					$this->diagnostics->record( $room_id, (int) $message_event['id'], $job['scheduling_error']->get_error_code() ); }
				if ( empty( $job['id'] ) ) {
					$degraded = true;
					$this->diagnostics->record( $room_id, (int) $message_event['id'], 'acl_ar_agent_job_create_failed' );
					continue;
				}
				++$scheduled;
				$stored_job = $this->jobs->find( (int) $job['id'] );
				if ( ! $natural && $stored_job && 'pending' === (string) $stored_job['status'] && ! $queue->enqueue_job( (int) $job['id'] ) ) {
					$degraded = true;
					$this->diagnostics->record( $room_id, (int) $message_event['id'], 'acl_ar_agent_job_enqueue_failed' ); }
			}
		} catch ( \Throwable $throwable ) {
			$degraded = true;
			$this->diagnostics->record( $room_id, (int) $message_event['id'], 'acl_ar_orchestration_dispatch_exception' );
		}

		$public_jobs = array();
		foreach ( $jobs as $job_result ) {
			if ( ! empty( $job_result['id'] ) ) {
				$stored_job = $this->jobs->find( (int) $job_result['id'] );
				if ( $stored_job ) {
					$public_jobs[] = $this->public_jobs->prepare( $stored_job, $this->access->can_manage_room( $room_id ) ); }
			} elseif ( isset( $job_result['ok'] ) && false === $job_result['ok'] ) {
				$public_jobs[] = array(
					'id'       => 0,
					'agent_id' => (int) ( $job_result['agent_id'] ?? 0 ),
					'status'   => 'failed',
					'error'    => __( 'Agent job could not be scheduled.', 'acl-agent-rooms' ),
				);
			}
		}
		return array(
			'jobs'                 => $public_jobs,
			'brain_runs'           => $this->public_brain_runs( $brain_result['brain_runs'] ),
			'scheduled_turn_count' => count( $planned ),
			'scheduled'            => $scheduled,
			'degraded'             => $degraded,
		);
	}

	public function manual_reply( \WP_REST_Request $request ) {
		$room_id  = absint( $request['id'] );
		$agent_id = absint( $request['agent_id'] );
		$room     = $this->rooms->find( $room_id );
		$agent    = $this->agents->find( $agent_id );

		if ( ! $room || ! $agent ) {
			return new \WP_Error( 'acl_ar_manual_reply_missing_data', __( 'Room or agent was not found.', 'acl-agent-rooms' ), array( 'status' => 404 ) );
		}
		if ( 'active' !== (string) ( $room['status'] ?? 'active' ) ) {
			return new \WP_Error( 'acl_ar_room_inactive', __( 'This room is not accepting new agent replies.', 'acl-agent-rooms' ), array( 'status' => 423 ) );
		}

		$assigned_ids = $this->rooms->get_agent_ids( $room_id );
		if ( ! in_array( $agent_id, $assigned_ids, true ) || empty( $agent['enabled'] ) ) {
			return new \WP_Error( 'acl_ar_agent_not_assigned', __( 'That agent is not available in this room.', 'acl-agent-rooms' ), array( 'status' => 400 ) );
		}
		$eligible = $this->participation->enforce( $room_id, $agent_id, 'explicit' );
		if ( is_wp_error( $eligible ) ) {
			return $eligible; }

		$trigger = $this->messages->latest_user_message_for_room( $room_id );
		if ( ! $trigger ) {
			return new \WP_Error( 'acl_ar_manual_reply_needs_user_message', __( 'Send a user message before generating an agent reply.', 'acl-agent-rooms' ), array( 'status' => 400 ) );
		}
		if ( 'brain' === (string) ( $agent['execution_mode'] ?? 'independent' ) ) {
			$trigger_event = $this->events->find_by_legacy_message_id( (int) $trigger['id'] );
			if ( ! $trigger_event ) {
				$trigger_event = $this->room_events->create_message_event( $trigger ); }
			if ( is_wp_error( $trigger_event ) ) {
				return $trigger_event; }
			$planned = array();
			if ( 'natural' === (string) ( $room['conversation_mode'] ?? 'immediate' ) ) {
				( new ConversationTurnService() )->activate_trigger( $room, (int) $trigger_event['id'] );
				$planned = ( new NaturalConversationDirector() )->plan( $room, array( $agent ), array( $agent_id ), false )['turns']; }
			$result = ( new BrainRunService() )->create_for_targets( $room, $trigger_event, array( $agent ), true, $planned );
			return new \WP_REST_Response(
				array(
					'jobs'       => array(),
					'brain_runs' => $this->public_brain_runs( $result['brain_runs'] ),
					'messages'   => $this->prepare_messages( $this->messages->for_room( $room_id, 100, 0 ) ),
				),
				201
			);
		}

		$request_key  = JobRepository::request_key( 'manual', $room_id, (int) $trigger['id'], $agent_id );
		$existing_job = $this->jobs->find_by_request_key( $request_key );
		if ( $existing_job && ! in_array( (string) $existing_job['status'], array( 'failed' ), true ) ) {
			return new \WP_REST_Response(
				array(
					'job'      => $this->public_jobs->prepare( $existing_job, $this->access->can_manage_room( $room_id ) ),
					'messages' => $this->prepare_messages( $this->messages->for_room( $room_id, 100, 0 ) ),
				),
				200
			);
		}
		$authorization = $this->execution_policy->authorize( get_current_user_id(), $room_id, 'manual-reply', array( 'agent_id' => $agent_id ) );
		if ( is_wp_error( $authorization ) ) {
			return $authorization;
		}

		$job_id = $existing_job ? (int) $existing_job['id'] : $this->jobs->create( $room_id, (int) $trigger['id'], $agent_id, $request_key );
		if ( is_wp_error( $job_id ) ) {
			return $job_id;
		}
		if ( 'natural' === (string) ( $room['conversation_mode'] ?? 'immediate' ) ) {
			$trigger_event = $this->events->find_by_legacy_message_id( (int) $trigger['id'] );
			if ( ! $trigger_event ) {
				$trigger_event = $this->room_events->create_message_event( $trigger );
			} if ( is_wp_error( $trigger_event ) ) {
				return $trigger_event; }
			( new ConversationTurnService() )->activate_trigger( $room, (int) $trigger_event['id'] );
			$plan    = ( new NaturalConversationDirector() )->plan( $room, array( $agent ), array( $agent_id ), false );
			$planned = $plan['turns'][0] ?? null;
			if ( $planned ) {
				$this->jobs->schedule( (int) $job_id, (string) $planned['due_at'] );
				( new ConversationTurnService() )->create( $room, $trigger_event, $planned, 'independent', 0, (int) $job_id ); }
			$job = $this->jobs->find( (int) $job_id );
			return new \WP_REST_Response(
				array(
					'job'                  => $this->public_jobs->prepare( $job, $this->access->can_manage_room( $room_id ) ),
					'messages'             => $this->prepare_messages( $this->messages->for_room( $room_id, 100, 0 ) ),
					'scheduled_turn_count' => $planned ? 1 : 0,
				),
				201
			);
		}
		$queued = $this->room_events->create_agent_lifecycle( $this->jobs->find( (int) $job_id ), \ACL\AgentRooms\Models\RoomEvent::TYPE_AGENT_QUEUED );
		if ( is_wp_error( $queued ) ) {
			return $queued; }
		$result = $this->runtime->run_job( (int) $job_id, (bool) $existing_job );
		$job    = is_wp_error( $result ) ? $this->jobs->find( (int) $job_id ) : $result;

		return new \WP_REST_Response(
			array(
				'job'      => $this->public_jobs->prepare(
					is_array( $job ) ? $job : array(
						'id'           => (int) $job_id,
						'agent_id'     => $agent_id,
						'status'       => 'failed',
						'public_error' => __( 'Agent reply failed.', 'acl-agent-rooms' ),
					),
					$this->access->can_manage_room( $room_id )
				),
				'messages' => $this->prepare_messages( $this->messages->for_room( $room_id, 100, 0 ) ),
			),
			201
		);
	}

	private function targets_for_room_mode( array $room, array $agents, string $content ): array {
		$mode = (string) ( $room['agent_reply_mode'] ?? 'manual' );

		if ( 'auto' === $mode ) {
			return $this->participation->filter_targets( (int) $room['id'], $agents, true );
		}

		if ( in_array( $mode, array( 'manual', 'slash' ), true ) ) {
			return array();
		}

		$mentioned = $this->parser->mentioned_agents( $content, $agents );
		foreach ( $mentioned as $agent ) {
			$eligible = $this->participation->enforce( (int) $room['id'], (int) $agent['id'], 'explicit' );
			if ( is_wp_error( $eligible ) ) {
				return $eligible;}
		}
		return $mentioned;
	}

	private function create_jobs_for_targets( array $room, int $message_id, array $targets, array $planned = array(), bool $natural = false, array $trigger_event = array() ): array {
		$max  = max( 1, (int) ( $room['max_agents_per_turn'] ?? 1 ) );
		$jobs = array();

		$plans = array();
		foreach ( $planned as $item ) {
			$plans[ (int) ( $item['agent']['id'] ?? 0 ) ] = $item; }
		foreach ( $natural ? $targets : array_slice( $targets, 0, $max ) as $agent ) {
			$request_key      = JobRepository::request_key( 'auto', (int) $room['id'], $message_id, (int) $agent['id'] );
			$job_id           = $this->jobs->create( (int) $room['id'], $message_id, (int) $agent['id'], $request_key );
			$scheduling_error = null;
			if ( ! is_wp_error( $job_id ) && $natural && isset( $plans[ (int) $agent['id'] ] ) ) {
				$this->jobs->schedule( (int) $job_id, (string) $plans[ (int) $agent['id'] ]['due_at'] );
				$turn = ( new ConversationTurnService() )->create( $room, $trigger_event, $plans[ (int) $agent['id'] ], 'independent', 0, (int) $job_id );
				if ( is_wp_error( $turn ) ) {
					$scheduling_error = $turn; }
			} elseif ( ! is_wp_error( $job_id ) ) {
				$queued = $this->room_events->create_agent_lifecycle( $this->jobs->find( (int) $job_id ), \ACL\AgentRooms\Models\RoomEvent::TYPE_AGENT_QUEUED );
				if ( is_wp_error( $queued ) ) {
					$scheduling_error = $queued; }
			}
			$jobs[] = is_wp_error( $job_id )
				? array(
					'ok'       => false,
					'agent_id' => (int) $agent['id'],
					'message'  => PublicError::from_error( $job_id, __( 'Agent job could not be created.', 'acl-agent-rooms' ) ),
				)
				: array(
					'ok'               => true,
					'id'               => (int) $job_id,
					'agent_id'         => (int) $agent['id'],
					'scheduling_error' => $scheduling_error,
				);
		}

		return $jobs;
	}

	private function public_brain_runs( array $items ): array {
		$out = array();
		foreach ( $items as $item ) {
			$run = $item['run'] ?? null;
			if ( ! $run ) {
				$error = $item['error'] ?? null;
				$out[] = array(
					'id'        => 0,
					'status'    => 'failed',
					'agent_ids' => array_values( array_map( 'absint', (array) ( $item['agent_ids'] ?? array() ) ) ),
					'error'     => is_wp_error( $error ) ? PublicError::from_error( $error, __( 'Shared Brain execution failed.', 'acl-agent-rooms' ) ) : __( 'Shared Brain execution failed.', 'acl-agent-rooms' ),
				);
				continue;
			}$out[] = array(
				'id'                 => (int) $run['id'],
				'brain_id'           => (int) $run['brain_id'],
				'status'             => (string) $run['status'],
				'agent_ids'          => $run['target_agent_ids'],
				'response_event_ids' => $run['response_event_ids'],
				'error'              => '' !== (string) $run['public_error'] ? PublicError::message( (string) $run['public_error'] ) : null,
			);
		}return $out; }

	private function prepare_messages( array $messages ): array {
		$agent_ids = array();
		foreach ( $messages as $message ) {
			if ( 'agent' === (string) ( $message['sender_type'] ?? '' ) && ! empty( $message['sender_agent_id'] ) ) {
				$agent_ids[] = (int) $message['sender_agent_id'];
			}
		}

		$agents = array();
		foreach ( array_unique( $agent_ids ) as $agent_id ) {
			$agent = $this->agents->find( $agent_id );
			if ( $agent ) {
				$agents[ $agent_id ] = $agent;
			}
		}

		return array_map(
			function ( array $message ) use ( $agents ): array {
				if ( 'agent' !== (string) ( $message['sender_type'] ?? '' ) ) {
					return $message;
				}

				$agent_id = (int) ( $message['sender_agent_id'] ?? 0 );
				$agent    = $agents[ $agent_id ] ?? null;
				if ( ! $agent ) {
					return $message;
				}

				$message['agent_name']           = (string) $agent['name'];
				$message['agent_slug']           = (string) $agent['slug'];
				$message['avatar_attachment_id'] = (int) $agent['avatar_attachment_id'];
				$message['avatar_url']           = (string) $agent['avatar_url'];
				$message['avatar_alt']           = (string) $agent['avatar_alt'];

				return $message;
			},
			$messages
		);
	}

	private function duplicate_message_response( int $room_id, ?array $message ) {
		$message_id = (int) ( $message['id'] ?? 0 );
		$event      = $this->room_events->create_message_event( $message ?: array() );
		if ( is_wp_error( $event ) ) {
			return $event; }
		$jobs       = array_map(
			fn( array $job ): array => $this->public_jobs->prepare( $job, $this->access->can_manage_room( $room_id ) ),
			$this->jobs->for_trigger_message( $message_id )
		);
		$brain_runs = array();
		if ( $event ) {
			foreach ( ( new BrainRunRepository() )->for_trigger_event( (int) $event['id'] ) as $run ) {
				$brain_runs[] = array( 'run' => $run ); }
		}
		return new \WP_REST_Response(
			array(
				'message_id' => $message_id,
				'duplicate'  => true,
				'jobs'       => $jobs,
				'event'      => $this->project_event( $event, $room_id, get_current_user_id() ),
				'brain_runs' => $this->public_brain_runs( $brain_runs ),
				'messages'   => $this->prepare_messages( $this->messages->for_room( $room_id, 100, 0 ) ),
			),
			200
		);
	}

	private function project_event( array $event, int $room_id, int $user_id ): array {
		$projected = $this->projection->project_page( array( $event ), $this->access->can_manage_room( $room_id, $user_id ), $user_id );
		return $projected[0];
	}

	private function assigned_agents_notice( array $agents ): string {
		if ( empty( $agents ) ) {
			return __( 'No agents are assigned to this room.', 'acl-agent-rooms' );
		}

		$slugs = array_map(
			static function ( array $agent ): string {
				return '@' . (string) $agent['slug'];
			},
			$agents
		);

		return sprintf(
			/* translators: %s is a comma-separated list of agent mentions. */
			__( 'Assigned agents: %s', 'acl-agent-rooms' ),
			implode( ', ', $slugs )
		);
	}
}
