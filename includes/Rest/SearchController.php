<?php
/** Room search REST API. @package ACL_Agent_Rooms */
namespace ACL\AgentRooms\Rest;

use ACL\AgentRooms\Services\AccessService;
use ACL\AgentRooms\Services\EventSearchService;
if ( ! defined( 'ABSPATH' ) ) {
	exit;}
class SearchController extends AbstractController {
	private AccessService $access;
	private EventSearchService $service;
	public function __construct( ?AccessService $access = null ) {
		$this->access  = $access ?: new AccessService();
		$this->service = new EventSearchService( $this->access );}
	public function register_routes(): void {
		register_rest_route(
			self::NAMESPACE,
			'/rooms/(?P<id>[\d]+)/search',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'search' ),
				'permission_callback' => array( $this, 'permissions' ),
			)
		);
		register_rest_route(
			self::NAMESPACE,
			'/rooms/(?P<id>[\d]+)/events/(?P<event_id>[\d]+)/context',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'context' ),
				'permission_callback' => array( $this, 'permissions' ),
			)
		);}
	public function permissions( \WP_REST_Request $r ) {
		$allowed = $this->require_room_user();
		if ( is_wp_error( $allowed ) ) {
			return $allowed;
		}return $this->access->can_read_room( absint( $r['id'] ) ) ? true : new \WP_Error( 'acl_ar_room_forbidden', __( 'You cannot access this room.', 'acl-agent-rooms' ), array( 'status' => 403 ) );}
	public function search( \WP_REST_Request $r ) {
		$rate = ( new \ACL\AgentRooms\Services\RateLimiter() )->can_search( get_current_user_id(), absint( $r['id'] ) );
		if ( is_wp_error( $rate ) ) {
			return $rate;
		}$data = $this->service->search( absint( $r['id'] ), get_current_user_id(), (string) $r['q'], (string) $r['cursor'], absint( $r['limit'] ?: 20 ) );
		return is_wp_error( $data ) ? $data : new \WP_REST_Response(
			$data,
			200,
			array(
				'Cache-Control' => 'private, no-store',
				'Vary'          => 'Cookie, X-WP-Nonce',
			)
		);}
	public function context( \WP_REST_Request $r ) {
		$data = $this->service->context( absint( $r['id'] ), get_current_user_id(), absint( $r['event_id'] ), absint( $r['radius'] ?: 3 ) );
		return is_wp_error( $data ) ? $data : new \WP_REST_Response(
			$data,
			200,
			array(
				'Cache-Control' => 'private, no-store',
				'Vary'          => 'Cookie, X-WP-Nonce',
			)
		);}
}
