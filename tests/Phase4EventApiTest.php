<?php

use ACL\AgentRooms\Models\RoomEvent;
use ACL\AgentRooms\Repositories\AgentRepository;
use ACL\AgentRooms\Repositories\EventRepository;
use ACL\AgentRooms\Repositories\RoomRepository;
use ACL\AgentRooms\Rest\EventsController;
use ACL\AgentRooms\Services\AccessService;
use ACL\AgentRooms\Services\EventCursor;
use ACL\AgentRooms\Services\EventProjectionService;
use ACL\AgentRooms\Services\RoomApplicationService;
use ACL\AgentRooms\Services\RoomEventService;

class ACL_AR_Phase4EventApiTest extends ACL_AR_TestCase {
	private AgentRepository $agents; private RoomRepository $rooms; private EventRepository $events; private RoomEventService $writer; private AccessService $access; private RoomApplicationService $application; private EventCursor $cursor;
	private int $owner=0; private int $member=0; private int $outsider=0; private int $room=0; private int $public_room=0; private int $agent=0; private string $prefix='';

	public function run(): void {
		$this->prefix='phase4-'.gmdate('YmdHis').'-'.wp_generate_password(6,false,false); $this->agents=new AgentRepository();$this->rooms=new RoomRepository();$this->events=new EventRepository();$this->writer=new RoomEventService($this->events);$this->access=new AccessService($this->rooms);$this->cursor=new EventCursor();$this->application=new RoomApplicationService($this->rooms,$this->access);
		$this->set_up();
		try {
			$this->test_route_and_validation();$this->pass('event_route_and_arguments');
			$this->test_cursor_contract();$this->pass('signed_cursor_validation');
			$this->test_visibility_and_paging();$this->pass('visibility_and_cursor_paging');
			$this->test_projection_security();$this->pass('projection_identity_and_redaction');
			$this->test_access_and_http_cache();$this->pass('access_and_private_etag');
			$this->test_legacy_routes();$this->pass('legacy_route_compatibility');
		} finally {$this->tear_down();}
	}

	private function set_up(): void {
		$this->owner=$this->user('owner');$this->member=$this->user('member');$this->outsider=$this->user('outsider');
		get_user_by('id',$this->owner)->add_cap('acl_ar_manage_own_rooms');wp_set_current_user($this->owner);
		$this->agent=$this->agents->create(array('name'=>'Phase Four Agent','slug'=>$this->prefix.'-agent','provider_route'=>'fake','model'=>'fake','system_prompt'=>'test','avatar_url'=>'https://example.test/agent.png','enabled'=>1));
		$this->room=$this->rooms->create(array('title'=>$this->prefix,'slug'=>$this->prefix,'owner_user_id'=>$this->owner,'type'=>'private','visibility'=>'private'));
		$this->public_room=$this->rooms->create(array('title'=>$this->prefix.' public','slug'=>$this->prefix.'-public','owner_user_id'=>$this->owner,'type'=>'public','visibility'=>'public'));
		$this->rooms->add_member($this->room,$this->member,'member');
		if(is_wp_error($this->agent)||is_wp_error($this->room)||is_wp_error($this->public_room)){throw new RuntimeException('Phase 4 fixture setup failed.');}
	}

	private function user(string $suffix): int {$id=wp_insert_user(array('user_login'=>$this->prefix.'-'.$suffix,'user_pass'=>wp_generate_password(24),'user_email'=>$this->prefix.'-'.$suffix.'@example.test','display_name'=>'Phase Four '.ucfirst($suffix),'role'=>'subscriber'));if(is_wp_error($id)){throw new RuntimeException($id->get_error_message());}get_user_by('id',$id)->add_cap('acl_ar_use_rooms');return (int)$id;}
	private function event(string $content,string $audience='room',?int $audience_id=null,string $actor='user',?int $actor_id=null,array $metadata=array(),string $type=RoomEvent::TYPE_MESSAGE): array {$result=$this->writer->create(array('room_id'=>$this->room,'event_type'=>$type,'actor_type'=>$actor,'actor_id'=>$actor_id?:('agent'===$actor?$this->agent:$this->owner),'audience_type'=>$audience,'audience_id'=>$audience_id,'content'=>$content,'content_format'=>'plain','metadata'=>$metadata));if(is_wp_error($result)){throw new RuntimeException($result->get_error_message());}return $result;}

	private function test_route_and_validation(): void {
		$controller=new EventsController($this->rooms,$this->access,$this->application);$controller->register_routes();$routes=rest_get_server()->get_routes();$path='/acl-agent-rooms/v1/rooms/(?P<id>[\d]+)/events';$this->assert_true(isset($routes[$path]),'Event route did not register.');$this->assert_true(isset($routes[$path][0]['args']['before_cursor'],$routes[$path][0]['args']['after_cursor'],$routes[$path][0]['args']['limit']),'Formal REST arguments missing.');
		wp_set_current_user($this->member);$both=$this->application->events($this->room,$this->member,'one','two',50);$this->assert_wp_error($both,'Mutually exclusive cursors were accepted.');$this->assert_same('acl_ar_invalid_cursor',$both->get_error_code(),'Wrong mutual cursor error.');
		foreach(array(0,101,'bad') as $limit){$error=$this->application->events($this->room,$this->member,'','',$limit);$this->assert_wp_error($error,'Invalid event limit accepted.');$this->assert_same('acl_ar_invalid_event_limit',$error->get_error_code(),'Wrong limit error.');}
		$default=$this->application->events($this->room,$this->member,'','',null);$this->assert_true(is_array($default)&&isset($default['events'],$default['paging'],$default['sync']),'Default limit request failed.');
	}

	private function test_cursor_contract(): void {
		$cursor=$this->cursor->encode($this->room,'after',123);$decoded=$this->cursor->decode($cursor,$this->room,'after');$this->assert_same(123,(int)$decoded['event_id'],'Cursor boundary changed.');$this->assert_true(false===strpos($cursor,'room_id'),'Cursor is not opaque.');
		$malformed=$this->cursor->decode('not-a-cursor',$this->room,'after');$this->assert_wp_error($malformed,'Malformed cursor accepted.');$this->assert_same('acl_ar_invalid_cursor',$malformed->get_error_code(),'Malformed cursor code changed.');
		$tampered=$this->cursor->decode(substr($cursor,0,-1).('a'===substr($cursor,-1)?'b':'a'),$this->room,'after');$this->assert_wp_error($tampered,'Tampered cursor accepted.');
		$room=$this->cursor->decode($cursor,$this->public_room,'after');$this->assert_wp_error($room,'Cross-room cursor accepted.');$this->assert_same('acl_ar_cursor_room_mismatch',$room->get_error_code(),'Wrong room mismatch code.');
		$direction=$this->cursor->decode($cursor,$this->room,'before');$this->assert_wp_error($direction,'Wrong-direction cursor accepted.');$this->assert_same('acl_ar_cursor_direction_mismatch',$direction->get_error_code(),'Wrong direction mismatch code.');
	}

	private function test_visibility_and_paging(): void {
		$visible1=$this->event('visible-one');$hidden_user=$this->event('private-owner','user',$this->owner);$hidden_agent=$this->event('private-agent','agent',$this->agent);$hidden_mod=$this->event('private-moderator','moderators');$visible2=$this->event('visible-two');$visible3=$this->event('visible-three');
		wp_set_current_user($this->member);$initial=$this->application->events($this->room,$this->member,'','',2);$this->assert_same(array((int)$visible2['id'],(int)$visible3['id']),array_column($initial['events'],'id'),'Initial page was not newest visible ascending.');$this->assert_true($initial['paging']['has_more_before'],'Hidden rows broke visible has_more_before.');
		$history=$this->application->events($this->room,$this->member,$initial['paging']['before_cursor'],'',2);$this->assert_same(array((int)$visible1['id']),array_column($history['events'],'id'),'History leaked hidden rows or ordering changed.');$this->assert_true(!$history['paging']['has_more_before'],'History has_more leaked hidden rows.');
		$after=$this->application->events($this->room,$this->member,'',$this->cursor->encode($this->room,'after',(int)$visible1['id']),2);$this->assert_same(array((int)$visible2['id'],(int)$visible3['id']),array_column($after['events'],'id'),'After cursor did not scan across hidden rows.');
		$this->assert_true(!in_array((int)$hidden_user['id'],array_column($after['events'],'id'),true)&&!in_array((int)$hidden_agent['id'],array_column($after['events'],'id'),true)&&!in_array((int)$hidden_mod['id'],array_column($after['events'],'id'),true),'Private audience event leaked.');
		$after_decoded=$this->cursor->decode($initial['paging']['after_cursor'],$this->room,'after');$this->assert_same((int)$visible3['id'],(int)$after_decoded['event_id'],'Public cursor used a hidden boundary.');
		wp_set_current_user($this->owner);$manager=$this->application->events($this->room,$this->owner,'','',20);$this->assert_true(in_array((int)$hidden_user['id'],array_column($manager['events'],'id'),true)&&in_array((int)$hidden_agent['id'],array_column($manager['events'],'id'),true)&&in_array((int)$hidden_mod['id'],array_column($manager['events'],'id'),true),'Authorized manager could not inspect private events.');
	}

	private function test_projection_security(): void {
		$failure=$this->event('','room',null,'agent',$this->agent,array('provider_route'=>'fake','model'=>'secret-model','total_tokens'=>99,'lease_token'=>'lease-secret','idempotency_key'=>'internal','error'=>'Authorization: Bearer exposed-secret'),RoomEvent::TYPE_AGENT_FAILED);
		wp_set_current_user($this->member);$member=$this->application->events($this->room,$this->member,'','',100);$dto=current(array_filter($member['events'],static fn($row)=>(int)$row['id']===(int)$failure['id']));$encoded=wp_json_encode($dto);
		$this->assert_true(false===strpos($encoded,'secret-model')&&false===strpos($encoded,'lease-secret')&&false===strpos($encoded,'idempotency'),'Ordinary DTO leaked operational metadata.');$this->assert_true(false===strpos($encoded,'exposed-secret'),'Failure DTO leaked credential text.');
		$human=current(array_filter($member['events'],static fn($row)=>'message'===$row['type']&&'user'===$row['actor']['type']));$this->assert_same('Phase Four Owner',$human['actor']['name'],'Human display name projection failed.');
		wp_set_current_user($this->owner);$manager=$this->application->events($this->room,$this->owner,'','',100);$manager_dto=current(array_filter($manager['events'],static fn($row)=>(int)$row['id']===(int)$failure['id']));$this->assert_same('secret-model',$manager_dto['diagnostics']['model'],'Manager diagnostics missing allowed model.');$this->assert_true(false===strpos(wp_json_encode($manager_dto),'exposed-secret')&&false===strpos(wp_json_encode($manager_dto),'lease-secret'),'Manager diagnostics were not sanitized.');
		$agent=current(array_filter($manager['events'],static fn($row)=>'agent'===$row['actor']['type']));$this->assert_same('Phase Four Agent',$agent['actor']['name'],'Agent name projection failed.');$this->assert_same('https://example.test/agent.png',$agent['actor']['avatar_url'],'Agent avatar projection failed.');
		$deleted=$this->user('deleted');$historical=$this->event('historical','room',null,'user',$deleted);wp_delete_user($deleted);$projected=(new EventProjectionService($this->agents))->project_page(array($this->events->find((int)$historical['id'])),false);$this->assert_same('Former user',$projected[0]['actor']['name'],'Deleted actor did not degrade safely.');
	}

	private function test_access_and_http_cache(): void {
		wp_set_current_user($this->outsider);$private=$this->application->events($this->room,$this->outsider,'','',50);$this->assert_wp_error($private,'Unauthorized private room returned events.');$this->assert_same('acl_ar_room_forbidden',$private->get_error_code(),'Private room error code changed.');$public=$this->application->events($this->public_room,$this->outsider,'','',50);$this->assert_true(is_array($public),'Public room reader was rejected.');
		wp_set_current_user($this->member);$controller=new EventsController($this->rooms,$this->access,$this->application);$request=new WP_REST_Request('GET','/acl-agent-rooms/v1/rooms/'.$this->room.'/events');$request->set_param('id',$this->room);$request->set_param('limit',50);$response=$controller->index($request);$etag=$response->get_headers()['ETag']??'';$this->assert_true(0===strpos($response->get_headers()['Cache-Control'],'private'),'Private cache control missing.');$this->assert_true(''!==$etag,'ETag missing.');$request->set_header('If-None-Match',$etag);$not_modified=$controller->index($request);$this->assert_same(304,$not_modified->get_status(),'Conditional event request did not return 304.');
	}

	private function test_legacy_routes(): void {$routes=rest_get_server()->get_routes();foreach(array('/acl-agent-rooms/v1/rooms/(?P<id>[\d]+)/messages','/acl-agent-rooms/v1/rooms/(?P<id>[\d]+)/agents/(?P<agent_id>[\d]+)/reply','/acl-agent-rooms/v1/agent-jobs/(?P<id>[\d]+)/run') as $route){$this->assert_true(isset($routes[$route]),'Legacy route missing: '.$route);}}
	private function pass(string $name):void{echo 'PASS '.$name.PHP_EOL;}
	private function tear_down():void{global $wpdb;wp_set_current_user($this->owner);foreach(array($this->room,$this->public_room) as $room){$wpdb->delete($wpdb->prefix.'acl_ar_usage',array('room_id'=>$room));if($room&&$this->rooms->find($room)){$this->rooms->delete($room);}}if($this->agent){$this->agents->delete($this->agent);}foreach(array($this->owner,$this->member,$this->outsider) as $user){if($user){wp_delete_user($user);}}wp_set_current_user(0);}
}
