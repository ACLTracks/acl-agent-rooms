<?php
/** Phase 9 release-candidate contract and integration suite. */
class ACL_AR_Phase9ProductionTest extends ACL_AR_TestCase {
	public function run(): void {
		\ACL\AgentRooms\Installer::install();
		$this->contracts_and_schema();
		$this->search_moderation_and_privacy();
	}

	private function contracts_and_schema(): void {
		global $wpdb;
		$root  = dirname( __DIR__ );
		$files = array(
			'acl-agent-rooms.php','includes/Installer.php','includes/Deactivator.php','includes/Repositories/EventRepository.php','includes/Repositories/EventSearchRepository.php','includes/Repositories/MaintenanceRunRepository.php','includes/Repositories/MessageRepository.php','includes/Repositories/ReactionRepository.php','includes/Repositories/RoomRestrictionRepository.php','includes/Services/AccessService.php','includes/Services/AgentRuntime.php','includes/Services/ModerationPolicy.php','includes/Services/ModerationService.php','includes/Services/EventSearchBackfillService.php','includes/Services/EventSearchIndexer.php','includes/Services/EventSearchService.php','includes/Services/SearchCursor.php','includes/Services/RetentionService.php','includes/Services/MaintenanceService.php','includes/Services/HealthService.php','includes/Services/QueueService.php','includes/Rest/AbstractController.php','includes/Rest/SearchController.php','includes/Rest/ModerationController.php','includes/Rest/HealthController.php','assets/js/room/api.js','assets/js/room/search.js','assets/js/room/moderation.js','README.md','CHANGELOG.md',
		);
		$source = '';
		foreach ( $files as $file ) { $source .= "\n" . (string) file_get_contents( $root . '/' . $file ); }
		$contracts = array(
			'1.1.0','acl_ar_room_restrictions','acl_ar_event_search','acl_ar_maintenance_runs','TYPE_MODERATION','TYPE_MESSAGE_DELETE','can_read_room','can_write_room','is_banned','is_muted','can_target','moderation_self','moderation_protected','PLACEHOLDER','soft_delete','message-delete','EventSearchIndexer','EventSearchBackfillService','SearchCursor','hash_hmac','wp_salt','search_cursor_invalid','EventVisibilityService','project_page','context','private, no-store','RetentionService','data_retention_days','archived','maintenance_failed','HealthService','active_restrictions','MAINTENANCE_HOOK','SEARCH_BACKFILL_HOOK','run_maintenance','trigger_restricted','SearchController','ModerationController','HealthController','canModerate','phase9','Api.prototype.search','Api.prototype.context','Api.prototype.moderate','Api.prototype.removeMessage','ACLARRoomSearch','ACLARRoomModeration','aria-live','focus-visible','FULLTEXT KEY','room_user_active','room_event','task_started','items_scanned','items_changed','expires_at','revoked_at','idempotency_key','moderators','message_delete','moderation','no-store','Vary','Cookie','X-WP-Nonce','manage_settings','EventSearchRepository','RoomRestrictionRepository','MaintenanceRunRepository','redact','expired','retention_expired','search_backfill','presence_cleanup','event_backfill','has_more','next_cursor','target_event_id','query','radius','limit','before_id','audience_type','legacy_message_id','deleted_at','updated_at','created_by','revoke','impose','count_active','remove_message','restrict','reason','expires_at','rest_ensure_response','mutating_capability','require_room_user','can_manage_room','wp_schedule_event','daily','enqueue_search_backfill','run_search_backfill','run_event_backfill','EventRepository','MessageRepository','ReactionRepository','current_time','sanitize_textarea_field','hash_equals','base64_encode','mb_strtolower','wp_strip_all_tags','esc_like','LIKE','ORDER BY','LIMIT','status','degraded','generated_at','db_version','version'
		);
		$this->assert_same( 121, count( $contracts ), 'Phase 9 contract list count changed unexpectedly.' );
		foreach ( $contracts as $contract ) { $this->assert_true( false !== strpos( $source, $contract ), 'Missing Phase 9 contract: ' . $contract ); }
		foreach ( array( 'acl_ar_room_restrictions', 'acl_ar_event_search', 'acl_ar_maintenance_runs' ) as $table ) {
			$this->assert_same( $wpdb->prefix . $table, (string) $wpdb->get_var( $wpdb->prepare( 'SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA=%s AND TABLE_NAME=%s', DB_NAME, $wpdb->prefix . $table ) ), 'Missing Phase 9 table.' );
		}
		$this->assert_true( false !== strpos( $source, "className = 'acl-ar-room-app__search'" ), 'Search panel styling class is not integrated.' );
		$this->assert_true( false !== strpos( $source, "event.key === 'Escape'" ) && false !== strpos( $source, 'aria-controls' ), 'Search keyboard accessibility contract is incomplete.' );
		$this->assert_true( false !== strpos( $source, 'index_with_content' ) && false !== strpos( $source, 'effective_content' ), 'Search reconciliation contract is incomplete.' );
		$this->assert_true( false !== strpos( $source, 'wp_clear_scheduled_hook( QueueService::SEARCH_BACKFILL_HOOK )' ) && false !== strpos( $source, 'wp_clear_scheduled_hook( QueueService::MAINTENANCE_HOOK )' ), 'Phase 9 cron hooks are not cleared on deactivation.' );
		$this->assert_true( false === strpos( $source, 'wp_acl_ar_' ), 'Phase 9 production code hard-codes the default table prefix.' );
	}

	private function search_moderation_and_privacy(): void {
		global $wpdb;
		$prefix = 'phase9-' . gmdate( 'YmdHis' ) . '-' . wp_generate_password( 5, false, false );
		$owner  = wp_insert_user( array( 'user_login' => $prefix . '-owner', 'user_pass' => wp_generate_password( 20 ), 'user_email' => $prefix . '-owner@example.test', 'role' => 'administrator' ) );
		$member = wp_insert_user( array( 'user_login' => $prefix . '-member', 'user_pass' => wp_generate_password( 20 ), 'user_email' => $prefix . '-member@example.test', 'role' => 'subscriber' ) );
		$this->assert_true( ! is_wp_error( $owner ) && ! is_wp_error( $member ), 'Phase 9 users were not created.' );
		if ( is_wp_error( $owner ) || is_wp_error( $member ) ) { return; }

		$owner  = (int) $owner;
		$member = (int) $member;
		( new \WP_User( $member ) )->add_cap( 'acl_ar_use_rooms' );
		$rooms       = new \ACL\AgentRooms\Repositories\RoomRepository();
		$messages    = new \ACL\AgentRooms\Repositories\MessageRepository();
		$events      = new \ACL\AgentRooms\Repositories\EventRepository();
		$room_events = new \ACL\AgentRooms\Services\RoomEventService( $events );
		$room_id     = 0;

		try {
			wp_set_current_user( $owner );
			$room_id = $rooms->create( array( 'title' => $prefix, 'slug' => $prefix, 'owner_user_id' => $owner, 'type' => 'private', 'visibility' => 'private', 'status' => 'active', 'agent_reply_mode' => 'manual' ) );
			$this->assert_true( is_int( $room_id ) && $room_id > 0, 'Phase 9 room was not created.' );
			$this->assert_true( $rooms->add_member( $room_id, $member ), 'Phase 9 member was not assigned.' );

			$legacy_id = $messages->create( array( 'room_id' => $room_id, 'sender_type' => 'user', 'sender_user_id' => $owner, 'content' => 'phase nine old searchable phrase', 'client_request_id' => $prefix . '-message' ) );
			$this->assert_true( is_int( $legacy_id ) && $legacy_id > 0, 'Search message was not created.' );
			$event = $room_events->create_from_legacy_message( $messages->find( (int) $legacy_id ) );
			$this->assert_true( is_array( $event ) && 'message' === $event['event_type'], 'Search event was not created.' );

			$access = new \ACL\AgentRooms\Services\AccessService( $rooms );
			$search = new \ACL\AgentRooms\Services\EventSearchService( $access );
			$before = $search->search( $room_id, $owner, 'old searchable phrase' );
			$this->assert_same( 1, count( $before['results'] ), 'Initial search index result is missing.' );

			$edit = ( new \ACL\AgentRooms\Services\MessageEditService( null, $events, $messages ) )->edit( $room_id, (int) $event['id'], $owner, 'phase nine revised searchable phrase', $prefix . '-edit' );
			$this->assert_true( is_array( $edit ), 'Message edit failed.' );
			$revised = $search->search( $room_id, $owner, 'revised searchable phrase' );
			$old     = $search->search( $room_id, $owner, 'old searchable phrase' );
			$this->assert_same( 1, count( $revised['results'] ), 'Edited content was not indexed immediately.' );
			$this->assert_same( 0, count( $old['results'] ), 'Stale pre-edit search content remained indexed.' );

			( new \ACL\AgentRooms\Repositories\EventSearchRepository() )->upsert( (int) $event['id'], $room_id, 'phase nine old searchable phrase', 'user', (string) $event['created_at'] );
			$reconciled = ( new \ACL\AgentRooms\Services\EventSearchBackfillService() )->run_batch( 50 );
			$this->assert_true( (int) $reconciled['changed'] >= 1, 'Search backfill did not reconcile a stale row.' );
			$this->assert_same( 1, count( $search->search( $room_id, $owner, 'revised searchable phrase' )['results'] ), 'Reconciled search content is missing.' );
			$this->assert_same( 0, count( $search->search( $room_id, $owner, 'old searchable phrase' )['results'] ), 'Reconciliation retained stale search content.' );

			$hidden = $room_events->create( array( 'room_id' => $room_id, 'event_type' => 'whisper', 'actor_type' => 'user', 'actor_id' => $owner, 'audience_type' => 'user', 'audience_id' => $owner, 'content' => 'private context marker' ) );
			$hidden_context = $search->context( $room_id, $member, (int) $hidden['id'], 3 );
			$this->assert_wp_error( $hidden_context, 'Hidden target context leaked to another room member.' );
			$this->assert_same( 'acl_ar_event_not_found', $hidden_context->get_error_code(), 'Hidden context did not use a non-enumerating error.' );

			$cursor = ( new \ACL\AgentRooms\Services\SearchCursor() )->encode( $room_id, $owner, 'revised searchable phrase', (int) $event['id'] );
			$this->assert_same( (int) $event['id'], ( new \ACL\AgentRooms\Services\SearchCursor() )->decode( $cursor, $room_id, $owner, 'revised searchable phrase' ), 'Signed search cursor did not round trip.' );
			$this->assert_wp_error( ( new \ACL\AgentRooms\Services\SearchCursor() )->decode( $cursor, $room_id, $member, 'revised searchable phrase' ), 'Search cursor crossed user scope.' );
			$this->assert_wp_error( ( new \ACL\AgentRooms\Services\SearchCursor() )->decode( $cursor, $room_id, $owner, 'different query' ), 'Search cursor crossed query scope.' );

			$moderation = new \ACL\AgentRooms\Services\ModerationService( $access );
			$mute       = $moderation->restrict( $room_id, $member, 'mute', 'test mute' );
			$this->assert_true( is_array( $mute ) && 'moderation' === $mute['event']['event_type'], 'Mute did not create a durable event.' );
			$this->assert_true( $access->can_read_room( $room_id, $member ) && ! $access->can_write_room( $room_id, $member ), 'Mute enforcement is incorrect.' );
			$moderation->restrict( $room_id, $member, 'unmute' );
			$this->assert_true( $access->can_write_room( $room_id, $member ), 'Unmute did not restore writes.' );
			$moderation->restrict( $room_id, $member, 'ban', 'test ban' );
			$this->assert_true( ! $access->can_read_room( $room_id, $member ), 'Ban did not block reads.' );
			$moderation->restrict( $room_id, $member, 'unban' );
			$this->assert_true( $access->can_read_room( $room_id, $member ), 'Unban did not restore reads.' );

			$remove_legacy_id = $messages->create( array( 'room_id' => $room_id, 'sender_type' => 'user', 'sender_user_id' => $member, 'content' => 'phase nine remove this message', 'client_request_id' => $prefix . '-remove' ) );
			$remove_event     = $room_events->create_from_legacy_message( $messages->find( (int) $remove_legacy_id ) );
			$this->assert_same( 1, count( $search->search( $room_id, $owner, 'remove this message' )['results'] ), 'Removal fixture was not indexed.' );
			$removed = $moderation->remove_message( $room_id, (int) $remove_event['id'], 'test removal' );
			$this->assert_true( is_array( $removed ) && 'message_delete' === $removed['event']['event_type'], 'Moderator removal event is missing.' );
			$this->assert_same( \ACL\AgentRooms\Services\ModerationService::PLACEHOLDER, $events->find( (int) $remove_event['id'] )['content'], 'Canonical message was not redacted.' );
			$this->assert_same( \ACL\AgentRooms\Services\ModerationService::PLACEHOLDER, $messages->find( (int) $remove_legacy_id )['content'], 'Legacy message was not redacted.' );
			$this->assert_same( 0, count( $search->search( $room_id, $owner, 'remove this message' )['results'] ), 'Removed message remained searchable.' );
			$duplicate = $moderation->remove_message( $room_id, (int) $remove_event['id'], 'duplicate' );
			$this->assert_true( ! empty( $duplicate['duplicate'] ), 'Moderator removal was not idempotent.' );
			$delete_count = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}acl_ar_events WHERE event_type='message_delete' AND parent_event_id=%d", (int) $remove_event['id'] ) );
			$this->assert_same( 1, $delete_count, 'Duplicate removal emitted another deletion event.' );
		} finally {
			wp_set_current_user( $owner );
			if ( $room_id && $rooms->find( $room_id ) ) {
				$wpdb->delete( $wpdb->prefix . 'acl_ar_usage', array( 'room_id' => $room_id ), array( '%d' ) );
				$rooms->delete( $room_id );
			}
			wp_delete_user( $member );
			wp_delete_user( $owner );
			wp_set_current_user( 0 );
		}
	}
}
