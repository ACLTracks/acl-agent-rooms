<?php
/** Authorized private Room Files REST API. @package ACL_Agent_Rooms */
namespace ACL\AgentRooms\Rest;

use ACL\AgentRooms\Repositories\RoomRepository;
use ACL\AgentRooms\Services\AccessService;
use ACL\AgentRooms\Services\RoomFileService;

if ( ! defined( 'ABSPATH' ) ) {
	exit; }

class RoomFilesController extends AbstractController {
	private RoomRepository $rooms;
	private AccessService $access;
	private RoomFileService $service;
	public function __construct( ?RoomRepository $rooms = null, ?AccessService $access = null, ?RoomFileService $service = null ) {
		$this->rooms   = $rooms ?: new RoomRepository();
		$this->access  = $access ?: new AccessService( $this->rooms );
		$this->service = $service ?: new RoomFileService( $this->rooms, null, $this->access );}
	public function register_routes(): void {
		register_rest_route(
			self::NAMESPACE,
			'/rooms/(?P<id>\d+)/files',
			array(
				array(
					'methods'             => 'GET',
					'callback'            => array( $this, 'index' ),
					'permission_callback' => array( $this, 'read_permission' ),
				),
				array(
					'methods'             => 'POST',
					'callback'            => array( $this, 'create' ),
					'permission_callback' => array( $this, 'manage_permission' ),
				),
			)
		);
		register_rest_route(
			self::NAMESPACE,
			'/rooms/(?P<id>\d+)/files/assets',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'assets' ),
				'permission_callback' => array( $this, 'manage_permission' ),
			)
		);
		register_rest_route(
			self::NAMESPACE,
			'/rooms/(?P<id>\d+)/files/(?P<file_id>\d+)',
			array(
				array(
					'methods'             => 'GET',
					'callback'            => array( $this, 'view' ),
					'permission_callback' => array( $this, 'read_permission' ),
				),
				array(
					'methods'             => 'PATCH',
					'callback'            => array( $this, 'update' ),
					'permission_callback' => array( $this, 'manage_permission' ),
				),
				array(
					'methods'             => 'DELETE',
					'callback'            => array( $this, 'remove' ),
					'permission_callback' => array( $this, 'manage_permission' ),
				),
			)
		);
		register_rest_route(
			self::NAMESPACE,
			'/rooms/(?P<id>\d+)/files/(?P<file_id>\d+)/replace',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'replace' ),
				'permission_callback' => array( $this, 'manage_permission' ),
			)
		);
		register_rest_route(
			self::NAMESPACE,
			'/rooms/(?P<id>\d+)/files/(?P<file_id>\d+)/retry',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'retry' ),
				'permission_callback' => array( $this, 'manage_permission' ),
			)
		);
		register_rest_route(
			self::NAMESPACE,
			'/rooms/(?P<id>\d+)/files/(?P<file_id>\d+)/storage-asset',
			array(
				'methods'             => 'DELETE',
				'callback'            => array( $this, 'delete_asset' ),
				'permission_callback' => array( $this, 'manage_permission' ),
			)
		);
	}
	public function read_permission( \WP_REST_Request $request ) {
		$nonce = $this->verify_nonce( $request );
		if ( is_wp_error( $nonce ) ) {
			return $nonce;
		}$room_id = absint( $request['id'] );
		return $this->access->can_read_room( $room_id ) ? true : new \WP_Error( 'acl_ar_room_file_forbidden', __( 'You cannot access room files.', 'acl-agent-rooms' ), array( 'status' => 403 ) );}
	public function manage_permission( \WP_REST_Request $request ) {
		$nonce = $this->verify_nonce( $request );
		if ( is_wp_error( $nonce ) ) {
			return $nonce;
		}$room_id = absint( $request['id'] );
		return $this->access->can_manage_room( $room_id ) ? true : new \WP_Error( 'acl_ar_room_file_forbidden', __( 'Only room managers may change room files.', 'acl-agent-rooms' ), array( 'status' => 403 ) );}
	public function index( \WP_REST_Request $request ) {
		return $this->response(
			array(
				'files'   => $this->service->list_for_user( absint( $request['id'] ), get_current_user_id() ),
				'storage' => $this->service->availability(),
			)
		);}
	public function assets( \WP_REST_Request $request ) {
		$result = $this->service->owned_assets( absint( $request['id'] ), get_current_user_id() );
		return is_wp_error( $result ) ? $result : $this->response( array( 'assets' => $result ) );}
	public function create( \WP_REST_Request $request ) {
		$files  = $request->get_file_params();
		$result = ! empty( $files['file'] ) ? $this->service->upload( absint( $request['id'] ), $files['file'], get_current_user_id() ) : $this->service->attach( absint( $request['id'] ), absint( $request->get_param( 'storage_asset_id' ) ), get_current_user_id() );
		return is_wp_error( $result ) ? $result : $this->response( array( 'file' => $result ), 201 );}
	public function update( \WP_REST_Request $request ) {
		$data = array();
		foreach ( array( 'room_label', 'context_enabled', 'priority' ) as $key ) {
			if ( $request->has_param( $key ) ) {
				$data[ $key ] = $request->get_param( $key );
			}
		}$result = $this->service->update( absint( $request['id'] ), absint( $request['file_id'] ), $data, get_current_user_id() );
		return is_wp_error( $result ) ? $result : $this->response( array( 'file' => $result ) );}
	public function replace( \WP_REST_Request $request ) {
		$files = $request->get_file_params();
		if ( empty( $files['file'] ) ) {
			return new \WP_Error( 'acl_ar_room_file_upload_required', __( 'Choose a replacement file.', 'acl-agent-rooms' ), array( 'status' => 400 ) );
		}$result = $this->service->replace( absint( $request['id'] ), absint( $request['file_id'] ), $files['file'], get_current_user_id() );
		return is_wp_error( $result ) ? $result : $this->response( array( 'file' => $result ) );}
	public function remove( \WP_REST_Request $request ) {
		$result = $this->service->remove( absint( $request['id'] ), absint( $request['file_id'] ), get_current_user_id() );
		return is_wp_error( $result ) ? $result : $this->response( array( 'removed' => true ) );}
	public function delete_asset( \WP_REST_Request $request ) {
		if ( ! rest_sanitize_boolean( $request->get_param( 'confirm' ) ) ) {
			return new \WP_Error( 'acl_ar_room_file_delete_confirmation', __( 'Explicit storage deletion confirmation is required.', 'acl-agent-rooms' ), array( 'status' => 400 ) );
		}$result = $this->service->delete_asset( absint( $request['id'] ), absint( $request['file_id'] ), get_current_user_id() );
		return is_wp_error( $result ) ? $result : $this->response( array( 'deleted' => true ) );}
	public function retry( \WP_REST_Request $request ) {
		$result = $this->service->retry( absint( $request['id'] ), absint( $request['file_id'] ), get_current_user_id() );
		return is_wp_error( $result ) ? $result : $this->response( array( 'file' => $this->service->list_for_user( absint( $request['id'] ), get_current_user_id() ) ) );}
	public function view( \WP_REST_Request $request ) {
		$result = $this->service->viewer( absint( $request['id'] ), absint( $request['file_id'] ), get_current_user_id(), absint( $request->get_param( 'start' ) ?: 1 ), absint( $request->get_param( 'end' ) ?: 200 ) );
		return is_wp_error( $result ) ? $result : $this->response( $result );}
	private function response( array $data, int $status = 200 ): \WP_REST_Response {
		return new \WP_REST_Response(
			$data,
			$status,
			array(
				'Cache-Control'          => 'private, no-store',
				'Vary'                   => 'Cookie, X-WP-Nonce',
				'X-Content-Type-Options' => 'nosniff',
			)
		);}
}
