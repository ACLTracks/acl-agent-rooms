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

	public function send( array $request ) {
		$this->calls++;
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
			$this->test_failed_retry();
			$this->pass( 'failed_job_retry' );
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

	private function test_failed_retry(): void {
		$trigger = $this->messages->create_user_idempotent( $this->room_ids[0], $this->user_id, 'retry trigger', 'client-' . wp_generate_password( 16, false, false ) );
		$job = $this->jobs->create( $this->room_ids[0], (int) $trigger['id'], $this->agent_ids[0], JobRepository::request_key( 'retry', $this->room_ids[0], (int) $trigger['id'], $this->agent_ids[0] ) );
		$fake = new ACL_AR_FakeSwitchboard();
		$fake->failures_remaining = 1;
		$runtime = new AgentRuntime( $this->jobs, $this->rooms, $this->agents, $this->messages, null, $fake, null, new JobRetryPolicy() );
		$this->assert_wp_error( $runtime->run_job( (int) $job ), 'First retry test should fail.' );
		$this->assert_true( ! empty( $this->jobs->find( (int) $job )['retryable'] ), 'Retryable failure was not recorded.' );
		$result = $runtime->run_job( (int) $job, true );
		$this->assert_same( 'completed', $result['status'], 'Intentional failed-job retry did not complete.' );
		$terminal = ( new JobRetryPolicy() )->classify( new WP_Error( 'invalid_provider_model', 'invalid', array( 'status' => 400 ) ), 1 );
		$this->assert_true( empty( $terminal['retryable'] ), 'Validation failure was classified as automatically retryable.' );
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
