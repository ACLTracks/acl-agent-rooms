<?php
/** Deterministic multi-tab human presence aggregation. @package ACL_Agent_Rooms */
namespace ACL\AgentRooms\Services;

use ACL\AgentRooms\Repositories\PresenceRepository;
use ACL\AgentRooms\Repositories\PresenceSessionRepository;
if ( ! defined( 'ABSPATH' ) ) {
	exit; }
class PresenceAggregationService {
	private PresenceSessionRepository $sessions;
	private PresenceRepository $presence;
	public function __construct( ?PresenceSessionRepository $sessions = null, ?PresenceRepository $presence = null ) {
		$this->sessions = $sessions ?: new PresenceSessionRepository();
		$this->presence = $presence ?: new PresenceRepository();}
	public function aggregate_user( int $room_id, int $user_id ): array {
		$rows    = $this->sessions->active_for_user( $room_id, $user_id );
		$now     = time();
		$window  = max( 15, min( 300, (int) apply_filters( 'acl_ar_presence_active_window', 60, $room_id, $user_id ) ) );
		$state   = 'offline';
		$last    = null;
		$expires = null;
		$visible = false;
		$recent  = false;
		$hidden  = false;
		foreach ( $rows as $row ) {
			$seen   = strtotime( (string) $row['last_seen_at'] . ' UTC' ) ?: 0;
			$active = strtotime( (string) $row['last_active_at'] . ' UTC' ) ?: 0;
			$exp    = strtotime( (string) $row['expires_at'] . ' UTC' ) ?: 0;
			if ( $seen && ( ! $last || $seen > strtotime( $last . ' UTC' ) ) ) {
				$last = (string) $row['last_seen_at'];
			}if ( $exp && ( ! $expires || $exp > strtotime( $expires . ' UTC' ) ) ) {
				$expires = (string) $row['expires_at'];
			}if ( 'visible' === $row['visibility_state'] ) {
				$visible = true;
				if ( 'active' === $row['activity_state'] && $active >= $now - $window ) {
					$recent = true;
				}
			} else {
				$hidden = true;}
		}
		if ( $visible ) {
			$state = $recent ? 'active' : 'idle';
		} elseif ( $hidden ) {
			$state = 'away';}
		$this->presence->upsert( $room_id, 'user', $user_id, $state, $last, 0, $expires );
		return array(
			'state'        => $state,
			'last_seen_at' => $last,
			'expires_at'   => $expires,
		);
	}
	public function aggregate_room( int $room_id ): void {
		$ids = array();
		foreach ( $this->sessions->active_for_room( $room_id ) as $row ) {
			$ids[ (int) $row['user_id'] ] = true;
		}foreach ( array_keys( $ids ) as $id ) {
			$this->aggregate_user( $room_id, (int) $id );}}
	public function cleanup(): void {
		$this->sessions->cleanup_expired( 200 );
		$recent = max( 300, min( DAY_IN_SECONDS, (int) apply_filters( 'acl_ar_presence_recent_window', 900 ) ) );
		$this->presence->delete_old_offline( $recent, 200 );}
}
