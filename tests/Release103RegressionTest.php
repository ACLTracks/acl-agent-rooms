<?php
/** Focused functional regression coverage for the 1.1.0 moderation UI contract. */
class ACL_AR_Release103RegressionTest extends ACL_AR_TestCase {
	public function run(): void {
		global $wpdb;
		$this->assert_same( '1.5.7', ACL_AR_VERSION, 'Plugin version is not 1.1.0.' );
		$this->assert_same( '1.4.1', ACL_AR_DB_VERSION, 'Database version is not 1.1.0.' );
		$schema = $this->schema_fingerprint();
		\ACL\AgentRooms\Installer::install();
		\ACL\AgentRooms\Installer::install();
		$this->assert_same( $schema, $this->schema_fingerprint(), 'Installer twice changed schema or indexes.' );
		$this->assert_same( '1.4.1', (string) get_option( 'acl_ar_db_version' ), 'Installed database version is not 1.1.0.' );

		$prefix = 'release-103-' . gmdate( 'YmdHis' ) . '-' . wp_generate_password( 5, false, false );
		$owner = wp_insert_user( array( 'user_login' => $prefix . '-owner', 'user_pass' => wp_generate_password( 20 ), 'user_email' => $prefix . '-owner@example.test', 'role' => 'administrator', 'display_name' => 'Moderator Alpha' ) );
		$member = wp_insert_user( array( 'user_login' => $prefix . '-member', 'user_pass' => wp_generate_password( 20 ), 'user_email' => $prefix . '-member@example.test', 'role' => 'subscriber', 'display_name' => 'User Beta' ) );
		$other = wp_insert_user( array( 'user_login' => $prefix . '-other', 'user_pass' => wp_generate_password( 20 ), 'user_email' => $prefix . '-other@example.test', 'role' => 'subscriber', 'display_name' => 'User Gamma' ) );
		$equal = wp_insert_user( array( 'user_login' => $prefix . '-equal', 'user_pass' => wp_generate_password( 20 ), 'user_email' => $prefix . '-equal@example.test', 'role' => 'administrator', 'display_name' => 'Moderator Delta' ) );
		$this->assert_true( ! is_wp_error( $owner ) && ! is_wp_error( $member ) && ! is_wp_error( $other ) && ! is_wp_error( $equal ), 'Controlled users were not created.' );
		if ( is_wp_error( $owner ) || is_wp_error( $member ) || is_wp_error( $other ) || is_wp_error( $equal ) ) { return; }
		$owner = (int) $owner; $member = (int) $member; $other = (int) $other; $equal = (int) $equal;
		foreach ( array( $member, $other ) as $id ) { ( new \WP_User( $id ) )->add_cap( 'acl_ar_use_rooms' ); }
		$rooms = new \ACL\AgentRooms\Repositories\RoomRepository();
		$messages = new \ACL\AgentRooms\Repositories\MessageRepository();
		$events = new \ACL\AgentRooms\Repositories\EventRepository();
		$room_events = new \ACL\AgentRooms\Services\RoomEventService( $events );
		$room_id = 0;
		try {
			wp_set_current_user( $owner );
			$room_id = $rooms->create( array( 'title' => $prefix, 'slug' => $prefix, 'owner_user_id' => $owner, 'type' => 'private', 'visibility' => 'private', 'status' => 'active' ) );
			$this->assert_true( is_int( $room_id ) && $room_id > 0, 'Controlled room was not created.' );
			$this->assert_true( $rooms->add_member( $room_id, $member ) && $rooms->add_member( $room_id, $other ) && $rooms->add_member( $room_id, $equal, 'moderator' ), 'Controlled participants were not assigned.' );
			$presence = new \ACL\AgentRooms\Services\PresenceSessionService();
			$presence->heartbeat( $room_id, $member, 'release-103-beta-session', 'visible', 'active' );
			$presence->heartbeat( $room_id, $other, 'release-103-gamma-session', 'visible', 'active' );
			$presence->heartbeat( $room_id, $equal, 'release-103-delta-session', 'visible', 'active' );

			$controller = new \ACL\AgentRooms\Rest\PresenceController( $rooms, new \ACL\AgentRooms\Services\AccessService( $rooms ) );
			$request = new \WP_REST_Request( 'GET', '/' ); $request->set_param( 'id', $room_id );
			$data = $controller->participants( $request )->get_data();
			$beta = $this->participant( $data['participants'], 'user', $member );
			$alpha = $this->participant( $data['participants'], 'user', $owner );
			$delta = $this->participant( $data['participants'], 'user', $equal );
			$this->assert_same( array( 'state', 'can_target' ), array_keys( $beta['moderation'] ), 'Participant projection exposes more than the minimum moderation state.' );
			$this->assert_same( 'none', $beta['moderation']['state'], 'Unrestricted participant state is incorrect.' );
			$this->assert_true( true === $beta['moderation']['can_target'], 'Valid moderation target is not projected.' );
			$this->assert_true( true === $alpha['is_current_user'] && false === $alpha['moderation']['can_target'], 'Current-user identity or self-protection is incorrect.' );
			$this->assert_true( false === $delta['moderation']['can_target'], 'Equal-manager protection is not projected.' );
			$this->assert_true( false === stripos( wp_json_encode( $data ), 'private reason' ), 'Participant projection leaked a private restriction reason.' );

			$access = new \ACL\AgentRooms\Services\AccessService( $rooms );
			$moderation = new \ACL\AgentRooms\Services\ModerationService( $access );
			$this->assert_wp_error( $moderation->restrict( $room_id, $owner, 'mute' ), 'Self-moderation was not rejected.' );
			$this->assert_wp_error( $moderation->restrict( $room_id, $equal, 'mute' ), 'Equal-manager moderation was not rejected.' );
			$this->assert_true( is_array( $moderation->restrict( $room_id, $member, 'mute', 'private reason' ) ), 'Mute failed.' );
			$this->assert_true( $access->can_read_room( $room_id, $member ) && ! $access->can_write_room( $room_id, $member ), 'Mute is not backend-enforced.' );
			$data = $controller->participants( $request )->get_data(); $beta = $this->participant( $data['participants'], 'user', $member );
			$this->assert_same( 'muted', $beta['moderation']['state'], 'Muted state did not refresh authoritatively.' );
			$moderation->restrict( $room_id, $member, 'unmute' );
			$this->assert_true( $access->can_write_room( $room_id, $member ), 'Unmute did not restore writes.' );
			$moderation->restrict( $room_id, $member, 'ban', 'private reason' );
			$this->assert_true( ! $access->can_read_room( $room_id, $member ), 'Ban is not backend-enforced.' );
			$data = $controller->participants( $request )->get_data(); $beta = $this->participant( $data['participants'], 'user', $member );
			$this->assert_same( 'banned', $beta['moderation']['state'], 'Banned target is unavailable to its manager for unban.' );
			$moderation->restrict( $room_id, $member, 'unban' );
			$this->assert_true( $access->can_read_room( $room_id, $member ), 'Unban did not restore reads.' );

			wp_set_current_user( $member );
			$ordinary = $controller->participants( $request )->get_data();
			$this->assert_true( ! isset( $this->participant( $ordinary['participants'], 'user', $other )['moderation'] ), 'Ordinary user received manager-only moderation state.' );
			wp_set_current_user( $owner );
			( new \ACL\AgentRooms\Rest\ModerationController( $access ) )->register_routes();
			$routes = rest_get_server()->get_routes();
			$this->assert_true( isset( $routes['/acl-agent-rooms/v1/rooms/(?P<id>[\d]+)/moderation'] ), 'Moderation REST route changed.' );
			$this->assert_true( isset( $routes['/acl-agent-rooms/v1/rooms/(?P<id>[\d]+)/events/(?P<event_id>[\d]+)'] ), 'Message-removal REST route changed.' );

			$legacy_id = $messages->create( array( 'room_id' => $room_id, 'sender_type' => 'user', 'sender_user_id' => $member, 'content' => 'release one zero three removable secret', 'client_request_id' => $prefix . '-message' ) );
			$event = $room_events->create_from_legacy_message( $messages->find( (int) $legacy_id ) );
			( new \ACL\AgentRooms\Services\MessageEditService( null, $events, $messages ) )->edit( $room_id, (int) $event['id'], $member, 'edited removable secret', $prefix . '-edit' );
			$search = new \ACL\AgentRooms\Services\EventSearchService( $access );
			$this->assert_same( 1, count( $search->search( $room_id, $owner, 'edited removable secret' )['results'] ), 'Removal fixture was not searchable before removal.' );
			$removed = $moderation->remove_message( $room_id, (int) $event['id'], 'controlled removal' );
			$this->assert_true( is_array( $removed ) && 'message_delete' === $removed['event']['event_type'], 'Message removal did not create its durable event.' );
			$projected = ( new \ACL\AgentRooms\Services\EventProjectionService() )->project_page( array( $events->find( (int) $event['id'] ) ), true, $owner )[0];
			$this->assert_same( 'Message removed by a moderator.', $projected['content'], 'Canonical redaction projection is incorrect.' );
			$this->assert_true( ! empty( $projected['deleted_at'] ) && false === $projected['moderation']['can_remove'], 'Removed event remains removable.' );
			$this->assert_same( 0, count( $search->search( $room_id, $owner, 'edited removable secret' )['results'] ), 'Removed content remains searchable.' );
			$this->assert_true( ! empty( $moderation->remove_message( $room_id, (int) $event['id'] )['duplicate'] ), 'Message removal is not idempotent.' );

			$whisper = $room_events->create( array( 'room_id' => $room_id, 'event_type' => 'whisper', 'actor_type' => 'user', 'actor_id' => $member, 'audience_type' => 'user', 'audience_id' => $other, 'content' => 'release private whisper marker' ) );
			$this->assert_same( 0, count( $search->search( $room_id, $owner, 'private whisper marker' )['results'] ), 'Whisper entered the search index.' );
			$visibility = new \ACL\AgentRooms\Services\EventVisibilityService( $access );
			$this->assert_true( $visibility->can_view( $whisper, $member, false ) && $visibility->can_view( $whisper, $other, false ), 'Whisper sender or recipient lost visibility.' );
			$this->assert_true( ! $visibility->can_view( $whisper, $equal, false ), 'Whisper leaked to another participant.' );
			$this->assert_same( 0, (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}acl_ar_agent_jobs WHERE room_id=%d", $room_id ) ), 'Regression invoked an agent/provider job.' );
		} finally {
			wp_set_current_user( $owner );
			if ( $room_id && $rooms->find( $room_id ) ) { $wpdb->delete( $wpdb->prefix . 'acl_ar_usage', array( 'room_id' => $room_id ), array( '%d' ) ); $rooms->delete( $room_id ); }
			foreach ( array( $member, $other, $equal, $owner ) as $id ) { if ( $id ) { wp_delete_user( $id ); } }
			wp_set_current_user( 0 );
		}
	}

	private function participant( array $participants, string $type, int $id ): array {
		foreach ( $participants as $participant ) { if ( $type === (string) $participant['actor']['type'] && $id === (int) $participant['actor']['id'] ) { return $participant; } }
		return array();
	}

	private function schema_fingerprint(): string {
		global $wpdb;
		$like = $wpdb->esc_like( $wpdb->prefix . 'acl_ar_' ) . '%';
		$columns = $wpdb->get_results( $wpdb->prepare( 'SELECT TABLE_NAME,COLUMN_NAME,ORDINAL_POSITION,COLUMN_TYPE,IS_NULLABLE,COLUMN_DEFAULT,EXTRA FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=%s AND TABLE_NAME LIKE %s ORDER BY TABLE_NAME,ORDINAL_POSITION', DB_NAME, $like ), ARRAY_A );
		$indexes = $wpdb->get_results( $wpdb->prepare( 'SELECT TABLE_NAME,INDEX_NAME,NON_UNIQUE,SEQ_IN_INDEX,COLUMN_NAME,SUB_PART,INDEX_TYPE FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=%s AND TABLE_NAME LIKE %s ORDER BY TABLE_NAME,INDEX_NAME,SEQ_IN_INDEX', DB_NAME, $like ), ARRAY_A );
		return hash( 'sha256', wp_json_encode( array( $columns, $indexes ) ) );
	}
}
