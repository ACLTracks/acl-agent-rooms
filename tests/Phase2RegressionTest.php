<?php

use ACL\AgentRooms\Contracts\SwitchboardClientInterface;
use ACL\AgentRooms\Repositories\AgentRepository;
use ACL\AgentRooms\Repositories\JobRepository;
use ACL\AgentRooms\Repositories\MessageRepository;
use ACL\AgentRooms\Repositories\RoomRepository;
use ACL\AgentRooms\Rest\MessagesController;
use ACL\AgentRooms\Services\AccessService;
use ACL\AgentRooms\Services\AgentExecutionPolicy;
use ACL\AgentRooms\Services\AgentMentionParser;
use ACL\AgentRooms\Services\AgentRuntime;
use ACL\AgentRooms\Services\JobRetryPolicy;
use ACL\AgentRooms\Services\MessagePolicy;
use ACL\AgentRooms\Services\PublicError;
use ACL\AgentRooms\Services\PublicJob;
use ACL\AgentRooms\Services\RateLimiter;

class ACL_AR_FakeSwitchboard implements SwitchboardClientInterface {
	public int $calls = 0;
	public int $failures_remaining = 0;
	public $during_send = null;

	public function send( array $request ) {
		$this->calls++;
		if ( is_callable( $this->during_send ) ) {
			$callback = $this->during_send;
			$this->during_send = null;
			$callback();
		}
		if ( $this->failures_remaining > 0 ) {
			$this->failures_remaining--;
			return new WP_Error( 'acl_ar_switchboard_exception', 'Authorization: Bearer secret-token', array( 'status' => 503 ) );
		}
		return array(
			'content' => 'Deterministic fake reply', 'raw_provider' => 'fake', 'finish_reason' => 'stop',
			'usage' => array( 'prompt_tokens' => 1, 'completion_tokens' => 2, 'total_tokens' => 3 ),
		);
	}
}

class ACL_AR_Phase2RegressionTest extends ACL_AR_TestCase {
	private AgentRepository $agents;
	private RoomRepository $rooms;
	private MessageRepository $messages;
	private JobRepository $jobs;
	private int $user_id = 0;
	private array $room_ids = array();
	private array $agent_ids = array();
	private string $prefix;

	public function run(): void {
		$this->prefix = 'phase2-' . gmdate( 'YmdHis' ) . '-' . wp_generate_password( 6, false, false );
		$this->agents = new AgentRepository();
		$this->rooms = new RoomRepository();
		$this->messages = new MessageRepository();
		$this->jobs = new JobRepository();
		$this->set_up_fixture();
		try {
			$this->test_duplicate_user_message();
			$this->pass( 'duplicate_user_message' );
			$this->test_duplicate_job_and_crash_recovery();
			$this->pass( 'duplicate_job_and_crash_recovery' );
			$this->test_concurrent_lock_and_stale_recovery();
			$this->pass( 'concurrent_lock_and_stale_recovery' );
			$this->test_repository_lease_fencing();
			$this->pass( 'repository_lease_fencing' );
			$this->test_running_job_priority();
			$this->pass( 'running_job_priority' );
			$this->test_stale_worker_cannot_publish();
			$this->pass( 'stale_worker_publication_fence' );
			$this->test_failed_retry();
			$this->pass( 'failed_job_retry' );
			$this->test_foreground_retry_lifecycle();
			$this->pass( 'foreground_retry_lifecycle' );
			$this->test_attempt_failure_backfill();
			$this->pass( 'attempt_failure_backfill' );
			$this->test_message_boundaries();
			$this->pass( 'message_size_boundaries' );
			$this->test_command_controller_smoke();
			$this->pass( 'ask_agents_help_controller_smoke' );
			$this->test_execution_authority_and_rate_limit();
			$this->pass( 'execution_authority_and_rate_limit' );
			$this->test_assignment_rollback();
			$this->pass( 'room_agent_assignment_rollback' );
			$this->test_room_delete_rollback();
			$this->pass( 'room_deletion_rollback' );
			$this->test_public_error_and_job_projection();
			$this->pass( 'public_error_and_job_projection' );
			$this->test_existing_commands();
			$this->pass( 'command_parser_compatibility' );
		} finally {
			$this->tear_down_fixture();
		}
	}

	private function pass( string $name ): void {
		echo 'PASS ' . $name . PHP_EOL;
	}

	private function set_up_fixture(): void {
		$this->user_id = wp_insert_user( array( 'user_login' => $this->prefix, 'user_pass' => wp_generate_password( 24 ), 'user_email' => $this->prefix . '@example.test', 'role' => 'subscriber' ) );
		if ( is_wp_error( $this->user_id ) ) {
			throw new RuntimeException( $this->user_id->get_error_message() );
		}
		get_user_by( 'id', $this->user_id )->add_cap( 'acl_ar_use_rooms' );
		wp_set_current_user( $this->user_id );
		foreach ( array( 'one', 'two' ) as $suffix ) {
			$id = $this->agents->create( array( 'name' => $this->prefix . '-' . $suffix, 'slug' => $this->prefix . '-' . $suffix, 'provider_route' => 'fake', 'model' => 'fake', 'system_prompt' => 'test', 'enabled' => 1 ) );
			if ( is_wp_error( $id ) ) {
				throw new RuntimeException( $id->get_error_message() );
			}
			$this->agent_ids[] = (int) $id;
		}
		$room_id = $this->rooms->create( array( 'title' => $this->prefix, 'slug' => $this->prefix, 'owner_user_id' => $this->user_id, 'agent_reply_mode' => 'manual' ) );
		if ( is_wp_error( $room_id ) ) {
			throw new RuntimeException( $room_id->get_error_message() );
		}
		$this->room_ids[] = (int) $room_id;
		$this->assert_true( true === $this->rooms->assign_agents( (int) $room_id, array( $this->agent_ids[0] ) ), 'Initial agent assignment failed.' );
	}

	private function test_duplicate_user_message(): void {
		$key = 'client-' . wp_generate_password( 16, false, false );
		$first = $this->messages->create_user_idempotent( $this->room_ids[0], $this->user_id, 'hello', $key );
		$second = $this->messages->create_user_idempotent( $this->room_ids[0], $this->user_id, 'hello', $key );
		$this->assert_true( ! is_wp_error( $first ) && ! is_wp_error( $second ), 'Idempotent message creation failed.' );
		$this->assert_same( (int) $first['id'], (int) $second['id'], 'Duplicate client request created another message.' );
		$this->assert_true( ! empty( $first['created'] ) && empty( $second['created'] ), 'Duplicate creation flags were incorrect.' );
	}

	private function test_duplicate_job_and_crash_recovery(): void {
		$trigger = $this->messages->create_user_idempotent( $this->room_ids[0], $this->user_id, 'trigger', 'client-' . wp_generate_password( 16, false, false ) );
		$job_id = $this->jobs->create( $this->room_ids[0], (int) $trigger['id'], $this->agent_ids[0], JobRepository::request_key( 'test', $this->room_ids[0], (int) $trigger['id'], $this->agent_ids[0] ) );
		$fake = new ACL_AR_FakeSwitchboard();
		$runtime = new AgentRuntime( $this->jobs, $this->rooms, $this->agents, $this->messages, null, $fake );
		$first = $runtime->run_job( (int) $job_id );
		$second = $runtime->run_job( (int) $job_id );
		$this->assert_same( 'completed', $first['status'], 'First job execution did not complete.' );
		$this->assert_same( (int) $first['response_message_id'], (int) $second['response_message_id'], 'Duplicate execution changed response message.' );
		$this->assert_same( 1, $fake->calls, 'Duplicate execution called the provider twice.' );

		$trigger2 = $this->messages->create_user_idempotent( $this->room_ids[0], $this->user_id, 'crash trigger', 'client-' . wp_generate_password( 16, false, false ) );
		$job2 = $this->jobs->create( $this->room_ids[0], (int) $trigger2['id'], $this->agent_ids[0], JobRepository::request_key( 'crash', $this->room_ids[0], (int) $trigger2['id'], $this->agent_ids[0] ) );
		$response = $this->messages->create( array( 'room_id' => $this->room_ids[0], 'sender_type' => 'agent', 'sender_agent_id' => $this->agent_ids[0], 'content' => 'already stored', 'response_job_id' => $job2 ) );
		$calls = $fake->calls;
		$recovered = $runtime->run_job( (int) $job2 );
		$this->assert_same( (int) $response, (int) $recovered['response_message_id'], 'Persisted response was not recovered.' );
		$this->assert_same( $calls, $fake->calls, 'Crash recovery called the provider.' );
	}

	private function test_concurrent_lock_and_stale_recovery(): void {
		global $wpdb;
		$trigger = $this->messages->create_user_idempotent( $this->room_ids[0], $this->user_id, 'lease trigger', 'client-' . wp_generate_password( 16, false, false ) );
		$job = $this->jobs->create( $this->room_ids[0], (int) $trigger['id'], $this->agent_ids[0], JobRepository::request_key( 'lease', $this->room_ids[0], (int) $trigger['id'], $this->agent_ids[0] ) );
		$this->assert_true( $this->jobs->acquire( (int) $job, 'lease-one', 120 ), 'First lease failed.' );
		$this->assert_true( ! $this->jobs->acquire( (int) $job, 'lease-two', 120 ), 'Second worker stole an active lease.' );
		$wpdb->update( $wpdb->prefix . 'acl_ar_agent_jobs', array( 'lease_expires_at' => gmdate( 'Y-m-d H:i:s', time() - 5 ) ), array( 'id' => $job ) );
		$fake = new ACL_AR_FakeSwitchboard();
		$result = ( new AgentRuntime( $this->jobs, $this->rooms, $this->agents, $this->messages, null, $fake ) )->run_job( (int) $job );
		$this->assert_same( 'completed', $result['status'], 'Expired lease was not recoverable.' );
	}

	private function test_repository_lease_fencing(): void {
		$trigger = $this->messages->create_user_idempotent( $this->room_ids[0], $this->user_id, 'repository fence trigger', 'client-' . wp_generate_password( 16, false, false ) );
		$job_id = (int) $this->jobs->create( $this->room_ids[0], (int) $trigger['id'], $this->agent_ids[0], JobRepository::request_key( 'repository-fence', $this->room_ids[0], (int) $trigger['id'], $this->agent_ids[0] ) );
		$this->assert_true( $this->jobs->acquire( $job_id, 'repository-owner', 120 ), 'Repository fence owner did not acquire the job.' );
		$this->assert_true( ! $this->jobs->complete( $job_id, 0, 'stale-owner' ), 'Stale job completion was reported as successful.' );
		$this->assert_true( ! $this->jobs->fail( $job_id, 'stale', 'stale_error', 'Stale failure', true, 30, 'stale-owner' ), 'Stale job failure was reported as successful.' );
		$running = $this->jobs->find( $job_id );
		$this->assert_same( 'running', $running['status'], 'Stale job mutation changed durable status.' );
		$this->assert_same( 'repository-owner', $running['lease_token'], 'Stale job mutation replaced the durable owner.' );
		$this->assert_true( $this->jobs->fail( $job_id, 'owner failure', 'owner_failure', 'Owner failure', false, 0, 'repository-owner' ), 'Owning job failure did not affect exactly one row.' );
	}

	private function test_running_job_priority(): void {
		global $wpdb;
		$trigger = $this->messages->create_user_idempotent( $this->room_ids[0], $this->user_id, 'running priority trigger', 'client-' . wp_generate_password( 16, false, false ) );
		$running_id = (int) $this->jobs->create( $this->room_ids[0], (int) $trigger['id'], $this->agent_ids[0], JobRepository::request_key( 'running-priority', $this->room_ids[0], (int) $trigger['id'], $this->agent_ids[0] ) );
		$this->assert_true( $this->jobs->acquire( $running_id, 'running-priority-owner', 120 ), 'Priority running job was not acquired.' );
		$queued_id = (int) $this->jobs->create( $this->room_ids[0], (int) $trigger['id'], $this->agent_ids[0], JobRepository::request_key( 'queued-priority', $this->room_ids[0], (int) $trigger['id'], $this->agent_ids[0] ) );
		$this->assert_same( $running_id, (int) $this->jobs->active_for_assignment( $this->room_ids[0], $this->agent_ids[0] )['id'], 'Newer queued work hid an unexpired running job.' );
		$this->assert_same( 'thinking', ( new \ACL\AgentRooms\Services\AgentStateReconciler() )->reconcile_assignment( $this->room_ids[0], $this->agent_ids[0] ), 'Unexpired running job did not project Thinking.' );
		$wpdb->update( $wpdb->prefix . 'acl_ar_agent_jobs', array( 'lease_expires_at' => gmdate( 'Y-m-d H:i:s', time() - 1 ) ), array( 'id' => $running_id ) );
		$this->assert_same( $queued_id, (int) $this->jobs->active_for_assignment( $this->room_ids[0], $this->agent_ids[0] )['id'], 'Queued fallback did not take over after the running lease expired.' );
		$this->assert_same( 'queued', ( new \ACL\AgentRooms\Services\AgentStateReconciler() )->reconcile_assignment( $this->room_ids[0], $this->agent_ids[0] ), 'Expired running work did not fall back to Queued.' );
		$this->jobs->cancel( $running_id );
		$this->jobs->cancel( $queued_id );
	}

	private function test_stale_worker_cannot_publish(): void {
		global $wpdb;
		$trigger = $this->messages->create_user_idempotent( $this->room_ids[0], $this->user_id, 'stale publication trigger', 'client-' . wp_generate_password( 16, false, false ) );
		$job_id = (int) $this->jobs->create( $this->room_ids[0], (int) $trigger['id'], $this->agent_ids[0], JobRepository::request_key( 'stale-publication', $this->room_ids[0], (int) $trigger['id'], $this->agent_ids[0] ) );
		$usage_before = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}acl_ar_usage WHERE room_id=%d AND agent_id=%d", $this->room_ids[0], $this->agent_ids[0] ) );
		$fake = new ACL_AR_FakeSwitchboard();
		$fake->during_send = static function () use ( $wpdb, $job_id ): void {
			$wpdb->update(
				$wpdb->prefix . 'acl_ar_agent_jobs',
				array(
					'lease_token'      => 'replacement-owner',
					'lease_expires_at' => gmdate( 'Y-m-d H:i:s', time() + 120 ),
				),
				array( 'id' => $job_id )
			);
		};
		$result = ( new AgentRuntime( $this->jobs, $this->rooms, $this->agents, $this->messages, null, $fake ) )->run_job( $job_id );
		$this->assert_wp_error( $result, 'Stale job worker did not reject publication.' );
		$this->assert_same( 'acl_ar_job_lease_lost', $result->get_error_code(), 'Stale job worker returned the wrong persistence error.' );
		$fresh = $this->jobs->find( $job_id );
		$this->assert_same( 'running', $fresh['status'], 'Stale job worker changed the replacement owner status.' );
		$this->assert_same( 'replacement-owner', $fresh['lease_token'], 'Stale job worker cleared the replacement owner.' );
		$this->assert_same( 0, (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}acl_ar_messages WHERE response_job_id=%d", $job_id ) ), 'Stale job worker won the response message slot.' );
		$this->assert_same( 0, (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}acl_ar_events WHERE job_id=%d AND event_type='agent_completed'", $job_id ) ), 'Stale job worker emitted a completion event.' );
		$this->assert_same( $usage_before, (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}acl_ar_usage WHERE room_id=%d AND agent_id=%d", $this->room_ids[0], $this->agent_ids[0] ) ), 'Stale job worker recorded usage.' );
		$this->jobs->cancel( $job_id );
		( new \ACL\AgentRooms\Services\AgentStateReconciler() )->reconcile_assignment( $this->room_ids[0], $this->agent_ids[0] );
	}

	private function test_failed_retry(): void {
		global $wpdb;
		$trigger = $this->messages->create_user_idempotent( $this->room_ids[0], $this->user_id, 'retry trigger', 'client-' . wp_generate_password( 16, false, false ) );
		$job = $this->jobs->create( $this->room_ids[0], (int) $trigger['id'], $this->agent_ids[0], JobRepository::request_key( 'retry', $this->room_ids[0], (int) $trigger['id'], $this->agent_ids[0] ) );
		$fake = new ACL_AR_FakeSwitchboard();
		$fake->failures_remaining = 1;
		$runtime = new AgentRuntime( $this->jobs, $this->rooms, $this->agents, $this->messages, null, $fake, null, new JobRetryPolicy() );
		$this->assert_wp_error( $runtime->run_job( (int) $job ), 'First retry test should fail.' );
		$this->assert_true( ! empty( $this->jobs->find( (int) $job )['retryable'] ), 'Retryable failure was not recorded.' );
		$this->assert_same( 'queued', ( new \ACL\AgentRooms\Services\AgentStateReconciler() )->reconcile_assignment( $this->room_ids[0], $this->agent_ids[0] ), 'Retryable failure was projected as terminal.' );
		$wpdb->update( $wpdb->prefix . 'acl_ar_agent_jobs', array( 'next_attempt_at' => gmdate( 'Y-m-d H:i:s', time() - 1 ) ), array( 'id' => (int) $job ) );
		$brain_runs = new \ACL\AgentRooms\Repositories\BrainRunRepository();
		$controller = new \ACL\AgentRooms\Rest\RoomWorkController(
			$this->rooms,
			new AccessService( $this->rooms ),
			$brain_runs,
			$this->jobs,
			new \ACL\AgentRooms\Services\BrainRuntime( $brain_runs ),
			$runtime
		);
		$request = new WP_REST_Request( 'POST', '/acl-agent-rooms/v1/rooms/' . $this->room_ids[0] . '/work' );
		$request->set_param( 'id', $this->room_ids[0] );
		$request->set_param( 'job_ids', array( (int) $job ) );
		$response = $controller->run( $request )->get_data();
		$result   = $this->jobs->find( (int) $job );
		$this->assert_same( 'completed', $result['status'], 'Due failed-job retry did not complete through RoomWork.' );
		$this->assert_same( 2, $fake->calls, 'Automatic RoomWork retry used an unexpected provider request count.' );
		$this->assert_true( empty( $response['pending'] ), 'Successful automatic RoomWork retry remained pending.' );
		$this->assert_same( 'ready', ( new \ACL\AgentRooms\Repositories\PresenceRepository() )->find( $this->room_ids[0], 'agent', $this->agent_ids[0] )['state'], 'Successful retry did not restore Ready.' );
		$this->assert_same( 1, (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}acl_ar_messages WHERE response_job_id=%d", (int) $job ) ), 'Successful retry did not persist exactly one response.' );
		$this->assert_same( 1, (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}acl_ar_events WHERE job_id=%d AND event_type='message'", (int) $job ) ), 'Successful retry did not persist exactly one response event.' );
		$this->assert_same( 1, (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}acl_ar_events WHERE job_id=%d AND event_type='agent_completed'", (int) $job ) ), 'Successful retry did not persist exactly one completion event.' );
		$terminal = ( new JobRetryPolicy() )->classify( new WP_Error( 'invalid_provider_model', 'invalid', array( 'status' => 400 ) ), 1 );
		$this->assert_true( empty( $terminal['retryable'] ), 'Validation failure was classified as automatically retryable.' );
		wp_clear_scheduled_hook( \ACL\AgentRooms\Services\QueueService::SINGLE_HOOK, array( (int) $job ) );
		if ( function_exists( 'as_unschedule_all_actions' ) ) {
			as_unschedule_all_actions( \ACL\AgentRooms\Services\QueueService::SINGLE_HOOK, array( (int) $job ), 'acl-agent-rooms' );
		}
	}

	private function test_foreground_retry_lifecycle(): void {
		global $wpdb;
		$trigger = $this->messages->create_user_idempotent( $this->room_ids[0], $this->user_id, 'foreground retry trigger', 'client-' . wp_generate_password( 16, false, false ) );
		$job_id = (int) $this->jobs->create( $this->room_ids[0], (int) $trigger['id'], $this->agent_ids[0], JobRepository::request_key( 'foreground-retry', $this->room_ids[0], (int) $trigger['id'], $this->agent_ids[0] ) );
		$fake = new ACL_AR_FakeSwitchboard();
		$fake->failures_remaining = 3;
		$runtime = new AgentRuntime( $this->jobs, $this->rooms, $this->agents, $this->messages, null, $fake );
		$this->assert_wp_error( $runtime->run_job( $job_id ), 'First foreground attempt did not fail at the controlled seam.' );
		$first = $this->jobs->find( $job_id );
		$this->assert_true( ! empty( $first['retryable'] ) && ! empty( $first['next_attempt_at'] ), 'First foreground failure did not retain retry work.' );
		$legacy_first = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}acl_ar_events WHERE job_id=%d AND event_type='agent_failed' ORDER BY id LIMIT 1", $job_id ), ARRAY_A );
		$replayed_first = ( new \ACL\AgentRooms\Services\RoomEventService() )->create_agent_lifecycle( $first, \ACL\AgentRooms\Models\RoomEvent::TYPE_AGENT_FAILED, array( 'attempt' => 1, 'retryable' => true ) );
		$this->assert_same( (int) $legacy_first['id'], (int) $replayed_first['id'], 'Attempt-one lifecycle replay did not retain the legacy unsuffixed idempotency key.' );
		$this->assert_same( 1, (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}acl_ar_events WHERE job_id=%d AND event_type='agent_failed'", $job_id ) ), 'Attempt-one lifecycle replay duplicated a historical event.' );
		$scheduled = function_exists( 'as_has_scheduled_action' )
			? as_has_scheduled_action( \ACL\AgentRooms\Services\QueueService::SINGLE_HOOK, array( $job_id ), 'acl-agent-rooms' )
			: wp_next_scheduled( \ACL\AgentRooms\Services\QueueService::SINGLE_HOOK, array( $job_id ) );
		$this->assert_true( false !== $scheduled && 0 !== $scheduled, 'Retryable Independent failure did not schedule one-off durable work.' );
		$this->assert_same( 'queued', ( new \ACL\AgentRooms\Services\AgentStateReconciler() )->reconcile_assignment( $this->room_ids[0], $this->agent_ids[0] ), 'Retry backoff did not reconcile as queued.' );

		$brain_runs = new \ACL\AgentRooms\Repositories\BrainRunRepository();
		$controller = new \ACL\AgentRooms\Rest\RoomWorkController(
			$this->rooms,
			new AccessService( $this->rooms ),
			$brain_runs,
			$this->jobs,
			new \ACL\AgentRooms\Services\BrainRuntime( $brain_runs ),
			$runtime
		);
		$request = new WP_REST_Request( 'POST', '/acl-agent-rooms/v1/rooms/' . $this->room_ids[0] . '/work' );
		$request->set_param( 'id', $this->room_ids[0] );
		$request->set_param( 'job_ids', array( $job_id ) );
		$before_due = $controller->run( $request )->get_data();
		$this->assert_same( 1, $fake->calls, 'Foreground worker bypassed the persisted retry due time.' );
		$this->assert_true( ! empty( $before_due['pending'] ), 'Retryable Independent work dropped out of the foreground loop.' );
		$this->assert_true( ! empty( $before_due['jobs'][0]['retryable'] ) && ! empty( $before_due['jobs'][0]['next_attempt_at'] ), 'Foreground response omitted the retry schedule.' );
		$this->assert_true( (int) $before_due['retry_after_ms'] >= 750, 'Foreground response omitted a bounded retry delay.' );

		$failed_event_ids = array();
		for ( $attempt = 2; $attempt <= 3; $attempt++ ) {
			$wpdb->update( $wpdb->prefix . 'acl_ar_agent_jobs', array( 'next_attempt_at' => gmdate( 'Y-m-d H:i:s', time() - 1 ) ), array( 'id' => $job_id ) );
			$result = $controller->run( $request )->get_data();
			$events = $wpdb->get_results( $wpdb->prepare( "SELECT id,metadata_json FROM {$wpdb->prefix}acl_ar_events WHERE job_id=%d AND event_type='agent_failed' ORDER BY id", $job_id ), ARRAY_A );
			$failed_event_ids = array_map( static fn( $event ) => (int) $event['id'], $events );
			$this->assert_same( $attempt, count( $failed_event_ids ), 'A retry attempt did not emit a distinct failure lifecycle event.' );
			if ( 2 === $attempt ) {
				$this->assert_true( ! empty( $result['pending'] ), 'Second retryable failure became terminal foreground work.' );
				$this->assert_same( 'queued', ( new \ACL\AgentRooms\Services\AgentStateReconciler() )->reconcile_assignment( $this->room_ids[0], $this->agent_ids[0] ), 'Second retry backoff did not remain queued.' );
			}
		}
		$terminal = $this->jobs->find( $job_id );
		$this->assert_same( 3, $fake->calls, 'Foreground retry used an unexpected provider attempt count.' );
		$this->assert_true( empty( $terminal['retryable'] ) && empty( $terminal['next_attempt_at'] ), 'Exhausted Independent work remained retryable.' );
		$this->assert_same( 'error', ( new \ACL\AgentRooms\Repositories\PresenceRepository() )->find( $this->room_ids[0], 'agent', $this->agent_ids[0] )['state'], 'Exhausted Independent work did not project Error.' );
		$this->assert_true( 3 === count( $failed_event_ids ) && $failed_event_ids[0] < $failed_event_ids[1] && $failed_event_ids[1] < $failed_event_ids[2], 'Failure lifecycle event IDs were not monotonic and unique.' );
		$this->assert_same( 0, (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}acl_ar_messages WHERE response_job_id=%d", $job_id ) ), 'Exhausted retry created an assistant message.' );
		wp_clear_scheduled_hook( \ACL\AgentRooms\Services\QueueService::SINGLE_HOOK, array( $job_id ) );
		if ( function_exists( 'as_unschedule_all_actions' ) ) {
			as_unschedule_all_actions( \ACL\AgentRooms\Services\QueueService::SINGLE_HOOK, array( $job_id ), 'acl-agent-rooms' );
		}
	}

	private function test_attempt_failure_backfill(): void {
		global $wpdb;
		$trigger = $this->messages->create_user_idempotent( $this->room_ids[0], $this->user_id, 'attempt backfill trigger', 'client-' . wp_generate_password( 16, false, false ) );
		$job_id = (int) $this->jobs->create( $this->room_ids[0], (int) $trigger['id'], $this->agent_ids[0], JobRepository::request_key( 'attempt-backfill', $this->room_ids[0], (int) $trigger['id'], $this->agent_ids[0] ) );
		$events = new \ACL\AgentRooms\Services\RoomEventService();
		$events->create_agent_lifecycle( $this->jobs->find( $job_id ), \ACL\AgentRooms\Models\RoomEvent::TYPE_AGENT_QUEUED );
		$this->assert_true( $this->jobs->acquire( $job_id, 'attempt-backfill-one', 120 ), 'Attempt-one backfill fixture could not acquire the job.' );
		$this->assert_true( $this->jobs->fail( $job_id, 'retry once', 'retry_once', 'Temporary failure', true, 1, 'attempt-backfill-one' ), 'Attempt-one backfill fixture could not persist failure.' );
		$first = $this->jobs->find( $job_id );
		$first_event = $events->reconcile_job( $first );
		$this->assert_true( is_array( $first_event ), 'Attempt-one failure event was not created.' );
		$wpdb->update( $wpdb->prefix . 'acl_ar_agent_jobs', array( 'next_attempt_at' => gmdate( 'Y-m-d H:i:s', time() - 1 ) ), array( 'id' => $job_id ) );
		$this->assert_true( $this->jobs->acquire( $job_id, 'attempt-backfill-two', 120 ), 'Attempt-two backfill fixture could not acquire the job.' );
		$this->assert_true( $this->jobs->fail( $job_id, 'terminal failure', 'terminal_failure', 'Terminal failure', false, 0, 'attempt-backfill-two' ), 'Attempt-two terminal failure was not persisted.' );
		$failed = $this->jobs->find( $job_id );
		$this->assert_same( 2, (int) $failed['attempts'], 'Attempt-two backfill fixture has the wrong attempt count.' );
		$current_key = hash( 'sha256', 'agent-job:' . $job_id . ':agent_failed:attempt-2' );
		$this->assert_same( 0, (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}acl_ar_events WHERE idempotency_key=%s", $current_key ) ), 'Attempt-two event existed before crash recovery.' );
		$missing_ids = array_map( static fn( $item ) => (int) $item['id'], $this->jobs->missing_lifecycle_batch( 500 ) );
		$this->assert_true( in_array( $job_id, $missing_ids, true ), 'Prior attempt failure suppressed current-attempt lifecycle recovery.' );
		$backfill = new \ACL\AgentRooms\Services\EventBackfillService( $this->messages, $events, $this->jobs );
		$backfill->run_batch( 500 );
		$this->assert_same( 1, (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}acl_ar_events WHERE idempotency_key=%s", $current_key ) ), 'Backfill did not create the current-attempt terminal failure event.' );
		$this->assert_same( 2, (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}acl_ar_events WHERE job_id=%d AND event_type='agent_failed'", $job_id ) ), 'Backfill did not preserve exactly one failure event per attempt.' );
		$this->assert_same( 'error', ( new \ACL\AgentRooms\Repositories\PresenceRepository() )->find( $this->room_ids[0], 'agent', $this->agent_ids[0] )['state'], 'Recovered terminal failure did not project Error.' );
		$backfill->run_batch( 500 );
		$this->assert_same( 2, (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}acl_ar_events WHERE job_id=%d AND event_type='agent_failed'", $job_id ) ), 'Repeated backfill duplicated a current-attempt failure event.' );
	}

	private function test_message_boundaries(): void {
		$policy = new MessagePolicy();
		$this->assert_wp_error( $policy->normalize( " \r\n ", $this->user_id, $this->room_ids[0] ), 'Whitespace message was accepted.' );
		$this->assert_same( 12000, strlen( $policy->normalize( str_repeat( 'x', 12000 ), $this->user_id, $this->room_ids[0] ) ), 'Boundary-size message failed.' );
		$this->assert_wp_error( $policy->normalize( str_repeat( 'x', 12001 ), $this->user_id, $this->room_ids[0] ), 'Oversized character message was accepted.' );
		$this->assert_wp_error( $policy->normalize( str_repeat( 'x', 49153 ), $this->user_id, $this->room_ids[0] ), 'Oversized byte payload was accepted.' );
	}

	private function test_execution_authority_and_rate_limit(): void {
		$policy = new AgentExecutionPolicy( new AccessService( $this->rooms ), new RateLimiter() );
		$other = wp_insert_user( array( 'user_login' => $this->prefix . '-other', 'user_pass' => wp_generate_password( 24 ), 'role' => 'subscriber' ) );
		get_user_by( 'id', $other )->add_cap( 'acl_ar_use_rooms' );
		$this->assert_wp_error( $policy->authorize( (int) $other, $this->room_ids[0], 'test' ), 'Non-member agent execution was authorized.' );
		wp_delete_user( (int) $other );
		$limit = static fn() => 1;
		add_filter( 'acl_ar_agent_rate_limit_count', $limit );
		delete_transient( 'acl_ar_agent_rate_' . $this->user_id . '_' . $this->room_ids[0] );
		$this->assert_true( true === $policy->authorize( $this->user_id, $this->room_ids[0], 'test' ), 'First agent action was limited.' );
		$limited = $policy->authorize( $this->user_id, $this->room_ids[0], 'test' );
		$this->assert_wp_error( $limited, 'Second agent action was not limited.' );
		$this->assert_same( 429, (int) $limited->get_error_data()['status'], 'Agent rate limit did not return 429.' );
		remove_filter( 'acl_ar_agent_rate_limit_count', $limit );
		delete_transient( 'acl_ar_agent_rate_' . $this->user_id . '_' . $this->room_ids[0] );
	}

	private function test_command_controller_smoke(): void {
		$access = new AccessService( $this->rooms );
		$fake = new ACL_AR_FakeSwitchboard();
		$runtime = new AgentRuntime( $this->jobs, $this->rooms, $this->agents, $this->messages, null, $fake );
		$controller = new MessagesController( $this->rooms, $this->agents, $this->messages, $this->jobs, $access, $runtime );
		$commands = array( '/agents', '/help', '/ask ' . $this->agents->find( $this->agent_ids[0] )['slug'] . ' hello' );
		$ask_response = null;
		foreach ( $commands as $index => $command ) {
			$request = new WP_REST_Request( 'POST', '/acl-agent-rooms/v1/rooms/' . $this->room_ids[0] . '/messages' );
			$request->set_param( 'id', $this->room_ids[0] );
			$request->set_param( 'content', $command );
			$request->set_param( 'client_request_id', 'command-' . $index . '-' . wp_generate_password( 12, false, false ) );
			$response = $controller->create( $request );
			$this->assert_true( $response instanceof WP_REST_Response && in_array( $response->get_status(), array( 200, 201 ), true ), $command . ' controller smoke failed.' );
			if ( 2 === $index ) { $ask_response = $response; }
		}
		$this->assert_same( 0, $fake->calls, '/ask called the provider before the human-message response returned.' );
		$ask_jobs = $ask_response ? (array) ( $ask_response->get_data()['jobs'] ?? array() ) : array();
		$this->assert_same( 1, count( $ask_jobs ), '/ask did not enqueue exactly one agent job.' );
		$runtime->run_job( (int) $ask_jobs[0]['id'] );
		$this->assert_same( 1, $fake->calls, 'The queued /ask job did not execute exactly one fake agent response.' );
		delete_transient( 'acl_ar_agent_rate_' . $this->user_id . '_' . $this->room_ids[0] );
	}

	private function test_assignment_rollback(): void {
		$fail = static fn( $value, $operation, $step ) => 'assign' === $operation && 0 === $step;
		add_filter( 'acl_ar_room_mutation_fail', $fail, 10, 3 );
		$result = $this->rooms->assign_agents( $this->room_ids[0], array( $this->agent_ids[1] ) );
		remove_filter( 'acl_ar_room_mutation_fail', $fail, 10 );
		$this->assert_wp_error( $result, 'Injected assignment failure did not return an error.' );
		$this->assert_same( array( $this->agent_ids[0] ), $this->rooms->get_agent_ids( $this->room_ids[0] ), 'Assignment rollback did not restore original agents.' );
	}

	private function test_room_delete_rollback(): void {
		$room = $this->rooms->create( array( 'title' => $this->prefix . '-delete', 'slug' => $this->prefix . '-delete', 'owner_user_id' => $this->user_id ) );
		$this->room_ids[] = (int) $room;
		$message = $this->messages->create( array( 'room_id' => $room, 'sender_type' => 'user', 'sender_user_id' => $this->user_id, 'content' => 'must survive rollback' ) );
		$fail = static fn( $value, $operation, $step ) => 'delete' === $operation && 1 === $step;
		add_filter( 'acl_ar_room_mutation_fail', $fail, 10, 3 );
		$result = $this->rooms->delete( (int) $room );
		remove_filter( 'acl_ar_room_mutation_fail', $fail, 10 );
		$this->assert_wp_error( $result, 'Injected room-delete failure did not return an error.' );
		$this->assert_true( null !== $this->rooms->find( (int) $room ) && null !== $this->messages->find( (int) $message ), 'Room deletion rollback lost data.' );
	}

	private function test_public_error_and_job_projection(): void {
		$redacted = PublicError::message( 'Authorization: Bearer abc.secret api_key=topsecret' );
		$this->assert_true( false === stripos( $redacted, 'abc.secret' ) && false === stripos( $redacted, 'topsecret' ), 'Public error leaked a secret.' );
		$projection = ( new PublicJob( $this->agents ) )->prepare( array( 'id' => 1, 'agent_id' => $this->agent_ids[0], 'status' => 'failed', 'attempts' => 1, 'public_error' => $redacted, 'error_message' => 'raw-secret', 'lease_token' => 'lock-secret' ) );
		$this->assert_true( ! isset( $projection['error_message'], $projection['lease_token'] ), 'Public job exposed internal fields.' );
	}

	private function test_existing_commands(): void {
		$parser = new AgentMentionParser();
		$this->assert_same( 'ask', $parser->parse_slash_command( '/ask agent-name hello' )['command'], '/ask regression failed.' );
		$this->assert_same( 'agents', $parser->parse_slash_command( '/agents' )['command'], '/agents regression failed.' );
		$this->assert_same( 'help', $parser->parse_slash_command( '/help' )['command'], '/help regression failed.' );
	}

	private function tear_down_fixture(): void {
		global $wpdb;
		remove_all_filters( 'acl_ar_room_mutation_fail' );
		foreach ( array_reverse( array_unique( $this->room_ids ) ) as $room_id ) {
			$wpdb->delete( $wpdb->prefix . 'acl_ar_usage', array( 'room_id' => $room_id ) );
			if ( $this->rooms->find( $room_id ) ) {
				$this->rooms->delete( $room_id );
			}
			delete_transient( 'acl_ar_rate_' . $this->user_id . '_' . $room_id );
			delete_transient( 'acl_ar_agent_rate_' . $this->user_id . '_' . $room_id );
		}
		foreach ( $this->agent_ids as $agent_id ) {
			$this->agents->delete( $agent_id );
		}
		if ( $this->user_id ) {
			wp_delete_user( $this->user_id );
		}
		wp_set_current_user( 0 );
	}
}
