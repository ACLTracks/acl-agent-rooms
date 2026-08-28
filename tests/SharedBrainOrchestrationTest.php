<?php
/** Deterministic no-cost integration coverage for 1.1.0 Shared Brain orchestration. */

class ACL_AR_FakeBrainSwitchboard implements \ACL\AgentRooms\Contracts\SwitchboardClientInterface {
	public int $calls = 0; public array $requests = array(); public bool $invalid = false; public bool $omit_first = false; public $during_send = null;
	public function send( array $request ) { $this->calls++;$this->requests[]=$request;if(is_callable($this->during_send)){$callback=$this->during_send;$this->during_send=null;$callback();}if($this->invalid){return array('content'=>'{"responses":[{"agent_id":999999,"content":"wrong"}]}','usage'=>array());}$responses=array();foreach((array)($request['metadata']['agent_ids']??array()) as $id){$responses[]=array('agent_id'=>(int)$id,'content'=>'Brain response for agent '.(int)$id);}if($this->omit_first&&1===$this->calls){array_pop($responses);}$count=count($responses);return array('content'=>wp_json_encode(array('responses'=>$responses)),'usage'=>array('prompt_tokens'=>100,'completion_tokens'=>20*$count,'total_tokens'=>100+20*$count),'estimated_cost'=>0,'raw_provider'=>'controlled-fake','finish_reason'=>'stop'); }
}

class ACL_AR_FailBrainMessageEventService extends \ACL\AgentRooms\Services\RoomEventService {
	public int $message_attempts = 0;
	public int $failures_remaining = 1;
	public function create( array $input ) {
		if ( 'message' === (string) ( $input['event_type'] ?? '' ) ) {
			++$this->message_attempts;
			if ( $this->failures_remaining > 0 ) {
				--$this->failures_remaining;
				return new \WP_Error( 'acl_ar_test_fanout_failure', 'Controlled fan-out failure.' );
			}
		}
		return parent::create( $input );
	}
}

class ACL_AR_SharedBrainOrchestrationTest extends ACL_AR_TestCase {
	private int $owner=0,$room=0,$brain=0;private array $agents=array();private string $prefix='';
	public function run():void{global$wpdb;$this->prefix='brain-110-'.gmdate('YmdHis').'-'.wp_generate_password(5,false,false);add_filter('acl_ar_brain_provider_model_valid',array($this,'allow_fake'),10,3);$this->owner=(int)wp_insert_user(array('user_login'=>$this->prefix.'-owner','user_pass'=>wp_generate_password(20),'user_email'=>$this->prefix.'@example.test','role'=>'administrator'));$this->assert_true($this->owner>0,'Brain test owner was not created.');wp_set_current_user($this->owner);try{$this->schema_contract();$this->create_fixture();$this->grouping_prompt_and_dispatch();$this->parser_contract();$this->missing_response_recovers();$this->contract_violation_recovers();$this->lease_fencing_and_stale_worker();$this->saved_response_retry_is_due_gated();$this->saved_response_recovery();$this->failure_grouping_and_recovery();$this->ask_trigger_path();$this->no_fallback_and_disablement();$this->routes_ui_and_security();$this->room_work_route_security();}finally{$this->cleanup();remove_filter('acl_ar_brain_provider_model_valid',array($this,'allow_fake'),10);wp_set_current_user(0);}}
	public function allow_fake($allowed,string $provider,string $model):?bool{return 'controlled-fake'===$provider&&'brain-model-v1'===$model?true:$allowed;}
	private function schema_contract():void{global$wpdb;$this->assert_same('1.5.6',ACL_AR_VERSION,'Plugin version.');$this->assert_same('1.4.1',ACL_AR_DB_VERSION,'Database version.');$before=$this->fingerprint();\ACL\AgentRooms\Installer::install();\ACL\AgentRooms\Installer::install();$this->assert_same($before,$this->fingerprint(),'Migration twice changed schema.');$this->assert_same('1.4.1',(string)get_option('acl_ar_db_version'),'Installed database version.');foreach(array('acl_ar_brains','acl_ar_brain_runs')as$suffix){$table=$wpdb->prefix.$suffix;$this->assert_same($table,(string)$wpdb->get_var($wpdb->prepare('SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA=%s AND TABLE_NAME=%s',DB_NAME,$table)),'Missing '.$suffix);}$agent_cols=$wpdb->get_col($wpdb->prepare('SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=%s AND TABLE_NAME=%s',DB_NAME,$wpdb->prefix.'acl_ar_agents'));foreach(array('execution_mode','brain_id')as$column){$this->assert_true(in_array($column,$agent_cols,true),'Missing agent '.$column);}$indexes=$wpdb->get_col($wpdb->prepare('SELECT DISTINCT INDEX_NAME FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=%s AND TABLE_NAME=%s',DB_NAME,$wpdb->prefix.'acl_ar_brain_runs'));foreach(array('PRIMARY','request_key','room_id','brain_id','trigger_event_id','worker','lease')as$index){$this->assert_true(in_array($index,$indexes,true),'Missing run index '.$index);}$this->assert_same(0,(int)$wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}acl_ar_agents WHERE execution_mode<>'independent' AND brain_id IS NULL"),'Existing agents did not retain a valid independent migration state.');}
	private function create_fixture():void{$brains=new \ACL\AgentRooms\Repositories\BrainRepository();$this->brain=(int)$brains->create(array('owner_user_id'=>$this->owner,'name'=>$this->prefix.' Brain','slug'=>$this->prefix.'-brain','description'=>'Controlled no-cost Brain','provider'=>'controlled-fake','model'=>'brain-model-v1','orchestration_prompt'=>'Coordinate without merging personas.','temperature'=>0.4,'max_tokens_per_agent'=>200,'max_total_tokens'=>500,'settings'=>array('response_format'=>'json'),'enabled'=>1));$this->assert_true($this->brain>0,'Brain was not created.');$agents=new \ACL\AgentRooms\Repositories\AgentRepository();foreach(array('Alpha','Beta','Gamma')as$i=>$name){$id=$agents->create(array('owner_user_id'=>$this->owner,'name'=>$this->prefix.' '.$name,'slug'=>$this->prefix.'-'.strtolower($name),'description'=>$name.' description','provider_route'=>'independent-provider-'.$i,'model'=>'independent-model-'.$i,'system_prompt'=>'PERSONA-'.$name.'-UNIQUE','temperature'=>1.1,'max_tokens'=>321,'visibility'=>'private','enabled'=>1,'execution_mode'=>'brain','brain_id'=>$this->brain));$this->assert_true(is_int($id)&&$id>0,'Brain agent was not created.');$this->agents[]=(int)$id;}$rooms=new \ACL\AgentRooms\Repositories\RoomRepository();$this->room=(int)$rooms->create(array('owner_user_id'=>$this->owner,'title'=>$this->prefix.' Room','slug'=>$this->prefix.'-room','description'=>'ROOM-DESCRIPTION-UNIQUE','top_context'=>'ROOM-CONTEXT-UNIQUE','type'=>'private','visibility'=>'private','status'=>'active','agent_reply_mode'=>'auto','max_context_messages'=>20,'max_agents_per_turn'=>3));$this->assert_true($this->room>0,'Brain room was not created.');$this->assert_true(true===$rooms->assign_agents($this->room,$this->agents),'Brain agents were not assigned.');}
	private function grouping_prompt_and_dispatch():void{global$wpdb;$messages=new \ACL\AgentRooms\Repositories\MessageRepository();$events=new \ACL\AgentRooms\Services\RoomEventService();$messages->create(array('room_id'=>$this->room,'sender_type'=>'user','sender_user_id'=>$this->owner,'content'=>'HISTORY-MARKER-ONCE'));$trigger_id=$messages->create(array('room_id'=>$this->room,'sender_type'=>'user','sender_user_id'=>$this->owner,'content'=>'TRIGGER-MARKER-ONCE','client_request_id'=>$this->prefix.'-trigger','metadata'=>array('brain_trigger_mode'=>'automatic')));$trigger=$events->create_message_event($messages->find((int)$trigger_id));$rooms=new \ACL\AgentRooms\Repositories\RoomRepository();$targets=$rooms->get_agents($this->room);$groups=(new \ACL\AgentRooms\Services\BrainGroupingService())->group($targets);$this->assert_same(0,count($groups['independent']),'Brain agents leaked into independent grouping.');$this->assert_same(1,count($groups['brains']),'Same-Brain agents did not form one group.');$this->assert_same($this->agents,array_map(static fn($a)=>(int)$a['id'],$groups['brains'][0]['agents']),'Brain grouping lost assignment order.');$fake=new ACL_AR_FakeBrainSwitchboard();$runs=new \ACL\AgentRooms\Repositories\BrainRunRepository();$runtime=new \ACL\AgentRooms\Services\BrainRuntime($runs,null,null,null,null,null,null,null,$fake);$service=new \ACL\AgentRooms\Services\BrainRunService(null,$runs,$runtime);$result=$service->create_for_targets($rooms->find($this->room),$trigger,$targets,true);$this->assert_same(1,$fake->calls,'Same-Brain agents did not use exactly one Switchboard call.');$this->assert_same(1,count($result['brain_runs']),'Exactly one Brain run was not created.');$run=$result['brain_runs'][0]['run'];$this->assert_same('completed',$run['status'],'Brain run did not complete.');$this->assert_same($this->agents,$run['target_agent_ids'],'Run target order changed.');$request=$fake->requests[0];$this->assert_same('controlled-fake',$request['provider_route'],'Brain provider was not used.');$this->assert_same('brain-model-v1',$request['model'],'Brain model was not used.');$this->assert_same(500,$request['max_tokens'],'Brain token scaling is wrong.');$this->assert_same(array('type'=>'json_object','fields'=>array('responses'=>'array')),$request['structured']??array(),'Brain request did not require Switchboard structured output.');$prompt=$request['system_prompt'];$this->assert_same(1,substr_count($prompt,'ROOM-CONTEXT-UNIQUE'),'Room context was not included exactly once.');$this->assert_same(1,substr_count($prompt,'TRIGGER-MARKER-ONCE'),'Trigger was not included exactly once.');$this->assert_same(1,substr_count($prompt,'HISTORY-MARKER-ONCE'),'History was not included exactly once.');foreach(array('Alpha','Beta','Gamma')as$name){$this->assert_same(1,substr_count($prompt,'PERSONA-'.$name.'-UNIQUE'),'Agent persona was not included exactly once.');}$run_id=(int)$run['id'];$this->assert_same(3,(int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->prefix}acl_ar_messages WHERE brain_run_id=%d",$run_id)),'Brain fan-out did not create three messages.');$this->assert_same($this->agents,array_map('intval',$wpdb->get_col($wpdb->prepare("SELECT sender_agent_id FROM {$wpdb->prefix}acl_ar_messages WHERE brain_run_id=%d ORDER BY id",$run_id))),'Fan-out actor identity/order is wrong.');$this->assert_same(1,(int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->prefix}acl_ar_usage WHERE brain_run_id=%d",$run_id)),'Brain usage was not recorded exactly once.');$this->assert_same(1,(int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->prefix}acl_ar_events WHERE room_id=%d AND event_type='brain_run' AND target_id=%d",$this->room,$this->brain)),'Terminal Brain event count is wrong.');$this->assert_same(0,(int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->prefix}acl_ar_agent_jobs WHERE room_id=%d",$this->room)),'Brain execution created individual jobs.');$this->assert_same(0,(int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->prefix}acl_ar_events WHERE room_id=%d AND event_type IN ('agent_queued','agent_thinking','agent_responding','agent_completed','agent_failed')",$this->room)),'Brain execution created per-agent lifecycle events.');$message_events=$wpdb->get_col($wpdb->prepare("SELECT id FROM {$wpdb->prefix}acl_ar_events WHERE room_id=%d AND event_type='message' AND actor_type='agent' ORDER BY id",$this->room));$this->assert_same(3,count($message_events),'Brain answers are not ordinary agent message events.');$this->assert_same(3,(int)$wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}acl_ar_event_search WHERE event_id IN (".implode(',',array_map('absint',$message_events)).')'),'Brain messages were not indexed for search.');$read=new \ACL\AgentRooms\Services\ReadStateService();$state=$read->state($this->room,$this->owner);$this->assert_true((int)$state['unread_count']>=3,'Brain messages were not unread-bearing.');$policy=new \ACL\AgentRooms\Services\MessageInteractionPolicy();$this->assert_true(is_array($policy->target($this->room,(int)$message_events[0],$this->owner,'reply')),'Brain message is not replyable.');$reaction=(new \ACL\AgentRooms\Services\ReactionService())->mutate($this->room,(int)$message_events[0],$this->owner,'👍','add',$this->prefix.'-reaction');$this->assert_true(is_array($reaction),'Brain message is not reactable.');$before_calls=$fake->calls;$again=$service->create_for_targets($rooms->find($this->room),$trigger,$targets,true);$this->assert_same($run_id,(int)$again['brain_runs'][0]['run']['id'],'Duplicate trigger created a different run.');$this->assert_same($before_calls,$fake->calls,'Duplicate trigger called Switchboard again.');$this->assert_same(3,(int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->prefix}acl_ar_messages WHERE brain_run_id=%d",$run_id)),'Duplicate trigger duplicated responses.');}
	private function parser_contract():void{$parser=new \ACL\AgentRooms\Services\BrainResponseParser();$ids=$this->agents;$valid=wp_json_encode(array('responses'=>array_map(static fn($id)=>array('agent_id'=>$id,'content'=>'ok '.$id),$ids)));$this->assert_same(3,count($parser->parse($valid,$ids)),'Valid JSON was rejected.');$this->assert_same(3,count($parser->parse("```json\n{$valid}\n```",$ids)),'One fenced JSON wrapper was rejected.');$this->assert_wp_error($parser->parse($valid.' trailing prose',$ids),'Trailing prose was accepted.');$duplicate=wp_json_encode(array('responses'=>array(array('agent_id'=>$ids[0],'content'=>'a'),array('agent_id'=>$ids[0],'content'=>'b'),array('agent_id'=>$ids[2],'content'=>'c'))));$this->assert_wp_error($parser->parse($duplicate,$ids),'Duplicate agent IDs were accepted.');$unknown=wp_json_encode(array('responses'=>array(array('agent_id'=>$ids[0],'content'=>'a'),array('agent_id'=>$ids[1],'content'=>'b'),array('agent_id'=>999999,'content'=>'c'))));$unknown_result=$parser->parse($unknown,$ids);$this->assert_wp_error($unknown_result,'Unknown agent substituted for a requested agent.');$this->assert_same('acl_ar_brain_response_missing_agent',$unknown_result->get_error_code(),'Unknown substitution did not become a recoverable missing-agent failure.');$extra=wp_json_encode(array('responses'=>array(array('agent_id'=>$ids[0],'content'=>'a'),array('agent_id'=>999999,'content'=>'discard me'),array('agent_id'=>$ids[1],'content'=>'b'),array('agent_id'=>$ids[2],'content'=>'c'))));$this->assert_same(3,count($parser->parse($extra,$ids)),'A harmless extra unrequested response blocked a complete requested set.');$missing=wp_json_encode(array('responses'=>array(array('agent_id'=>$ids[0],'content'=>'a'))));$this->assert_wp_error($parser->parse($missing,$ids),'Missing responses were accepted.');$empty=wp_json_encode(array('responses'=>array(array('agent_id'=>$ids[0],'content'=>'a'),array('agent_id'=>$ids[1],'content'=>''),array('agent_id'=>$ids[2],'content'=>'c'))));$this->assert_wp_error($parser->parse($empty,$ids),'Empty response was accepted.');$html=wp_json_encode(array('responses'=>array(array('agent_id'=>$ids[0],'content'=>'a'),array('agent_id'=>$ids[1],'content'=>'<b>b</b>'),array('agent_id'=>$ids[2],'content'=>'c'))));$this->assert_wp_error($parser->parse($html,$ids),'HTML response was accepted.');$oversized=wp_json_encode(array('responses'=>array(array('agent_id'=>$ids[0],'content'=>str_repeat('x',\ACL\AgentRooms\Services\MessagePolicy::DEFAULT_CHARACTER_LIMIT+1)),array('agent_id'=>$ids[1],'content'=>'b'),array('agent_id'=>$ids[2],'content'=>'c'))));$this->assert_wp_error($parser->parse($oversized,$ids),'Oversized response was accepted.');}
	private function missing_response_recovers():void{global$wpdb;$messages=new \ACL\AgentRooms\Repositories\MessageRepository();$events=new \ACL\AgentRooms\Services\RoomEventService();$runs=new \ACL\AgentRooms\Repositories\BrainRunRepository();$trigger_id=$messages->create(array('room_id'=>$this->room,'sender_type'=>'user','sender_user_id'=>$this->owner,'content'=>'MISSING-RESPONSE-RECOVERY','client_request_id'=>$this->prefix.'-missing-recovery','metadata'=>array('brain_trigger_mode'=>'automatic')));$trigger=$events->create_message_event($messages->find((int)$trigger_id));$run=$runs->create($this->room,$this->brain,(int)$trigger['id'],$this->agents,'controlled-fake','brain-model-v1');$fake=new ACL_AR_FakeBrainSwitchboard();$fake->omit_first=true;$runtime=new \ACL\AgentRooms\Services\BrainRuntime($runs,null,null,null,null,null,null,null,$fake);$first=$runtime->run((int)$run['id']);$this->assert_wp_error($first,'Incomplete Brain output was not rejected before publication.');$failed=$runs->find((int)$run['id']);$this->assert_same('failed',$failed['status'],'Incomplete Brain output did not enter recoverable failure state.');$this->assert_same('acl_ar_brain_response_missing_agent',$failed['error_code'],'Incomplete Brain output lost its diagnostic code.');$this->assert_true(!empty($failed['next_attempt_at']),'Incomplete Brain output was made terminal instead of retryable.');foreach($this->agents as$agent_id){$this->assert_same('queued',(new \ACL\AgentRooms\Services\AgentStateReconciler())->reconcile_assignment($this->room,$agent_id),'Retryable Brain failure did not remain queued.');}$this->assert_true((bool)wp_next_scheduled(\ACL\AgentRooms\Services\QueueService::BRAIN_HOOK,array((int)$run['id'])),'Incomplete Brain output did not schedule a bounded retry.');$this->assert_same(0,(int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->prefix}acl_ar_messages WHERE brain_run_id=%d",(int)$run['id'])),'Incomplete Brain output published a partial response.');$wpdb->update($wpdb->prefix.'acl_ar_brain_runs',array('next_attempt_at'=>gmdate('Y-m-d H:i:s',time()-1)),array('id'=>(int)$run['id']));$filter=static fn($client,$request)=>$fake;add_filter('acl_ar_switchboard_client',$filter,10,2);try{(new \ACL\AgentRooms\Services\QueueService())->run_brain((int)$run['id']);}finally{remove_filter('acl_ar_switchboard_client',$filter,10);}$done=$runs->find((int)$run['id']);$this->assert_same('completed',$done['status'],'A complete retry did not recover through the Brain queue worker.');$this->assert_same(2,$fake->calls,'Incomplete Brain recovery made an unexpected number of provider requests.');foreach($this->agents as$agent_id){$this->assert_same('ready',(new \ACL\AgentRooms\Repositories\PresenceRepository())->find($this->room,'agent',$agent_id)['state'],'Recovered Brain retry did not restore Ready.');}$this->assert_same(3,(int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->prefix}acl_ar_messages WHERE brain_run_id=%d",(int)$run['id'])),'Recovered Brain run did not publish every response exactly once.');$this->assert_same(1,(int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->prefix}acl_ar_messages WHERE id=%d",(int)$trigger_id)),'Brain recovery changed or removed the user message.');wp_clear_scheduled_hook(\ACL\AgentRooms\Services\QueueService::BRAIN_HOOK,array((int)$run['id']));}
	private function contract_violation_recovers():void{
		global $wpdb;
		$messages = new \ACL\AgentRooms\Repositories\MessageRepository();
		$events   = new \ACL\AgentRooms\Services\RoomEventService();
		$runs     = new \ACL\AgentRooms\Repositories\BrainRunRepository();
		$message_id = $messages->create( array( 'room_id' => $this->room, 'sender_type' => 'user', 'sender_user_id' => $this->owner, 'content' => 'UNKNOWN-AGENT-RECOVERY', 'client_request_id' => $this->prefix . '-unknown-recovery' ) );
		$trigger  = $events->create_message_event( $messages->find( (int) $message_id ) );
		$run      = $runs->create( $this->room, $this->brain, (int) $trigger['id'], $this->agents, 'controlled-fake', 'brain-model-v1' );
		$fake     = new ACL_AR_FakeBrainSwitchboard();
		$fake->invalid = true;
		$runtime  = new \ACL\AgentRooms\Services\BrainRuntime( $runs, null, null, null, null, null, null, null, $fake );
		$first    = $runtime->run( (int) $run['id'] );
		$failed   = $runs->find( (int) $run['id'] );
		$this->assert_wp_error( $first, 'Unknown-agent response was not rejected.' );
		$this->assert_same( 'failed', $failed['status'], 'Unknown-agent response did not enter failed state.' );
		$this->assert_same( 'acl_ar_brain_response_missing_agent', $failed['error_code'], 'Unknown-agent response did not become a recoverable completeness failure.' );
		$this->assert_true( ! empty( $failed['next_attempt_at'] ), 'Unknown-agent response was made terminal.' );
		$this->assert_same( 0, (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}acl_ar_messages WHERE brain_run_id=%d", (int) $run['id'] ) ), 'Invalid first response was partially published.' );
		$fake->invalid = false;
		$recovered = $runtime->run( (int) $run['id'], true );
		$this->assert_true( ! is_wp_error( $recovered ), 'Guided contract retry did not recover.' );
		$fresh = $runs->find( (int) $run['id'] );
		$this->assert_same( 'completed', $fresh['status'], 'Guided contract retry did not complete.' );
		$this->assert_same( '', $fresh['error_code'], 'Successful Brain retry retained a stale error code.' );
		$this->assert_same( '', $fresh['public_error'], 'Successful Brain retry retained a stale public error.' );
		$this->assert_same( 2, $fake->calls, 'Guided contract retry used the wrong provider request count.' );
		$this->assert_true( false !== strpos( $fake->requests[1]['system_prompt'], 'BEGIN RESPONSE CONTRACT CORRECTION' ), 'Retry prompt omitted the correction boundary.' );
		$this->assert_true( false !== strpos( $fake->requests[1]['system_prompt'], implode( ', ', $this->agents ) ), 'Retry prompt omitted the authoritative agent order.' );
		$this->assert_same( count( $this->agents ), (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}acl_ar_messages WHERE brain_run_id=%d", (int) $run['id'] ) ), 'Guided retry did not publish exactly one response per requested agent.' );
		$audit = ( new \ACL\AgentRooms\Repositories\EventRepository() )->find_by_idempotency_key( hash( 'sha256', 'brain-run:' . (int) $run['id'] . ':terminal' ) );
		$this->assert_true( is_array( $audit ) && 'completed' === (string) ( $audit['metadata']['status'] ?? '' ), 'Recovered Brain completion audit is missing.' );
		$this->assert_true( ! isset( $audit['metadata']['error'], $audit['metadata']['error_code'], $audit['metadata']['stage'], $audit['metadata']['result_status'] ), 'Recovered Brain completion audit retained stale failure diagnostics.' );
		wp_clear_scheduled_hook( \ACL\AgentRooms\Services\QueueService::BRAIN_HOOK, array( (int) $run['id'] ) );
	}
	private function lease_fencing_and_stale_worker():void{
		global $wpdb;
		$messages = new \ACL\AgentRooms\Repositories\MessageRepository();
		$events   = new \ACL\AgentRooms\Services\RoomEventService();
		$runs     = new \ACL\AgentRooms\Repositories\BrainRunRepository();
		$message_id = $messages->create( array( 'room_id' => $this->room, 'sender_type' => 'user', 'sender_user_id' => $this->owner, 'content' => 'BRAIN-LEASE-REPOSITORY', 'client_request_id' => $this->prefix . '-brain-lease-repository' ) );
		$trigger = $events->create_message_event( $messages->find( (int) $message_id ) );
		$run = $runs->create( $this->room, $this->brain, (int) $trigger['id'], $this->agents, 'controlled-fake', 'brain-model-v1' );
		$run_id = (int) $run['id'];
		$this->assert_true( $runs->acquire( $run_id, 'brain-owner', 180 ), 'Brain repository owner did not acquire its run.' );
		$this->assert_true( ! $runs->update_targets( $run_id, $this->agents, 'stale-brain-owner' ), 'Stale Brain target update was reported as successful.' );
		$this->assert_true( $runs->update_targets( $run_id, $this->agents, 'brain-owner' ), 'Owning no-op Brain target verification failed.' );
		$responses = array_map( static fn( $id ) => array( 'agent_id' => (int) $id, 'content' => 'Lease response ' . (int) $id ), $this->agents );
		$this->assert_true( ! $runs->save_response( $run_id, $responses, array(), 'stale-brain-owner' ), 'Stale Brain response save was reported as successful.' );
		$this->assert_true( ! $runs->fail( $run_id, 'stale_error', 'Stale failure', true, 30, 'stale-brain-owner' ), 'Stale Brain failure was reported as successful.' );
		$owned = $runs->find( $run_id );
		$this->assert_same( 'running', $owned['status'], 'Stale Brain repository mutation changed durable status.' );
		$this->assert_same( 'brain-owner', $owned['lease_token'], 'Stale Brain repository mutation replaced the owner.' );
		$this->assert_true( $runs->fail( $run_id, 'retryable_contract', 'Retryable contract failure', true, 1, 'brain-owner' ), 'Owning Brain failure did not affect exactly one row.' );
		$wpdb->update( $wpdb->prefix . 'acl_ar_brain_runs', array( 'next_attempt_at' => gmdate( 'Y-m-d H:i:s', time() - 1 ) ), array( 'id' => $run_id ) );
		$this->assert_true( $runs->acquire( $run_id, 'brain-recovery-owner', 180 ), 'Brain recovery owner did not acquire the retry.' );
		$this->assert_true( $runs->save_response( $run_id, $responses, array(), 'brain-recovery-owner' ), 'Owning Brain response save did not affect exactly one row.' );
		$saved = $runs->find( $run_id );
		$this->assert_same( 'response_saved', $saved['status'], 'Owning Brain response did not reach response_saved.' );
		$this->assert_same( '', $saved['error_code'], 'Owning Brain response retained a stale error code.' );
		$this->assert_same( '', $saved['public_error'], 'Owning Brain response retained a stale public error.' );
		$done = ( new \ACL\AgentRooms\Services\BrainRuntime( $runs, null, null, null, null, null, null, null, new ACL_AR_FakeBrainSwitchboard() ) )->run( $run_id );
		$this->assert_same( 'completed', $done['status'], 'Saved fenced Brain response did not complete.' );
		$this->assert_true( $runs->complete( $run_id, $done['response_event_ids'] ), 'An idempotent saved-response resume did not recognize the completed run.' );
		$this->assert_true( ! $runs->fail( $run_id, 'late_fanout_failure', 'Late fan-out failure', true, 30 ), 'A late fan-out worker changed a completed Brain run to failed.' );
		$this->assert_same( 'completed', $runs->find( $run_id )['status'], 'A late fan-out worker reopened a completed Brain run.' );
		$this->assert_true( $runs->cancel( $run_id, 'late_saved_cancel', 'Late saved-response cancellation' ), 'A late saved-response cancellation did not resolve idempotently.' );
		$this->assert_same( 'completed', $runs->find( $run_id )['status'], 'A late saved-response cancellation changed a completed Brain run.' );

		$stale_message_id = $messages->create( array( 'room_id' => $this->room, 'sender_type' => 'user', 'sender_user_id' => $this->owner, 'content' => 'BRAIN-STALE-WORKER', 'client_request_id' => $this->prefix . '-brain-stale-worker' ) );
		$stale_trigger = $events->create_message_event( $messages->find( (int) $stale_message_id ) );
		$stale_run = $runs->create( $this->room, $this->brain, (int) $stale_trigger['id'], $this->agents, 'controlled-fake', 'brain-model-v1' );
		$stale_run_id = (int) $stale_run['id'];
		$wpdb->update( $wpdb->prefix . 'acl_ar_brain_runs', array( 'attempts' => \ACL\AgentRooms\Repositories\BrainRunRepository::MAX_ATTEMPTS - 1 ), array( 'id' => $stale_run_id ) );
		$fake = new ACL_AR_FakeBrainSwitchboard();
		$fake->during_send = static function () use ( $wpdb, $stale_run_id ): void {
			$wpdb->update( $wpdb->prefix . 'acl_ar_brain_runs', array( 'lease_token' => 'replacement-brain-owner' ), array( 'id' => $stale_run_id ) );
		};
		$result = ( new \ACL\AgentRooms\Services\BrainRuntime( $runs, null, null, null, null, null, null, null, $fake ) )->run( $stale_run_id );
		$this->assert_wp_error( $result, 'Stale Brain worker did not reject persistence.' );
		$this->assert_same( 'acl_ar_brain_run_lease_lost', $result->get_error_code(), 'Stale Brain worker returned the wrong persistence error.' );
		$fresh = $runs->find( $stale_run_id );
		$this->assert_same( 'running', $fresh['status'], 'Stale Brain worker changed the replacement owner status.' );
		$this->assert_same( 'replacement-brain-owner', $fresh['lease_token'], 'Stale Brain worker cleared the replacement owner.' );
		$this->assert_same( '', $fresh['error_code'], 'Stale Brain worker persisted its failure.' );
		$this->assert_same( array(), $fresh['validated_responses'], 'Stale Brain worker persisted a validated response.' );
		$this->assert_same( 0, (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}acl_ar_messages WHERE brain_run_id=%d", $stale_run_id ) ), 'Stale Brain worker published a response.' );
		$this->assert_same( null, ( new \ACL\AgentRooms\Repositories\EventRepository() )->find_by_idempotency_key( hash( 'sha256', 'brain-run:' . $stale_run_id . ':terminal' ) ), 'Stale Brain worker emitted a terminal event.' );
		foreach ( $this->agents as $agent_id ) {
			$this->assert_same( 'responding', ( new \ACL\AgentRooms\Repositories\PresenceRepository() )->find( $this->room, 'agent', $agent_id )['state'], 'Stale Brain worker projected a post-lease failure state.' );
		}
		$runs->cancel( $stale_run_id, 'test_cleanup', 'Test cleanup', 'replacement-brain-owner' );

		$cancel_message_id = $messages->create( array( 'room_id' => $this->room, 'sender_type' => 'user', 'sender_user_id' => $this->owner, 'content' => 'BRAIN-STALE-CANCEL', 'client_request_id' => $this->prefix . '-brain-stale-cancel' ) );
		$cancel_trigger = $events->create_message_event( $messages->find( (int) $cancel_message_id ) );
		$cancel_run = $runs->create( $this->room, $this->brain, (int) $cancel_trigger['id'], $this->agents, 'controlled-fake', 'brain-model-v1' );
		$cancel_run_id = (int) $cancel_run['id'];
		$cutoff = (int) ( ( new \ACL\AgentRooms\Repositories\RoomRepository() )->find( $this->room )['cleared_through_event_id'] ?? 0 );
		$cancel_fake = new ACL_AR_FakeBrainSwitchboard();
		$cancel_fake->during_send = static function () use ( $wpdb, $cancel_run_id, $cancel_trigger ): void {
			$wpdb->update( $wpdb->prefix . 'acl_ar_brain_runs', array( 'lease_token' => 'replacement-cancel-owner' ), array( 'id' => $cancel_run_id ) );
			$wpdb->update( $wpdb->prefix . 'acl_ar_rooms', array( 'cleared_through_event_id' => (int) $cancel_trigger['id'] ), array( 'id' => $cancel_trigger['room_id'] ) );
		};
		$cancel_result = ( new \ACL\AgentRooms\Services\BrainRuntime( $runs, null, null, null, null, null, null, null, $cancel_fake ) )->run( $cancel_run_id );
		$this->assert_wp_error( $cancel_result, 'Stale Brain cancel worker did not reject persistence.' );
		$this->assert_same( 'acl_ar_brain_run_lease_lost', $cancel_result->get_error_code(), 'Stale Brain cancel worker returned the wrong persistence error.' );
		$cancel_fresh = $runs->find( $cancel_run_id );
		$this->assert_same( 'running', $cancel_fresh['status'], 'Stale Brain cancel worker changed the replacement owner status.' );
		$this->assert_same( 'replacement-cancel-owner', $cancel_fresh['lease_token'], 'Stale Brain cancel worker cleared the replacement owner.' );
		$this->assert_same( null, ( new \ACL\AgentRooms\Repositories\EventRepository() )->find_by_idempotency_key( hash( 'sha256', 'brain-run:' . $cancel_run_id . ':terminal' ) ), 'Stale Brain cancel worker emitted a terminal event.' );
		foreach ( $this->agents as $agent_id ) {
			$this->assert_same( 'responding', ( new \ACL\AgentRooms\Repositories\PresenceRepository() )->find( $this->room, 'agent', $agent_id )['state'], 'Stale Brain cancel worker projected a post-lease state.' );
		}
		$wpdb->update( $wpdb->prefix . 'acl_ar_rooms', array( 'cleared_through_event_id' => $cutoff ), array( 'id' => $this->room ) );
		$runs->cancel( $cancel_run_id, 'test_cleanup', 'Test cleanup', 'replacement-cancel-owner' );
	}
	private function saved_response_retry_is_due_gated():void{
		global $wpdb;
		$messages = new \ACL\AgentRooms\Repositories\MessageRepository();
		$events   = new \ACL\AgentRooms\Services\RoomEventService();
		$runs     = new \ACL\AgentRooms\Repositories\BrainRunRepository();
		$rooms    = new \ACL\AgentRooms\Repositories\RoomRepository();
		$trigger_message_id = $messages->create( array( 'room_id' => $this->room, 'sender_type' => 'user', 'sender_user_id' => $this->owner, 'content' => 'FANOUT-DUE-RECOVERY', 'client_request_id' => $this->prefix . '-fanout-due-recovery' ) );
		$trigger = $events->create_message_event( $messages->find( (int) $trigger_message_id ) );
		$run = $runs->create( $this->room, $this->brain, (int) $trigger['id'], $this->agents, 'controlled-fake', 'brain-model-v1' );
		$run_id = (int) $run['id'];
		$fake = new ACL_AR_FakeBrainSwitchboard();
		$flaky_events = new ACL_AR_FailBrainMessageEventService();
		$runtime = new \ACL\AgentRooms\Services\BrainRuntime( $runs, null, null, null, null, null, null, null, $fake, null, $flaky_events );
		$first = $runtime->run( $run_id );
		$this->assert_wp_error( $first, 'Controlled fan-out failure was not surfaced.' );
		$failed = $runs->find( $run_id );
		$this->assert_same( 'failed', $failed['status'], 'Fan-out failure did not enter due-gated recovery.' );
		$this->assert_same( count( $this->agents ), count( $failed['validated_responses'] ), 'Fan-out failure discarded its validated provider response.' );
		$this->assert_true( ! empty( $failed['next_attempt_at'] ) && strtotime( $failed['next_attempt_at'] . ' UTC' ) > time(), 'Fan-out failure did not persist a future retry time.' );
		$this->assert_same( 1, $fake->calls, 'Fan-out failure repeated the provider request.' );
		$this->assert_same( 1, $flaky_events->message_attempts, 'Controlled fan-out seam used an unexpected attempt count.' );
		$this->assert_same( 0, (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}acl_ar_messages WHERE brain_run_id=%d", $run_id ) ), 'Failed fan-out left a partial message.' );
		$this->assert_same( 0, (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}acl_ar_events e INNER JOIN {$wpdb->prefix}acl_ar_messages m ON m.id=e.legacy_message_id WHERE m.brain_run_id=%d AND e.event_type='message'", $run_id ) ), 'Failed fan-out left a partial event.' );
		$this->assert_same( 1, (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}acl_ar_usage WHERE brain_run_id=%d", $run_id ) ), 'Failed fan-out duplicated or omitted usage.' );
		$this->assert_true( ! in_array( $run_id, array_map( 'intval', array_column( $runs->pending( 20 ), 'id' ) ), true ), 'Future fan-out recovery entered the due queue early.' );

		$controller = new \ACL\AgentRooms\Rest\RoomWorkController(
			$rooms,
			new \ACL\AgentRooms\Services\AccessService( $rooms ),
			$runs,
			new \ACL\AgentRooms\Repositories\JobRepository(),
			$runtime,
			new \ACL\AgentRooms\Services\AgentRuntime()
		);
		$request = new \WP_REST_Request( 'POST', '/acl-agent-rooms/v1/rooms/' . $this->room . '/work' );
		$request->set_param( 'id', $this->room );
		$request->set_param( 'brain_run_ids', array( $run_id ) );
		$before_due = $controller->run( $request )->get_data();
		$this->assert_same( 1, $fake->calls, 'RoomWork repeated the provider request before fan-out recovery was due.' );
		$this->assert_same( 1, $flaky_events->message_attempts, 'RoomWork attempted fan-out before recovery was due.' );
		$this->assert_same( 'failed', $runs->find( $run_id )['status'], 'RoomWork changed saved recovery state before it was due.' );
		$this->assert_true( ! empty( $before_due['pending'] ), 'RoomWork dropped future fan-out recovery from foreground work.' );
		$this->assert_same( $failed['next_attempt_at'], $before_due['brain_runs'][0]['next_attempt_at'], 'RoomWork omitted the saved-response retry time.' );
		$this->assert_true( (int) $before_due['retry_after_ms'] >= 750, 'RoomWork omitted the saved-response retry delay.' );

		$queue = new \ACL\AgentRooms\Services\QueueService();
		$args = array( $run_id );
		wp_clear_scheduled_hook( \ACL\AgentRooms\Services\QueueService::BRAIN_HOOK, $args );
		$this->assert_true( true === wp_schedule_single_event( time() + 1, \ACL\AgentRooms\Services\QueueService::BRAIN_HOOK, $args ), 'Earlier Brain WP-Cron fixture was not scheduled.' );
		$wp_cron_retry = new \ReflectionMethod( \ACL\AgentRooms\Services\QueueService::class, 'schedule_wp_cron_retry' );
		$replacement_due = time() + 30;
		$this->assert_true( true === $wp_cron_retry->invoke( $queue, \ACL\AgentRooms\Services\QueueService::BRAIN_HOOK, $args, $replacement_due ), 'Brain WP-Cron retry replacement failed.' );
		$this->assert_true( (int) wp_next_scheduled( \ACL\AgentRooms\Services\QueueService::BRAIN_HOOK, $args ) >= $replacement_due, 'Brain WP-Cron retry retained an earlier pre-due event.' );
		wp_clear_scheduled_hook( \ACL\AgentRooms\Services\QueueService::BRAIN_HOOK, $args );

		$wpdb->update( $wpdb->prefix . 'acl_ar_brain_runs', array( 'next_attempt_at' => gmdate( 'Y-m-d H:i:s', time() - 1 ) ), array( 'id' => $run_id ) );
		$this->assert_true( in_array( $run_id, array_map( 'intval', array_column( $runs->pending( 20 ), 'id' ) ), true ), 'Due fan-out recovery did not enter the queue.' );
		$after_due = $controller->run( $request )->get_data();
		$done = $runs->find( $run_id );
		$this->assert_same( 'completed', $done['status'], 'Due saved-response fan-out did not recover.' );
		$this->assert_same( 1, $fake->calls, 'Due saved-response fan-out called the provider again.' );
		$this->assert_same( 4, $flaky_events->message_attempts, 'Due saved-response fan-out used an unexpected event attempt count.' );
		$this->assert_true( empty( $after_due['pending'] ), 'Completed saved-response fan-out remained pending.' );
		$this->assert_same( 0, (int) $after_due['retry_after_ms'], 'Completed saved-response fan-out retained a retry delay.' );
		$this->assert_same( count( $this->agents ), (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}acl_ar_messages WHERE brain_run_id=%d", $run_id ) ), 'Recovered fan-out did not publish exactly one message per agent.' );
		$this->assert_same( count( $this->agents ), (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}acl_ar_events e INNER JOIN {$wpdb->prefix}acl_ar_messages m ON m.id=e.legacy_message_id WHERE m.brain_run_id=%d AND e.event_type='message'", $run_id ) ), 'Recovered fan-out did not publish exactly one event per agent.' );
		$this->assert_same( count( $this->agents ), count( array_unique( $done['response_event_ids'] ) ), 'Recovered fan-out stored duplicate response event IDs.' );
		$this->assert_same( 1, (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}acl_ar_usage WHERE brain_run_id=%d", $run_id ) ), 'Recovered fan-out duplicated usage.' );
		$audit_key = hash( 'sha256', 'brain-run:' . $run_id . ':terminal' );
		$audit = ( new \ACL\AgentRooms\Repositories\EventRepository() )->find_by_idempotency_key( $audit_key );
		$this->assert_true( is_array( $audit ) && 'completed' === (string) ( $audit['metadata']['status'] ?? '' ), 'Recovered fan-out lacks one completed audit.' );
		$controller->run( $request );
		$this->assert_same( count( $this->agents ), (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}acl_ar_messages WHERE brain_run_id=%d", $run_id ) ), 'Replayed saved-response recovery duplicated messages.' );
		$this->assert_same( count( $this->agents ), (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}acl_ar_events e INNER JOIN {$wpdb->prefix}acl_ar_messages m ON m.id=e.legacy_message_id WHERE m.brain_run_id=%d AND e.event_type='message'", $run_id ) ), 'Replayed saved-response recovery duplicated events.' );
		$this->assert_same( 1, (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}acl_ar_usage WHERE brain_run_id=%d", $run_id ) ), 'Replayed saved-response recovery duplicated usage.' );
		$this->assert_same( 4, $flaky_events->message_attempts, 'Replayed completed recovery attempted fan-out again.' );

		$exhaust_message_id = $messages->create( array( 'room_id' => $this->room, 'sender_type' => 'user', 'sender_user_id' => $this->owner, 'content' => 'FANOUT-EXHAUSTION', 'client_request_id' => $this->prefix . '-fanout-exhaustion' ) );
		$exhaust_trigger = $events->create_message_event( $messages->find( (int) $exhaust_message_id ) );
		$exhaust_run = $runs->create( $this->room, $this->brain, (int) $exhaust_trigger['id'], $this->agents, 'controlled-fake', 'brain-model-v1' );
		$exhaust_id = (int) $exhaust_run['id'];
		$exhaust_fake = new ACL_AR_FakeBrainSwitchboard();
		$always_fail_events = new ACL_AR_FailBrainMessageEventService();
		$always_fail_events->failures_remaining = \ACL\AgentRooms\Repositories\BrainRunRepository::MAX_ATTEMPTS;
		$exhaust_runtime = new \ACL\AgentRooms\Services\BrainRuntime( $runs, null, null, null, null, null, null, null, $exhaust_fake, null, $always_fail_events );
		$clear_exhaust_retry = static function () use ( $exhaust_id ): void {
			wp_clear_scheduled_hook( \ACL\AgentRooms\Services\QueueService::BRAIN_HOOK, array( $exhaust_id ) );
			if ( function_exists( 'as_unschedule_all_actions' ) ) {
				as_unschedule_all_actions( \ACL\AgentRooms\Services\QueueService::BRAIN_HOOK, array( $exhaust_id ), 'acl-agent-rooms' );
			}
		};
		$this->assert_wp_error( $exhaust_runtime->run( $exhaust_id ), 'First exhausted fan-out seam did not fail.' );
		$clear_exhaust_retry();
		for ( $attempt = 2; $attempt <= \ACL\AgentRooms\Repositories\BrainRunRepository::MAX_ATTEMPTS; $attempt++ ) {
			$wpdb->update( $wpdb->prefix . 'acl_ar_brain_runs', array( 'next_attempt_at' => gmdate( 'Y-m-d H:i:s', time() - 1 ) ), array( 'id' => $exhaust_id ) );
			$this->assert_wp_error( $exhaust_runtime->run( $exhaust_id ), 'Saved fan-out exhaustion attempt did not fail.' );
			if ( $attempt < \ACL\AgentRooms\Repositories\BrainRunRepository::MAX_ATTEMPTS ) {
				$clear_exhaust_retry();
			}
		}
		$exhausted = $runs->find( $exhaust_id );
		$this->assert_same( 'failed', $exhausted['status'], 'Exhausted saved fan-out did not remain terminally failed.' );
		$this->assert_same( \ACL\AgentRooms\Repositories\BrainRunRepository::MAX_ATTEMPTS, (int) $exhausted['attempts'], 'Saved fan-out exhaustion used the wrong attempt count.' );
		$this->assert_same( null, $exhausted['next_attempt_at'], 'Exhausted saved fan-out retained pending work.' );
		$this->assert_same( 1, $exhaust_fake->calls, 'Saved fan-out exhaustion repeated the provider request.' );
		$this->assert_same( \ACL\AgentRooms\Repositories\BrainRunRepository::MAX_ATTEMPTS, $always_fail_events->message_attempts, 'Saved fan-out exhaustion used the wrong publication count.' );
		$this->assert_same( 0, (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}acl_ar_messages WHERE brain_run_id=%d", $exhaust_id ) ), 'Exhausted fan-out left a partial message.' );
		$this->assert_same( 1, (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}acl_ar_usage WHERE brain_run_id=%d", $exhaust_id ) ), 'Exhausted fan-out duplicated usage.' );
		$exhaust_audit = ( new \ACL\AgentRooms\Repositories\EventRepository() )->find_by_idempotency_key( hash( 'sha256', 'brain-run:' . $exhaust_id . ':terminal' ) );
		$this->assert_true( is_array( $exhaust_audit ) && 'failed' === (string) ( $exhaust_audit['metadata']['status'] ?? '' ), 'Exhausted fan-out omitted its terminal failed audit.' );
		foreach ( $this->agents as $agent_id ) {
			$this->assert_same( 'error', ( new \ACL\AgentRooms\Repositories\PresenceRepository() )->find( $this->room, 'agent', $agent_id )['state'], 'Exhausted fan-out did not project Error.' );
		}
		$scheduled = function_exists( 'as_has_scheduled_action' )
			? as_has_scheduled_action( \ACL\AgentRooms\Services\QueueService::BRAIN_HOOK, array( $exhaust_id ), 'acl-agent-rooms' )
			: wp_next_scheduled( \ACL\AgentRooms\Services\QueueService::BRAIN_HOOK, array( $exhaust_id ) );
		$this->assert_true( false === $scheduled || 0 === $scheduled, 'Exhausted fan-out scheduled another retry.' );
		$clear_exhaust_retry();
		if ( function_exists( 'as_unschedule_all_actions' ) ) {
			as_unschedule_all_actions( \ACL\AgentRooms\Services\QueueService::BRAIN_HOOK, array( $run_id ), 'acl-agent-rooms' );
		}
	}
	private function saved_response_recovery():void{
		global $wpdb;
		$messages = new \ACL\AgentRooms\Repositories\MessageRepository();
		$events   = new \ACL\AgentRooms\Services\RoomEventService();
		$trigger_id = $messages->create( array( 'room_id' => $this->room, 'sender_type' => 'user', 'sender_user_id' => $this->owner, 'content' => 'RECOVERY-TRIGGER', 'client_request_id' => $this->prefix . '-recovery' ) );
		$trigger = $events->create_message_event( $messages->find( (int) $trigger_id ) );
		$runs = new \ACL\AgentRooms\Repositories\BrainRunRepository();
		$run = $runs->create( $this->room, $this->brain, (int) $trigger['id'], $this->agents, 'controlled-fake', 'brain-model-v1' );
		$responses = array_map( static fn( $id ) => array( 'agent_id' => $id, 'content' => 'Recovered response ' . $id ), $this->agents );
		$wpdb->update( $wpdb->prefix . 'acl_ar_brain_runs', array( 'status' => 'response_saved', 'validated_response_json' => wp_json_encode( $responses ), 'prompt_tokens' => 77, 'completion_tokens' => 33, 'total_tokens' => 110 ), array( 'id' => (int) $run['id'] ) );
		$saved = $runs->find( (int) $run['id'] );
		$fake = new ACL_AR_FakeBrainSwitchboard();
		$runtime = new \ACL\AgentRooms\Services\BrainRuntime( $runs, null, null, null, null, null, null, null, $fake );
		$done = $runtime->run( (int) $run['id'] );
		$this->assert_same( 0, $fake->calls, 'Saved response recovery called Switchboard.' );
		$this->assert_same( 'completed', $done['status'], 'Saved response recovery did not complete.' );
		$this->assert_same( 3, (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}acl_ar_messages WHERE brain_run_id=%d", (int) $run['id'] ) ), 'Saved response recovery did not fan out exactly once.' );
		$runtime->run( (int) $run['id'] );
		$this->assert_same( 3, (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}acl_ar_messages WHERE brain_run_id=%d", (int) $run['id'] ) ), 'Recovery retry duplicated responses.' );
		$this->assert_same( 1, (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}acl_ar_usage WHERE brain_run_id=%d", (int) $run['id'] ) ), 'Recovery duplicated usage.' );

		$rooms = new \ACL\AgentRooms\Repositories\RoomRepository();
		$cutoff = (int) ( $rooms->find( $this->room )['cleared_through_event_id'] ?? 0 );
		$wpdb->update( $wpdb->prefix . 'acl_ar_rooms', array( 'cleared_through_event_id' => (int) $trigger['id'] ), array( 'id' => $this->room ) );
		$resume_saved = new \ReflectionMethod( \ACL\AgentRooms\Services\BrainRuntime::class, 'resume_saved' );
		$resume_saved->setAccessible( true );
		$late = $resume_saved->invoke( $runtime, $saved );
		$this->assert_true( is_array( $late ) && 'completed' === (string) $late['status'], 'Late saved-response cancellation did not resolve to the completed run.' );
		$this->assert_same( 'completed', $runs->find( (int) $run['id'] )['status'], 'Late saved-response cancellation changed completed state.' );
		$audit = ( new \ACL\AgentRooms\Repositories\EventRepository() )->find_by_idempotency_key( hash( 'sha256', 'brain-run:' . (int) $run['id'] . ':terminal' ) );
		$this->assert_true( is_array( $audit ) && 'completed' === (string) ( $audit['metadata']['status'] ?? '' ), 'Late saved-response cancellation replaced the completed audit.' );
		$this->assert_same( 3, (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}acl_ar_messages WHERE brain_run_id=%d", (int) $run['id'] ) ), 'Late saved-response cancellation changed published messages.' );
		$this->assert_same( 1, (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}acl_ar_usage WHERE brain_run_id=%d", (int) $run['id'] ) ), 'Late saved-response cancellation duplicated usage.' );
		foreach ( $this->agents as $agent_id ) {
			$this->assert_same( 'ready', ( new \ACL\AgentRooms\Repositories\PresenceRepository() )->find( $this->room, 'agent', $agent_id )['state'], 'Late saved-response cancellation changed Ready state.' );
		}
		$wpdb->update( $wpdb->prefix . 'acl_ar_rooms', array( 'cleared_through_event_id' => $cutoff ), array( 'id' => $this->room ) );
	}
	private function failure_grouping_and_recovery():void{
		global $wpdb;
		$agents_repo=new \ACL\AgentRooms\Repositories\AgentRepository();
		$sample=array($agents_repo->find($this->agents[0]),$agents_repo->find($this->agents[1]),$agents_repo->find($this->agents[2]));
		$sample[0]['execution_mode']='independent';$sample[0]['brain_id']=null;$sample[0]['sort_order']=30;
		$sample[1]['brain_id']=$this->brain;$sample[1]['sort_order']=20;
		$sample[2]['brain_id']=$this->brain+9999;$sample[2]['sort_order']=10;
		$grouped=(new \ACL\AgentRooms\Services\BrainGroupingService())->group(array($sample[0],$sample[1],$sample[2],$sample[1]));
		$this->assert_same(1,count($grouped['independent']),'Mixed grouping lost the independent agent.');
		$this->assert_same(2,count($grouped['brains']),'Mixed grouping did not create two Brain groups.');
		$this->assert_same($this->agents[2],(int)$grouped['brains'][0]['agents'][0]['id'],'Brain groups were not deterministic by assignment order.');

		$messages=new \ACL\AgentRooms\Repositories\MessageRepository();$events=new \ACL\AgentRooms\Services\RoomEventService();$runs=new \ACL\AgentRooms\Repositories\BrainRunRepository();
		$bad_message=$messages->create(array('room_id'=>$this->room,'sender_type'=>'user','sender_user_id'=>$this->owner,'content'=>'INVALID-OUTPUT-TRIGGER','client_request_id'=>$this->prefix.'-invalid'));
		$bad_trigger=$events->create_message_event($messages->find((int)$bad_message));$bad_run=$runs->create($this->room,$this->brain,(int)$bad_trigger['id'],$this->agents,'controlled-fake','brain-model-v1');
		$bad_fake=new ACL_AR_FakeBrainSwitchboard();$bad_fake->invalid=true;$before_audits=(int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->prefix}acl_ar_events WHERE room_id=%d AND event_type='brain_run'",$this->room));$wpdb->update($wpdb->prefix.'acl_ar_brain_runs',array('attempts'=>\ACL\AgentRooms\Repositories\BrainRunRepository::MAX_ATTEMPTS-1),array('id'=>(int)$bad_run['id']));
		$bad_runtime=new \ACL\AgentRooms\Services\BrainRuntime($runs,null,null,null,null,null,null,null,$bad_fake);$this->assert_wp_error($bad_runtime->run((int)$bad_run['id']),'Invalid structured output was not rejected by runtime.');
		$this->assert_same(1,$bad_fake->calls,'Invalid structured output used more than one provider request.');
		$this->assert_same(0,(int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->prefix}acl_ar_messages WHERE brain_run_id=%d",(int)$bad_run['id'])),'Invalid output produced partial messages.');
		$this->assert_same(0,(int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->prefix}acl_ar_usage WHERE brain_run_id=%d",(int)$bad_run['id'])),'Invalid output produced a usage record.');
		$this->assert_same($before_audits+1,(int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->prefix}acl_ar_events WHERE room_id=%d AND event_type='brain_run'",$this->room)),'Invalid output did not create exactly one terminal audit event.');
		$this->assert_same(0,(int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->prefix}acl_ar_agent_jobs WHERE trigger_message_id=%d",(int)$bad_message)),'Invalid output silently fell back to individual jobs.');
		$audit=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->prefix}acl_ar_events WHERE room_id=%d AND event_type='brain_run' ORDER BY id DESC LIMIT 1",$this->room),ARRAY_A);$audit['metadata']=json_decode((string)$audit['metadata_json'],true);$this->assert_same('room',$audit['audience_type'],'Terminal Brain failure was hidden from non-manager room participants.');$projection=new \ACL\AgentRooms\Services\EventProjectionService();$public=$projection->project_page(array($audit),false,$this->owner);$this->assert_true(!empty($public[0]['metadata']['error']),'Terminal Brain failure omitted its public room error.');$this->assert_true(!isset($public[0]['diagnostics']),'Terminal Brain failure exposed manager diagnostics to a room participant.');$projected=$projection->project_page(array($audit),true,$this->owner);$this->assert_same('response_validation',$projected[0]['diagnostics']['stage']??'','Terminal Brain failure omitted its stage.');$this->assert_same('acl_ar_brain_response_missing_agent',$projected[0]['diagnostics']['error_code']??'','Terminal Brain failure omitted its sanitized error code.');$this->assert_same(502,(int)($projected[0]['diagnostics']['result_status']??0),'Terminal Brain failure omitted its result status.');$this->assert_same('failed',$projected[0]['metadata']['status']??'','Terminal Brain failure was not projected to the room owner.');$this->assert_true(!empty($projected[0]['metadata']['error']),'Terminal Brain failure omitted its actionable public message.');
		foreach($this->agents as$agent_id){$this->assert_same('error',(new \ACL\AgentRooms\Repositories\PresenceRepository())->find($this->room,'agent',$agent_id)['state'],'Exhausted Brain failure did not project Error.');}

		$assignment=$wpdb->prefix.'acl_ar_room_agents';$wpdb->update($assignment,array('participation_state'=>'paused'),array('room_id'=>$this->room,'agent_id'=>$this->agents[2]));
		$pause_message=$messages->create(array('room_id'=>$this->room,'sender_type'=>'user','sender_user_id'=>$this->owner,'content'=>'PAUSED-EXCLUSION-TRIGGER','client_request_id'=>$this->prefix.'-paused','metadata'=>array('brain_trigger_mode'=>'automatic')));
		$pause_trigger=$events->create_message_event($messages->find((int)$pause_message));$pause_run=$runs->create($this->room,$this->brain,(int)$pause_trigger['id'],$this->agents,'controlled-fake','brain-model-v1');$pause_fake=new ACL_AR_FakeBrainSwitchboard();$pause_runtime=new \ACL\AgentRooms\Services\BrainRuntime($runs,null,null,null,null,null,null,null,$pause_fake);$pause_done=$pause_runtime->run((int)$pause_run['id']);
		$this->assert_same('completed',$pause_done['status'],'Run with one paused agent did not complete for eligible agents.');
		$this->assert_same(array($this->agents[0],$this->agents[1]),$pause_fake->requests[0]['metadata']['agent_ids'],'Paused agent was not excluded before dispatch.');
		$this->assert_same(2,(int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->prefix}acl_ar_messages WHERE brain_run_id=%d",(int)$pause_run['id'])),'Paused-agent run did not fan out to exactly two eligible agents.');
		$wpdb->update($assignment,array('participation_state'=>'active'),array('room_id'=>$this->room,'agent_id'=>$this->agents[2]));

		$stale_message=$messages->create(array('room_id'=>$this->room,'sender_type'=>'user','sender_user_id'=>$this->owner,'content'=>'STALE-LEASE-TRIGGER','client_request_id'=>$this->prefix.'-stale'));
		$stale_trigger=$events->create_message_event($messages->find((int)$stale_message));$stale_run=$runs->create($this->room,$this->brain,(int)$stale_trigger['id'],$this->agents,'controlled-fake','brain-model-v1');
		$wpdb->update($wpdb->prefix.'acl_ar_brain_runs',array('status'=>'running','attempts'=>1,'lease_token'=>'abandoned','locked_at'=>gmdate('Y-m-d H:i:s',time()-600)),array('id'=>(int)$stale_run['id']));
		$this->assert_true(in_array((int)$stale_run['id'],array_map(static fn($row)=>(int)$row['id'],$runs->pending(20)),true),'Stale Brain lease was not recoverable by the queue.');
		$stale_fake=new ACL_AR_FakeBrainSwitchboard();$stale_runtime=new \ACL\AgentRooms\Services\BrainRuntime($runs,null,null,null,null,null,null,null,$stale_fake);$stale_done=$stale_runtime->run((int)$stale_run['id']);
		$this->assert_same('completed',$stale_done['status'],'Stale Brain run did not recover to completion.');
		$this->assert_same(1,$stale_fake->calls,'Stale Brain recovery did not issue exactly one replacement dispatch.');
		$this->assert_same(2,$runs->find((int)$stale_run['id'])['attempts'],'Stale Brain recovery attempt accounting is wrong.');
	}
	private function ask_trigger_path():void{
		global $wpdb;
		$agent_rows=array_map(fn($id)=>(new \ACL\AgentRooms\Repositories\AgentRepository())->find($id),$this->agents);
		$slugs=array_map(static fn($agent)=>(string)$agent['slug'],$agent_rows);
		$input='/ask '.implode(',',$slugs).' MULTI-ASK-TRIGGER';
		$parsed=(new \ACL\AgentRooms\Services\AgentMentionParser())->parse_slash_command($input);
		$this->assert_same($slugs,$parsed['agent_slugs'],'Multi-agent /ask did not preserve requested slug order.');
		$fake=new ACL_AR_FakeBrainSwitchboard();
		$filter=static fn($client,$request)=>$fake;
		add_filter('acl_ar_switchboard_client',$filter,10,2);
		try{
			$request=new \WP_REST_Request('POST','/');
			$request->set_param('id',$this->room);
			$request->set_param('content',$input);
			$request->set_param('client_request_id',$this->prefix.'-multi-ask');
			$rooms=new \ACL\AgentRooms\Repositories\RoomRepository();$agents=new \ACL\AgentRooms\Repositories\AgentRepository();$messages=new \ACL\AgentRooms\Repositories\MessageRepository();$jobs=new \ACL\AgentRooms\Repositories\JobRepository();$access=new \ACL\AgentRooms\Services\AccessService($rooms);
			$response=(new \ACL\AgentRooms\Rest\MessagesController($rooms,$agents,$messages,$jobs,$access,new \ACL\AgentRooms\Services\AgentRuntime($jobs,$rooms,$agents,$messages)))->create($request);
			$this->assert_true($response instanceof \WP_REST_Response,'Multi-agent /ask did not return a REST response.');
			$data=$response->get_data();
			$this->assert_same(0,$fake->calls,'Multi-agent /ask called Shared Brain before returning the human message.');
			$this->assert_same(1,count($data['brain_runs']??array()),'Multi-agent /ask did not create exactly one Brain run.');
			$this->assert_same(0,count($data['jobs']??array()),'Multi-agent /ask created individual agent jobs.');
			$run_id=(int)($data['brain_runs'][0]['id']??0);
			(new \ACL\AgentRooms\Services\BrainRuntime())->run($run_id);
		}finally{remove_filter('acl_ar_switchboard_client',$filter,10);}
		$this->assert_same(1,$fake->calls,'Multi-agent /ask did not use exactly one Brain request.');
		$this->assert_same(3,(int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->prefix}acl_ar_messages WHERE brain_run_id=%d",$run_id)),'Multi-agent /ask did not fan out three responses.');
	}
	private function no_fallback_and_disablement():void{global$wpdb;$agents=new \ACL\AgentRooms\Repositories\AgentRepository();$original=$agents->find($this->agents[0]);$this->assert_same('independent-provider-0',$original['provider_route'],'Independent provider was overwritten.');$data=$original;$data['execution_mode']='independent';$data['brain_id']=null;$agents->update($this->agents[0],$data);$restored=$agents->find($this->agents[0]);$this->assert_same('independent-provider-0',$restored['provider_route'],'Switching to Independent did not restore stored provider.');$this->assert_same('independent-model-0',$restored['model'],'Switching to Independent did not restore stored model.');$data=$restored;$data['execution_mode']='brain';$data['brain_id']=$this->brain;$agents->update($this->agents[0],$data);$brains=new \ACL\AgentRooms\Repositories\BrainRepository();$brains->set_enabled($this->brain,false);$this->assert_same('controlled-fake',$brains->find($this->brain)['provider'],'Disabling a Brain erased its saved runtime.');$this->assert_wp_error((new \ACL\AgentRooms\Services\BrainConfigService())->agent_availability($agents->find($this->agents[0])),'Disabled Brain did not make agent unavailable.');$messages=new \ACL\AgentRooms\Repositories\MessageRepository();$events=new \ACL\AgentRooms\Services\RoomEventService();$trigger_id=$messages->create(array('room_id'=>$this->room,'sender_type'=>'user','sender_user_id'=>$this->owner,'content'=>'DISABLED-TRIGGER','client_request_id'=>$this->prefix.'-disabled'));$trigger=$events->create_message_event($messages->find((int)$trigger_id));$runs=new \ACL\AgentRooms\Repositories\BrainRunRepository();$run=$runs->create($this->room,$this->brain,(int)$trigger['id'],$this->agents,'controlled-fake','brain-model-v1');$fake=new ACL_AR_FakeBrainSwitchboard();$runtime=new \ACL\AgentRooms\Services\BrainRuntime($runs,null,null,null,null,null,null,null,$fake);$this->assert_wp_error($runtime->run((int)$run['id']),'Disabled Brain run did not fail safely.');$this->assert_same(0,$fake->calls,'Disabled Brain called Switchboard or fell back.');$this->assert_same('canceled',$runs->find((int)$run['id'])['status'],'Disabled Brain run was not canceled.');$this->assert_same(0,(int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->prefix}acl_ar_agent_jobs WHERE trigger_message_id=%d",(int)$trigger_id)),'Disabled Brain created individual fallback jobs.');$brains->set_enabled($this->brain,true);$this->assert_same('brain-model-v1',$brains->find($this->brain)['model'],'Re-enabling a Brain did not preserve its saved model.');}
	private function routes_ui_and_security():void{(new \ACL\AgentRooms\Rest\BrainsController())->register_routes();$routes=rest_get_server()->get_routes();$this->assert_true(isset($routes['/acl-agent-rooms/v1/brains']),'Brain collection route missing.');$this->assert_true(isset($routes['/acl-agent-rooms/v1/brains/(?P<id>[\d]+)']),'Brain item route missing.');$this->assert_true(isset($routes['/acl-agent-rooms/v1/rooms/(?P<room_id>[\d]+)/brain-runs']),'Brain run diagnostics route missing.');$root=dirname(__DIR__);$admin=file_get_contents($root.'/assets/js/admin.js');$agents=file_get_contents($root.'/includes/Admin/AgentsPage.php');$uninstall=file_get_contents($root.'/uninstall.php');$runtime=preg_replace('/\s+/','',(string)file_get_contents($root.'/includes/Services/BrainRuntime.php'));foreach(array('data-acl-ar-execution-mode','data-acl-ar-brain-select','acl-ar-runtime-inherited')as$needle){$this->assert_true(false!==strpos($admin.$agents,$needle),'Agent execution UI contract missing '.$needle);}$this->assert_true(false!==strpos($uninstall,'acl_ar_brain_runs')&&false!==strpos($uninstall,'acl_ar_brains'),'Destructive uninstall omits Brain tables.');$this->assert_true(0===preg_match('/api[_-]?key|authorization header|provider credential/i',wp_json_encode((new \ACL\AgentRooms\Rest\BrainsController())->prepare((new \ACL\AgentRooms\Repositories\BrainRepository())->find($this->brain)))),'Brain REST response exposed credential fields.');$this->assert_true(false!==strpos($runtime,"'agent_id'=>null"),'One-record Brain usage contract is missing.');}
	private function room_work_route_security():void{
		$rooms = new \ACL\AgentRooms\Repositories\RoomRepository();
		$agents = new \ACL\AgentRooms\Repositories\AgentRepository();
		$messages = new \ACL\AgentRooms\Repositories\MessageRepository();
		$jobs = new \ACL\AgentRooms\Repositories\JobRepository();
		$runs = new \ACL\AgentRooms\Repositories\BrainRunRepository();
		$access = new \ACL\AgentRooms\Services\AccessService( $rooms );
		$agent_runtime = new \ACL\AgentRooms\Services\AgentRuntime( $jobs, $rooms, $agents, $messages );
		$controller = new \ACL\AgentRooms\Rest\RoomWorkController( $rooms, $access, $runs, $jobs, new \ACL\AgentRooms\Services\BrainRuntime( $runs ), $agent_runtime );
		$controller->register_routes();
		$routes = rest_get_server()->get_routes();
		$this->assert_true( isset( $routes['/acl-agent-rooms/v1/rooms/(?P<id>[\d]+)/work'] ), 'Room foreground worker route missing.' );
		$missing = new \WP_REST_Request( 'POST', '/' );
		$missing->set_param( 'id', $this->room );
		$this->assert_wp_error( $controller->permissions( $missing ), 'Room foreground worker accepted a missing nonce.' );
		$allowed = new \WP_REST_Request( 'POST', '/' );
		$allowed->set_param( 'id', $this->room );
		$allowed->set_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );
		$this->assert_same( true, $controller->permissions( $allowed ), 'Room owner could not start foreground work.' );
		$allowed->set_param( 'brain_run_ids', array( 1, 2, 3, 4, 5, 6 ) );
		$this->assert_wp_error( $controller->run( $allowed ), 'Room foreground worker accepted an unbounded Brain list.' );
	}
	private function cleanup():void{global$wpdb;wp_set_current_user($this->owner);if($this->room){$wpdb->delete($wpdb->prefix.'acl_ar_usage',array('room_id'=>$this->room),array('%d'));$rooms=new \ACL\AgentRooms\Repositories\RoomRepository();if($rooms->find($this->room)){$rooms->delete($this->room);}}$repo=new \ACL\AgentRooms\Repositories\AgentRepository();foreach($this->agents as$id){if($repo->find($id)){$repo->delete($id);}}if($this->brain){(new \ACL\AgentRooms\Repositories\BrainRepository())->delete($this->brain);}if($this->owner){wp_delete_user($this->owner);}}
	private function fingerprint():string{global$wpdb;$like=$wpdb->esc_like($wpdb->prefix.'acl_ar_').'%';$c=$wpdb->get_results($wpdb->prepare('SELECT TABLE_NAME,COLUMN_NAME,ORDINAL_POSITION,COLUMN_TYPE,IS_NULLABLE,COLUMN_DEFAULT,EXTRA FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=%s AND TABLE_NAME LIKE %s ORDER BY TABLE_NAME,ORDINAL_POSITION',DB_NAME,$like),ARRAY_A);$i=$wpdb->get_results($wpdb->prepare('SELECT TABLE_NAME,INDEX_NAME,NON_UNIQUE,SEQ_IN_INDEX,COLUMN_NAME,SUB_PART,INDEX_TYPE FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=%s AND TABLE_NAME LIKE %s ORDER BY TABLE_NAME,INDEX_NAME,SEQ_IN_INDEX',DB_NAME,$like),ARRAY_A);return hash('sha256',wp_json_encode(array($c,$i)));}
}
