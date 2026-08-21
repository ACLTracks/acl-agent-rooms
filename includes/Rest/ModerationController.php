<?php
/** Moderation REST routes. @package ACL_Agent_Rooms */
namespace ACL\AgentRooms\Rest;

use ACL\AgentRooms\Services\AccessService;
use ACL\AgentRooms\Services\ModerationService;
if ( ! defined( 'ABSPATH' ) ) {
	exit;}
class ModerationController extends AbstractController {
	private AccessService $access;
	private ModerationService $service;
	public function __construct( ?AccessService $access = null ) {
		$this->access  = $access ?: new AccessService();
		$this->service = new ModerationService( $this->access );}
	public function register_routes(): void {
		register_rest_route(
			self::NAMESPACE,
			'/rooms/(?P<id>[\d]+)/moderation',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'restrict' ),
				'permission_callback' => array( $this, 'permissions' ),
			)
		);
		register_rest_route(
			self::NAMESPACE,
			'/rooms/(?P<id>[\d]+)/events/(?P<event_id>[\d]+)',
			array(
				'methods'             => 'DELETE',
				'callback'            => array( $this, 'remove' ),
				'permission_callback' => array( $this, 'permissions' ),
			)
		);}
	public function permissions( \WP_REST_Request $r ) {
		$nonce = $this->verify_nonce( $r );
		if ( is_wp_error( $nonce ) ) {
			return $nonce;
		}return $this->access->can_manage_room( absint( $r['id'] ) ) ? true : new \WP_Error( 'acl_ar_moderation_forbidden', __( 'You cannot moderate this room.', 'acl-agent-rooms' ), array( 'status' => 403 ) );}
	public function restrict( \WP_REST_Request $r ) {
		$rate = ( new \ACL\AgentRooms\Services\RateLimiter() )->can_moderate( get_current_user_id(), absint( $r['id'] ) );
		if ( is_wp_error( $rate ) ) {
			return $rate;
		}$seconds = absint( $r['duration_seconds'] );
		if ( $seconds && ( $seconds < 60 || $seconds > 30 * DAY_IN_SECONDS ) ) {
			return new \WP_Error( 'acl_ar_moderation_duration', __( 'Duration must be between 60 seconds and 30 days.', 'acl-agent-rooms' ), array( 'status' => 400 ) );
		}$expires = $seconds ? gmdate( 'Y-m-d H:i:s', time() + $seconds ) : null;
		return rest_ensure_response( $this->service->restrict( absint( $r['id'] ), absint( $r['target_user_id'] ), sanitize_key( (string) $r['action'] ), sanitize_textarea_field( (string) $r['reason'] ), $expires ) );}
	public function remove( \WP_REST_Request $r ) {
		$rate = ( new \ACL\AgentRooms\Services\RateLimiter() )->can_moderate( get_current_user_id(), absint( $r['id'] ) );
		if ( is_wp_error( $rate ) ) {
			return $rate;
		}return rest_ensure_response( $this->service->remove_message( absint( $r['id'] ), absint( $r['event_id'] ), sanitize_textarea_field( (string) $r['reason'] ) ) );}
}
