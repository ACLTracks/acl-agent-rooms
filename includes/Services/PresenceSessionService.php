<?php
/** Authenticated browser presence session orchestration. @package ACL_Agent_Rooms */
namespace ACL\AgentRooms\Services;

use ACL\AgentRooms\Repositories\PresenceSessionRepository;
if ( ! defined( 'ABSPATH' ) ) {
	exit; }
class PresenceSessionService {
	private PresenceSessionRepository $sessions;
	private PresenceAggregationService $aggregation;
	public function __construct( ?PresenceSessionRepository $sessions = null, ?PresenceAggregationService $aggregation = null ) {
		$this->sessions    = $sessions ?: new PresenceSessionRepository();
		$this->aggregation = $aggregation ?: new PresenceAggregationService( $this->sessions );}
	public function session_hash( int $room_id, int $user_id, string $session_id ): string {
		return hash( 'sha256', $room_id . '|' . $user_id . '|' . $session_id );}
	public function heartbeat( int $room_id, int $user_id, string $session_id, string $visibility, string $activity ) {
		if ( ! $this->valid_session( $session_id ) ) {
			return new \WP_Error( 'acl_ar_presence_session_invalid', __( 'Presence session is invalid.', 'acl-agent-rooms' ), array( 'status' => 400 ) );
		}$ttl = 'hidden' === $visibility ? max( 90, min( 600, (int) apply_filters( 'acl_ar_presence_hidden_ttl', 180 ) ) ) : max( 45, min( 300, (int) apply_filters( 'acl_ar_presence_visible_ttl', 90 ) ) );
		if ( ! $this->sessions->upsert( $room_id, $user_id, $this->session_hash( $room_id, $user_id, $session_id ), $visibility, $activity, $ttl ) ) {
			return new \WP_Error( 'acl_ar_presence_failed', __( 'Presence could not be updated.', 'acl-agent-rooms' ), array( 'status' => 500 ) );
		}return $this->aggregation->aggregate_user( $room_id, $user_id );}
	public function leave( int $room_id, int $user_id, string $session_id ) {
		if ( ! $this->valid_session( $session_id ) ) {
			return new \WP_Error( 'acl_ar_presence_session_invalid', __( 'Presence session is invalid.', 'acl-agent-rooms' ), array( 'status' => 400 ) );
		}$this->sessions->delete_for_user( $room_id, $user_id, $this->session_hash( $room_id, $user_id, $session_id ) );
		return $this->aggregation->aggregate_user( $room_id, $user_id );}
	private function valid_session( string $id ): bool {
		return strlen( $id ) >= 16 && strlen( $id ) <= 128 && 1 === preg_match( '/^[A-Za-z0-9._:-]+$/', $id );}
}
