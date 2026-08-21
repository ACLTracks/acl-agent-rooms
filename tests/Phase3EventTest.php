<?php

use ACL\AgentRooms\Models\RoomEvent;
use ACL\AgentRooms\Repositories\AgentRepository;
use ACL\AgentRooms\Repositories\EventRepository;
use ACL\AgentRooms\Repositories\JobRepository;
use ACL\AgentRooms\Repositories\MessageRepository;
use ACL\AgentRooms\Repositories\RoomRepository;
use ACL\AgentRooms\Rest\MessagesController;
use ACL\AgentRooms\Services\AccessService;
use ACL\AgentRooms\Services\AgentRuntime;
use ACL\AgentRooms\Services\EventBackfillService;
use ACL\AgentRooms\Services\RoomEventService;

class ACL_AR_Phase3EventTest extends ACL_AR_TestCase {
	private AgentRepository $agents;
	private RoomRepository $rooms;
	private MessageRepository $messages;
	private JobRepository $jobs;
	private EventRepository $events;
	private RoomEventService $event_service;
	private int $user_id = 0;
	private int $room_id = 0;
	private int $agent_id = 0;
	private string $prefix;
	private array $old_options = array();

	public function run(): void {
		$this->prefix='phase3-'.gmdate('YmdHis').'-'.wp_generate_password(6,false,false);
		$this->agents=new AgentRepository();$this->rooms=new RoomRepository();$this->messages=new MessageRepository();$this->jobs=new JobRepository();$this->events=new EventRepository();$this->event_service=new RoomEventService($this->events);
		foreach(array('acl_ar_event_backfill_cursor','acl_ar_event_backfill_status','acl_ar_event_backfill_error') as $option){$this->old_options[$option]=get_option($option,null);}
		$this->set_up_fixture();
		try {
			$this->test_schema_and_repeat_migration();$this->pass('event_schema_and_repeat_migration');
			$this->test_global_backfill_exactness();$this->pass('existing_message_backfill_exactness');
			$this->test_partial_backfill_resume();$this->pass('partial_backfill_resume_and_idempotency');
			$this->test_user_dual_write_and_reconciliation();$this->pass('user_dual_write_duplicate_and_reconciliation');
			$this->test_agent_dual_write_and_recovery();$this->pass('agent_response_dual_write_and_recovery');
			$this->test_concurrent_event_idempotency();$this->pass('concurrent_event_idempotency');
			$this->test_lifecycle_contract();$this->pass('lifecycle_idempotency_and_sanitization');
			$this->test_new_table_room_deletion();$this->pass('new_table_room_deletion');
			$this->test_new_table_deletion_rollback();$this->pass('new_table_deletion_rollback');
			$this->test_compatibility_surface();$this->pass('shortcode_and_no_event_route_compatibility');
		} finally {$this->tear_down_fixture();}
	}

	private function set_up_fixture(): void {
		$this->user_id=wp_insert_user(array('user_login'=>$this->prefix,'user_pass'=>wp_generate_password(24),'user_email'=>$this->prefix.'@example.test','role'=>'subscriber'));
		if(is_wp_error($this->user_id)){throw new RuntimeException($this->user_id->get_error_message());}
		get_user_by('id',$this->user_id)->add_cap('acl_ar_use_rooms');wp_set_current_user($this->user_id);
		$this->agent_id=$this->agents->create(array('name'=>$this->prefix,'slug'=>$this->prefix,'provider_route'=>'fake','model'=>'fake','system_prompt'=>'test','enabled'=>1));
		$this->room_id=$this->rooms->create(array('title'=>$this->prefix,'slug'=>$this->prefix,'owner_user_id'=>$this->user_id,'agent_reply_mode'=>'manual'));
		if(is_wp_error($this->agent_id)||is_wp_error($this->room_id)){throw new RuntimeException('Phase 3 fixture creation failed.');}
		$result=$this->rooms->assign_agents($this->room_id,array($this->agent_id));if(is_wp_error($result)){throw new RuntimeException($result->get_error_message());}
	}

	private function test_schema_and_repeat_migration(): void {
		global $wpdb;
		\ACL\AgentRooms\Installer::install();\ACL\AgentRooms\Installer::install();
		foreach(array('acl_ar_events','acl_ar_event_reactions','acl_ar_room_reads','acl_ar_room_presence') as $suffix){$this->assert_same($wpdb->prefix.$suffix,(string)$wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s',$wpdb->prefix.$suffix)),'Missing event table '.$suffix);}
		$required=array('room_id_id','room_event_id','room_actor_id','room_audience_id','parent_event_id','job_id','legacy_message_id','idempotency_key');
		$index_rows=$wpdb->get_results('SHOW INDEX FROM '.$wpdb->prefix.'acl_ar_events',ARRAY_A);$indexes=array_values(array_unique(array_map(static fn(array $row):string=>(string)$row['Key_name'],$index_rows)));
		foreach($required as $index){$this->assert_true(in_array($index,$indexes,true),'Missing event index '.$index);}
		$this->assert_same(ACL_AR_DB_VERSION,(string)get_option('acl_ar_db_version'),'Repeat migration changed schema version.');
	}

	private function test_global_backfill_exactness(): void {
		global $wpdb;
		$result=(new EventBackfillService($this->messages,$this->event_service,$this->jobs))->run_batch(500);
		$this->assert_true(!is_wp_error($result),'Global backfill failed.');
		$message_count=(int)$wpdb->get_var('SELECT COUNT(*) FROM '.$wpdb->prefix.'acl_ar_messages');
		$event_count=(int)$wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}acl_ar_events WHERE legacy_message_id IS NOT NULL");
		$this->assert_same($message_count,$event_count,'Legacy message/event counts differ.');
		$this->assert_same(0,$this->messages->count_missing_events(),'Backfill left missing message events.');
	}

	private function test_partial_backfill_resume(): void {
		global $wpdb;
		$ids=array();for($i=0;$i<3;$i++){$ids[]=$this->messages->create(array('room_id'=>$this->room_id,'sender_type'=>'user','sender_user_id'=>$this->user_id,'content'=>'partial-'.$i));}
		$backfill=new EventBackfillService($this->messages,$this->event_service,$this->jobs);$first=$backfill->run_batch(1);
		$this->assert_same(1,(int)$first['processed'],'Bounded batch did not stop at one.');$this->assert_true(!empty($first['has_more']),'Partial batch did not report continuation.');
		while($this->messages->count_missing_events()>0){$result=$backfill->run_batch(2);$this->assert_true(!is_wp_error($result),'Backfill resume failed.');}
		$count_before=(int)$wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM '.$wpdb->prefix.'acl_ar_events WHERE legacy_message_id IN (%d,%d,%d)',...$ids));$backfill->run_batch(10);$count_after=(int)$wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM '.$wpdb->prefix.'acl_ar_events WHERE legacy_message_id IN (%d,%d,%d)',...$ids));
		$this->assert_same(3,$count_before,'Partial messages did not get exactly one event each.');$this->assert_same($count_before,$count_after,'Repeat backfill duplicated events.');
	}

	private function controller( ACL_AR_FakeSwitchboard $fake ): MessagesController {
		$access=new AccessService($this->rooms);$runtime=new AgentRuntime($this->jobs,$this->rooms,$this->agents,$this->messages,null,$fake);
		return new MessagesController($this->rooms,$this->agents,$this->messages,$this->jobs,$access,$runtime);
	}

	private function request( string $content, string $key ): WP_REST_Request {$r=new WP_REST_Request('POST','/acl-agent-rooms/v1/rooms/'.$this->room_id.'/messages');$r->set_param('id',$this->room_id);$r->set_param('content',$content);$r->set_param('client_request_id',$key);return $r;}

	private function test_user_dual_write_and_reconciliation(): void {
		global $wpdb;$fake=new ACL_AR_FakeSwitchboard();$controller=$this->controller($fake);$key='phase3-client-'.wp_generate_password(16,false,false);
		$first=$controller->create($this->request('dual write',$key));$data=$first->get_data();$message_id=(int)$data['message_id'];$event=$this->events->find_by_legacy_message_id($message_id);
		$this->assert_true((bool)$event,'User message event missing.');$second=$controller->create($this->request('dual write',$key));$this->assert_same($message_id,(int)$second->get_data()['message_id'],'Duplicate request changed message.');$this->assert_same((int)$event['id'],(int)$this->events->find_by_legacy_message_id($message_id)['id'],'Duplicate request changed event.');
		$missing=$this->messages->create_user_idempotent($this->room_id,$this->user_id,'repair me','phase3-repair-'.wp_generate_password(12,false,false));$repair_key=(string)$missing['message']['client_request_id'];
		$this->assert_true(null===$this->events->find_by_legacy_message_id((int)$missing['id']),'Direct fixture unexpectedly dual-wrote.');$repaired=$controller->create($this->request('repair me',$repair_key));
		$this->assert_same((int)$missing['id'],(int)$repaired->get_data()['message_id'],'Reconciliation duplicated message.');$this->assert_true(null!==$this->events->find_by_legacy_message_id((int)$missing['id']),'Reconciliation did not repair event.');
	}

	private function test_agent_dual_write_and_recovery(): void {
		$trigger=$this->messages->create_user_idempotent($this->room_id,$this->user_id,'agent trigger','phase3-agent-'.wp_generate_password(12,false,false));$this->event_service->create_message_event($trigger['message']);
		$job=$this->jobs->create($this->room_id,(int)$trigger['id'],$this->agent_id,JobRepository::request_key('phase3',$this->room_id,(int)$trigger['id'],$this->agent_id));$fake=new ACL_AR_FakeSwitchboard();$runtime=new AgentRuntime($this->jobs,$this->rooms,$this->agents,$this->messages,null,$fake);
		$first=$runtime->run_job((int)$job);$second=$runtime->run_job((int)$job);$message=$this->messages->find((int)$first['response_message_id']);$event=$this->events->find_by_legacy_message_id((int)$message['id']);
		$this->assert_true((bool)$event,'Agent response event missing.');$this->assert_same((int)$event['id'],(int)$this->events->find_by_legacy_message_id((int)$message['id'])['id'],'Duplicate job changed response event.');$this->assert_same(1,$fake->calls,'Duplicate job called fake provider twice.');
		$trigger2=$this->messages->create_user_idempotent($this->room_id,$this->user_id,'crash','phase3-crash-'.wp_generate_password(12,false,false));$job2=$this->jobs->create($this->room_id,(int)$trigger2['id'],$this->agent_id,JobRepository::request_key('phase3-crash',$this->room_id,(int)$trigger2['id'],$this->agent_id));$response=$this->messages->create(array('room_id'=>$this->room_id,'sender_type'=>'agent','sender_agent_id'=>$this->agent_id,'content'=>'stored before event','response_job_id'=>$job2));$calls=$fake->calls;$recovered=$runtime->run_job((int)$job2);
		$this->assert_same((int)$response,(int)$recovered['response_message_id'],'Crash recovery response mismatch.');$this->assert_true(null!==$this->events->find_by_legacy_message_id((int)$response),'Crash recovery did not repair response event.');$this->assert_same($calls,$fake->calls,'Crash recovery called provider.');
	}

	private function test_concurrent_event_idempotency(): void {
		$message=$this->messages->create(array('room_id'=>$this->room_id,'sender_type'=>'system','content'=>'concurrent'));$row=$this->messages->find((int)$message);$one=$this->event_service->create_from_legacy_message($row);$two=$this->event_service->create_from_legacy_message($row);$this->assert_same((int)$one['id'],(int)$two['id'],'Concurrent-equivalent insertion duplicated event.');
	}

	private function test_lifecycle_contract(): void {
		global $wpdb;$trigger=$this->messages->create(array('room_id'=>$this->room_id,'sender_type'=>'user','sender_user_id'=>$this->user_id,'content'=>'failure'));$job=$this->jobs->create($this->room_id,(int)$trigger,$this->agent_id,JobRepository::request_key('phase3-fail',$this->room_id,(int)$trigger,$this->agent_id));$this->jobs->fail((int)$job,'internal','provider_error','Authorization: Bearer exposed-secret',false,0);
		$stored=$this->jobs->find((int)$job);$one=$this->event_service->create_agent_lifecycle($stored,RoomEvent::TYPE_AGENT_FAILED,array('error'=>\ACL\AgentRooms\Services\PublicError::message($stored['public_error'])));$two=$this->event_service->create_agent_lifecycle($stored,RoomEvent::TYPE_AGENT_FAILED,array('error'=>\ACL\AgentRooms\Services\PublicError::message($stored['public_error'])));
		$this->assert_same((int)$one['id'],(int)$two['id'],'Lifecycle state duplicated.');$this->assert_true(false===stripos(wp_json_encode($two['metadata']),'exposed-secret'),'Lifecycle metadata leaked secret.');
		$count=(int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->prefix}acl_ar_events WHERE job_id=%d AND event_type=%s",$job,RoomEvent::TYPE_AGENT_FAILED));$this->assert_same(1,$count,'Lifecycle unique row count invalid.');
	}

	private function seed_structural_rows( int $room_id, int $event_id ): void {global $wpdb;$now=current_time('mysql',true);$wpdb->insert($wpdb->prefix.'acl_ar_event_reactions',array('event_id'=>$event_id,'user_id'=>$this->user_id,'reaction'=>'test','created_at'=>$now));$wpdb->insert($wpdb->prefix.'acl_ar_room_reads',array('room_id'=>$room_id,'user_id'=>$this->user_id,'last_read_event_id'=>$event_id,'updated_at'=>$now));$wpdb->insert($wpdb->prefix.'acl_ar_room_presence',array('room_id'=>$room_id,'actor_type'=>'user','actor_id'=>$this->user_id,'state'=>'online','updated_at'=>$now));}
	private function structural_counts( int $room_id, int $event_id ): array {global $wpdb;return array((int)$wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM '.$wpdb->prefix.'acl_ar_events WHERE room_id=%d',$room_id)),(int)$wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM '.$wpdb->prefix.'acl_ar_event_reactions WHERE event_id=%d',$event_id)),(int)$wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM '.$wpdb->prefix.'acl_ar_room_reads WHERE room_id=%d',$room_id)),(int)$wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM '.$wpdb->prefix.'acl_ar_room_presence WHERE room_id=%d',$room_id)));}

	private function make_deletion_room( string $suffix ): array {$room=$this->rooms->create(array('title'=>$this->prefix.$suffix,'slug'=>$this->prefix.$suffix,'owner_user_id'=>$this->user_id));$message=$this->messages->create(array('room_id'=>$room,'sender_type'=>'user','sender_user_id'=>$this->user_id,'content'=>'delete'));$event=$this->event_service->create_from_legacy_message($this->messages->find((int)$message));$this->seed_structural_rows((int)$room,(int)$event['id']);return array((int)$room,(int)$message,(int)$event['id']);}
	private function test_new_table_room_deletion(): void {[$room,$message,$event]=$this->make_deletion_room('-delete');$result=$this->rooms->delete($room);$this->assert_true(true===$result,'Room deletion failed.');$this->assert_same(array(0,0,0,0),$this->structural_counts($room,$event),'Room deletion left event-owned rows.');}
	private function test_new_table_deletion_rollback(): void {[$room,$message,$event]=$this->make_deletion_room('-rollback');$before=$this->structural_counts($room,$event);$fail=static fn($value,$operation,$step)=>'delete'===$operation&&7===$step;add_filter('acl_ar_room_mutation_fail',$fail,10,3);$result=$this->rooms->delete($room);remove_filter('acl_ar_room_mutation_fail',$fail,10);$this->assert_wp_error($result,'Injected new-table deletion failure did not error.');$this->assert_same($before,$this->structural_counts($room,$event),'New-table deletion rollback lost rows.');$this->assert_true(null!==$this->rooms->find($room)&&null!==$this->messages->find($message),'Rollback lost legacy rows.');$this->rooms->delete($room);}

	private function test_compatibility_surface(): void {wp_set_current_user($this->user_id);$html=(new \ACL\AgentRooms\Shortcodes\AgentRoomShortcode($this->rooms,new AccessService($this->rooms)))->render(array('id'=>$this->room_id));$this->assert_true(false!==strpos($html,'acl-ar-room'),'Shortcode mount failed.');do_action('rest_api_init');foreach(array_keys(rest_get_server()->get_routes()) as $route){$this->assert_true(false===strpos($route,'acl-agent-rooms/v1/events')&&false===strpos($route,'/events'),'Event REST route shipped in Phase 3.');}}

	private function pass(string $name):void{echo 'PASS '.$name.PHP_EOL;}
	private function tear_down_fixture(): void {global $wpdb;remove_all_filters('acl_ar_room_mutation_fail');$wpdb->delete($wpdb->prefix.'acl_ar_usage',array('room_id'=>$this->room_id));if($this->rooms->find($this->room_id)){$this->rooms->delete($this->room_id);}if($this->agent_id){$this->agents->delete($this->agent_id);}if($this->user_id){wp_delete_user($this->user_id);}foreach($this->old_options as $option=>$value){if(null===$value){delete_option($option);}else{update_option($option,$value,false);}}wp_set_current_user(0);}
}
