<?php
/** Deterministic no-cost functional coverage for Natural Conversation under 1.4.1. */

class ACL_AR_NaturalFakeSwitchboard implements \ACL\AgentRooms\Contracts\SwitchboardClientInterface {
	public int $calls = 0;
	public int $failures_remaining = 0;
	public int $contract_failures_remaining = 0;
	public string $content_prefix = 'Distinct natural response ';
	public array $requests = array();
	public $during_send = null;
	public function send( array $request ) {
		$this->calls++; $this->requests[] = $request;
		if ( is_callable( $this->during_send ) ) { ( $this->during_send )( $request ); }
		if ( $this->failures_remaining > 0 ) { --$this->failures_remaining; return new \WP_Error( 'acl_switchboard_retryable_http_status', 'Provider rate limit.', array( 'status' => 429, 'retry_after' => 30 ) ); }
		if ( 'acl-agent-rooms-brain' === (string) ( $request['metadata']['source'] ?? '' ) ) {
			if ( $this->contract_failures_remaining > 0 ) { --$this->contract_failures_remaining; return array( 'content' => '{"turns":[{"agent_id":999999,"content":"wrong participant","purpose":"reply"}]}', 'usage' => array(), 'estimated_cost' => 0, 'raw_provider' => 'natural-fake', 'finish_reason' => 'stop' ); }
			$turns = array(); foreach ( (array) ( $request['metadata']['agent_ids'] ?? array() ) as $index => $id ) { $turns[] = array( 'agent_id' => (int) $id, 'content' => $this->content_prefix . (int) $id, 'purpose' => 1 === $index ? 'steer' : 'reply' ); }
			return array( 'content' => wp_json_encode( array( 'turns' => $turns ) ), 'usage' => array( 'prompt_tokens' => 90, 'completion_tokens' => 30, 'total_tokens' => 120 ), 'estimated_cost' => 0, 'raw_provider' => 'natural-fake', 'finish_reason' => 'stop' );
		}
		return array( 'content' => 'A concise independent natural response.', 'usage' => array( 'prompt_tokens' => 25, 'completion_tokens' => 8, 'total_tokens' => 33 ), 'estimated_cost' => 0, 'raw_provider' => 'natural-fake', 'finish_reason' => 'stop' );
	}
}

class ACL_AR_NaturalRecoveryMessageRepository extends \ACL\AgentRooms\Repositories\MessageRepository {
	public $after_find = null;
	public function find_by_response_job_id( int $job_id ): ?array {
		$response = parent::find_by_response_job_id( $job_id );
		if ( $response && is_callable( $this->after_find ) ) {
			$callback         = $this->after_find;
			$this->after_find = null;
			$callback( $response );
		}
		return $response;
	}
}

class ACL_AR_NaturalConversationTest extends ACL_AR_TestCase {
	private int $owner = 0; private int $room = 0; private int $brain = 0; private array $agents = array(); private array $turn_ids = array(); private string $prefix = '';
	public function run(): void {
		$this->prefix = 'natural-130-' . gmdate( 'YmdHis' ) . '-' . wp_generate_password( 5, false, false );
		add_filter( 'acl_ar_brain_provider_model_valid', array( $this, 'allow_fake' ), 10, 3 );
		$this->owner = (int) wp_insert_user( array( 'user_login' => $this->prefix . '-owner', 'user_pass' => wp_generate_password( 24 ), 'user_email' => $this->prefix . '@example.test', 'role' => 'administrator' ) );
		$this->assert_true( $this->owner > 0, 'Natural test owner was not created.' ); wp_set_current_user( $this->owner );
		try { $this->schema_and_defaults(); $this->create_fixture(); $this->settings_and_director(); $this->parser_prompt_and_timing(); $this->brain_scheduling_and_supersession(); $this->retryable_brain_failure_preserves_turns(); $this->contract_violation_retry_preserves_turns(); $this->independent_publication(); $this->independent_turn_owner_fence(); $this->independent_persistence_serialization(); $this->existing_response_recovery_serialization(); $this->retryable_independent_publication(); $this->security_health_and_sources(); } finally { $this->cleanup(); remove_filter( 'acl_ar_brain_provider_model_valid', array( $this, 'allow_fake' ), 10 ); wp_set_current_user( 0 ); }
	}
	public function allow_fake( $allowed, string $provider, string $model ): ?bool { return 'controlled-fake' === $provider && 'natural-model-v1' === $model ? true : $allowed; }

	private function schema_and_defaults(): void {
		global $wpdb; $before = $this->fingerprint(); \ACL\AgentRooms\Installer::install(); \ACL\AgentRooms\Installer::install();
		$this->assert_same( '1.5.6', ACL_AR_VERSION, 'Natural plugin version mismatch.' ); $this->assert_same( '1.4.1', ACL_AR_DB_VERSION, 'Natural DB version mismatch.' ); $this->assert_same( '1.4.1', (string) get_option( 'acl_ar_db_version' ), 'Natural installed version mismatch.' ); $this->assert_same( $before, $this->fingerprint(), 'Natural installer twice changed schema.' );
		$table = $wpdb->prefix . 'acl_ar_conversation_turns'; $this->assert_same( $table, (string) $wpdb->get_var( $wpdb->prepare( 'SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA=%s AND TABLE_NAME=%s', DB_NAME, $table ) ), 'Conversation turn table missing.' );
		$columns = $wpdb->get_col( $wpdb->prepare( 'SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=%s AND TABLE_NAME=%s', DB_NAME, $table ) ); foreach ( array( 'id','room_id','trigger_event_id','agent_id','brain_run_id','job_id','source_type','status','purpose','content','due_at','typing_at','published_event_id','idempotency_key','cancel_reason','created_at','updated_at' ) as $column ) { $this->assert_true( in_array( $column, $columns, true ), 'Conversation turn column missing: ' . $column ); }
		$indexes = $wpdb->get_col( $wpdb->prepare( 'SELECT DISTINCT INDEX_NAME FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=%s AND TABLE_NAME=%s', DB_NAME, $table ) ); foreach ( array( 'PRIMARY','idempotency_key','due_worker','room_pending','trigger_event_id','brain_run_id','job_id','agent_id' ) as $index ) { $this->assert_true( in_array( $index, $indexes, true ), 'Conversation turn index missing: ' . $index ); }
		$room_columns = $wpdb->get_col( $wpdb->prepare( 'SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=%s AND TABLE_NAME=%s', DB_NAME, $wpdb->prefix . 'acl_ar_rooms' ) ); foreach ( array( 'conversation_mode','natural_min_responders','natural_max_responders','natural_initial_delay_min_ms','natural_initial_delay_max_ms','natural_inter_turn_delay_min_ms','natural_inter_turn_delay_max_ms','natural_allow_silence','natural_silence_chance','natural_cancel_pending_on_new_message','natural_max_pending_turns','natural_steering_question_bias','natural_active_trigger_event_id' ) as $column ) { $this->assert_true( in_array( $column, $room_columns, true ), 'Natural room column missing: ' . $column ); }
		$agent_columns = $wpdb->get_col( $wpdb->prepare( 'SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=%s AND TABLE_NAME=%s', DB_NAME, $wpdb->prefix . 'acl_ar_agents' ) ); foreach ( array( 'natural_participation_chance','natural_question_tendency','natural_delay_min_ms','natural_delay_max_ms','natural_cooldown_seconds','natural_max_auto_responses_per_10m','natural_conversation_role' ) as $column ) { $this->assert_true( in_array( $column, $agent_columns, true ), 'Natural agent column missing: ' . $column ); }
	}

	private function create_fixture(): void {
		$brains = new \ACL\AgentRooms\Repositories\BrainRepository(); $this->brain = (int) $brains->create( array( 'owner_user_id' => $this->owner, 'name' => $this->prefix . ' Brain', 'slug' => $this->prefix . '-brain', 'provider' => 'controlled-fake', 'model' => 'natural-model-v1', 'orchestration_prompt' => 'Keep each contribution distinct.', 'max_tokens_per_agent' => 200, 'max_total_tokens' => 600, 'enabled' => 1 ) ); $this->assert_true( $this->brain > 0, 'Natural Brain was not created.' );
		$repo = new \ACL\AgentRooms\Repositories\AgentRepository(); $roles = array( 'quiet', 'balanced', 'facilitator' ); foreach ( $roles as $index => $role ) { $id = $repo->create( array( 'owner_user_id' => $this->owner, 'name' => $this->prefix . ' ' . ucfirst( $role ), 'slug' => $this->prefix . '-' . $role, 'description' => 'Distinct ' . $role . ' participant', 'provider_route' => 'independent-' . $index, 'model' => 'independent-model-' . $index, 'system_prompt' => 'PERSONA-' . strtoupper( $role ), 'execution_mode' => 'brain', 'brain_id' => $this->brain, 'enabled' => 1, 'natural_participation_chance' => 'quiet' === $role ? 0 : 100, 'natural_question_tendency' => 'facilitator' === $role ? 90 : 10, 'natural_cooldown_seconds' => 20, 'natural_max_auto_responses_per_10m' => 'quiet' === $role ? 0 : 4, 'natural_conversation_role' => $role ) ); $this->assert_true( is_int( $id ) && $id > 0, 'Natural agent was not created.' ); $this->agents[] = (int) $id; }
		$rooms = new \ACL\AgentRooms\Repositories\RoomRepository(); $this->room = (int) $rooms->create( array( 'owner_user_id' => $this->owner, 'title' => $this->prefix . ' Room', 'slug' => $this->prefix . '-room', 'description' => 'Natural test room', 'top_context' => 'NATURAL-ROOM-CONTEXT', 'type' => 'private', 'visibility' => 'private', 'status' => 'active', 'agent_reply_mode' => 'auto', 'max_agents_per_turn' => 3, 'conversation_mode' => 'natural', 'natural_min_responders' => 1, 'natural_max_responders' => 2, 'natural_initial_delay_min_ms' => 1500, 'natural_initial_delay_max_ms' => 4500, 'natural_inter_turn_delay_min_ms' => 2500, 'natural_inter_turn_delay_max_ms' => 8000, 'natural_allow_silence' => 0, 'natural_silence_chance' => 10, 'natural_cancel_pending_on_new_message' => 1, 'natural_max_pending_turns' => 4, 'natural_steering_question_bias' => 100 ) ); $this->assert_true( $this->room > 0, 'Natural room was not created.' ); $this->assert_true( true === $rooms->assign_agents( $this->room, $this->agents ), 'Natural agents were not assigned.' );
	}

	private function settings_and_director(): void {
		global $wpdb; $rooms = new \ACL\AgentRooms\Repositories\RoomRepository(); $agents = new \ACL\AgentRooms\Repositories\AgentRepository(); $room = $rooms->find( $this->room ); $rows = $rooms->get_agents( $this->room );
		$this->assert_same( 'natural', $room['conversation_mode'], 'Natural room mode did not persist.' ); $this->assert_same( 1, $room['natural_min_responders'], 'Natural minimum did not persist.' ); $this->assert_same( 2, $room['natural_max_responders'], 'Natural maximum did not persist.' ); $this->assert_same( 1500, $room['natural_initial_delay_min_ms'], 'Natural first minimum did not persist.' ); $this->assert_same( 8000, $room['natural_inter_turn_delay_max_ms'], 'Natural inter maximum did not persist.' ); $this->assert_true( $room['natural_cancel_pending_on_new_message'], 'Natural supersession default is disabled.' );
		$rooms->update( $this->room, array( 'natural_min_responders' => 9, 'natural_max_responders' => 2, 'natural_initial_delay_min_ms' => 70000, 'natural_initial_delay_max_ms' => 1, 'natural_silence_chance' => 150, 'natural_max_pending_turns' => 99 ) ); $clamped = $rooms->find( $this->room ); $this->assert_same( 9, $clamped['natural_min_responders'], 'Responder minimum was not bounded.' ); $this->assert_same( 9, $clamped['natural_max_responders'], 'Responder maximum did not follow minimum.' ); $this->assert_same( 60000, $clamped['natural_initial_delay_min_ms'], 'Delay minimum upper bound failed.' ); $this->assert_same( 60000, $clamped['natural_initial_delay_max_ms'], 'Delay maximum ordering failed.' ); $this->assert_same( 100, $clamped['natural_silence_chance'], 'Probability bound failed.' ); $this->assert_same( 10, $clamped['natural_max_pending_turns'], 'Pending limit bound failed.' );
		$rooms->update( $this->room, array( 'natural_min_responders' => 1, 'natural_max_responders' => 2, 'natural_initial_delay_min_ms' => 1500, 'natural_initial_delay_max_ms' => 4500, 'natural_silence_chance' => 10, 'natural_max_pending_turns' => 4 ) ); $room = $rooms->find( $this->room );
		$quiet = $agents->find( $this->agents[0] ); $this->assert_same( 0, $quiet['natural_participation_chance'], 'Quiet custom participation did not persist.' ); $this->assert_same( 0, $quiet['natural_max_auto_responses_per_10m'], 'Quiet frequency limit did not persist.' ); $this->assert_same( 'quiet', $quiet['natural_conversation_role'], 'Quiet role did not persist.' ); $facilitator = $agents->find( $this->agents[2] ); $this->assert_same( 90, $facilitator['natural_question_tendency'], 'Facilitator tendency did not persist.' ); $this->assert_same( 'facilitator', $facilitator['natural_conversation_role'], 'Facilitator role did not persist.' );
		$min_random = static fn( int $min, int $max ): int => $min; $max_random = static fn( int $min, int $max ): int => $max;
		$one = ( new \ACL\AgentRooms\Services\NaturalConversationDirector( null, $rooms, $min_random, static fn():int => 1700000000 ) )->plan( $room, $rows ); $this->assert_same( 1, count( $one['targets'] ), 'Ordinary natural message could not select one responder.' ); $this->assert_true( ! in_array( $this->agents[0], array_map( static fn($a)=>(int)$a['id'], $one['targets'] ), true ), 'Quiet zero-chance agent was selected automatically.' );
		$two = ( new \ACL\AgentRooms\Services\NaturalConversationDirector( null, $rooms, $max_random, static fn():int => 1700000000 ) )->plan( $room, $rows ); $this->assert_same( 2, count( $two['targets'] ), 'Ordinary natural message could not select two responders.' ); $this->assert_same( count( array_unique( array_map( static fn($a)=>(int)$a['id'], $two['targets'] ) ) ), count( $two['targets'] ), 'Director selected a duplicate responder.' );
		$silent_room = $room; $silent_room['natural_allow_silence'] = true; $silent_room['natural_silence_chance'] = 100; $silent = ( new \ACL\AgentRooms\Services\NaturalConversationDirector( null, $rooms, $min_random ) )->plan( $silent_room, $rows ); $this->assert_true( $silent['silent'] && 0 === count( $silent['targets'] ), 'Enabled silence did not produce a silent turn.' ); $silent_room['natural_allow_silence'] = false; $not_silent = ( new \ACL\AgentRooms\Services\NaturalConversationDirector( null, $rooms, $min_random ) )->plan( $silent_room, $rows ); $this->assert_true( count( $not_silent['targets'] ) >= 1, 'Silence occurred while disabled.' );
		$forced = ( new \ACL\AgentRooms\Services\NaturalConversationDirector( null, $rooms, $min_random ) )->plan( $room, $rows, array( $this->agents[0] ), false ); $this->assert_same( array( $this->agents[0] ), array_map( static fn($a)=>(int)$a['id'], $forced['targets'] ), 'Direct mention did not force the quiet agent.' ); $all = ( new \ACL\AgentRooms\Services\NaturalConversationDirector( null, $rooms, $min_random ) )->plan( $room, $rows, $this->agents, false ); $this->assert_same( $this->agents, array_map( static fn($a)=>(int)$a['id'], $all['targets'] ), '/ask did not force every eligible target.' );
		$wpdb->update( $wpdb->prefix . 'acl_ar_room_agents', array( 'participation_state' => 'paused' ), array( 'room_id' => $this->room, 'agent_id' => $this->agents[0] ) ); $this->assert_same( 'paused', (string) $rooms->get_assignment( $this->room, $this->agents[0] )['participation_state'], 'Paused fixture did not persist.' ); $paused = ( new \ACL\AgentRooms\Services\NaturalConversationDirector( null, $rooms, $min_random ) )->plan( $room, $rows, array( $this->agents[0] ), false ); $this->assert_same( 0, count( $paused['targets'] ), 'Paused forced agent was selected.' ); $wpdb->update( $wpdb->prefix . 'acl_ar_room_agents', array( 'participation_state' => 'active', 'auto_muted' => 0 ), array( 'room_id' => $this->room, 'agent_id' => $this->agents[0] ) );
		$wpdb->update( $wpdb->prefix . 'acl_ar_room_agents', array( 'auto_muted' => 1 ), array( 'room_id' => $this->room, 'agent_id' => $this->agents[1] ) ); $muted = ( new \ACL\AgentRooms\Services\NaturalConversationDirector( null, $rooms, $min_random ) )->plan( $room, $rooms->get_agents( $this->room ) ); $this->assert_true( ! in_array( $this->agents[1], array_map( static fn($a)=>(int)$a['id'], $muted['targets'] ), true ), 'Auto-muted agent was selected automatically.' ); $wpdb->update( $wpdb->prefix . 'acl_ar_room_agents', array( 'auto_muted' => 0 ), array( 'room_id' => $this->room, 'agent_id' => $this->agents[1] ) );
	}

	private function parser_prompt_and_timing(): void {
		$parser = new \ACL\AgentRooms\Services\BrainResponseParser(); $ids = $this->agents; $valid_items = array(); foreach ( $ids as $index => $id ) { $valid_items[] = array( 'agent_id' => $id, 'content' => 'Natural ' . $id, 'purpose' => 1 === $index ? 'steer' : 'reply' ); } $valid = wp_json_encode( array( 'turns' => $valid_items ) ); $parsed = $parser->parse( $valid, $ids, true ); $this->assert_same( 3, count( $parsed ), 'Valid natural response was rejected.' ); $this->assert_same( 'steer', $parsed[1]['purpose'], 'Natural purpose was not retained.' );
		$unknown = $valid_items; $unknown[2]['agent_id'] = 999999; $this->assert_wp_error( $parser->parse( wp_json_encode( array( 'turns' => $unknown ) ), $ids, true ), 'Unknown natural agent was accepted.' ); $missing = $valid_items; array_pop( $missing ); $this->assert_wp_error( $parser->parse( wp_json_encode( array( 'turns' => $missing ) ), $ids, true ), 'Missing natural response was accepted.' ); $duplicate = $valid_items; $duplicate[1]['agent_id'] = $ids[0]; $this->assert_wp_error( $parser->parse( wp_json_encode( array( 'turns' => $duplicate ) ), $ids, true ), 'Duplicate natural response was accepted.' ); $delay = $valid_items; $delay[0]['delay_ms'] = 5000; $this->assert_wp_error( $parser->parse( wp_json_encode( array( 'turns' => $delay ) ), $ids, true ), 'Model delay field was accepted.' ); $steers = $valid_items; $steers[0]['purpose'] = 'steer'; $this->assert_wp_error( $parser->parse( wp_json_encode( array( 'turns' => $steers ) ), $ids, true ), 'Multiple steering turns were accepted.' ); $reordered = array( $valid_items[1], $valid_items[0], $valid_items[2] ); $this->assert_wp_error( $parser->parse( wp_json_encode( array( 'turns' => $reordered ) ), $ids, true ), 'Model changed server speaking order.' );
		$rooms = new \ACL\AgentRooms\Repositories\RoomRepository(); $agent_repo = new \ACL\AgentRooms\Repositories\AgentRepository(); $room = $rooms->find( $this->room ); $rows = array_map( static fn($id)=>(new \ACL\AgentRooms\Repositories\AgentRepository())->find($id), $ids ); $trigger = array( 'id' => 999, 'legacy_message_id' => 0, 'content' => 'NATURAL-TRIGGER-ONCE' ); $brain = ( new \ACL\AgentRooms\Repositories\BrainRepository() )->find( $this->brain ); $request = ( new \ACL\AgentRooms\Services\BrainPromptBuilder() )->build_request( $room, $brain, $trigger, $rows ); $prompt = $request['system_prompt']; $this->assert_same( 1, substr_count( $prompt, 'NATURAL-TRIGGER-ONCE' ), 'Natural trigger was not included once.' ); $this->assert_same( 1, substr_count( $prompt, 'NATURAL-ROOM-CONTEXT' ), 'Natural room context was not included once.' ); foreach ( array( 'QUIET','BALANCED','FACILITATOR' ) as $name ) { $this->assert_same( 1, substr_count( $prompt, 'PERSONA-' . $name ), 'Natural persona was not included once.' ); } $this->assert_true( false !== strpos( $prompt, 'distinct contribution' ) && false !== strpos( $prompt, 'Never mention orchestration' ) && false !== strpos( $prompt, '"turns"' ), 'Natural anti-repetition prompt contract missing.' );
		$clock = static fn():int => 1700000000; $random = static fn(int $min,int $max):int => $min; $schedule = ( new \ACL\AgentRooms\Services\NaturalDelayCalculator( $random, $clock ) )->schedule( $room, $rows ); $due = array_map( static fn($turn)=>strtotime($turn['due_at'].' UTC'), $schedule ); $this->assert_same( 3, count( $due ), 'Delay calculator lost a selected agent.' ); $this->assert_true( $due[0] >= 1700000002 && $due[0] <= 1700000005, 'First due time ignored room bounds.' ); $this->assert_true( $due[1] > $due[0] && $due[2] > $due[1], 'Due times do not preserve order.' ); $this->assert_same( 3, count( array_unique( $due ) ), 'Duplicate due times were created.' ); $this->assert_true( $due[2] <= 1700000120, 'Total delay exceeded safe bound.' ); $override = $rows[0]; $override['natural_delay_min_ms'] = 9000; $override['natural_delay_max_ms'] = 9000; $override_schedule = ( new \ACL\AgentRooms\Services\NaturalDelayCalculator( $random, $clock ) )->schedule( $room, array( $override ) ); $this->assert_same( 1700000009, strtotime( $override_schedule[0]['due_at'] . ' UTC' ), 'Agent delay override was ignored.' );
	}

	private function brain_scheduling_and_supersession(): void {
		global $wpdb; $messages = new \ACL\AgentRooms\Repositories\MessageRepository(); $events = new \ACL\AgentRooms\Services\RoomEventService(); $rooms = new \ACL\AgentRooms\Repositories\RoomRepository(); $agent_rows = $rooms->get_agents( $this->room );
		$message_id = $messages->create( array( 'room_id' => $this->room, 'sender_type' => 'user', 'sender_user_id' => $this->owner, 'content' => 'Hey, how is everyone?', 'client_request_id' => $this->prefix . '-brain-trigger', 'metadata' => array( 'brain_trigger_mode' => 'explicit' ) ) ); $trigger = $events->create_message_event( $messages->find( (int) $message_id ) ); $room = $rooms->find( $this->room ); ( new \ACL\AgentRooms\Services\ConversationTurnService() )->activate_trigger( $room, (int) $trigger['id'] );
		$director = new \ACL\AgentRooms\Services\NaturalConversationDirector( null, $rooms, static fn(int $min,int $max):int=>$min, static fn():int=>1700000000 ); $plan = $director->plan( $room, $agent_rows, $this->agents, false ); $fake = new ACL_AR_NaturalFakeSwitchboard(); $runs = new \ACL\AgentRooms\Repositories\BrainRunRepository(); $runtime = new \ACL\AgentRooms\Services\BrainRuntime( $runs, null, null, null, null, null, null, null, $fake ); $result = ( new \ACL\AgentRooms\Services\BrainRunService( null, $runs, $runtime ) )->create_for_targets( $room, $trigger, $plan['targets'], true, $plan['turns'] );
		$this->assert_same( 1, $fake->calls, 'Natural Shared Brain used more than one provider call.' ); $this->assert_same( 1, count( $result['brain_runs'] ), 'Natural Shared Brain did not create one run.' ); $run = $result['brain_runs'][0]['run']; $this->assert_same( 'response_saved', $run['status'], 'Natural Brain completed before scheduled publication.' ); $run_id = (int) $run['id']; $turn_repo = new \ACL\AgentRooms\Repositories\ConversationTurnRepository(); $turns = $turn_repo->for_brain_run( $run_id ); $this->turn_ids = array_merge( $this->turn_ids, array_map( static fn($turn)=>(int)$turn['id'], $turns ) ); $this->assert_same( 3, count( $turns ), 'Natural Shared Brain did not create three scheduled turns.' ); $this->assert_same( 0, (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}acl_ar_agent_jobs WHERE room_id=%d AND trigger_message_id=%d", $this->room, $message_id ) ), 'Natural Shared Brain created individual jobs.' ); $this->assert_same( 0, (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}acl_ar_messages WHERE brain_run_id=%d", $run_id ) ), 'Pending Brain content published early.' ); $this->assert_same( 1, (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}acl_ar_usage WHERE brain_run_id=%d", $run_id ) ), 'Natural Brain usage was not recorded once.' ); $this->assert_same( 0, (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}acl_ar_events WHERE room_id=%d AND event_type IN ('agent_queued','agent_thinking','agent_responding','agent_completed','agent_failed') AND id>%d", $this->room, (int) $trigger['id'] ) ), 'Natural Shared Brain created lifecycle chains.' ); $this->assert_true( '' !== (string) $turns[0]['content'], 'Validated Brain content was not stored.' );
		$rebased_due = array_map( static fn( $turn ) => strtotime( (string) $turn['due_at'] . ' UTC' ), $turns );
		$this->assert_true( $rebased_due[0] > time(), 'Provider latency consumed the first response-ready delay.' );
		$this->assert_true( $rebased_due[1] > $rebased_due[0] && $rebased_due[2] > $rebased_due[1], 'Response-ready turn times lost their configured speaker order.' );
		$this->assert_same( 3, count( array_unique( $rebased_due ) ), 'Response-ready turn times collapsed into one publication instant.' );
		if ( ! function_exists( 'as_schedule_single_action' ) ) {
			foreach ( $turns as $index => $turn ) {
				$this->assert_same( $rebased_due[ $index ], wp_next_scheduled( \ACL\AgentRooms\Services\QueueService::TURN_HOOK, array( (int) $turn['id'] ) ), 'Stale pre-response WordPress cron schedule was not replaced.' );
			}
		}
		$wpdb->update( $wpdb->prefix . 'acl_ar_conversation_turns', array( 'typing_at' => gmdate( 'Y-m-d H:i:s', time() - 2 ), 'due_at' => gmdate( 'Y-m-d H:i:s', time() + 20 ) ), array( 'id' => (int) $turns[0]['id'] ) ); ( new \ACL\AgentRooms\Services\ConversationTurnService() )->mark_typing( (int) $turns[0]['id'] ); $presence = ( new \ACL\AgentRooms\Repositories\PresenceRepository() )->find( $this->room, 'agent', (int) $turns[0]['agent_id'] ); $this->assert_same( 'typing', (string) $presence['state'], 'Due agent did not enter typing state.' ); $later_presence = ( new \ACL\AgentRooms\Repositories\PresenceRepository() )->find( $this->room, 'agent', (int) $turns[1]['agent_id'] ); $this->assert_true( ! $later_presence || 'typing' !== (string) $later_presence['state'], 'Later agent typed too early.' );
		$wpdb->update( $wpdb->prefix . 'acl_ar_conversation_turns', array( 'due_at' => gmdate( 'Y-m-d H:i:s', time() - 1 ) ), array( 'id' => (int) $turns[0]['id'] ) ); $published = ( new \ACL\AgentRooms\Services\ConversationTurnService() )->publish( (int) $turns[0]['id'] ); $this->assert_same( 'published', $published['status'], 'Scheduled Brain turn did not publish once.' ); $this->assert_true( (int) $published['published_event_id'] > 0, 'Published Brain turn lacks event ID.' ); $this->assert_same( 1, (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}acl_ar_messages WHERE brain_run_id=%d", $run_id ) ), 'Brain publication did not create exactly one message.' ); $this->assert_same( 1, $fake->calls, 'Brain publication made a second provider call.' ); $again = ( new \ACL\AgentRooms\Services\ConversationTurnService() )->publish( (int) $turns[0]['id'] ); $this->assert_same( 'published', $again['status'], 'Duplicate worker changed published state.' ); $this->assert_same( 1, (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}acl_ar_messages WHERE brain_run_id=%d", $run_id ) ), 'Duplicate worker republished the Brain turn.' );
		$new_message = $messages->create( array( 'room_id' => $this->room, 'sender_type' => 'user', 'sender_user_id' => $this->owner, 'content' => 'Let us discuss something else.', 'client_request_id' => $this->prefix . '-supersede' ) ); $new_trigger = $events->create_message_event( $messages->find( (int) $new_message ) ); $canceled = ( new \ACL\AgentRooms\Services\ConversationTurnService() )->activate_trigger( $rooms->find( $this->room ), (int) $new_trigger['id'] ); $this->assert_same( 2, $canceled, 'New message did not cancel both stale unpublished turns.' ); $fresh_turns = $turn_repo->for_brain_run( $run_id ); $fresh_by_id = array(); foreach ( $fresh_turns as $fresh_turn ) { $fresh_by_id[ (int) $fresh_turn['id'] ] = $fresh_turn; } $published_fresh = $fresh_by_id[ (int) $turns[0]['id'] ]; $canceled_fresh = $fresh_by_id[ (int) $turns[1]['id'] ]; $this->assert_same( 'published', $published_fresh['status'], 'Supersession removed a published turn.' ); $this->assert_same( 'canceled', $canceled_fresh['status'], 'Superseded turn was not canceled.' ); $this->assert_same( 'superseded', $canceled_fresh['cancel_reason'], 'Supersession reason is unsafe or missing.' ); $this->assert_same( null, $canceled_fresh['content'], 'Superseded content was retained.' ); $this->assert_same( 'completed', $runs->find( $run_id )['status'], 'Partially published Brain run did not complete.' ); $this->assert_same( 1, (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}acl_ar_events WHERE room_id=%d AND event_type='brain_run' AND target_id=%d AND id>%d", $this->room, $this->brain, (int) $trigger['id'] ) ), 'Natural Brain emitted the wrong terminal event count.' ); $this->assert_same( 1, $fake->calls, 'Supersession made another provider call.' );
	}

	private function contract_violation_retry_preserves_turns(): void {
		global $wpdb;
		$messages = new \ACL\AgentRooms\Repositories\MessageRepository();
		$events   = new \ACL\AgentRooms\Services\RoomEventService();
		$rooms    = new \ACL\AgentRooms\Repositories\RoomRepository();
		$room     = $rooms->find( $this->room );
		$message_id = $messages->create(
			array(
				'room_id'           => $this->room,
				'sender_type'       => 'user',
				'sender_user_id'    => $this->owner,
				'content'           => 'Natural contract retry trigger',
				'client_request_id' => $this->prefix . '-natural-contract-retry',
			)
		);
		$trigger = $events->create_message_event( $messages->find( (int) $message_id ) );
		( new \ACL\AgentRooms\Services\ConversationTurnService() )->activate_trigger( $room, (int) $trigger['id'] );
		$room = $rooms->find( $this->room );
		$plan = ( new \ACL\AgentRooms\Services\NaturalConversationDirector( null, $rooms, static fn( int $min, int $max ): int => $min ) )->plan( $room, $rooms->get_agents( $this->room ), $this->agents, false );
		$fake = new ACL_AR_NaturalFakeSwitchboard();
		$fake->contract_failures_remaining = 1;
		$fake->content_prefix = 'Contract recovered response ';
		$runs = new \ACL\AgentRooms\Repositories\BrainRunRepository();
		$runtime = new \ACL\AgentRooms\Services\BrainRuntime( $runs, null, null, null, null, null, null, null, $fake );
		$result = ( new \ACL\AgentRooms\Services\BrainRunService( null, $runs, $runtime ) )->create_for_targets( $room, $trigger, $plan['targets'], true, $plan['turns'] );
		$run_id = (int) $result['brain_runs'][0]['run']['id'];
		$failed = $runs->find( $run_id );
		$this->assert_same( 'failed', $failed['status'], 'Natural contract failure did not enter recoverable failed state.' );
		$this->assert_same( 'acl_ar_brain_response_missing_agent', $failed['error_code'], 'Natural contract failure lost the recoverable completeness code.' );
		$this->assert_true( ! empty( $failed['next_attempt_at'] ), 'Natural contract failure was made terminal.' );
		$turn_repo = new \ACL\AgentRooms\Repositories\ConversationTurnRepository();
		$turns = $turn_repo->for_brain_run( $run_id );
		$this->turn_ids = array_merge( $this->turn_ids, array_map( static fn( $turn ) => (int) $turn['id'], $turns ) );
		foreach ( $turns as $turn ) {
			$this->assert_same( 'pending', $turn['status'], 'Natural contract failure terminated a scheduled turn.' );
			$this->assert_same( null, $turn['content'], 'Natural contract failure persisted unvalidated content.' );
		}
		$recovered = $runtime->run( $run_id, true );
		$this->assert_true( ! is_wp_error( $recovered ), 'Natural guided contract retry did not recover.' );
		$this->assert_same( 'response_saved', $runs->find( $run_id )['status'], 'Natural guided retry did not save the validated response.' );
		$this->assert_same( 2, $fake->calls, 'Natural guided retry used the wrong provider request count.' );
		$this->assert_true( false !== strpos( $fake->requests[1]['system_prompt'], 'BEGIN RESPONSE CONTRACT CORRECTION' ), 'Natural retry prompt omitted its correction boundary.' );
		$this->assert_true( false !== strpos( $fake->requests[1]['system_prompt'], 'rooted at "turns"' ), 'Natural retry prompt omitted the turns contract.' );
		$worker = new \ACL\AgentRooms\Services\ConversationTurnService();
		foreach ( $turn_repo->for_brain_run( $run_id ) as $turn ) {
			$wpdb->update( $wpdb->prefix . 'acl_ar_conversation_turns', array( 'due_at' => gmdate( 'Y-m-d H:i:s', time() - 1 ) ), array( 'id' => (int) $turn['id'] ) );
			$published = $worker->publish( (int) $turn['id'] );
			$this->assert_same( 'published', $published['status'], 'Natural recovered turn did not publish.' );
		}
		$this->assert_same( 'completed', $runs->find( $run_id )['status'], 'Natural recovered Brain run did not complete.' );
		$this->assert_same( count( $turns ), (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}acl_ar_messages WHERE brain_run_id=%d", $run_id ) ), 'Natural guided retry did not publish exactly once.' );
		$this->assert_same( 0, (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}acl_ar_conversation_turns WHERE brain_run_id=%d AND status IN ('pending','typing','publishing','failed')", $run_id ) ), 'Natural guided retry left stale work.' );
		wp_clear_scheduled_hook( \ACL\AgentRooms\Services\QueueService::BRAIN_HOOK, array( $run_id ) );
	}

	private function independent_publication(): void {
		global $wpdb; $agent_repo = new \ACL\AgentRooms\Repositories\AgentRepository(); $agent = $agent_repo->find( $this->agents[1] ); $agent['execution_mode'] = 'independent'; $agent['brain_id'] = null; $agent_repo->update( $this->agents[1], $agent ); $rooms = new \ACL\AgentRooms\Repositories\RoomRepository(); $room = $rooms->find( $this->room ); $messages = new \ACL\AgentRooms\Repositories\MessageRepository(); $events = new \ACL\AgentRooms\Services\RoomEventService(); $message_id = $messages->create( array( 'room_id' => $this->room, 'sender_type' => 'user', 'sender_user_id' => $this->owner, 'content' => 'Independent trigger', 'client_request_id' => $this->prefix . '-independent' ) ); $trigger = $events->create_message_event( $messages->find( (int) $message_id ) ); ( new \ACL\AgentRooms\Services\ConversationTurnService() )->activate_trigger( $room, (int) $trigger['id'] );
		$job_repo = new \ACL\AgentRooms\Repositories\JobRepository(); $job_id = $job_repo->create( $this->room, (int) $message_id, $this->agents[1], \ACL\AgentRooms\Repositories\JobRepository::request_key( 'natural-test', $this->room, (int) $message_id, $this->agents[1] ) ); $plan = array( 'agent' => $agent_repo->find( $this->agents[1] ), 'purpose' => 'reply', 'due_at' => gmdate( 'Y-m-d H:i:s', time() - 1 ), 'typing_at' => gmdate( 'Y-m-d H:i:s', time() - 2 ) ); $job_repo->schedule( (int) $job_id, $plan['due_at'] ); $turn = ( new \ACL\AgentRooms\Services\ConversationTurnService() )->create( $room, $trigger, $plan, 'independent', 0, (int) $job_id ); $this->turn_ids[] = (int) $turn['id']; $this->assert_true( is_array( $turn ) && 'pending' === $turn['status'], 'Independent scheduled turn was not created.' );
		$fake = new ACL_AR_NaturalFakeSwitchboard(); $filter = static fn($client,$request) => $fake; add_filter( 'acl_ar_switchboard_client', $filter, 10, 2 ); try { $result = ( new \ACL\AgentRooms\Services\ConversationTurnService() )->publish( (int) $turn['id'] ); } finally { remove_filter( 'acl_ar_switchboard_client', $filter, 10 ); }
		$this->assert_same( 1, $fake->calls, 'Selected Independent agent did not use exactly one request.' ); $this->assert_same( 'published', $result['status'], 'Independent scheduled turn did not publish.' ); $job = $job_repo->find( (int) $job_id ); $this->assert_same( 'completed', $job['status'], 'Independent scheduled job did not complete.' ); $this->assert_true( (int) $job['response_message_id'] > 0, 'Independent job lacks response message.' ); $this->assert_same( 1, (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}acl_ar_agent_jobs WHERE id=%d", $job_id ) ), 'Independent selection created duplicate jobs.' ); $this->assert_same( 1, (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}acl_ar_usage WHERE room_id=%d AND agent_id=%d AND created_at>=%s", $this->room, $this->agents[1], gmdate( 'Y-m-d H:i:s', time() - 60 ) ) ), 'Independent usage was not recorded once.' ); $this->assert_same( 'published', ( new \ACL\AgentRooms\Repositories\ConversationTurnRepository() )->find( (int) $turn['id'] )['status'], 'Independent turn state did not converge.' );
	}

	private function independent_turn_owner_fence(): void {
		global $wpdb;
		$rooms    = new \ACL\AgentRooms\Repositories\RoomRepository();
		$agents   = new \ACL\AgentRooms\Repositories\AgentRepository();
		$messages = new \ACL\AgentRooms\Repositories\MessageRepository();
		$events   = new \ACL\AgentRooms\Services\RoomEventService();
		$jobs     = new \ACL\AgentRooms\Repositories\JobRepository();
		$turns    = new \ACL\AgentRooms\Repositories\ConversationTurnRepository();
		$service  = new \ACL\AgentRooms\Services\ConversationTurnService();
		$message_id = $messages->create( array( 'room_id' => $this->room, 'sender_type' => 'user', 'sender_user_id' => $this->owner, 'content' => 'Natural turn owner fence', 'client_request_id' => $this->prefix . '-turn-owner-fence' ) );
		$trigger = $events->create_message_event( $messages->find( (int) $message_id ) );
		$service->activate_trigger( $rooms->find( $this->room ), (int) $trigger['id'] );
		$room = $rooms->find( $this->room );
		$due  = gmdate( 'Y-m-d H:i:s', time() - 1 );
		$job_id = (int) $jobs->create( $this->room, (int) $message_id, $this->agents[1], \ACL\AgentRooms\Repositories\JobRepository::request_key( 'natural-turn-owner', $this->room, (int) $message_id, $this->agents[1] ) );
		$jobs->schedule( $job_id, $due );
		$plan = array( 'agent' => $agents->find( $this->agents[1] ), 'purpose' => 'reply', 'due_at' => $due, 'typing_at' => $due );
		$turn = $service->create( $room, $trigger, $plan, 'independent', 0, $job_id );
		$turn_id = (int) $turn['id'];
		$this->turn_ids[] = $turn_id;
		$this->assert_true( $turns->acquire( $turn_id ), 'Natural owner test could not acquire its turn.' );

		$fake = new ACL_AR_NaturalFakeSwitchboard();
		$external = ( new \ACL\AgentRooms\Services\AgentRuntime( $jobs, $rooms, $agents, $messages, null, $fake ) )->run_job( $job_id );
		$this->assert_wp_error( $external, 'External worker was allowed to run a publishing Natural job.' );
		$this->assert_same( 'acl_ar_natural_turn_owner_required', $external->get_error_code(), 'External Natural job worker returned the wrong ownership error.' );
		$this->assert_same( 0, $fake->calls, 'External Natural job worker reached the provider.' );
		$this->assert_same( 'pending', $jobs->find( $job_id )['status'], 'External Natural job worker acquired the durable job.' );
		$this->assert_same( 'publishing', $turns->find( $turn_id )['status'], 'External Natural job worker changed the owning turn.' );

		$wpdb->update( $wpdb->prefix . 'acl_ar_conversation_turns', array( 'status' => 'pending', 'due_at' => $due, 'typing_at' => $due, 'updated_at' => $due ), array( 'id' => $turn_id ) );
		$this->assert_true( $jobs->acquire( $job_id, 'natural-preexisting-owner', 120 ), 'Natural running-job fixture did not acquire its lease.' );
		$filter = static fn( $client, $request ) => $fake;
		add_filter( 'acl_ar_switchboard_client', $filter, 10, 2 );
		try {
			$locked = $service->publish( $turn_id );
			$this->assert_wp_error( $locked, 'Authorized replacement turn did not observe the running job.' );
			$this->assert_same( 0, $fake->calls, 'Replacement turn called the provider while the durable job was running.' );
			$this->assert_same( 'running', $jobs->find( $job_id )['status'], 'Replacement turn changed the running durable job.' );
			$this->assert_same( 'pending', $turns->find( $turn_id )['status'], 'Replacement turn terminalized a recoverable running job.' );
			$this->assert_same( 0, (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}acl_ar_events WHERE job_id=%d AND event_type='agent_failed'", $job_id ) ), 'Replacement turn emitted a terminal failure for running work.' );

			$wpdb->update( $wpdb->prefix . 'acl_ar_agent_jobs', array( 'lease_expires_at' => gmdate( 'Y-m-d H:i:s', time() - 1 ) ), array( 'id' => $job_id ) );
			$wpdb->update( $wpdb->prefix . 'acl_ar_conversation_turns', array( 'status' => 'pending', 'due_at' => $due, 'typing_at' => $due, 'updated_at' => $due ), array( 'id' => $turn_id ) );
			$published = $service->publish( $turn_id );
			$this->assert_same( 'published', $published['status'], 'Authorized Natural turn did not recover after the stale job lease.' );
			$this->assert_same( 1, $fake->calls, 'Authorized Natural turn used an unexpected provider count.' );
			$this->assert_same( 'completed', $jobs->find( $job_id )['status'], 'Authorized Natural turn did not complete its durable job.' );
			$this->assert_same( 1, (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}acl_ar_messages WHERE response_job_id=%d", $job_id ) ), 'Authorized Natural turn did not publish exactly one message.' );
			$this->assert_same( 1, (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}acl_ar_events WHERE job_id=%d AND event_type='message'", $job_id ) ), 'Authorized Natural turn did not publish exactly one message event.' );
			$this->assert_same( 0, (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}acl_ar_events WHERE job_id=%d AND event_type='agent_failed'", $job_id ) ), 'Authorized Natural recovery emitted a failure event.' );
			( new \ACL\AgentRooms\Services\QueueService() )->run_single( $job_id );
		} finally {
			remove_filter( 'acl_ar_switchboard_client', $filter, 10 );
		}
		$this->assert_same( 1, $fake->calls, 'A legacy Natural SINGLE action reached the provider.' );
		$this->assert_same( 'published', $turns->find( $turn_id )['status'], 'A legacy Natural SINGLE action changed the published turn.' );
		$this->assert_same( 1, (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}acl_ar_messages WHERE response_job_id=%d", $job_id ) ), 'A legacy Natural SINGLE action duplicated the message.' );
	}

	private function independent_persistence_serialization(): void {
		global $wpdb;
		$rooms    = new \ACL\AgentRooms\Repositories\RoomRepository();
		$agents   = new \ACL\AgentRooms\Repositories\AgentRepository();
		$messages = new \ACL\AgentRooms\Repositories\MessageRepository();
		$events   = new \ACL\AgentRooms\Services\RoomEventService();
		$jobs     = new \ACL\AgentRooms\Repositories\JobRepository();
		$turns    = new \ACL\AgentRooms\Repositories\ConversationTurnRepository();
		$service  = new \ACL\AgentRooms\Services\ConversationTurnService();
		$due      = gmdate( 'Y-m-d H:i:s', time() - 1 );

		$trigger_message_id = (int) $messages->create(
			array(
				'room_id'           => $this->room,
				'sender_type'       => 'user',
				'sender_user_id'    => $this->owner,
				'content'           => 'Natural response loses to a newer committed human trigger',
				'client_request_id' => $this->prefix . '-serialized-old-trigger',
			)
		);
		$trigger = $events->create_message_event( $messages->find( $trigger_message_id ) );
		$service->activate_trigger( $rooms->find( $this->room ), (int) $trigger['id'] );
		$job_id = (int) $jobs->create( $this->room, $trigger_message_id, $this->agents[1], \ACL\AgentRooms\Repositories\JobRepository::request_key( 'natural-serialized-old', $this->room, $trigger_message_id, $this->agents[1] ) );
		$jobs->schedule( $job_id, $due );
		$plan = array( 'agent' => $agents->find( $this->agents[1] ), 'purpose' => 'reply', 'due_at' => $due, 'typing_at' => $due );
		$turn = $service->create( $rooms->find( $this->room ), $trigger, $plan, 'independent', 0, $job_id );
		$this->turn_ids[] = (int) $turn['id'];
		$usage_before     = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}acl_ar_usage WHERE room_id=%d AND agent_id=%d", $this->room, $this->agents[1] ) );
		$new_human        = null;
		$fake             = new ACL_AR_NaturalFakeSwitchboard();
		$fake->during_send = function () use ( &$new_human ): void {
			$new_human = ( new \ACL\AgentRooms\Services\HumanMessageService() )->persist(
				$this->room,
				$this->owner,
				'New human boundary committed before controller activation',
				$this->prefix . '-serialized-new-boundary',
				array( 'brain_trigger_mode' => 'automatic' ),
				true
			);
		};
		$filter = static fn( $client, $request ) => $fake;
		add_filter( 'acl_ar_switchboard_client', $filter, 10, 2 );
		try {
			$discarded = $service->publish( (int) $turn['id'] );
		} finally {
			remove_filter( 'acl_ar_switchboard_client', $filter, 10 );
		}
		$this->assert_wp_error( $discarded, 'A superseded Natural response was accepted.' );
		$this->assert_same( 'acl_ar_trigger_superseded', $discarded->get_error_code(), 'Superseded Natural persistence returned the wrong error.' );
		$this->assert_true( is_array( $new_human ) && ! empty( $new_human['event']['id'] ), 'The interleaved human trigger was not committed.' );
		$this->assert_same( (int) $new_human['event']['id'], (int) $rooms->find( $this->room )['natural_active_trigger_event_id'], 'Human commit did not advance the durable Natural boundary atomically.' );
		$service->activate_trigger( $rooms->find( $this->room ), (int) $new_human['event']['id'] );
		$this->assert_same( 1, $fake->calls, 'Supersession seam used an unexpected provider count.' );
		$this->assert_same( 'canceled', $jobs->find( $job_id )['status'], 'Superseded Natural job was not canceled.' );
		$this->assert_same( 'canceled', $turns->find( (int) $turn['id'] )['status'], 'Superseded Natural turn was rewritten as a failure.' );
		$this->assert_same( 'superseded', $turns->find( (int) $turn['id'] )['cancel_reason'], 'Superseded Natural turn lost its cancel reason.' );
		$this->assert_same( 0, (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}acl_ar_messages WHERE response_job_id=%d", $job_id ) ), 'Superseded Natural worker persisted a late assistant message.' );
		$this->assert_same( 0, (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}acl_ar_events WHERE job_id=%d AND event_type='message'", $job_id ) ), 'Superseded Natural worker persisted a late assistant event.' );
		$this->assert_same( 0, (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}acl_ar_events WHERE job_id=%d AND event_type='agent_failed'", $job_id ) ), 'Superseded Natural cancellation projected a terminal agent failure.' );
		$this->assert_same( $usage_before + 1, (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}acl_ar_usage WHERE room_id=%d AND agent_id=%d", $this->room, $this->agents[1] ) ), 'Discarded billable Natural response usage was not recorded exactly once.' );

		$winning_message_id = (int) $messages->create(
			array(
				'room_id'           => $this->room,
				'sender_type'       => 'user',
				'sender_user_id'    => $this->owner,
				'content'           => 'Natural response commits before the next human trigger',
				'client_request_id' => $this->prefix . '-serialized-winning-trigger',
			)
		);
		$winning_trigger = $events->create_message_event( $messages->find( $winning_message_id ) );
		$service->activate_trigger( $rooms->find( $this->room ), (int) $winning_trigger['id'] );
		$winning_job_id = (int) $jobs->create( $this->room, $winning_message_id, $this->agents[1], \ACL\AgentRooms\Repositories\JobRepository::request_key( 'natural-serialized-winning', $this->room, $winning_message_id, $this->agents[1] ) );
		$jobs->schedule( $winning_job_id, $due );
		$winning_turn = $service->create( $rooms->find( $this->room ), $winning_trigger, $plan, 'independent', 0, $winning_job_id );
		$this->turn_ids[] = (int) $winning_turn['id'];
		$winner_fake      = new ACL_AR_NaturalFakeSwitchboard();
		$winner_filter    = static fn( $client, $request ) => $winner_fake;
		add_filter( 'acl_ar_switchboard_client', $winner_filter, 10, 2 );
		try {
			$published = $service->publish( (int) $winning_turn['id'] );
		} finally {
			remove_filter( 'acl_ar_switchboard_client', $winner_filter, 10 );
		}
		$winning_job  = $jobs->find( $winning_job_id );
		$winning_turn = $turns->find( (int) $winning_turn['id'] );
		$this->assert_same( 'published', $published['status'], 'Room-first Natural worker did not publish.' );
		$this->assert_same( 'completed', $winning_job['status'], 'Room-first Natural worker did not complete its job.' );
		$this->assert_same( 'published', $winning_turn['status'], 'Natural turn publication was not atomic with job completion.' );
		$this->assert_true( (int) $winning_turn['published_event_id'] > 0, 'Atomic Natural publication lacks its message event.' );
		$later_human = ( new \ACL\AgentRooms\Services\HumanMessageService() )->persist(
			$this->room,
			$this->owner,
			'Human trigger committed after the assistant transaction',
			$this->prefix . '-serialized-after-worker',
			array( 'brain_trigger_mode' => 'automatic' ),
			true
		);
		$this->assert_true( is_array( $later_human ), 'Post-response human trigger was not committed.' );
		$service->activate_trigger( $rooms->find( $this->room ), (int) $later_human['event']['id'] );
		$this->assert_true( $jobs->cancel( $winning_job_id, 'superseded' ), 'Completed job cancellation was not idempotent.' );
		$this->assert_same( 'completed', $jobs->find( $winning_job_id )['status'], 'Late Natural cancellation rewrote a completed job.' );
		$this->assert_same( 'published', $turns->find( (int) $winning_turn['id'] )['status'], 'Late Natural activation rewrote a published turn.' );
		$this->assert_same( (int) $winning_turn['published_event_id'], (int) $turns->find( (int) $winning_turn['id'] )['published_event_id'], 'Late Natural activation changed the committed response event.' );
		$this->assert_true( (int) $winning_turn['published_event_id'] < (int) $later_human['event']['id'], 'Serialized Natural event order does not match the winning room lock.' );
		$this->assert_same( 1, (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}acl_ar_messages WHERE response_job_id=%d", $winning_job_id ) ), 'Serialized Natural winner did not retain exactly one assistant message.' );
	}

	private function existing_response_recovery_serialization(): void {
		global $wpdb;
		$rooms    = new \ACL\AgentRooms\Repositories\RoomRepository();
		$agents   = new \ACL\AgentRooms\Repositories\AgentRepository();
		$messages = new \ACL\AgentRooms\Repositories\MessageRepository();
		$events   = new \ACL\AgentRooms\Services\RoomEventService();
		$jobs     = new \ACL\AgentRooms\Repositories\JobRepository();
		$turns    = new \ACL\AgentRooms\Repositories\ConversationTurnRepository();
		$service  = new \ACL\AgentRooms\Services\ConversationTurnService();
		$due      = gmdate( 'Y-m-d H:i:s', time() - 1 );
		$plan     = array( 'agent' => $agents->find( $this->agents[1] ), 'purpose' => 'reply', 'due_at' => $due, 'typing_at' => $due );

		$trigger_message_id = (int) $messages->create( array( 'room_id' => $this->room, 'sender_type' => 'user', 'sender_user_id' => $this->owner, 'content' => 'Orphan response supersession trigger', 'client_request_id' => $this->prefix . '-orphan-superseded-trigger' ) );
		$trigger = $events->create_message_event( $messages->find( $trigger_message_id ) );
		$service->activate_trigger( $rooms->find( $this->room ), (int) $trigger['id'] );
		$job_id = (int) $jobs->create( $this->room, $trigger_message_id, $this->agents[1], \ACL\AgentRooms\Repositories\JobRepository::request_key( 'natural-orphan-superseded', $this->room, $trigger_message_id, $this->agents[1] ) );
		$jobs->schedule( $job_id, $due );
		$turn = $service->create( $rooms->find( $this->room ), $trigger, $plan, 'independent', 0, $job_id );
		$this->turn_ids[] = (int) $turn['id'];
		$this->assert_true( $turns->acquire( (int) $turn['id'] ), 'Superseded orphan recovery turn could not be acquired.' );
		$orphan_id = (int) $messages->create( array( 'room_id' => $this->room, 'sender_type' => 'agent', 'sender_agent_id' => $this->agents[1], 'content' => 'HIDDEN SUPERSEDED ORPHAN', 'response_job_id' => $job_id, 'provider_route' => 'controlled-fake', 'model' => 'natural-model-v1', 'prompt_tokens' => 4, 'completion_tokens' => 2, 'total_tokens' => 6 ) );
		$new_human = null;
		$recovery_messages = new ACL_AR_NaturalRecoveryMessageRepository();
		$recovery_messages->after_find = function () use ( &$new_human, $service, $rooms ): void {
			$new_human = ( new \ACL\AgentRooms\Services\HumanMessageService() )->persist( $this->room, $this->owner, 'New human supersedes orphan recovery', $this->prefix . '-orphan-new-boundary', array( 'brain_trigger_mode' => 'automatic' ), true );
			if ( is_array( $new_human ) ) {
				$service->activate_trigger( $rooms->find( $this->room ), (int) $new_human['event']['id'] );
			}
		};
		$fake = new ACL_AR_NaturalFakeSwitchboard();
		$recovery = ( new \ACL\AgentRooms\Services\AgentRuntime( $jobs, $rooms, $agents, $recovery_messages, null, $fake ) )->run_job( $job_id, false, (int) $turn['id'] );
		$this->assert_wp_error( $recovery, 'Superseded orphan response was recovered.' );
		$this->assert_same( 'acl_ar_trigger_superseded', $recovery->get_error_code(), 'Superseded orphan recovery returned the wrong error.' );
		$this->assert_same( 0, $fake->calls, 'Superseded orphan recovery called the provider.' );
		$this->assert_true( is_array( $new_human ) && ! empty( $new_human['event']['id'] ), 'Superseding human boundary was not committed.' );
		$this->assert_same( 'canceled', $jobs->find( $job_id )['status'], 'Superseded orphan recovery rewrote the canceled job.' );
		$this->assert_same( 'canceled', $turns->find( (int) $turn['id'] )['status'], 'Superseded orphan recovery rewrote the canceled turn.' );
		$this->assert_same( null, ( new \ACL\AgentRooms\Repositories\EventRepository() )->find_by_legacy_message_id( $orphan_id ), 'Superseded orphan recovery created a message event.' );
		$this->assert_same( 0, (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}acl_ar_events WHERE job_id=%d AND event_type='agent_failed'", $job_id ) ), 'Superseded orphan recovery projected a terminal failure.' );

		$valid_trigger_message_id = (int) $messages->create( array( 'room_id' => $this->room, 'sender_type' => 'user', 'sender_user_id' => $this->owner, 'content' => 'Valid orphan response recovery trigger', 'client_request_id' => $this->prefix . '-orphan-valid-trigger' ) );
		$valid_trigger = $events->create_message_event( $messages->find( $valid_trigger_message_id ) );
		$service->activate_trigger( $rooms->find( $this->room ), (int) $valid_trigger['id'] );
		$valid_job_id = (int) $jobs->create( $this->room, $valid_trigger_message_id, $this->agents[1], \ACL\AgentRooms\Repositories\JobRepository::request_key( 'natural-orphan-valid', $this->room, $valid_trigger_message_id, $this->agents[1] ) );
		$jobs->schedule( $valid_job_id, $due );
		$valid_turn = $service->create( $rooms->find( $this->room ), $valid_trigger, $plan, 'independent', 0, $valid_job_id );
		$this->turn_ids[] = (int) $valid_turn['id'];
		$this->assert_true( $turns->acquire( (int) $valid_turn['id'] ), 'Valid orphan recovery turn could not be acquired.' );
		$valid_orphan_id = (int) $messages->create( array( 'room_id' => $this->room, 'sender_type' => 'agent', 'sender_agent_id' => $this->agents[1], 'content' => 'VALID RECOVERED ORPHAN', 'response_job_id' => $valid_job_id, 'provider_route' => 'controlled-fake', 'model' => 'natural-model-v1', 'prompt_tokens' => 5, 'completion_tokens' => 3, 'total_tokens' => 8 ) );
		$valid_fake = new ACL_AR_NaturalFakeSwitchboard();
		$valid = ( new \ACL\AgentRooms\Services\AgentRuntime( $jobs, $rooms, $agents, new ACL_AR_NaturalRecoveryMessageRepository(), null, $valid_fake ) )->run_job( $valid_job_id, false, (int) $valid_turn['id'] );
		$valid_job  = $jobs->find( $valid_job_id );
		$valid_turn = $turns->find( (int) $valid_turn['id'] );
		$valid_event = ( new \ACL\AgentRooms\Repositories\EventRepository() )->find_by_legacy_message_id( $valid_orphan_id );
		$this->assert_same( 0, $valid_fake->calls, 'Valid orphan recovery called the provider.' );
		$this->assert_same( 'completed', $valid['status'], 'Valid orphan recovery did not complete its job.' );
		$this->assert_same( 'completed', $valid_job['status'], 'Recovered orphan job did not remain completed.' );
		$this->assert_same( $valid_orphan_id, (int) $valid_job['response_message_id'], 'Recovered orphan job points to the wrong message.' );
		$this->assert_same( 'published', $valid_turn['status'], 'Recovered orphan Natural turn was not published atomically.' );
		$this->assert_same( (int) $valid_event['id'], (int) $valid_turn['published_event_id'], 'Recovered orphan turn points to the wrong event.' );
		$this->assert_same( 1, (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}acl_ar_events WHERE legacy_message_id=%d", $valid_orphan_id ) ), 'Valid orphan recovery duplicated its message event.' );
	}

	private function retryable_independent_publication(): void {
		global $wpdb;
		$rooms      = new \ACL\AgentRooms\Repositories\RoomRepository();
		$agents     = new \ACL\AgentRooms\Repositories\AgentRepository();
		$messages   = new \ACL\AgentRooms\Repositories\MessageRepository();
		$events     = new \ACL\AgentRooms\Services\RoomEventService();
		$jobs       = new \ACL\AgentRooms\Repositories\JobRepository();
		$turns      = new \ACL\AgentRooms\Repositories\ConversationTurnRepository();
		$service    = new \ACL\AgentRooms\Services\ConversationTurnService();
		$message_id = $messages->create(
			array(
				'room_id'           => $this->room,
				'sender_type'       => 'user',
				'sender_user_id'    => $this->owner,
				'content'           => 'Retryable Independent natural trigger',
				'client_request_id' => $this->prefix . '-independent-retry',
			)
		);
		$trigger = $events->create_message_event( $messages->find( (int) $message_id ) );
		$service->activate_trigger( $rooms->find( $this->room ), (int) $trigger['id'] );
		$room   = $rooms->find( $this->room );
		$job_id = (int) $jobs->create( $this->room, (int) $message_id, $this->agents[1], \ACL\AgentRooms\Repositories\JobRepository::request_key( 'natural-retry-test', $this->room, (int) $message_id, $this->agents[1] ) );
		$plan   = array(
			'agent'     => $agents->find( $this->agents[1] ),
			'purpose'   => 'reply',
			'due_at'    => gmdate( 'Y-m-d H:i:s', time() - 1 ),
			'typing_at' => gmdate( 'Y-m-d H:i:s', time() - 2 ),
		);
		$jobs->schedule( $job_id, $plan['due_at'] );
		$turn = $service->create( $room, $trigger, $plan, 'independent', 0, $job_id );
		$this->turn_ids[] = (int) $turn['id'];
		$fake = new ACL_AR_NaturalFakeSwitchboard();
		$fake->failures_remaining = 1;
		$filter = static fn( $client, $request ) => $fake;
		add_filter( 'acl_ar_switchboard_client', $filter, 10, 2 );
		try {
			$first = $service->publish( (int) $turn['id'] );
			$this->assert_wp_error( $first, 'Retryable Natural Independent provider failure was not surfaced.' );
			$failed_job  = $jobs->find( $job_id );
			$failed_turn = $turns->find( (int) $turn['id'] );
			$this->assert_true( ! empty( $failed_job['retryable'] ) && ! empty( $failed_job['next_attempt_at'] ), 'Natural Independent job lost its retry schedule.' );
			$this->assert_same( 'pending', $failed_turn['status'], 'Retryable Natural Independent failure terminalized its turn.' );
			$this->assert_same( null, $failed_turn['cancel_reason'], 'Retryable Natural Independent turn gained a terminal reason.' );
			$this->assert_same( $failed_job['next_attempt_at'], $failed_turn['due_at'], 'Natural Independent turn retry was not aligned to its job.' );
			$job_action = function_exists( 'as_has_scheduled_action' )
				? as_has_scheduled_action( \ACL\AgentRooms\Services\QueueService::SINGLE_HOOK, array( $job_id ), 'acl-agent-rooms' )
				: wp_next_scheduled( \ACL\AgentRooms\Services\QueueService::SINGLE_HOOK, array( $job_id ) );
			$turn_action = function_exists( 'as_has_scheduled_action' )
				? as_has_scheduled_action( \ACL\AgentRooms\Services\QueueService::TURN_HOOK, array( (int) $turn['id'] ), 'acl-agent-rooms' )
				: wp_next_scheduled( \ACL\AgentRooms\Services\QueueService::TURN_HOOK, array( (int) $turn['id'] ) );
			$this->assert_true( false === $job_action || 0 === $job_action, 'Natural Independent retry scheduled a duplicate job action.' );
			$this->assert_true( false !== $turn_action && 0 !== $turn_action, 'Natural Independent retry did not retain its turn action.' );
			$this->assert_same( 'queued', ( new \ACL\AgentRooms\Services\AgentStateReconciler() )->reconcile_assignment( $this->room, $this->agents[1] ), 'Natural Independent backoff did not reconcile as queued.' );
			$past = gmdate( 'Y-m-d H:i:s', time() - 1 );
			$wpdb->update( $wpdb->prefix . 'acl_ar_agent_jobs', array( 'next_attempt_at' => $past ), array( 'id' => $job_id ) );
			$wpdb->update( $wpdb->prefix . 'acl_ar_conversation_turns', array( 'due_at' => $past, 'typing_at' => $past ), array( 'id' => (int) $turn['id'] ) );
			$recovered = $service->publish( (int) $turn['id'] );
		} finally {
			remove_filter( 'acl_ar_switchboard_client', $filter, 10 );
		}
		$this->assert_same( 2, $fake->calls, 'Natural Independent retry used the wrong provider request count.' );
		$this->assert_same( 'published', $recovered['status'], 'Natural Independent retry did not publish.' );
		$this->assert_same( 'completed', $jobs->find( $job_id )['status'], 'Natural Independent retry did not complete its job.' );
		$this->assert_same( 'ready', ( new \ACL\AgentRooms\Repositories\PresenceRepository() )->find( $this->room, 'agent', $this->agents[1] )['state'], 'Natural Independent retry did not restore Ready.' );
		$this->assert_same( 1, (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}acl_ar_messages WHERE response_job_id=%d", $job_id ) ), 'Natural Independent retry did not publish exactly one assistant message.' );
		wp_clear_scheduled_hook( \ACL\AgentRooms\Services\QueueService::SINGLE_HOOK, array( $job_id ) );
		if ( function_exists( 'as_unschedule_all_actions' ) ) {
			as_unschedule_all_actions( \ACL\AgentRooms\Services\QueueService::SINGLE_HOOK, array( $job_id ), 'acl-agent-rooms' );
		}
	}

	private function retryable_brain_failure_preserves_turns(): void {
		global $wpdb;
		$messages = new \ACL\AgentRooms\Repositories\MessageRepository(); $events = new \ACL\AgentRooms\Services\RoomEventService(); $rooms = new \ACL\AgentRooms\Repositories\RoomRepository(); $room = $rooms->find( $this->room );
		$message_id = $messages->create( array( 'room_id' => $this->room, 'sender_type' => 'user', 'sender_user_id' => $this->owner, 'content' => 'Retryable natural trigger', 'client_request_id' => $this->prefix . '-retryable-brain' ) ); $trigger = $events->create_message_event( $messages->find( (int) $message_id ) ); ( new \ACL\AgentRooms\Services\ConversationTurnService() )->activate_trigger( $room, (int) $trigger['id'] ); $room = $rooms->find( $this->room );
		$director = new \ACL\AgentRooms\Services\NaturalConversationDirector( null, $rooms, static fn(int $min,int $max):int=>$min ); $plan = $director->plan( $room, $rooms->get_agents( $this->room ), $this->agents, false ); $fake = new ACL_AR_NaturalFakeSwitchboard(); $fake->failures_remaining = 1; $fake->content_prefix = 'Recovered natural response '; $runs = new \ACL\AgentRooms\Repositories\BrainRunRepository(); $runtime = new \ACL\AgentRooms\Services\BrainRuntime( $runs, null, null, null, null, null, null, null, $fake );
		$result = ( new \ACL\AgentRooms\Services\BrainRunService( null, $runs, $runtime ) )->create_for_targets( $room, $trigger, $plan['targets'], true, $plan['turns'] ); $run = $result['brain_runs'][0]['run']; $run_id = (int) $run['id']; $this->assert_same( 'failed', $run['status'], 'Retryable provider failure did not enter recoverable failed state.' ); $this->assert_true( ! empty( $run['next_attempt_at'] ), 'Retryable Brain failure lost its retry schedule.' );
		$turn_repo = new \ACL\AgentRooms\Repositories\ConversationTurnRepository(); $turns = $turn_repo->for_brain_run( $run_id ); $this->turn_ids = array_merge( $this->turn_ids, array_map( static fn($turn)=>(int)$turn['id'], $turns ) ); foreach ( $turns as $turn ) { $this->assert_same( 'queued', ( new \ACL\AgentRooms\Services\AgentStateReconciler() )->reconcile_assignment( $this->room, (int) $turn['agent_id'] ), 'Natural Shared Brain retry was hidden by its active turn.' ); } $first_id = (int) $turns[0]['id']; $wpdb->update( $wpdb->prefix . 'acl_ar_conversation_turns', array( 'due_at' => gmdate( 'Y-m-d H:i:s', time() - 1 ) ), array( 'id' => $first_id ) ); ( new \ACL\AgentRooms\Services\ConversationTurnService() )->publish( $first_id ); $postponed = $turn_repo->find( $first_id ); $this->assert_same( 'pending', $postponed['status'], 'Due turn was failed while its Brain provider retry was pending.' ); $this->assert_same( null, $postponed['cancel_reason'], 'Retryable Brain turn gained a terminal failure reason.' );
		$retry = $runtime->run( $run_id, true ); $fresh = $runs->find( $run_id ); $this->assert_true( ! is_wp_error( $retry ), 'Intentional Brain retry did not recover.' ); $this->assert_same( 'response_saved', $fresh['status'], 'Successful retry did not save the Brain response.' ); $this->assert_same( 2, $fake->calls, 'Retry recovery used the wrong provider request count.' ); foreach ( $turn_repo->for_brain_run( $run_id ) as $turn ) { $this->assert_true( '' !== trim( (string) $turn['content'] ), 'Recovered Brain content was not persisted atomically.' ); $this->assert_true( 'failed' !== $turn['status'], 'Recovered Brain turn remained terminally failed.' ); }
		$worker = new \ACL\AgentRooms\Services\ConversationTurnService(); $published_ids = array(); foreach ( $turn_repo->for_brain_run( $run_id ) as $turn ) { $wpdb->update( $wpdb->prefix . 'acl_ar_conversation_turns', array( 'due_at' => gmdate( 'Y-m-d H:i:s', time() - 1 ) ), array( 'id' => (int) $turn['id'] ) ); $published = $worker->publish( (int) $turn['id'] ); $this->assert_true( is_array( $published ), 'Recovered Brain turn worker did not execute.' ); $this->assert_same( 'published', $published['status'], 'Recovered Brain turn did not publish.' ); $published_ids[] = (int) $published['published_event_id']; }
		$this->assert_same( 'completed', $runs->find( $run_id )['status'], 'Recovered Brain run did not reach a successful terminal state.' ); $this->assert_same( count( $turns ), (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}acl_ar_messages WHERE brain_run_id=%d", $run_id ) ), 'Recovered Brain replies were not persisted exactly once.' ); $this->assert_same( 0, (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}acl_ar_conversation_turns WHERE brain_run_id=%d AND status IN ('pending','typing','publishing','failed')", $run_id ) ), 'Recovered Brain left a stale or failed turn.' ); $this->assert_same( 1, (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}acl_ar_messages WHERE client_request_id=%s", $this->prefix . '-retryable-brain' ) ), 'Retry recovery duplicated the user message.' );
		$worker->publish( $first_id ); $this->assert_same( count( $turns ), (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}acl_ar_messages WHERE brain_run_id=%d", $run_id ) ), 'Duplicate turn worker republished an assistant message.' ); $page = ( new \ACL\AgentRooms\Services\RoomApplicationService() )->events( $this->room, $this->owner, '', '', 100 ); foreach ( $published_ids as $event_id ) { $this->assert_true( in_array( $event_id, array_map( 'intval', array_column( $page['events'], 'id' ) ), true ), 'Frontend event polling did not receive a recovered assistant response.' ); }
		$duplicate = ( new \ACL\AgentRooms\Services\BrainRunService( null, $runs, $runtime ) )->create_for_targets( $room, $trigger, $plan['targets'], true, $plan['turns'] ); $this->assert_same( $run_id, (int) $duplicate['brain_runs'][0]['run']['id'], 'Retry recovery request-key deduplication created a second Brain run.' ); $this->assert_same( 2, $fake->calls, 'Idempotent replay dispatched a duplicate provider request.' ); $this->assert_same( 1, count( $runs->for_trigger_event( (int) $trigger['id'] ) ), 'Retry recovery enqueued duplicate Brain work.' );
	}

	private function security_health_and_sources(): void {
		global $wpdb; $root = dirname( __DIR__ ); $messages = file_get_contents( $root . '/includes/Rest/MessagesController.php' ); $runtime = file_get_contents( $root . '/includes/Services/BrainRuntime.php' ); $prompt = file_get_contents( $root . '/includes/Services/BrainPromptBuilder.php' ); $admin = file_get_contents( $root . '/includes/Admin/RoomsPage.php' ) . file_get_contents( $root . '/includes/Admin/AgentsPage.php' ) . file_get_contents( $root . '/assets/js/admin.js' ); $uninstall = file_get_contents( $root . '/uninstall.php' );
		$this->assert_true( false !== strpos( $messages, 'NaturalConversationDirector' ) && false !== strpos( $messages, 'scheduled_turn_count' ), 'Natural director is not before dispatch.' ); $this->assert_true( false !== strpos( $runtime, 'superseded' ) && false !== strpos( $runtime, 'save_brain_content' ), 'Brain stale-output discard contract missing.' ); $this->assert_true( false !== strpos( $prompt, 'The server controls actual publication timing' ), 'Model cannot distinguish content from server timing.' ); $this->assert_true( false !== strpos( $admin, 'data-acl-ar-conversation-mode' ) && false !== strpos( $admin, 'data-acl-ar-natural-preset' ), 'Natural admin controls missing.' ); $this->assert_true( false !== strpos( $uninstall, 'acl_ar_conversation_turns' ), 'Destructive uninstall omits conversation turns.' ); $this->assert_true( false === strpos( $messages, "get_param( 'due_at'" ) && false === strpos( $messages, "get_param( 'content' ) . 'delay'" ), 'Client can supply hidden timing data.' );
		$health = ( new \ACL\AgentRooms\Services\HealthService() )->snapshot(); $this->assert_true( isset( $health['conversation_turns']['pending'], $health['conversation_turns']['overdue'], $health['conversation_turns']['stale_typing'], $health['conversation_turns']['failed'], $health['conversation_turns']['canceled'], $health['conversation_turns']['rooms_over_limit'], $health['conversation_turns']['missing_rooms'], $health['conversation_turns']['missing_agents'], $health['conversation_turns']['missing_brain_runs'], $health['conversation_turns']['missing_jobs'] ), 'Natural health metrics are incomplete.' ); $this->assert_true( false === strpos( wp_json_encode( $health ), 'Distinct natural response' ), 'Health output exposed scheduled content.' );
		$application = new \ACL\AgentRooms\Services\RoomApplicationService(); $page = $application->events( $this->room, $this->owner, '', '', 20 ); $this->assert_true( is_array( $page ) && isset( $page['sync']['agent_states'] ), 'Reload does not reconstruct safe agent states.' ); $this->assert_true( false === strpos( wp_json_encode( $page ), 'Distinct natural response ' . $this->agents[2] ), 'Ordinary event REST exposed pending response content.' );
		$this->assert_same( 0, (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}acl_ar_conversation_turns WHERE status='published' AND published_event_id IS NULL" ), 'Published turn lacks event reference.' ); $this->assert_same( 0, (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}acl_ar_conversation_turns t LEFT JOIN {$wpdb->prefix}acl_ar_rooms r ON r.id=t.room_id WHERE r.id IS NULL" ), 'Natural orphan room detected.' ); $this->assert_same( 0, (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}acl_ar_conversation_turns t LEFT JOIN {$wpdb->prefix}acl_ar_agents a ON a.id=t.agent_id WHERE a.id IS NULL" ), 'Natural orphan agent detected.' );
	}

	private function cleanup(): void {
		global $wpdb; foreach ( array_unique( $this->turn_ids ) as $id ) { wp_clear_scheduled_hook( \ACL\AgentRooms\Services\QueueService::TURN_HOOK, array( $id ) ); wp_clear_scheduled_hook( \ACL\AgentRooms\Services\QueueService::TYPING_HOOK, array( $id ) ); if ( function_exists( 'as_unschedule_action' ) ) { as_unschedule_action( \ACL\AgentRooms\Services\QueueService::TURN_HOOK, array( $id ), 'acl-agent-rooms' ); as_unschedule_action( \ACL\AgentRooms\Services\QueueService::TYPING_HOOK, array( $id ), 'acl-agent-rooms' ); } }
		if ( $this->room ) { $wpdb->delete( $wpdb->prefix . 'acl_ar_usage', array( 'room_id' => $this->room ), array( '%d' ) ); $rooms = new \ACL\AgentRooms\Repositories\RoomRepository(); if ( $rooms->find( $this->room ) ) { $rooms->delete( $this->room ); } }
		$repo = new \ACL\AgentRooms\Repositories\AgentRepository(); foreach ( $this->agents as $id ) { if ( $repo->find( $id ) ) { $repo->delete( $id ); } } if ( $this->brain ) { ( new \ACL\AgentRooms\Repositories\BrainRepository() )->delete( $this->brain ); } if ( $this->owner ) { wp_delete_user( $this->owner ); }
	}
	private function fingerprint(): string { global $wpdb; $like=$wpdb->esc_like($wpdb->prefix.'acl_ar_').'%'; $columns=$wpdb->get_results($wpdb->prepare('SELECT TABLE_NAME,COLUMN_NAME,ORDINAL_POSITION,COLUMN_TYPE,IS_NULLABLE,COLUMN_DEFAULT,EXTRA FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=%s AND TABLE_NAME LIKE %s ORDER BY TABLE_NAME,ORDINAL_POSITION',DB_NAME,$like),ARRAY_A); $indexes=$wpdb->get_results($wpdb->prepare('SELECT TABLE_NAME,INDEX_NAME,NON_UNIQUE,SEQ_IN_INDEX,COLUMN_NAME,SUB_PART,INDEX_TYPE FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=%s AND TABLE_NAME LIKE %s ORDER BY TABLE_NAME,INDEX_NAME,SEQ_IN_INDEX',DB_NAME,$like),ARRAY_A); return hash('sha256',wp_json_encode(array($columns,$indexes))); }
}
