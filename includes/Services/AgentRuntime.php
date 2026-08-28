<?php
/**
 * Agent job runner.
 *
 * @package ACL_Agent_Rooms
 */

namespace ACL\AgentRooms\Services;

use ACL\AgentRooms\Contracts\SwitchboardClientInterface;
use ACL\AgentRooms\Repositories\AgentRepository;
use ACL\AgentRooms\Repositories\EventRepository;
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

	public function run_job( int $job_id, bool $intentional_retry = false, int $conversation_turn_id = 0 ) {
		$job = $this->jobs->find( $job_id );
		if ( ! $job ) {
			return new \WP_Error( 'acl_ar_job_not_found', __( 'Agent job was not found.', 'acl-agent-rooms' ), array( 'status' => 404 ) );
		}
		$natural_turn = ( new ConversationTurnRepository() )->find_by_job( $job_id );
		if ( $natural_turn && in_array( (string) $natural_turn['status'], array( 'canceled', 'failed' ), true ) ) {
			$this->jobs->cancel( $job_id, (string) ( $natural_turn['cancel_reason'] ?: 'superseded' ) );
			return new \WP_Error( 'acl_ar_natural_turn_canceled', __( 'The scheduled conversation turn was canceled.', 'acl-agent-rooms' ), array( 'status' => 409 ) ); }
		if ( $natural_turn && (int) $natural_turn['id'] !== $conversation_turn_id ) {
			return new \WP_Error( 'acl_ar_natural_turn_owner_required', __( 'This scheduled conversation job can only run through its owning turn worker.', 'acl-agent-rooms' ), array( 'status' => 409 ) ); }
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
			$recovered = $this->recover_existing_response( $job, $existing_response, $natural_turn );
			if ( is_wp_error( $recovered ) ) {
				$reason = 'acl_ar_trigger_cleared' === $recovered->get_error_code() ? 'chat_cleared' : ( 'acl_ar_trigger_superseded' === $recovered->get_error_code() ? 'superseded' : '' );
				if ( '' !== $reason ) {
					$this->jobs->cancel( $job_id, $reason );
					if ( $natural_turn ) {
						( new ConversationTurnRepository() )->cancel( (int) $natural_turn['id'], $reason );
					}
					( new AgentStateReconciler() )->reconcile_assignment( (int) $job['room_id'], (int) $job['agent_id'] );
				}
				return $recovered;
			}
			$job       = $recovered['job'];
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
		$persisted = $this->persist_response( $job, $lease_token, $room, $agent, $trigger, $content, $response, $request, $usage, $natural_turn );
		if ( is_wp_error( $persisted ) ) {
			$discard_reason = 'acl_ar_trigger_cleared' === $persisted->get_error_code() ? 'chat_cleared' : ( 'acl_ar_trigger_superseded' === $persisted->get_error_code() ? 'superseded' : '' );
			if ( '' !== $discard_reason ) {
				$this->discard_provider_response( $job, $natural_turn, $room, $agent, $trigger, $request, $usage, $discard_reason );
			} elseif ( 'acl_ar_job_lease_lost' !== $persisted->get_error_code() ) {
				$this->record_failure( $job, $persisted, $lease_token );
			}
			do_action( 'acl_ar_after_agent_job', $job, $room, $agent, $persisted );
			return $persisted;
		}
		$message_id = (int) $persisted['message_id'];
		$result     = $persisted['job'];
		do_action( 'acl_ar_agent_response_persisted', $job_id, $message_id, $room, $agent );
		$completed = $this->room_events->reconcile_job( $result, $this->messages->find( $message_id ) );
		if ( is_wp_error( $completed ) ) {
			return $completed; }
		( new AgentStateReconciler() )->reconcile_assignment( (int) $room['id'], (int) $agent['id'] );
		do_action( 'acl_ar_after_agent_job', $result, $room, $agent, $response );

		return $result;
	}

	private function recover_existing_response( array $job, array $response, ?array $natural_turn = null ) {
		global $wpdb;
		if ( false === $wpdb->query( 'START TRANSACTION' ) ) {
			return new \WP_Error( 'acl_ar_job_recovery_unavailable', __( 'The saved agent response could not enter safe recovery.', 'acl-agent-rooms' ), array( 'status' => 503 ) );
		}
		$job_id      = (int) $job['id'];
		$locked_room = $this->rooms->find_for_update( (int) $job['room_id'] );
		if ( ! $locked_room || 'active' !== (string) ( $locked_room['status'] ?? '' ) ) {
			$wpdb->query( 'ROLLBACK' );
			return new \WP_Error( 'acl_ar_room_inactive', __( 'Room or agent is not active for execution.', 'acl-agent-rooms' ), array( 'status' => 422 ) );
		}
		$trigger_event    = ( new EventRepository() )->find_by_legacy_message_id( (int) $job['trigger_message_id'] );
		$trigger_event_id = (int) ( $trigger_event['id'] ?? 0 );
		$cutoff           = max( 0, (int) ( $locked_room['cleared_through_event_id'] ?? 0 ) );
		if ( $cutoff > 0 && ( 0 === $trigger_event_id || $trigger_event_id <= $cutoff ) ) {
			$wpdb->query( 'ROLLBACK' );
			return new \WP_Error( 'acl_ar_trigger_cleared', __( 'The triggering message was cleared before the saved response could be recovered.', 'acl-agent-rooms' ), array( 'status' => 409 ) );
		}
		if ( $natural_turn && ( 'natural' !== (string) ( $locked_room['conversation_mode'] ?? 'immediate' ) || $trigger_event_id !== (int) $natural_turn['trigger_event_id'] || (int) ( $locked_room['natural_active_trigger_event_id'] ?? 0 ) !== (int) $natural_turn['trigger_event_id'] ) ) {
			$wpdb->query( 'ROLLBACK' );
			return new \WP_Error( 'acl_ar_trigger_superseded', __( 'The triggering message was superseded before the saved response could be recovered.', 'acl-agent-rooms' ), array( 'status' => 409 ) );
		}
		$locked_job = $this->jobs->find_for_update( $job_id );
		$active     = $locked_job && ( in_array( (string) $locked_job['status'], array( 'pending', 'running' ), true ) || ( 'failed' === (string) $locked_job['status'] && ! empty( $locked_job['retryable'] ) ) );
		$idempotent = $locked_job && 'completed' === (string) $locked_job['status'] && (int) $locked_job['response_message_id'] === (int) $response['id'];
		if ( ! $active && ! $idempotent ) {
			$wpdb->query( 'ROLLBACK' );
			return new \WP_Error( 'acl_ar_job_recovery_state_changed', __( 'The saved response no longer belongs to recoverable work.', 'acl-agent-rooms' ), array( 'status' => 409 ) );
		}
		$event = $this->room_events->create_message_event( $response );
		if ( is_wp_error( $event ) ) {
			$wpdb->query( 'ROLLBACK' );
			return $event;
		}
		$completed = $idempotent || $this->jobs->complete( $job_id, (int) $response['id'] );
		$published = true;
		if ( $natural_turn ) {
			$turns      = new ConversationTurnRepository();
			$fresh_turn = $turns->find( (int) $natural_turn['id'] );
			$published  = $fresh_turn && 'published' === (string) $fresh_turn['status'] && (int) $fresh_turn['published_event_id'] === (int) $event['id'];
			$published  = $published || $turns->publish( (int) $natural_turn['id'], (int) $event['id'] );
		}
		if ( ! $completed || ! $published ) {
			$wpdb->query( 'ROLLBACK' );
			return new \WP_Error( 'acl_ar_job_recovery_failed', __( 'The saved response could not be recovered safely.', 'acl-agent-rooms' ), array( 'status' => 503 ) );
		}
		if ( false === $wpdb->query( 'COMMIT' ) ) {
			$wpdb->query( 'ROLLBACK' );
			return new \WP_Error( 'acl_ar_job_recovery_commit_failed', __( 'The saved response recovery could not be committed safely.', 'acl-agent-rooms' ), array( 'status' => 503 ) );
		}
		return array(
			'job'   => $this->jobs->find( $job_id ),
			'event' => $event,
		);
	}

	private function persist_response( array $job, string $lease_token, array $room, array $agent, array $trigger, string $content, array $response, array $request, array $usage, ?array $natural_turn = null ) {
		global $wpdb;
		if ( false === $wpdb->query( 'START TRANSACTION' ) ) {
			return new \WP_Error( 'acl_ar_job_persistence_unavailable', __( 'The agent response could not enter safe persistence.', 'acl-agent-rooms' ), array( 'status' => 503 ) );
		}
		$job_id      = (int) $job['id'];
		$locked_room = $this->rooms->find_for_update( (int) $room['id'] );
		if ( ! $locked_room || 'active' !== (string) ( $locked_room['status'] ?? '' ) ) {
			$wpdb->query( 'ROLLBACK' );
			return new \WP_Error( 'acl_ar_room_inactive', __( 'Room or agent is not active for execution.', 'acl-agent-rooms' ), array( 'status' => 422 ) );
		}
		$trigger_event    = ( new EventRepository() )->find_by_legacy_message_id( (int) $trigger['id'] );
		$trigger_event_id = (int) ( $trigger_event['id'] ?? 0 );
		$cutoff           = max( 0, (int) ( $locked_room['cleared_through_event_id'] ?? 0 ) );
		if ( $cutoff > 0 && ( 0 === $trigger_event_id || $trigger_event_id <= $cutoff ) ) {
			$wpdb->query( 'ROLLBACK' );
			return new \WP_Error( 'acl_ar_trigger_cleared', __( 'The triggering message was cleared while the agent was responding.', 'acl-agent-rooms' ), array( 'status' => 409 ) );
		}
		if ( $natural_turn && ( 'natural' !== (string) ( $locked_room['conversation_mode'] ?? 'immediate' ) || $trigger_event_id !== (int) $natural_turn['trigger_event_id'] || (int) ( $locked_room['natural_active_trigger_event_id'] ?? 0 ) !== (int) $natural_turn['trigger_event_id'] ) ) {
			$wpdb->query( 'ROLLBACK' );
			return new \WP_Error( 'acl_ar_trigger_superseded', __( 'The triggering message was superseded while the agent was responding.', 'acl-agent-rooms' ), array( 'status' => 409 ) );
		}
		$locked_job = $this->jobs->find_for_update( $job_id );
		if ( ! $locked_job || 'running' !== (string) $locked_job['status'] || ! hash_equals( $lease_token, (string) $locked_job['lease_token'] ) ) {
			$wpdb->query( 'ROLLBACK' );
			return new \WP_Error( 'acl_ar_job_lease_lost', __( 'Agent job ownership changed before this worker could publish its response.', 'acl-agent-rooms' ), array( 'status' => 409 ) );
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
			$wpdb->query( 'ROLLBACK' );
			return $message_id;
		}
		$message_event = $this->room_events->create_message_event( $this->messages->find( (int) $message_id ) );
		if ( is_wp_error( $message_event ) ) {
			$wpdb->query( 'ROLLBACK' );
			return $message_event;
		}
		$usage_saved = $this->usage->log(
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
		$completed = $usage_saved && $this->jobs->complete( $job_id, (int) $message_id, $lease_token );
		$published = ! $natural_turn || ( new ConversationTurnRepository() )->publish( (int) $natural_turn['id'], (int) $message_event['id'] );
		if ( ! $completed || ! $published ) {
			$wpdb->query( 'ROLLBACK' );
			return new \WP_Error( 'acl_ar_job_completion_failed', __( 'The agent response could not be committed safely and will be retried.', 'acl-agent-rooms' ), array( 'status' => 503 ) );
		}
		if ( false === $wpdb->query( 'COMMIT' ) ) {
			$wpdb->query( 'ROLLBACK' );
			return new \WP_Error( 'acl_ar_job_commit_failed', __( 'The agent response transaction could not be committed safely.', 'acl-agent-rooms' ), array( 'status' => 503 ) );
		}
		return array(
			'job'        => $this->jobs->find( $job_id ),
			'message_id' => (int) $message_id,
		);
	}

	private function discard_provider_response( array $job, ?array $natural_turn, array $room, array $agent, array $trigger, array $request, array $usage, string $reason ): void {
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
		$this->jobs->cancel( (int) $job['id'], $reason );
		if ( $natural_turn ) {
			( new ConversationTurnRepository() )->cancel( (int) $natural_turn['id'], $reason );
		}
		( new AgentStateReconciler() )->reconcile_assignment( (int) $room['id'], (int) $agent['id'] );
	}

	private function record_failure( array $job, \WP_Error $error, string $lease_token ): void {
		$eligible = $this->participation->enforce( (int) $job['room_id'], (int) $job['agent_id'], 'explicit' );
		if ( is_wp_error( $eligible ) ) {
			$this->jobs->cancel( (int) $job['id'] );
			( new AgentStateReconciler() )->reconcile_assignment( (int) $job['room_id'], (int) $job['agent_id'] );
			return; }
		$current        = $this->jobs->find( (int) $job['id'] ) ?: $job;
		$classification = $this->retry_policy->classify( $error, (int) ( $current['attempts'] ?? 0 ) );
		$persisted = $this->jobs->fail(
			(int) $job['id'],
			PublicError::from_error( $error ),
			(string) $classification['code'],
			(string) $classification['public'],
			(bool) $classification['retryable'],
			(int) $classification['delay'],
			$lease_token
		);
		$failed_job = $this->jobs->find( (int) $job['id'] );
		$natural_turn = ( new ConversationTurnRepository() )->find_by_job( (int) $job['id'] );
		if ( $persisted && ! $natural_turn && $failed_job && 'failed' === (string) $failed_job['status'] && ! empty( $failed_job['retryable'] ) ) {
			( new QueueService() )->enqueue_job_retry( (int) $job['id'], (int) $classification['delay'] );
		}
		if ( $failed_job ) {
			$this->room_events->reconcile_job( $failed_job ); }
	}
}
