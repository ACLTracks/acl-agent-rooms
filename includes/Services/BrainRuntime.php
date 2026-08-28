<?php
// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange -- This file operates on plugin-owned operational tables and requires uncached transaction/read-after-write truth.
// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- Identifiers are fixed $wpdb->prefix suffixes, fixed internal schema fragments, or allowlisted names; runtime values use placeholders or wpdb mutation arrays.
// phpcs:disable PluginCheck.Security.DirectDB.UnescapedDBParameter -- PHPCS cannot model the fixed identifier provenance above; request data is never used as an SQL identifier.
/** Executes and recovers one Shared Brain provider request. @package ACL_Agent_Rooms */
namespace ACL\AgentRooms\Services;

use ACL\AgentRooms\Contracts\SwitchboardClientInterface;
use ACL\AgentRooms\Repositories\AgentRepository;
use ACL\AgentRooms\Repositories\BrainRepository;
use ACL\AgentRooms\Repositories\BrainRunRepository;
use ACL\AgentRooms\Repositories\ConversationTurnRepository;
use ACL\AgentRooms\Repositories\EventRepository;
use ACL\AgentRooms\Repositories\MessageRepository;
use ACL\AgentRooms\Repositories\RoomRepository;

if ( ! defined( 'ABSPATH' ) ) {
	exit; }

class BrainRuntime {
	private BrainRunRepository $runs;
	private BrainRepository $brains;
	private RoomRepository $rooms;
	private AgentRepository $agents;
	private EventRepository $events;
	private MessageRepository $messages;
	private BrainConfigService $config;
	private BrainPromptBuilder $prompts;
	private BrainResponseParser $parser;
	private SwitchboardClientInterface $switchboard;
	private UsageLogger $usage;
	private RoomEventService $room_events;
	private BrainStateProjector $states;
	private AgentParticipationService $participation;
	private ConversationTurnRepository $turns;
	public function __construct( ?BrainRunRepository $runs = null, ?BrainRepository $brains = null, ?RoomRepository $rooms = null, ?AgentRepository $agents = null, ?EventRepository $events = null, ?MessageRepository $messages = null, ?BrainPromptBuilder $prompts = null, ?BrainResponseParser $parser = null, ?SwitchboardClientInterface $switchboard = null, ?UsageLogger $usage = null, ?RoomEventService $room_events = null ) {
		$this->runs          = $runs ?: new BrainRunRepository();
		$this->brains        = $brains ?: new BrainRepository();
		$this->rooms         = $rooms ?: new RoomRepository();
		$this->agents        = $agents ?: new AgentRepository();
		$this->events        = $events ?: new EventRepository();
		$this->messages      = $messages ?: new MessageRepository();
		$this->config        = new BrainConfigService( $this->brains );
		$this->prompts       = $prompts ?: new BrainPromptBuilder( $this->messages );
		$this->parser        = $parser ?: new BrainResponseParser();
		$this->switchboard   = $switchboard ?: new SwitchboardClient();
		$this->usage         = $usage ?: new UsageLogger();
		$this->room_events   = $room_events ?: new RoomEventService( $this->events );
		$this->states        = new BrainStateProjector();
		$this->participation = new AgentParticipationService( $this->rooms, null, $this->room_events );
		$this->turns         = new ConversationTurnRepository();
	}

	public function run( int $run_id, bool $intentional_retry = false ) {
		$run = $this->runs->find( $run_id );
		if ( ! $run ) {
			return new \WP_Error( 'acl_ar_brain_run_not_found', __( 'Brain run was not found.', 'acl-agent-rooms' ), array( 'status' => 404 ) );
		} if ( in_array( $run['status'], array( 'completed', 'canceled' ), true ) ) {
			return $run;
		} if ( 'response_saved' === $run['status'] ) {
			if ( ! $intentional_retry && ! empty( $run['next_attempt_at'] ) && strtotime( (string) $run['next_attempt_at'] . ' UTC' ) > time() ) {
				return new \WP_Error( 'acl_ar_brain_run_not_due', __( 'Shared Brain recovery is not due yet.', 'acl-agent-rooms' ), array( 'status' => 409 ) );
			}
			return $this->resume_saved( $run );}
		$token = hash( 'sha256', wp_generate_uuid4() . '|' . $run_id . '|' . microtime( true ) );
		if ( ! $this->runs->acquire( $run_id, $token, 180, $intentional_retry ) ) {
			return new \WP_Error( 'acl_ar_brain_run_locked', __( 'Brain run is already being executed.', 'acl-agent-rooms' ), array( 'status' => 409 ) );}
		$run = $this->runs->find( $run_id );
		if ( ! $run ) {
			return $this->lease_lost();
		}
		if ( ! empty( $run['validated_responses'] ) ) {
			$usage = array(
				'prompt_tokens'     => (int) $run['prompt_tokens'],
				'completion_tokens' => (int) $run['completion_tokens'],
				'total_tokens'      => (int) $run['total_tokens'],
				'estimated_cost'    => (float) $run['estimated_cost'],
			);
			if ( ! $this->runs->save_response( $run_id, $run['validated_responses'], $usage, $token ) ) {
				return $this->lease_lost();
			}
			return $this->resume_saved( $this->runs->find( $run_id ) );
		}
		$run     = $this->runs->find( $run_id );
		$room    = $this->rooms->find( (int) $run['room_id'] );
		$brain   = $this->config->runtime( (int) $run['brain_id'] );
		$trigger = $this->events->find( (int) $run['trigger_event_id'] );
		if ( ! $room || ! $trigger || is_wp_error( $brain ) ) {
			return $this->cancel( $run, is_wp_error( $brain ) ? $brain : new \WP_Error( 'acl_ar_brain_run_data_missing', __( 'Brain run data is no longer available.', 'acl-agent-rooms' ) ), $token );
		} if ( $this->cleared( $run ) ) {
			return $this->cancel( $run, new \WP_Error( 'acl_ar_trigger_cleared', __( 'The triggering message was cleared.', 'acl-agent-rooms' ) ), $token, 'ready' );
		} if ( $this->superseded( $run ) ) {
			return $this->cancel( $run, new \WP_Error( 'acl_ar_trigger_superseded', __( 'The triggering message was superseded.', 'acl-agent-rooms' ) ), $token, 'ready' );}
		$natural         = 'natural' === (string) ( $room['conversation_mode'] ?? 'immediate' );
		$trigger_message = ! empty( $trigger['legacy_message_id'] ) ? $this->messages->find( (int) $trigger['legacy_message_id'] ) : null;
		$mode            = (string) ( $trigger_message['metadata']['brain_trigger_mode'] ?? 'explicit' );
		$eligible        = array();
		foreach ( $run['target_agent_ids'] as $id ) {
			$agent = $this->agents->find( (int) $id );
			if ( ! $agent || empty( $agent['enabled'] ) || 'brain' !== (string) $agent['execution_mode'] || (int) $agent['brain_id'] !== (int) $brain['id'] ) {
				continue;
			}$check = $this->participation->enforce( (int) $room['id'], (int) $id, 'automatic' === $mode ? 'automatic' : 'explicit' );
			if ( ! is_wp_error( $check ) ) {
				$eligible[] = $agent;}
		}
		if ( ! $eligible ) {
			return $this->cancel( $run, new \WP_Error( 'acl_ar_brain_run_no_targets', __( 'No eligible agents remain for this Brain run.', 'acl-agent-rooms' ) ), $token, 'unavailable' );}
		$ids = array_map( static fn( $a )=> (int) $a['id'], $eligible );
		if ( ! $this->runs->update_targets( $run_id, $ids, $token ) ) {
			return $this->lease_lost();
		}
		if ( ! $natural ) {
			$this->states->project( (int) $room['id'], $ids, 'thinking', $run_id );
		} $trigger['legacy_message_id'] = (int) ( $trigger['legacy_message_id'] ?? 0 );
		$retry_error_code               = (int) $run['attempts'] > 1 ? (string) ( $run['error_code'] ?? '' ) : '';
		$request                        = $this->prompts->build_request( $room, $brain, $trigger, $eligible, $retry_error_code );
		if ( ! $natural ) {
			$this->states->project( (int) $room['id'], $ids, 'responding', $run_id );
		} $response = $this->switchboard->send( $request );
		if ( is_wp_error( $response ) ) {
			return $this->failure( $run, $response, $token, $ids, true, 'provider_dispatch' );
		} $usage                 = is_array( $response['usage'] ?? null ) ? $response['usage'] : array();
		$usage['estimated_cost'] = (float) ( $response['estimated_cost'] ?? 0 );
		if ( $this->cleared( $run ) || $this->superseded( $run ) ) {
			$this->log_usage( $run, $usage );
			$code = $this->cleared( $run ) ? 'acl_ar_trigger_cleared' : 'acl_ar_trigger_superseded';
			return $this->cancel( $run, new \WP_Error( $code, $this->cleared( $run ) ? __( 'The triggering message was cleared while the Shared Brain was responding.', 'acl-agent-rooms' ) : __( 'The triggering message was superseded while the Shared Brain was responding.', 'acl-agent-rooms' ) ), $token, 'ready' ); }
		$parsed = $this->parser->parse( (string) ( $response['content'] ?? '' ), $ids, $natural );
		if ( is_wp_error( $parsed ) ) {
			return $this->failure( $run, $parsed, $token, $ids, $this->retryable_response_error( $parsed ), 'response_validation', 5 );
		} if ( $natural && ! $this->save_natural_response( $run_id, $parsed, $usage, $token, ( new NaturalDelayCalculator() )->schedule( $room, $eligible ) ) ) {
			return $this->failure( $run, new \WP_Error( 'acl_ar_brain_turn_save_failed', __( 'Validated Brain turns could not be scheduled safely.', 'acl-agent-rooms' ) ), $token, $ids, true, 'turn_persistence' );
		} if ( ! $natural && ! $this->runs->save_response( $run_id, $parsed, $usage, $token ) ) {
			return $this->failure( $run, new \WP_Error( 'acl_ar_brain_response_save_failed', __( 'The Brain response could not be saved safely.', 'acl-agent-rooms' ) ), $token, $ids, true, 'response_persistence' );
		} return $this->resume_saved( $this->runs->find( $run_id ) );
	}

	private function save_natural_response( int $run_id, array $parsed, array $usage, string $token, array $schedule ): bool {
		global $wpdb;
		if ( false === $wpdb->query( 'START TRANSACTION' ) ) {
			return false;
		}
		try {
			$locked = $this->turns->lock_for_brain_run( $run_id );
			if ( count( $locked ) !== count( $parsed ) || ! $this->runs->save_response( $run_id, $parsed, $usage, $token ) || ! $this->turns->save_brain_content( $run_id, $parsed ) || ! $this->turns->rebase_brain_schedule( $run_id, $locked, $schedule ) ) {
				$wpdb->query( 'ROLLBACK' );
				return false;
			}
			if ( false === $wpdb->query( 'COMMIT' ) ) {
				$wpdb->query( 'ROLLBACK' );
				return false;
			}
			$queue = new QueueService();
			foreach ( $this->turns->for_brain_run( $run_id ) as $turn ) {
				$queue->reschedule_turn( $turn );
			}
			return true;
		} catch ( \Throwable $error ) {
			$wpdb->query( 'ROLLBACK' );
			return false;
		}
	}

	private function resume_saved( array $run ) {
		$this->log_usage(
			$run,
			array(
				'prompt_tokens'     => (int) $run['prompt_tokens'],
				'completion_tokens' => (int) $run['completion_tokens'],
				'total_tokens'      => (int) $run['total_tokens'],
				'estimated_cost'    => (float) $run['estimated_cost'],
			)
		);
		if ( $this->cleared( $run ) ) {
			return $this->cancel( $run, new \WP_Error( 'acl_ar_trigger_cleared', __( 'The triggering message was cleared before Shared Brain publication.', 'acl-agent-rooms' ) ), '', 'ready' );
		} if ( $this->superseded( $run ) ) {
			return $this->cancel( $run, new \WP_Error( 'acl_ar_trigger_superseded', __( 'The triggering message was superseded before Shared Brain publication.', 'acl-agent-rooms' ) ), '', 'ready' );}
		$room = $this->rooms->find( (int) $run['room_id'] );
		if ( $room && 'natural' === (string) $room['conversation_mode'] ) {
			foreach ( $this->turns->for_brain_run( (int) $run['id'] ) as $turn ) {
				if ( in_array( $turn['status'], array( 'pending', 'typing' ), true ) ) {
					( new QueueService() )->enqueue_turn( $turn );
				}
			}( new AgentStateReconciler() )->reconcile_room( (int) $run['room_id'] );
			return $this->runs->find( (int) $run['id'] );}
		$result = $this->fan_out( $run );
		if ( is_wp_error( $result ) ) {
			if ( 'acl_ar_trigger_cleared' === $result->get_error_code() ) {
				return $this->cancel( $run, $result, '', 'ready' );
			}if ( ! $this->runs->fail( (int) $run['id'], 'acl_ar_brain_fanout_failed', __( 'Shared Brain responses are awaiting safe recovery.', 'acl-agent-rooms' ), true, 30 ) ) {
				return new \WP_Error( 'acl_ar_brain_run_state_changed', __( 'Shared Brain recovery ownership changed before the failure could be saved.', 'acl-agent-rooms' ), array( 'status' => 409 ) );
			}
			$fresh      = $this->runs->find( (int) $run['id'] );
			$will_retry = $fresh && ! empty( $fresh['next_attempt_at'] );
			if ( $will_retry ) {
				( new QueueService() )->enqueue_brain_retry( (int) $run['id'], 30 );
			} else {
				$this->terminal_event( $fresh ?: $run, 'failed', 0, 0, $result, 'fanout' );
			}
			$this->states->project( (int) $run['room_id'], $run['target_agent_ids'], $will_retry ? 'responding' : 'error', (int) $run['id'] );
			return $result;
		} $this->states->project( (int) $run['room_id'], $run['target_agent_ids'], 'ready', (int) $run['id'] );
		return $this->runs->find( (int) $run['id'] );
	}

	private function fan_out( array $run ) {
		global $wpdb;
		$wpdb->query( 'START TRANSACTION' );
		$locked = $this->rooms->find_for_update( (int) $run['room_id'] );
		if ( ! $locked || (int) $run['trigger_event_id'] <= max( 0, (int) ( $locked['cleared_through_event_id'] ?? 0 ) ) ) {
			$wpdb->query( 'ROLLBACK' );
			return new \WP_Error( 'acl_ar_trigger_cleared', __( 'The triggering message was cleared before Shared Brain fan-out.', 'acl-agent-rooms' ) );
		}$event_ids = array();
		foreach ( (array) $run['validated_responses'] as $response ) {
			$agent_id = absint( $response['agent_id'] ?? 0 );
			$message  = $this->messages->find_by_brain_response( (int) $run['id'], $agent_id );
			if ( ! $message ) {
				$message_id = $this->messages->create(
					array(
						'room_id'         => (int) $run['room_id'],
						'sender_type'     => 'agent',
						'sender_agent_id' => $agent_id,
						'content'         => (string) $response['content'],
						'brain_run_id'    => (int) $run['id'],
						'brain_agent_id'  => $agent_id,
						'metadata'        => array(
							'brain_run_id' => (int) $run['id'],
							'brain_id'     => (int) $run['brain_id'],
							'runtime'      => 'brain',
						),
						'provider_route'  => (string) $run['provider'],
						'model'           => (string) $run['model'],
					)
				);
				if ( is_wp_error( $message_id ) ) {
					$wpdb->query( 'ROLLBACK' );
							return $message_id;
				}$message = $this->messages->find( (int) $message_id );
			} $key = hash( 'sha256', 'brain-response:' . (int) $run['id'] . ':' . $agent_id );
			$event = $this->room_events->create(
				array(
					'room_id'           => (int) $run['room_id'],
					'event_type'        => 'message',
					'actor_type'        => 'agent',
					'actor_id'          => $agent_id,
					'audience_type'     => 'room',
					'legacy_message_id' => (int) $message['id'],
					'idempotency_key'   => $key,
					'content'           => (string) $message['content'],
					'content_format'    => 'plain',
					'metadata'          => array( 'brain_run_id' => (int) $run['id'] ),
				)
			);
			if ( is_wp_error( $event ) ) {
				$wpdb->query( 'ROLLBACK' );
				return $event;
			}$event_ids[] = (int) $event['id'];
		}$audit = $this->terminal_event( $run, 'completed', count( $event_ids ), 0 );
		if ( is_wp_error( $audit ) ) {
			$wpdb->query( 'ROLLBACK' );
			return $audit;
		}if ( ! $this->runs->complete( (int) $run['id'], $event_ids ) ) {
			$wpdb->query( 'ROLLBACK' );
			return new \WP_Error( 'acl_ar_brain_run_completion_failed', __( 'Brain run completion will be recovered safely.', 'acl-agent-rooms' ) );
		}$wpdb->query( 'COMMIT' );
		return $event_ids; }
	private function terminal_event( array $run, string $status, int $published = 0, int $canceled = 0, ?\WP_Error $error = null, string $stage = '' ) {
		$metadata = array(
			'brain_run_id'    => (int) $run['id'],
			'status'          => $status,
			'agent_ids'       => $run['target_agent_ids'],
			'response_count'  => $published,
			'published_count' => $published,
			'canceled_count'  => $canceled,
			'provider_route'  => (string) ( $run['provider'] ?? '' ),
			'model'           => (string) ( $run['model'] ?? '' ),
			'attempts'        => (int) ( $run['attempts'] ?? 0 ),
			'retryable'       => ! empty( $run['next_attempt_at'] ),
		);
		if ( $error || ! empty( $run['error_code'] ) ) {
			$error_data                = $error && is_array( $error->get_error_data() ) ? $error->get_error_data() : array();
			$metadata['stage']         = sanitize_key( $stage );
			$metadata['error_code']    = sanitize_key( (string) ( $run['error_code'] ?? $error->get_error_code() ) );
			$metadata['error']         = PublicError::message( (string) ( $run['public_error'] ?? ( $error ? PublicError::from_error( $error ) : '' ) ), __( 'Shared Brain execution failed.', 'acl-agent-rooms' ) );
			$metadata['result_status'] = absint( $error_data['status'] ?? 0 );
		}
		return $this->room_events->create(
			array(
				'room_id'         => (int) $run['room_id'],
				'event_type'      => 'brain_run',
				'actor_type'      => 'system',
				'target_type'     => 'brain',
				'target_id'       => (int) $run['brain_id'],
				'audience_type'   => 'failed' === $status ? 'room' : 'moderators',
				'idempotency_key' => hash( 'sha256', 'brain-run:' . (int) $run['id'] . ':terminal' ),
				'content'         => null,
				'content_format'  => 'plain',
				'metadata'        => $metadata,
			)
		);}
	private function failure( array $run, \WP_Error $error, string $token, array $ids, bool $retryable, string $stage = '', int $retry_delay = 30 ) {
		$attempts    = (int) ( $this->runs->find( (int) $run['id'] )['attempts'] ?? 0 );
		$will_retry  = $retryable && $attempts < BrainRunRepository::MAX_ATTEMPTS;
		$retry_delay = max( 1, $retry_delay );
		if ( ! $this->runs->fail( (int) $run['id'], $error->get_error_code(), PublicError::from_error( $error, __( 'Shared Brain execution failed.', 'acl-agent-rooms' ) ), $will_retry, $retry_delay, $token ) ) {
			return $this->lease_lost();
		}
		$fresh      = $this->runs->find( (int) $run['id'] );
		if ( ! $fresh ) {
			return $this->lease_lost();
		}
		$will_retry = $fresh && ! empty( $fresh['next_attempt_at'] );
		if ( $will_retry ) {
			( new QueueService() )->enqueue_brain_retry( (int) $run['id'], $retry_delay );
		} else {
			$this->turns->cancel_for_brain( (int) $run['id'], 'brain_failed' );
			$this->terminal_event( $fresh, 'failed', 0, count( $this->turns->for_brain_run( (int) $run['id'] ) ), $error, $stage );
		}$this->states->project( (int) $run['room_id'], $ids, $will_retry ? 'queued' : 'error', (int) $run['id'] );
		return $error; }
	private function retryable_response_error( \WP_Error $error ): bool {
		return in_array(
			(string) $error->get_error_code(),
			array(
				'acl_ar_brain_response_invalid',
				'acl_ar_brain_response_prose',
				'acl_ar_brain_response_unknown_agent',
				'acl_ar_brain_response_duplicate_agent',
				'acl_ar_brain_response_missing_agent',
				'acl_ar_brain_response_empty',
				'acl_ar_brain_response_html',
				'acl_ar_brain_response_too_large',
				'acl_ar_brain_response_too_many_steers',
				'acl_ar_brain_response_order',
			),
			true
		);
	}
	private function cancel( array $run, \WP_Error $error, string $token = '', string $state = 'unavailable' ) {
		if ( ! $this->runs->cancel( (int) $run['id'], $error->get_error_code(), PublicError::from_error( $error, __( 'Shared Brain execution was canceled.', 'acl-agent-rooms' ) ), $token ) ) {
			return '' !== $token ? $this->lease_lost() : new \WP_Error( 'acl_ar_brain_run_state_changed', __( 'Shared Brain state changed before cancellation could be saved.', 'acl-agent-rooms' ), array( 'status' => 409 ) );
		}
		$fresh = $this->runs->find( (int) $run['id'] );
		if ( $fresh && 'completed' === (string) $fresh['status'] ) {
			return $fresh;
		}
		if ( ! $fresh || 'canceled' !== (string) $fresh['status'] ) {
			return new \WP_Error( 'acl_ar_brain_run_state_changed', __( 'Shared Brain state changed before cancellation could be confirmed.', 'acl-agent-rooms' ), array( 'status' => 409 ) );
		}
		$this->turns->cancel_for_brain( (int) $run['id'], $error->get_error_code() );
		$this->terminal_event( $fresh, 'canceled', 0, count( $this->turns->for_brain_run( (int) $run['id'] ) ), $error, 'canceled' );
		$this->states->project( (int) $run['room_id'], $run['target_agent_ids'], $state, (int) $run['id'] );
		return $error; }
	private function lease_lost(): \WP_Error {
		return new \WP_Error( 'acl_ar_brain_run_lease_lost', __( 'Shared Brain execution ownership changed before this worker could save its result.', 'acl-agent-rooms' ), array( 'status' => 409 ) ); }
	private function log_usage( array $run, array $usage ): void {
		$this->usage->log(
			array(
				'user_id'           => $this->trigger_user( $run ),
				'room_id'           => (int) $run['room_id'],
				'agent_id'          => null,
				'brain_id'          => (int) $run['brain_id'],
				'brain_run_id'      => (int) $run['id'],
				'provider_route'    => (string) $run['provider'],
				'model'             => (string) $run['model'],
				'prompt_tokens'     => (int) ( $usage['prompt_tokens'] ?? 0 ),
				'completion_tokens' => (int) ( $usage['completion_tokens'] ?? 0 ),
				'total_tokens'      => (int) ( $usage['total_tokens'] ?? 0 ),
				'estimated_cost'    => (float) ( $usage['estimated_cost'] ?? 0 ),
			)
		);}
	private function trigger_user( array $run ): int {
		$event = $this->events->find( (int) $run['trigger_event_id'] );
		return 'user' === (string) ( $event['actor_type'] ?? '' ) ? (int) $event['actor_id'] : 0; }
	private function cleared( array $run ): bool {
		$room = $this->rooms->find( (int) $run['room_id'] );
		return $room && (int) $run['trigger_event_id'] <= max( 0, (int) ( $room['cleared_through_event_id'] ?? 0 ) ); }
	private function superseded( array $run ): bool {
		$room = $this->rooms->find( (int) $run['room_id'] );
		return $room && 'natural' === (string) ( $room['conversation_mode'] ?? 'immediate' ) && (int) ( $room['natural_active_trigger_event_id'] ?? 0 ) !== (int) $run['trigger_event_id']; }
}
