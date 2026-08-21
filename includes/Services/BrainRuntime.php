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
		} if ( 'response_saved' === $run['status'] || ! empty( $run['validated_responses'] ) ) {
			return $this->resume_saved( $run );}
		$token = hash( 'sha256', wp_generate_uuid4() . '|' . $run_id . '|' . microtime( true ) );
		if ( ! $this->runs->acquire( $run_id, $token, 180, $intentional_retry ) ) {
			return new \WP_Error( 'acl_ar_brain_run_locked', __( 'Brain run is already being executed.', 'acl-agent-rooms' ), array( 'status' => 409 ) );}
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
		$this->runs->update_targets( $run_id, $ids, $token );
		if ( ! $natural ) {
			$this->states->project( (int) $room['id'], $ids, 'thinking', $run_id );
		} $trigger['legacy_message_id'] = (int) ( $trigger['legacy_message_id'] ?? 0 );
		$request                        = $this->prompts->build_request( $room, $brain, $trigger, $eligible );
		if ( ! $natural ) {
			$this->states->project( (int) $room['id'], $ids, 'responding', $run_id );
		} $response = $this->switchboard->send( $request );
		if ( is_wp_error( $response ) ) {
			return $this->failure( $run, $response, $token, $ids, true );
		} $usage                 = is_array( $response['usage'] ?? null ) ? $response['usage'] : array();
		$usage['estimated_cost'] = (float) ( $response['estimated_cost'] ?? 0 );
		if ( $this->cleared( $run ) || $this->superseded( $run ) ) {
			$this->log_usage( $run, $usage );
			$code = $this->cleared( $run ) ? 'acl_ar_trigger_cleared' : 'acl_ar_trigger_superseded';
			return $this->cancel( $run, new \WP_Error( $code, $this->cleared( $run ) ? __( 'The triggering message was cleared while the Shared Brain was responding.', 'acl-agent-rooms' ) : __( 'The triggering message was superseded while the Shared Brain was responding.', 'acl-agent-rooms' ) ), $token, 'ready' ); }
		$parsed = $this->parser->parse( (string) ( $response['content'] ?? '' ), $ids, $natural );
		if ( is_wp_error( $parsed ) ) {
			return $this->failure( $run, $parsed, $token, $ids, false );
		} if ( ! $this->runs->save_response( $run_id, $parsed, $usage, $token ) ) {
			return $this->failure( $run, new \WP_Error( 'acl_ar_brain_response_save_failed', __( 'The Brain response could not be saved safely.', 'acl-agent-rooms' ) ), $token, $ids, true );
		} if ( $natural && ! $this->turns->save_brain_content( $run_id, $parsed ) ) {
			return $this->failure( $this->runs->find( $run_id ), new \WP_Error( 'acl_ar_brain_turn_save_failed', __( 'Validated Brain turns could not be scheduled safely.', 'acl-agent-rooms' ) ), '', $ids, true );
		} return $this->resume_saved( $this->runs->find( $run_id ) );
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
			}$this->runs->fail( (int) $run['id'], 'acl_ar_brain_fanout_failed', __( 'Shared Brain responses are awaiting safe recovery.', 'acl-agent-rooms' ), true, 30 );
			$this->states->project( (int) $run['room_id'], $run['target_agent_ids'], 'error', (int) $run['id'] );
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
	private function terminal_event( array $run, string $status, int $published = 0, int $canceled = 0 ) {
		return $this->room_events->create(
			array(
				'room_id'         => (int) $run['room_id'],
				'event_type'      => 'brain_run',
				'actor_type'      => 'system',
				'target_type'     => 'brain',
				'target_id'       => (int) $run['brain_id'],
				'audience_type'   => 'moderators',
				'idempotency_key' => hash( 'sha256', 'brain-run:' . (int) $run['id'] . ':terminal' ),
				'content'         => null,
				'content_format'  => 'plain',
				'metadata'        => array(
					'status'          => $status,
					'agent_ids'       => $run['target_agent_ids'],
					'response_count'  => $published,
					'published_count' => $published,
					'canceled_count'  => $canceled,
				),
			)
		);}
	private function failure( array $run, \WP_Error $error, string $token, array $ids, bool $retryable ) {
		$attempts   = (int) ( $this->runs->find( (int) $run['id'] )['attempts'] ?? 0 );
		$will_retry = $retryable && $attempts < BrainRunRepository::MAX_ATTEMPTS;
		$this->runs->fail( (int) $run['id'], $error->get_error_code(), PublicError::from_error( $error, __( 'Shared Brain execution failed.', 'acl-agent-rooms' ) ), $will_retry, 30, $token );
		if ( ! $will_retry ) {
			$this->turns->cancel_for_brain( (int) $run['id'], 'brain_failed' );
			$fresh = $this->runs->find( (int) $run['id'] );
			$this->terminal_event( $fresh, 'failed', 0, count( $this->turns->for_brain_run( (int) $run['id'] ) ) );
		}$this->states->project( (int) $run['room_id'], $ids, 'error', (int) $run['id'] );
		return $error; }
	private function cancel( array $run, \WP_Error $error, string $token = '', string $state = 'unavailable' ) {
		$this->turns->cancel_for_brain( (int) $run['id'], $error->get_error_code() );
		$this->runs->cancel( (int) $run['id'], $error->get_error_code(), PublicError::from_error( $error, __( 'Shared Brain execution was canceled.', 'acl-agent-rooms' ) ) );
		$fresh = $this->runs->find( (int) $run['id'] );
		$this->terminal_event( $fresh, 'canceled', 0, count( $this->turns->for_brain_run( (int) $run['id'] ) ) );
		$this->states->project( (int) $run['room_id'], $run['target_agent_ids'], $state, (int) $run['id'] );
		return $error; }
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
