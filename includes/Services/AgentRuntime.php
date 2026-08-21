<?php
/**
 * Agent job runner.
 *
 * @package ACL_Agent_Rooms
 */

namespace ACL\AgentRooms\Services;

use ACL\AgentRooms\Contracts\SwitchboardClientInterface;
use ACL\AgentRooms\Repositories\AgentRepository;
use ACL\AgentRooms\Repositories\JobRepository;
use ACL\AgentRooms\Repositories\MessageRepository;
use ACL\AgentRooms\Repositories\RoomRepository;
use ACL\AgentRooms\Repositories\ConversationTurnRepository;
use ACL\AgentRooms\Models\RoomEvent;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class AgentRuntime {
	private JobRepository $jobs;
	private RoomRepository $rooms;
	private AgentRepository $agents;
	private MessageRepository $messages;
	private PromptBuilder $prompt_builder;
	private SwitchboardClientInterface $switchboard;
	private UsageLogger $usage;
	private JobRetryPolicy $retry_policy;
	private RoomEventService $room_events;
	private AgentParticipationService $participation;

	public function __construct(
		?JobRepository $jobs = null,
		?RoomRepository $rooms = null,
		?AgentRepository $agents = null,
		?MessageRepository $messages = null,
		?PromptBuilder $prompt_builder = null,
		?SwitchboardClientInterface $switchboard = null,
		?UsageLogger $usage = null,
		?JobRetryPolicy $retry_policy = null,
		?RoomEventService $room_events = null
	) {
		$this->jobs           = $jobs ?: new JobRepository();
		$this->rooms          = $rooms ?: new RoomRepository();
		$this->agents         = $agents ?: new AgentRepository();
		$this->messages       = $messages ?: new MessageRepository();
		$this->prompt_builder = $prompt_builder ?: new PromptBuilder( new ContextBuilder( $this->messages ) );
		$this->switchboard    = $switchboard ?: new SwitchboardClient();
		$this->usage          = $usage ?: new UsageLogger();
		$this->retry_policy   = $retry_policy ?: new JobRetryPolicy();
		$this->room_events    = $room_events ?: new RoomEventService();
		$this->participation  = new AgentParticipationService( $this->rooms, $this->jobs, $this->room_events );
	}

	public function run_job( int $job_id, bool $intentional_retry = false ) {
		$job = $this->jobs->find( $job_id );
		if ( ! $job ) {
			return new \WP_Error( 'acl_ar_job_not_found', __( 'Agent job was not found.', 'acl-agent-rooms' ), array( 'status' => 404 ) );
		}
		$natural_turn = ( new ConversationTurnRepository() )->find_by_job( $job_id );
		if ( $natural_turn && in_array( (string) $natural_turn['status'], array( 'canceled', 'failed' ), true ) ) {
			$this->jobs->cancel( $job_id, (string) ( $natural_turn['cancel_reason'] ?: 'superseded' ) );
			return new \WP_Error( 'acl_ar_natural_turn_canceled', __( 'The scheduled conversation turn was canceled.', 'acl-agent-rooms' ), array( 'status' => 409 ) ); }
		if ( $natural_turn && 'publishing' !== (string) $natural_turn['status'] ) {
			return new \WP_Error( 'acl_ar_natural_turn_not_acquired', __( 'The scheduled conversation turn is not due.', 'acl-agent-rooms' ), array( 'status' => 409 ) ); }
		if ( 'canceled' === (string) $job['status'] ) {
			return new \WP_Error( 'acl_ar_job_canceled', __( 'Agent job was canceled.', 'acl-agent-rooms' ), array( 'status' => 409 ) ); }
		$eligible = $this->participation->enforce( (int) $job['room_id'], (int) $job['agent_id'], 'explicit' );
		if ( is_wp_error( $eligible ) ) {
			$this->jobs->cancel( $job_id );
			( new AgentStateReconciler() )->reconcile_assignment( (int) $job['room_id'], (int) $job['agent_id'] );
			return $eligible; }
		$queued = $this->room_events->create_agent_lifecycle( $job, RoomEvent::TYPE_AGENT_QUEUED );
		if ( is_wp_error( $queued ) ) {
			return $queued; }
		if ( 'failed' === (string) $job['status'] ) {
			$failed_event = $this->room_events->reconcile_job( $job );
			if ( is_wp_error( $failed_event ) ) {
				return $failed_event; }
		}

		if ( 'completed' === (string) $job['status'] ) {
			$response   = $this->messages->find_by_response_job_id( $job_id );
			$reconciled = $this->room_events->reconcile_job( $job, $response );
			if ( is_wp_error( $reconciled ) ) {
				return $reconciled; }
			( new AgentStateReconciler() )->reconcile_assignment( (int) $job['room_id'], (int) $job['agent_id'] );
			return $job;
		}

		$existing_response = $this->messages->find_by_response_job_id( $job_id );
		if ( $existing_response ) {
			$event = $this->room_events->create_message_event( $existing_response );
			if ( is_wp_error( $event ) ) {
				return $event; }
			$this->jobs->complete( $job_id, (int) $existing_response['id'] );
			$job       = $this->jobs->find( $job_id );
			$completed = $this->room_events->reconcile_job( $job, $existing_response );
			return is_wp_error( $completed ) ? $completed : $job;
		}

		if ( ! $this->retry_policy->can_attempt( $job, $intentional_retry ) ) {
			return new \WP_Error( 'acl_ar_job_not_retryable', __( 'Agent job is not eligible for execution.', 'acl-agent-rooms' ), array( 'status' => 409 ) );
		}

		$lease_token = hash( 'sha256', wp_generate_uuid4() . '|' . $job_id . '|' . microtime( true ) );
		if ( ! $this->jobs->acquire( $job_id, $lease_token, (int) apply_filters( 'acl_ar_job_lease_seconds', 120, $job ), $intentional_retry ) ) {
			return new \WP_Error( 'acl_ar_job_locked', __( 'Agent job could not be locked for execution.', 'acl-agent-rooms' ), array( 'status' => 409 ) );
		}

		$job = $this->jobs->find( $job_id );
		if ( ! $job ) {
			return new \WP_Error( 'acl_ar_job_not_found_after_lock', __( 'Agent job was not found after locking.', 'acl-agent-rooms' ), array( 'status' => 404 ) );
		}
		$thinking = $this->room_events->create_agent_lifecycle( $job, RoomEvent::TYPE_AGENT_THINKING, array( 'attempt' => (int) $job['attempts'] ) );
		if ( is_wp_error( $thinking ) ) {
			$this->record_failure( $job, $thinking, $lease_token );
			return $thinking; }

		$room    = $this->rooms->find( (int) $job['room_id'] );
		$agent   = $this->agents->find( (int) $job['agent_id'] );
		$trigger = $this->messages->find( (int) $job['trigger_message_id'] );

		if ( ! $room || ! $agent || ! $trigger ) {
			$error = new \WP_Error( 'acl_ar_job_data_missing', __( 'Agent job is missing required data.', 'acl-agent-rooms' ), array( 'status' => 422 ) );
			$this->record_failure( $job, $error, $lease_token );
			return $error;
		}
		if ( 'brain' === (string) ( $agent['execution_mode'] ?? 'independent' ) ) {
			$this->jobs->cancel( $job_id, 'brain_runtime_required' );
			return new \WP_Error( 'acl_ar_brain_runtime_required', __( 'This agent must execute through its Shared Brain and cannot fall back to an individual model.', 'acl-agent-rooms' ), array( 'status' => 409 ) );
		}
		if ( 'active' !== (string) ( $room['status'] ?? 'active' ) || empty( $agent['enabled'] ) ) {
			$error = new \WP_Error( 'acl_ar_room_inactive', __( 'Room or agent is not active for execution.', 'acl-agent-rooms' ), array( 'status' => 422 ) );
			$this->record_failure( $job, $error, $lease_token );
			return $error;
		}
		if ( ( new RoomCutoffPolicy( $this->rooms ) )->legacy_message_is_cleared( (int) $room['id'], (int) $trigger['id'] ) ) {
			$this->jobs->cancel( $job_id, 'chat_cleared' );
			return new \WP_Error( 'acl_ar_trigger_cleared', __( 'The triggering message was cleared.', 'acl-agent-rooms' ), array( 'status' => 409 ) );
		}
		$trigger_user_id = (int) ( $trigger['sender_user_id'] ?? 0 );
		if ( $trigger_user_id > 0 && ! ( new AccessService( $this->rooms ) )->can_write_room( (int) $job['room_id'], $trigger_user_id ) ) {
			$this->jobs->cancel( $job_id );
			return new \WP_Error( 'acl_ar_trigger_restricted', __( 'The triggering user is no longer allowed to write in this room.', 'acl-agent-rooms' ), array( 'status' => 403 ) );
		}

		do_action( 'acl_ar_before_agent_job', $job, $room, $agent );

		$request = $this->prompt_builder->build_request( $room, $agent, $trigger );
		if ( $natural_turn ) {
			$purpose                   = (string) ( $natural_turn['purpose'] ?? 'reply' );
			$request['system_prompt'] .= "\n\nNatural Conversation contribution: add a distinct, concise contribution without repeating other agents. Do not mention selection, orchestration, timing, probabilities, or hidden reasoning. " . ( 'steer' === $purpose ? 'End with one useful steering question only if it helps clarify or move the room topic forward.' : 'Prioritize answering; do not force a question.' ); }
		$responding = $this->room_events->create_agent_lifecycle( $job, RoomEvent::TYPE_AGENT_RESPONDING, array( 'attempt' => (int) $job['attempts'] ) );
		if ( is_wp_error( $responding ) ) {
			$this->record_failure( $job, $responding, $lease_token );
			return $responding; }
		$dispatch_eligible = $this->participation->enforce( (int) $job['room_id'], (int) $job['agent_id'], 'explicit' );
		if ( is_wp_error( $dispatch_eligible ) ) {
			$this->jobs->cancel( $job_id );
			( new AgentStateReconciler() )->reconcile_assignment( (int) $job['room_id'], (int) $job['agent_id'] );
			return $dispatch_eligible; }
		if ( ( new RoomCutoffPolicy( $this->rooms ) )->legacy_message_is_cleared( (int) $room['id'], (int) $trigger['id'] ) ) {
			$this->jobs->cancel( $job_id, 'chat_cleared' );
			return new \WP_Error( 'acl_ar_trigger_cleared', __( 'The triggering message was cleared.', 'acl-agent-rooms' ), array( 'status' => 409 ) ); }

		$response = $this->switchboard->send( $request );
		if ( is_wp_error( $response ) ) {
			$this->record_failure( $job, $response, $lease_token );
			do_action( 'acl_ar_after_agent_job', $job, $room, $agent, $response );
			return $response;
		}

		$content = trim( (string) ( $response['content'] ?? '' ) );
		if ( '' === $content ) {
			$error = new \WP_Error( 'acl_ar_empty_agent_response', __( 'The agent response was empty.', 'acl-agent-rooms' ), array( 'status' => 502 ) );
			$this->record_failure( $job, $error, $lease_token );
			do_action( 'acl_ar_after_agent_job', $job, $room, $agent, $error );
			return $error;
		}

		$usage = is_array( $response['usage'] ?? null ) ? $response['usage'] : array();
		if ( $natural_turn ) {
			$fresh_room = $this->rooms->find( (int) $room['id'] );
			if ( ! $fresh_room || (int) ( $fresh_room['natural_active_trigger_event_id'] ?? 0 ) !== (int) $natural_turn['trigger_event_id'] ) {
				$this->usage->log(
					array(
						'user_id'           => (int) ( $trigger['sender_user_id'] ?? 0 ),
						'room_id'           => (int) $room['id'],
						'agent_id'          => (int) $agent['id'],
						'provider_route'    => (string) $request['provider_route'],
						'model'             => (string) $request['model'],
						'prompt_tokens'     => (int) ( $usage['prompt_tokens'] ?? 0 ),
						'completion_tokens' => (int) ( $usage['completion_tokens'] ?? 0 ),
						'total_tokens'      => (int) ( $usage['total_tokens'] ?? 0 ),
					)
				);
				$this->jobs->cancel( $job_id, 'superseded' );
				( new ConversationTurnRepository() )->cancel( (int) $natural_turn['id'], 'superseded' );
				( new AgentStateReconciler() )->reconcile_assignment( (int) $room['id'], (int) $agent['id'] );
				return new \WP_Error( 'acl_ar_trigger_superseded', __( 'The triggering message was superseded while the agent was responding.', 'acl-agent-rooms' ), array( 'status' => 409 ) ); }
		}
		if ( ( new RoomCutoffPolicy( $this->rooms ) )->legacy_message_is_cleared( (int) $room['id'], (int) $trigger['id'] ) ) {
			$this->usage->log(
				array(
					'user_id'           => (int) ( $trigger['sender_user_id'] ?? 0 ),
					'room_id'           => (int) $room['id'],
					'agent_id'          => (int) $agent['id'],
					'provider_route'    => (string) $request['provider_route'],
					'model'             => (string) $request['model'],
					'prompt_tokens'     => (int) ( $usage['prompt_tokens'] ?? 0 ),
					'completion_tokens' => (int) ( $usage['completion_tokens'] ?? 0 ),
					'total_tokens'      => (int) ( $usage['total_tokens'] ?? 0 ),
				)
			);
			$this->jobs->cancel( $job_id, 'chat_cleared' );
			( new AgentStateReconciler() )->reconcile_assignment( (int) $room['id'], (int) $agent['id'] );
			return new \WP_Error( 'acl_ar_trigger_cleared', __( 'The triggering message was cleared while the agent was responding.', 'acl-agent-rooms' ), array( 'status' => 409 ) );
		}
		$message_id = $this->messages->create(
			array(
				'room_id'           => (int) $room['id'],
				'sender_type'       => 'agent',
				'sender_agent_id'   => (int) $agent['id'],
				'content'           => $content,
				'metadata'          => array(
					'job_id'        => $job_id,
					'raw_provider'  => (string) ( $response['raw_provider'] ?? '' ),
					'finish_reason' => (string) ( $response['finish_reason'] ?? '' ),
				),
				'response_job_id'   => $job_id,
				'provider_route'    => (string) $request['provider_route'],
				'model'             => (string) $request['model'],
				'prompt_tokens'     => (int) ( $usage['prompt_tokens'] ?? 0 ),
				'completion_tokens' => (int) ( $usage['completion_tokens'] ?? 0 ),
				'total_tokens'      => (int) ( $usage['total_tokens'] ?? 0 ),
			)
		);

		if ( is_wp_error( $message_id ) ) {
			$this->record_failure( $job, $message_id, $lease_token );
			do_action( 'acl_ar_after_agent_job', $job, $room, $agent, $message_id );
			return $message_id;
		}

		do_action( 'acl_ar_agent_response_persisted', $job_id, (int) $message_id, $room, $agent );
		$message_event = $this->room_events->create_message_event( $this->messages->find( (int) $message_id ) );
		if ( is_wp_error( $message_event ) ) {
			$this->record_failure( $job, $message_event, $lease_token );
			return $message_event;
		}

		$this->usage->log(
			array(
				'user_id'           => (int) ( $trigger['sender_user_id'] ?? 0 ),
				'room_id'           => (int) $room['id'],
				'agent_id'          => (int) $agent['id'],
				'provider_route'    => (string) $request['provider_route'],
				'model'             => (string) $request['model'],
				'prompt_tokens'     => (int) ( $usage['prompt_tokens'] ?? 0 ),
				'completion_tokens' => (int) ( $usage['completion_tokens'] ?? 0 ),
				'total_tokens'      => (int) ( $usage['total_tokens'] ?? 0 ),
			)
		);

		if ( ! $this->jobs->complete( $job_id, (int) $message_id, $lease_token ) ) {
			return new \WP_Error( 'acl_ar_job_completion_failed', __( 'Agent response was saved and will be reconciled on retry.', 'acl-agent-rooms' ), array( 'status' => 503 ) );
		}
		$result    = $this->jobs->find( $job_id );
		$completed = $this->room_events->reconcile_job( $result, $this->messages->find( (int) $message_id ) );
		if ( is_wp_error( $completed ) ) {
			return $completed; }
		( new AgentStateReconciler() )->reconcile_assignment( (int) $room['id'], (int) $agent['id'] );
		do_action( 'acl_ar_after_agent_job', $result, $room, $agent, $response );

		return $result;
	}

	private function record_failure( array $job, \WP_Error $error, string $lease_token ): void {
		$eligible = $this->participation->enforce( (int) $job['room_id'], (int) $job['agent_id'], 'explicit' );
		if ( is_wp_error( $eligible ) ) {
			$this->jobs->cancel( (int) $job['id'] );
			( new AgentStateReconciler() )->reconcile_assignment( (int) $job['room_id'], (int) $job['agent_id'] );
			return; }
		$current        = $this->jobs->find( (int) $job['id'] ) ?: $job;
		$classification = $this->retry_policy->classify( $error, (int) ( $current['attempts'] ?? 0 ) );
		$this->jobs->fail(
			(int) $job['id'],
			PublicError::from_error( $error ),
			(string) $classification['code'],
			(string) $classification['public'],
			(bool) $classification['retryable'],
			(int) $classification['delay'],
			$lease_token
		);
		$failed_job = $this->jobs->find( (int) $job['id'] );
		if ( $failed_job ) {
			$this->room_events->reconcile_job( $failed_job ); }
	}
}
