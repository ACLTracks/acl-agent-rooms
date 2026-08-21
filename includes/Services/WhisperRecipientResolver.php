<?php
// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange -- This file operates on plugin-owned operational tables and requires uncached transaction/read-after-write truth.
// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- Identifiers are fixed $wpdb->prefix suffixes, fixed internal schema fragments, or allowlisted names; runtime values use placeholders or wpdb mutation arrays.
// phpcs:disable PluginCheck.Security.DirectDB.UnescapedDBParameter -- PHPCS cannot model the fixed identifier provenance above; request data is never used as an SQL identifier.
/** Eligible human whisper recipients. @package ACL_Agent_Rooms */
namespace ACL\AgentRooms\Services;

use ACL\AgentRooms\Repositories\EventRepository;
use ACL\AgentRooms\Repositories\RoomRepository;
if ( ! defined( 'ABSPATH' ) ) {
	exit;}
class WhisperRecipientResolver {
	private RoomRepository $rooms;
	private AccessService $access;
	private EventRepository $events;
	public function __construct( ?RoomRepository $rooms = null, ?AccessService $access = null, ?EventRepository $events = null ) {
		$this->rooms  = $rooms ?: new RoomRepository();
		$this->access = $access ?: new AccessService( $this->rooms );
		$this->events = $events ?: new EventRepository();}
	public function eligible( int $room_id, int $sender_id ): array {
		$room = $this->rooms->find( $room_id );
		if ( ! $room ) {
			return array();
		}$ids = array( (int) $room['owner_user_id'] );
		global $wpdb;
		$members = $wpdb->get_col( $wpdb->prepare( 'SELECT user_id FROM ' . $wpdb->prefix . 'acl_ar_room_members WHERE room_id=%d', $room_id ) );
		$ids     = array_merge( $ids, array_map( 'absint', (array) $members ) );
		$after   = 0;
		do {
			$rows = $this->events->scan_room_after( $room_id, $after, 500 );
			foreach ( $rows as $e ) {
				$after = max( $after, (int) $e['id'] );
				if ( 'user' === (string) $e['actor_type'] ) {
					$ids[] = (int) $e['actor_id'];
				}
			}
		} while ( 500 === count( $rows ) );
		$out = array();
		foreach ( array_unique( array_filter( $ids ) ) as $id ) {
			if ( $id === $sender_id || ! $this->access->can_access_room( $room_id, $id, false ) ) {
				continue;
			}$u = get_user_by( 'id', $id );
			if ( $u ) {
				$out[ $id ] = array(
					'id'   => $id,
					'name' => (string) $u->display_name,
				);
			}
		}return $out;}
	public function resolve( int $room_id, int $sender_id, int $canonical_id, string $display_name = '' ) {
		if ( $canonical_id ) {
			$eligible = $this->eligible( $room_id, $sender_id );
			return isset( $eligible[ $canonical_id ] ) ? $canonical_id : new \WP_Error( 'acl_ar_whisper_forbidden', __( 'That user cannot receive whispers in this room.', 'acl-agent-rooms' ), array( 'status' => 403 ) );
		}$display_name = trim( $display_name );
		if ( '' === $display_name ) {
			return new \WP_Error( 'acl_ar_whisper_recipient_required', __( 'Choose a whisper recipient.', 'acl-agent-rooms' ), array( 'status' => 400 ) );
		}$matches = array();
		foreach ( $this->eligible( $room_id, $sender_id ) as $id => $u ) {
			if ( 0 === strcasecmp( $display_name, $u['name'] ) ) {
				$matches[] = $id;
			}
		}if ( ! $matches ) {
			return new \WP_Error( 'acl_ar_whisper_recipient_not_found', __( 'Whisper recipient was not found.', 'acl-agent-rooms' ), array( 'status' => 404 ) );
		}if ( count( $matches ) > 1 ) {
			return new \WP_Error( 'acl_ar_whisper_recipient_ambiguous', __( 'More than one eligible participant has that display name.', 'acl-agent-rooms' ), array( 'status' => 409 ) );
		}return $matches[0];}
}
