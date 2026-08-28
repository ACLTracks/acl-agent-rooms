<?php
/** Deterministic no-cost coverage for the 1.5.7 human-message response path. */

class ACL_AR_ResponsivenessFakeSwitchboard implements \ACL\AgentRooms\Contracts\SwitchboardClientInterface {
	public int $calls = 0;
	public int $delay_ms = 0;
	public bool $fail = false;
	public function send( array $request ) {
		++$this->calls;
		if ( $this->delay_ms > 0 ) { usleep( $this->delay_ms * 1000 ); }
		if ( $this->fail ) { return new \WP_Error( 'acl_ar_controlled_provider_failure', 'Controlled no-cost provider failure.', array( 'status' => 503 ) ); }
		$agent_ids = array_values( array_map( 'absint', (array) ( $request['metadata']['agent_ids'] ?? array() ) ) );
		if ( $agent_ids ) {
			$key = ! empty( $request['system_prompt'] ) && false !== strpos( (string) $request['system_prompt'], '"turns"' ) ? 'turns' : 'responses';
			$rows = array_map(
				static function ( int $id ) use ( $key ): array {
					$row = array( 'agent_id' => $id, 'content' => 'Controlled Brain reply for ' . $id );
					if ( 'turns' === $key ) { $row['purpose'] = 'reply'; }
					return $row;
				},
				$agent_ids
			);
			return array( 'content' => wp_json_encode( array( $key => $rows ) ), 'usage' => array( 'prompt_tokens' => 0, 'completion_tokens' => 0, 'total_tokens' => 0 ), 'estimated_cost' => 0, 'raw_provider' => 'controlled-responsiveness-fake', 'finish_reason' => 'stop' );
		}
		return array( 'content' => 'Controlled Independent reply.', 'usage' => array( 'prompt_tokens' => 0, 'completion_tokens' => 0, 'total_tokens' => 0 ), 'estimated_cost' => 0, 'raw_provider' => 'controlled-responsiveness-fake', 'finish_reason' => 'stop' );
	}
}

class ACL_AR_MessageResponsivenessTest extends ACL_AR_TestCase {
	private int $owner = 0;
	private array $rooms = array();
	private array $agents = array();
	private int $brain = 0;
	private array $job_ids = array();
	private array $run_ids = array();
	private string $prefix = '';
	private ACL_AR_ResponsivenessFakeSwitchboard $fake;
	private $client_filter;

	public function run(): void {
		$this->prefix = 'responsive-141-' . gmdate( 'YmdHis' ) . '-' . wp_generate_password( 6, false, false );
		$this->fake = new ACL_AR_ResponsivenessFakeSwitchboard();
		$this->client_filter = fn( $client, $request ) => $this->fake;
		add_filter( 'acl_ar_switchboard_client', $this->client_filter, 10, 2 );
		add_filter( 'acl_ar_brain_provider_model_valid', array( $this, 'allow_fake_brain' ), 10, 3 );
		$this->owner = (int) wp_insert_user( array( 'user_login' => $this->prefix, 'user_pass' => wp_generate_password( 24 ), 'user_email' => $this->prefix . '@example.test', 'display_name' => 'Responsive Sender', 'role' => 'administrator' ) );
		wp_set_current_user( $this->owner );
		try {
			$this->independent_endpoint_and_commit();
			$this->ask_command_exposes_foreground_work();
			$this->provider_failure_preserves_message();
			$this->shared_brain_endpoint_and_one_call();
			$this->no_agent_natural_silence_and_idempotency();
			$this->post_persistence_dispatch_failure();
			$this->project_file_request_boundary();
		} finally { $this->cleanup(); }
	}

	private function ask_command_exposes_foreground_work(): void {
		$agent_id = $this->make_agent( 'Ask Independent' );
		$agent = ( new \ACL\AgentRooms\Repositories\AgentRepository() )->find( $agent_id );
		$room_id = $this->make_room( 'Ask Independent Room', array(), array( $agent_id ) );
		$request = new \WP_REST_Request( 'POST', '/acl-agent-rooms/v1/rooms/' . $room_id . '/commands' );
		$request->set_param( 'id', $room_id );
		$request->set_param( 'input', '/ask ' . $agent['slug'] . ' Controlled foreground command' );
		$request->set_param( 'client_request_id', $this->prefix . '-ask-independent' );
		$calls = $this->fake->calls;
		$response = ( new \ACL\AgentRooms\Rest\CommandsController( null, $this->controller() ) )->execute( $request );
		$this->assert_true( $response instanceof \WP_REST_Response && 200 === $response->get_status(), '/ask command did not return a successful response.' );
		$data = $response->get_data();
		$this->assert_same( 1, count( $data['jobs'] ?? array() ), '/ask command did not expose its job to the foreground worker.' );
		$this->assert_same( 0, count( $data['brain_runs'] ?? array() ), 'Independent /ask command exposed unexpected Shared Brain work.' );
		$this->assert_same( $data['jobs'], $data['result']['jobs'], '/ask command returned inconsistent job lists.' );
		$this->assert_same( $calls, $this->fake->calls, '/ask command ran its provider before the foreground worker.' );
		$job_id = (int) $data['jobs'][0]['id'];
		$this->job_ids[] = $job_id;
		$work = $this->work_controller()->run( $this->work_request( $room_id, array(), array( $job_id ) ) );
		$this->assert_true( $work instanceof \WP_REST_Response && 200 === $work->get_status(), 'Foreground worker did not accept the /ask job.' );
		$this->assert_same( false, (bool) $work->get_data()['pending'], 'Foreground worker left the /ask job pending.' );
		$this->assert_same( $calls + 1, $this->fake->calls, 'Foreground /ask job did not use exactly one provider request.' );
	}

	public function allow_fake_brain( $allowed, string $provider, string $model ): ?bool {
		return 'controlled-responsiveness' === $provider && 'responsive-v1' === $model ? true : $allowed;
	}

	private function controller(): \ACL\AgentRooms\Rest\MessagesController {
		$rooms = new \ACL\AgentRooms\Repositories\RoomRepository();
		$agents = new \ACL\AgentRooms\Repositories\AgentRepository();
		$messages = new \ACL\AgentRooms\Repositories\MessageRepository();
		$jobs = new \ACL\AgentRooms\Repositories\JobRepository();
		return new \ACL\AgentRooms\Rest\MessagesController( $rooms, $agents, $messages, $jobs, new \ACL\AgentRooms\Services\AccessService( $rooms ), new \ACL\AgentRooms\Services\AgentRuntime( $jobs, $rooms, $agents, $messages, null, $this->fake ) );
	}

	private function work_controller(): \ACL\AgentRooms\Rest\RoomWorkController {
		$rooms = new \ACL\AgentRooms\Repositories\RoomRepository();
		$agents = new \ACL\AgentRooms\Repositories\AgentRepository();
		$messages = new \ACL\AgentRooms\Repositories\MessageRepository();
		$jobs = new \ACL\AgentRooms\Repositories\JobRepository();
		$runs = new \ACL\AgentRooms\Repositories\BrainRunRepository();
		return new \ACL\AgentRooms\Rest\RoomWorkController(
			$rooms,
			new \ACL\AgentRooms\Services\AccessService( $rooms ),
			$runs,
			$jobs,
			new \ACL\AgentRooms\Services\BrainRuntime( $runs ),
			new \ACL\AgentRooms\Services\AgentRuntime( $jobs, $rooms, $agents, $messages, null, $this->fake )
		);
	}

	private function work_request( int $room_id, array $brain_run_ids = array(), array $job_ids = array() ): \WP_REST_Request {
		$request = new \WP_REST_Request( 'POST', '/acl-agent-rooms/v1/rooms/' . $room_id . '/work' );
		$request->set_param( 'id', $room_id );
		$request->set_param( 'brain_run_ids', $brain_run_ids );
		$request->set_param( 'job_ids', $job_ids );
		return $request;
	}

	private function request( int $room_id, string $content, string $nonce ): \WP_REST_Request {
		$request = new \WP_REST_Request( 'POST', '/acl-agent-rooms/v1/rooms/' . $room_id . '/messages' );
		$request->set_param( 'id', $room_id ); $request->set_param( 'content', $content ); $request->set_param( 'client_request_id', $nonce );
		return $request;
	}

	private function make_agent( string $suffix, string $mode = 'independent', int $brain_id = 0 ): int {
		$id = ( new \ACL\AgentRooms\Repositories\AgentRepository() )->create( array( 'owner_user_id' => $this->owner, 'name' => $this->prefix . ' ' . $suffix, 'slug' => sanitize_title( $this->prefix . '-' . $suffix ), 'provider_route' => 'controlled-responsiveness', 'model' => 'responsive-v1', 'system_prompt' => 'Controlled no-cost test participant.', 'execution_mode' => $mode, 'brain_id' => $brain_id ?: null, 'enabled' => 1 ) );
		$this->assert_true( is_int( $id ) && $id > 0, 'Responsiveness agent was not created.' ); $this->agents[] = (int) $id; return (int) $id;
	}

	private function make_room( string $suffix, array $data = array(), array $agent_ids = array() ): int {
		$rooms = new \ACL\AgentRooms\Repositories\RoomRepository();
		$id = $rooms->create( array_merge( array( 'owner_user_id' => $this->owner, 'title' => $this->prefix . ' ' . $suffix, 'slug' => sanitize_title( $this->prefix . '-' . $suffix ), 'type' => 'private', 'visibility' => 'private', 'status' => 'active', 'agent_reply_mode' => 'auto', 'max_agents_per_turn' => max( 1, count( $agent_ids ) ) ), $data ) );
		$this->assert_true( is_int( $id ) && $id > 0, 'Responsiveness room was not created.' );
		if ( $agent_ids ) { $this->assert_same( true, $rooms->assign_agents( (int) $id, $agent_ids ), 'Responsiveness agents were not assigned.' ); }
		$this->rooms[] = (int) $id; return (int) $id;
	}

	private function independent_endpoint_and_commit(): void {
		global $wpdb;
		$agent = $this->make_agent( 'Independent' ); $room = $this->make_room( 'Independent Room', array(), array( $agent ) );
		$dispatch_state = array(); $order = array();
		$committed_hook = static function () use ( &$order ): void { $order[] = 'committed'; };
		$hook = function( int $room_id, int $event_id, int $message_id ) use ( &$dispatch_state, &$order, $wpdb ): void {
			$order[] = 'dispatch';
			$dispatch_state = array( 'room_id' => $room_id, 'event_id' => $event_id, 'message_id' => $message_id, 'read' => (int) $wpdb->get_var( $wpdb->prepare( "SELECT last_read_event_id FROM {$wpdb->prefix}acl_ar_room_reads WHERE room_id=%d AND user_id=%d", $room_id, $this->owner ) ) );
		};
		add_action( 'acl_ar_human_message_committed', $committed_hook, 10, 5 );
		add_action( 'acl_ar_before_downstream_dispatch', $hook, 10, 3 );
		$this->fake->delay_ms = 2000; $before_calls = $this->fake->calls; $started = microtime( true );
		$response = $this->controller()->create( $this->request( $room, 'Slow Independent message', $this->prefix . '-independent' ) );
		$elapsed = microtime( true ) - $started; remove_action( 'acl_ar_before_downstream_dispatch', $hook, 10 ); remove_action( 'acl_ar_human_message_committed', $committed_hook, 10 );
		$this->assert_true( $response instanceof \WP_REST_Response && 201 === $response->get_status(), 'Independent human message endpoint failed.' );
		$data = $response->get_data(); $event_id = (int) $data['event']['id']; $message_id = (int) $data['message_id'];
		$this->assert_true( $elapsed < 1.0, 'Slow Independent provider delayed the human message response.' );
		$this->assert_same( $before_calls, $this->fake->calls, 'Independent provider ran inside the human message endpoint.' );
		$this->assert_true( $message_id > 0 && $event_id > 0, 'Endpoint omitted the canonical message/event.' );
		$this->assert_same( array( 'committed', 'dispatch' ), $order, 'Downstream dispatch started before the durable commit signal.' );
		$this->assert_same( $event_id, $dispatch_state['event_id'], 'Dispatch hook observed a different event.' );
		$this->assert_true( $dispatch_state['read'] >= $event_id, 'Human read boundary was not committed with the event.' );
		$this->assert_same( 'queued', $data['orchestration']['status'], 'Independent response did not report safe queued status.' );
		$this->assert_same( 1, count( $data['jobs'] ), 'Independent message did not create exactly one job.' );
		$job_id = (int) $data['jobs'][0]['id']; $this->job_ids[] = $job_id;
		$this->fake->delay_ms = 5;
		$work = $this->work_controller()->run( $this->work_request( $room, array(), array( $job_id ) ) );
		$this->assert_true( $work instanceof \WP_REST_Response && 200 === $work->get_status(), 'Foreground worker did not return a successful Independent response.' );
		$this->assert_same( false, (bool) $work->get_data()['pending'], 'Foreground worker left completed Independent work pending.' );
		$this->assert_same( $before_calls + 1, $this->fake->calls, 'Queued Independent job did not make exactly one provider request.' );
		$job = ( new \ACL\AgentRooms\Repositories\JobRepository() )->find( $job_id );
		$this->assert_same( 'completed', $job['status'], 'Queued Independent job did not complete.' );
		$this->assert_true( null !== ( new \ACL\AgentRooms\Repositories\MessageRepository() )->find( $message_id ), 'Independent execution removed the human message.' );
	}

	private function provider_failure_preserves_message(): void {
		$agent = $this->make_agent( 'Failure Agent' ); $room = $this->make_room( 'Failure Room', array(), array( $agent ) );
		$this->fake->delay_ms = 0; $this->fake->fail = true; $calls = $this->fake->calls;
		$response = $this->controller()->create( $this->request( $room, 'Persist through provider failure', $this->prefix . '-failure' ) );
		$data = $response->get_data(); $this->assert_same( $calls, $this->fake->calls, 'Failing provider ran inside the message endpoint.' );
		$job_id = (int) $data['jobs'][0]['id']; $this->job_ids[] = $job_id; ( new \ACL\AgentRooms\Services\AgentRuntime() )->run_job( $job_id );
		$this->assert_same( $calls + 1, $this->fake->calls, 'Controlled provider failure was not exercised exactly once.' );
		$this->assert_true( null !== ( new \ACL\AgentRooms\Repositories\MessageRepository() )->find( (int) $data['message_id'] ), 'Provider failure removed the human message.' );
		$this->assert_true( null !== ( new \ACL\AgentRooms\Repositories\EventRepository() )->find( (int) $data['event']['id'] ), 'Provider failure removed the human event.' );
		$this->fake->fail = false;
	}

	private function shared_brain_endpoint_and_one_call(): void {
		$brains = new \ACL\AgentRooms\Repositories\BrainRepository();
		$this->brain = (int) $brains->create( array( 'owner_user_id' => $this->owner, 'name' => $this->prefix . ' Brain', 'slug' => sanitize_title( $this->prefix . '-brain' ), 'provider' => 'controlled-responsiveness', 'model' => 'responsive-v1', 'orchestration_prompt' => 'Return distinct controlled answers.', 'max_tokens_per_agent' => 100, 'max_total_tokens' => 300, 'enabled' => 1 ) );
		$this->assert_true( $this->brain > 0, 'Responsiveness Brain was not created.' );
		$a = $this->make_agent( 'Brain Alpha', 'brain', $this->brain ); $b = $this->make_agent( 'Brain Beta', 'brain', $this->brain );
		$room = $this->make_room( 'Brain Room', array( 'max_agents_per_turn' => 2 ), array( $a, $b ) );
		$this->fake->delay_ms = 2000; $calls = $this->fake->calls; $started = microtime( true );
		$response = $this->controller()->create( $this->request( $room, 'Slow Shared Brain message', $this->prefix . '-brain-message' ) );
		$elapsed = microtime( true ) - $started; $data = $response->get_data();
		$this->assert_true( $elapsed < 1.0, 'Slow Shared Brain delayed the human message response.' );
		$this->assert_same( $calls, $this->fake->calls, 'Shared Brain ran inside the human message endpoint.' );
		$this->assert_same( 1, count( $data['brain_runs'] ), 'Shared Brain message did not enqueue one grouped run.' );
		$run_id = (int) $data['brain_runs'][0]['id']; $this->run_ids[] = $run_id;
		$this->fake->delay_ms = 5;
		$work = $this->work_controller()->run( $this->work_request( $room, array( $run_id ) ) );
		$this->assert_true( $work instanceof \WP_REST_Response && 200 === $work->get_status(), 'Foreground worker did not return a successful Shared Brain response.' );
		$this->assert_same( false, (bool) $work->get_data()['pending'], 'Foreground worker left completed Shared Brain work pending.' );
		$this->assert_same( $calls + 1, $this->fake->calls, 'Shared Brain did not use exactly one queued provider request.' );
		$this->assert_same( 2, count( ( new \ACL\AgentRooms\Repositories\BrainRunRepository() )->find( $run_id )['response_event_ids'] ), 'Shared Brain did not fan out both replies.' );
		$this->assert_true( null !== ( new \ACL\AgentRooms\Repositories\MessageRepository() )->find( (int) $data['message_id'] ), 'Shared Brain execution removed the human message.' );
	}

	private function no_agent_natural_silence_and_idempotency(): void {
		global $wpdb;
		$room = $this->make_room( 'Silent Natural Room', array( 'conversation_mode' => 'natural', 'agent_reply_mode' => 'auto' ) );
		$calls = $this->fake->calls; $request = $this->request( $room, 'No agent should still show this', $this->prefix . '-silent' );
		$single_message = static fn() => 1; add_filter( 'acl_ar_rate_limit_count', $single_message );
		$first = $this->controller()->create( $request ); $again = $this->controller()->create( $request );
		remove_filter( 'acl_ar_rate_limit_count', $single_message );
		$this->assert_same( $calls, $this->fake->calls, 'No-agent Natural Conversation called a provider.' );
		$this->assert_true( $again instanceof \WP_REST_Response && 200 === $again->get_status(), 'Idempotent retry was incorrectly rejected by the message rate limit.' );
		$this->assert_same( (int) $first->get_data()['message_id'], (int) $again->get_data()['message_id'], 'Retry changed the canonical message ID.' );
		$this->assert_same( (int) $first->get_data()['event']['id'], (int) $again->get_data()['event']['id'], 'Retry changed the canonical event ID.' );
		$this->assert_same( true, (bool) $again->get_data()['duplicate'], 'Retry did not report idempotent reconciliation.' );
		$this->assert_same( 1, (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}acl_ar_messages WHERE room_id=%d AND client_request_id=%s", $room, $this->prefix . '-silent' ) ), 'Retry created a duplicate human message.' );
		$this->assert_same( 0, (int) $first->get_data()['orchestration']['scheduled'], 'Natural Conversation silence scheduled agent work.' );
	}

	private function post_persistence_dispatch_failure(): void {
		$room = $this->make_room( 'Dispatch Failure Room' ); $filter = '__return_true';
		add_filter( 'acl_ar_force_orchestration_dispatch_failure', $filter );
		$response = $this->controller()->create( $this->request( $room, 'Keep this after dispatch failure', $this->prefix . '-dispatch-failure' ) );
		remove_filter( 'acl_ar_force_orchestration_dispatch_failure', $filter ); $data = $response->get_data();
		$this->assert_true( $response instanceof \WP_REST_Response && 201 === $response->get_status(), 'Post-persistence dispatch failure changed message success.' );
		$this->assert_same( 'degraded', $data['orchestration']['status'], 'Dispatch failure did not expose a safe status.' );
		$this->assert_true( ! isset( $data['orchestration']['code'], $data['orchestration']['message'] ), 'Ordinary response exposed orchestration internals.' );
		$this->assert_true( null !== ( new \ACL\AgentRooms\Repositories\MessageRepository() )->find( (int) $data['message_id'] ), 'Dispatch failure removed the human message.' );
		$health = ( new \ACL\AgentRooms\Services\HealthService() )->snapshot()['orchestration'];
		$this->assert_true( $health['failure_count'] >= 1 && (int) $health['latest']['event_id'] === (int) $data['event']['id'], 'Manager health omitted the safe orchestration diagnostic.' );
	}

	private function project_file_request_boundary(): void {
		$source = file_get_contents( dirname( __DIR__ ) . '/includes/Rest/MessagesController.php' );
		$create = substr( $source, strpos( $source, 'public function create' ), strpos( $source, 'public function manual_reply' ) - strpos( $source, 'public function create' ) );
		$this->assert_true( false === strpos( $create, 'RoomFileRetrievalService' ) && false === strpos( $create, 'prompt_block(' ), 'Project-file retrieval remained in the human-message response path.' );
		$this->assert_true( false !== strpos( file_get_contents( dirname( __DIR__ ) . '/includes/Services/PromptBuilder.php' ), 'RoomFileRetrievalService' ) || false !== strpos( file_get_contents( dirname( __DIR__ ) . '/includes/Services/BrainPromptBuilder.php' ), 'RoomFileRetrievalService' ), 'Project-file retrieval was not preserved in queued runtime prompt construction.' );
	}

	private function cleanup(): void {
		remove_filter( 'acl_ar_switchboard_client', $this->client_filter, 10 );
		remove_filter( 'acl_ar_brain_provider_model_valid', array( $this, 'allow_fake_brain' ), 10 );
		remove_all_filters( 'acl_ar_force_orchestration_dispatch_failure' );
		foreach ( $this->job_ids as $id ) { wp_clear_scheduled_hook( \ACL\AgentRooms\Services\QueueService::SINGLE_HOOK, array( $id ) ); }
		foreach ( $this->run_ids as $id ) { wp_clear_scheduled_hook( \ACL\AgentRooms\Services\QueueService::BRAIN_HOOK, array( $id ) ); }
		$rooms = new \ACL\AgentRooms\Repositories\RoomRepository(); foreach ( array_reverse( $this->rooms ) as $id ) { if ( $rooms->find( $id ) ) { $rooms->delete( $id ); } }
		$agents = new \ACL\AgentRooms\Repositories\AgentRepository(); foreach ( $this->agents as $id ) { if ( $agents->find( $id ) ) { $agents->delete( $id ); } }
		if ( $this->brain ) { ( new \ACL\AgentRooms\Repositories\BrainRepository() )->delete( $this->brain ); }
		delete_option( \ACL\AgentRooms\Services\OrchestrationDiagnosticService::OPTION );
		if ( $this->owner ) { wp_delete_user( $this->owner ); } wp_set_current_user( 0 );
	}
}
