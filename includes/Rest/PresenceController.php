<?php
// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange -- This file operates on plugin-owned operational tables and requires uncached transaction/read-after-write truth.
// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- Identifiers are fixed $wpdb->prefix suffixes, fixed internal schema fragments, or allowlisted names; runtime values use placeholders or wpdb mutation arrays.
// phpcs:disable PluginCheck.Security.DirectDB.UnescapedDBParameter -- PHPCS cannot model the fixed identifier provenance above; request data is never used as an SQL identifier.
/** Room participants, human presence sessions, and agent participation REST API. @package ACL_Agent_Rooms */
namespace ACL\AgentRooms\Rest;

use ACL\AgentRooms\Repositories\PresenceRepository;
use ACL\AgentRooms\Repositories\RoomRepository;
use ACL\AgentRooms\Repositories\RoomRestrictionRepository;
use ACL\AgentRooms\Services\AccessService;
use ACL\AgentRooms\Services\AgentParticipationService;
use ACL\AgentRooms\Services\AgentStateReconciler;
use ACL\AgentRooms\Services\ModerationPolicy;
use ACL\AgentRooms\Services\PresenceAggregationService;
use ACL\AgentRooms\Services\PresenceSessionService;
use ACL\AgentRooms\Services\RateLimiter;
if ( ! defined( 'ABSPATH' ) ) {
	exit;}
class PresenceController extends AbstractController {
	private RoomRepository $rooms;
	private AccessService $access;
	private PresenceSessionService $sessions;
	private PresenceRepository $presence;
	private AgentParticipationService $participation;
	private RoomRestrictionRepository $restrictions;
	private ModerationPolicy $moderation;
	public function __construct( ?RoomRepository $rooms = null, ?AccessService $access = null ) {
		$this->rooms         = $rooms ?: new RoomRepository();
		$this->access        = $access ?: new AccessService( $this->rooms );
		$this->sessions      = new PresenceSessionService();
		$this->presence      = new PresenceRepository();
		$this->participation = new AgentParticipationService( $this->rooms );
		$this->restrictions  = new RoomRestrictionRepository();
		$this->moderation    = new ModerationPolicy( $this->rooms, $this->restrictions );}
	public function register_routes(): void {
		$id = array(
			'id' => array(
				'required'          => true,
				'sanitize_callback' => 'absint',
				'validate_callback' => static fn( $v )=>is_numeric( $v ) && (int) $v > 0,
			),
		);
		register_rest_route(
			self::NAMESPACE,
			'/rooms/(?P<id>\d+)/participants',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this, 'participants' ),
				'permission_callback' => array( $this, 'read_permissions' ),
				'args'                => $id,
			)
		);
		register_rest_route(
			self::NAMESPACE,
			'/rooms/(?P<id>\d+)/presence/heartbeat',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'heartbeat' ),
				'permission_callback' => array( $this, 'write_permissions' ),
				'args'                => array_merge( $id, $this->presence_args() ),
			)
		);
		register_rest_route(
			self::NAMESPACE,
			'/rooms/(?P<id>\d+)/presence/session',
			array(
				'methods'             => \WP_REST_Server::DELETABLE,
				'callback'            => array( $this, 'leave' ),
				'permission_callback' => array( $this, 'write_permissions' ),
				'args'                => array_merge( $id, array( 'session_id' => $this->session_arg() ) ),
			)
		);
		$agent_args = array(
			'agent_id'            => array(
				'required'          => true,
				'sanitize_callback' => 'absint',
				'validate_callback' => static fn( $v )=>is_numeric( $v ) && (int) $v > 0,
			),
			'participation_state' => array(
				'required'          => true,
				'sanitize_callback' => 'sanitize_key',
				'validate_callback' => static fn( $v )=>in_array( $v, array( 'active', 'paused' ), true ),
			),
			'auto_muted'          => array(
				'required'          => true,
				'sanitize_callback' => 'rest_sanitize_boolean',
				'validate_callback' => static fn( $v )=>is_bool( $v ) || in_array( $v, array( 0, 1, '0', '1', 'true', 'false' ), true ),
			),
			'client_request_id'   => array(
				'required'          => true,
				'sanitize_callback' => 'sanitize_text_field',
				'validate_callback' => static fn( $v )=>is_string( $v ) && strlen( $v ) >= 8 && strlen( $v ) <= 128,
			),
		);
		register_rest_route(
			self::NAMESPACE,
			'/rooms/(?P<id>\d+)/agents/(?P<agent_id>\d+)/participation',
			array(
				'methods'             => 'PATCH',
				'callback'            => array( $this, 'change_participation' ),
				'permission_callback' => array( $this, 'manage_permissions' ),
				'args'                => array_merge( $id, $agent_args ),
			)
		);
	}
	private function session_arg(): array {
		return array(
			'required'          => true,
			'sanitize_callback' => 'sanitize_text_field',
			'validate_callback' => static fn( $v )=>is_string( $v ) && strlen( $v ) >= 16 && strlen( $v ) <= 128 && 1 === preg_match( '/^[A-Za-z0-9._:-]+$/', $v ),
		);}
	private function presence_args(): array {
		return array(
			'session_id'       => $this->session_arg(),
			'visibility_state' => array(
				'required'          => true,
				'sanitize_callback' => 'sanitize_key',
				'validate_callback' => static fn( $v )=>in_array( $v, array( 'visible', 'hidden' ), true ),
			),
			'activity_state'   => array(
				'required'          => true,
				'sanitize_callback' => 'sanitize_key',
				'validate_callback' => static fn( $v )=>in_array( $v, array( 'active', 'idle' ), true ),
			),
		);}
	public function read_permissions( \WP_REST_Request $r ) {
		$base = $this->require_room_user();
		if ( is_wp_error( $base ) ) {
			return $base;
		}return $this->access->can_access_room( absint( $r['id'] ) ) ? true : new \WP_Error( 'acl_ar_room_forbidden', __( 'You cannot access this room.', 'acl-agent-rooms' ), array( 'status' => 403 ) );}
	public function write_permissions( \WP_REST_Request $r ) {
		$nonce = $this->verify_nonce( $r );
		if ( is_wp_error( $nonce ) ) {
			return $nonce;
		}return $this->read_permissions( $r );}
	public function manage_permissions( \WP_REST_Request $r ) {
		$write = $this->write_permissions( $r );
		if ( is_wp_error( $write ) ) {
			return $write;
		}return $this->access->can_manage_room( absint( $r['id'] ) ) ? true : new \WP_Error( 'acl_ar_room_manage_forbidden', __( 'You cannot manage this room.', 'acl-agent-rooms' ), array( 'status' => 403 ) );}
	public function participants( \WP_REST_Request $r ): \WP_REST_Response {
		return $this->response( absint( $r['id'] ) );}
	public function heartbeat( \WP_REST_Request $r ) {
		$room = absint( $r['id'] );
		$user = get_current_user_id();
		$rate = ( new RateLimiter() )->can_heartbeat( $user, $room, (string) $r['session_id'] );
		if ( is_wp_error( $rate ) ) {
			return $rate;
		}$result = $this->sessions->heartbeat( $room, $user, (string) $r['session_id'], (string) $r['visibility_state'], (string) $r['activity_state'] );
		if ( is_wp_error( $result ) ) {
			return $result;
		}return $this->response( $room, true );}
	public function leave( \WP_REST_Request $r ) {
		$result = $this->sessions->leave( absint( $r['id'] ), get_current_user_id(), (string) $r['session_id'] );
		if ( is_wp_error( $result ) ) {
			return $result;
		}return $this->response( absint( $r['id'] ), true );}
	public function change_participation( \WP_REST_Request $r ) {
		$result = $this->participation->change( absint( $r['id'] ), absint( $r['agent_id'] ), (string) $r['participation_state'], rest_sanitize_boolean( $r['auto_muted'] ), get_current_user_id(), (string) $r['client_request_id'] );
		if ( is_wp_error( $result ) ) {
			return $result;
		}return $this->response( absint( $r['id'] ), true );}
	private function response( int $room_id, bool $include_self = false ): \WP_REST_Response {
		$snapshot = $this->snapshot( $room_id );
		$body     = $snapshot;
		if ( $include_self ) {
			$body['self'] = null;
			foreach ( $snapshot['participants'] as $p ) {
				if ( ! empty( $p['is_current_user'] ) ) {
						$body['self'] = $p;
							break;
				}
			}
		}$scope   = $room_id . '|' . get_current_user_id() . '|' . ( $this->access->can_manage_room( $room_id ) ? 'manager' : 'reader' ) . '|' . $snapshot['sync']['presence_version'];
		$response = new \WP_REST_Response( $body, 200 );
		$response->header( 'Cache-Control', 'private, no-store, max-age=0' );
		$response->header( 'Vary', 'Cookie, X-WP-Nonce' );
		$response->header( 'ETag', '"' . hash( 'sha256', $scope ) . '"' );
		return $response;}
	private function snapshot( int $room_id ): array {
		global $wpdb;
		( new PresenceAggregationService() )->aggregate_room( $room_id );
		( new AgentStateReconciler() )->reconcile_room( $room_id );
		$room     = $this->rooms->find( $room_id );
		$rows     = $this->presence->for_room( $room_id );
		$by_user  = array();
		$by_agent = array();
		foreach ( $rows as $row ) {
			if ( 'user' === $row['actor_type'] ) {
				$by_user[ (int) $row['actor_id'] ] = $row;
			} else {
				$by_agent[ (int) $row['actor_id'] ] = $row;
			}
		}$ids         = array( get_current_user_id(), (int) ( $room['owner_user_id'] ?? 0 ) );
		$members      = $wpdb->get_col( $wpdb->prepare( "SELECT user_id FROM {$wpdb->prefix}acl_ar_room_members WHERE room_id=%d ORDER BY id LIMIT 200", $room_id ) );
		$ids          = array_values( array_unique( array_filter( array_merge( $ids, array_map( 'intval', (array) $members ), array_keys( $by_user ) ) ) ) );
		$participants = array();
		$summary      = array(
			'active_people' => 0,
			'idle_people'   => 0,
			'away_people'   => 0,
			'agents'        => 0,
			'agents_active' => 0,
			'agents_paused' => 0,
			'agents_muted'  => 0,
		);
		$recent       = max( 300, min( DAY_IN_SECONDS, (int) apply_filters( 'acl_ar_presence_recent_window', 900 ) ) );
		$manager      = $this->access->can_manage_room( $room_id );
		$actor_id     = get_current_user_id();
		foreach ( $ids as $id ) {
			$banned = $this->restrictions->has( $room_id, $id, 'ban' );
			if ( ! $this->access->can_access_room( $room_id, $id ) && ! ( $manager && $banned ) ) {
				continue;
			}$u    = get_userdata( $id );
			$row   = $by_user[ $id ] ?? null;
			$state = (string) ( $row['state'] ?? 'offline' );
			if ( ! $banned && 'offline' === $state && $id !== $actor_id && ( ! $row || strtotime( (string) $row['updated_at'] . ' UTC' ) < time() - $recent ) ) {
				continue;
			}if ( isset( $summary[ $state . '_people' ] ) && ! $banned ) {
				++$summary[ $state . '_people' ];
			}$label = array(
				'active'  => 'Active now',
				'idle'    => 'Idle',
				'away'    => 'Away',
				'offline' => 'Recently active',
			)[ $state ] ?? 'Recently active';
			$dto    = array(
				'actor'           => array(
					'type'       => 'user',
					'id'         => $id,
					'name'       => $u ? (string) $u->display_name : __( 'Former user', 'acl-agent-rooms' ),
					'avatar_url' => $u ? ( get_avatar_url( $id, array( 'size' => 96 ) ) ?: null ) : null,
				),
				'presence'        => array(
					'state'           => $state,
					'last_seen_label' => $label,
				),
				'is_current_user' => $id === $actor_id,
			);
			if ( $manager ) {
				$targetable        = true === $this->moderation->can_target( $room_id, $actor_id, $id );
				$dto['moderation'] = array(
					'state'      => $banned ? 'banned' : ( $this->restrictions->has( $room_id, $id, 'mute' ) ? 'muted' : 'none' ),
					'can_target' => $targetable,
				);
			}$participants[] = $dto;}
		foreach ( $this->rooms->get_agents( $room_id ) as $agent ) {
			$row    = $by_agent[ (int) $agent['id'] ] ?? array();
			$state  = (string) ( $row['state'] ?? 'ready' );
			$paused = 'paused' === (string) $agent['participation_state'];
			++$summary['agents'];
			if ( in_array( $state, array( 'queued', 'thinking', 'typing', 'responding', 'pausing' ), true ) ) {
				++$summary['agents_active'];
			}if ( $paused ) {
				++$summary['agents_paused'];
			}if ( ! empty( $agent['auto_muted'] ) ) {
				++$summary['agents_muted'];
			}$dto = array(
				'actor'         => array(
					'type'       => 'agent',
					'id'         => (int) $agent['id'],
					'name'       => (string) $agent['name'],
					'avatar_url' => ! empty( $agent['avatar_url'] ) ? esc_url_raw( $agent['avatar_url'] ) : null,
				),
				'presence'      => array( 'state' => $state ),
				'participation' => array(
					'state'             => $paused ? 'paused' : 'active',
					'auto_muted'        => ! empty( $agent['auto_muted'] ),
					'can_manual_invoke' => ! $paused,
				),
			);
			if ( $manager ) {
				$dto['permissions'] = array(
					'can_pause'     => true,
					'can_mute_auto' => true,
				);
			}$participants[] = $dto;}
		$version = hash( 'sha256', wp_json_encode( array( $participants, $summary ) ) );
		return array(
			'participants' => $participants,
			'summary'      => $summary,
			'sync'         => array(
				'room_id'          => $room_id,
				'server_time'      => gmdate( 'c' ),
				'presence_version' => $version,
			),
		);}
}
