<?php
/** Private health and maintenance REST API. @package ACL_Agent_Rooms */
namespace ACL\AgentRooms\Rest;

use ACL\AgentRooms\Capabilities;
use ACL\AgentRooms\Services\HealthService;
use ACL\AgentRooms\Services\MaintenanceService;
if ( ! defined( 'ABSPATH' ) ) {
	exit;}
class HealthController extends AbstractController {
	public function register_routes(): void {
		register_rest_route(
			self::NAMESPACE,
			'/health',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'health' ),
				'permission_callback' => fn()=> $this->require_capability( Capabilities::MANAGE_SETTINGS ),
			)
		);
		register_rest_route(
			self::NAMESPACE,
			'/maintenance/(?P<task>[a-z_]+)',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'run' ),
				'permission_callback' => fn( $r )=>$this->mutating_capability( $r, Capabilities::MANAGE_SETTINGS ),
			)
		);}
	public function health() {
		return new \WP_REST_Response(
			( new HealthService() )->snapshot(),
			200,
			array(
				'Cache-Control' => 'private, no-store',
				'Vary'          => 'Cookie, X-WP-Nonce',
			)
		);}
	public function run( \WP_REST_Request $r ) {
		$rate = ( new \ACL\AgentRooms\Services\RateLimiter() )->can_maintain( get_current_user_id() );
		if ( is_wp_error( $rate ) ) {
			return $rate;
		}return new \WP_REST_Response( ( new MaintenanceService() )->run( sanitize_key( (string) $r['task'] ), absint( $r['limit'] ?: 100 ) ), 200, array( 'Cache-Control' => 'private, no-store' ) );}
}
