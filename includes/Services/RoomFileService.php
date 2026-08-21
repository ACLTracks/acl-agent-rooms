<?php
/** Room-file application service and permission boundary. @package ACL_Agent_Rooms */
namespace ACL\AgentRooms\Services;

use ACL\AgentRooms\Repositories\RoomFileRepository;
use ACL\AgentRooms\Repositories\RoomRepository;

if ( ! defined( 'ABSPATH' ) ) {
	exit; }

class RoomFileService {
	private RoomRepository $rooms;
	private RoomFileRepository $files;
	private AccessService $access;
	private StorageBridge $storage;
	private RoomFileExtractionService $extractor;
	private RoomEventService $events;
	public function __construct( ?RoomRepository $rooms = null, ?RoomFileRepository $files = null, ?AccessService $access = null, ?StorageBridge $storage = null ) {
		$this->rooms     = $rooms ?: new RoomRepository();
		$this->files     = $files ?: new RoomFileRepository();
		$this->access    = $access ?: new AccessService( $this->rooms );
		$this->storage   = $storage ?: new StorageBridge();
		$this->extractor = new RoomFileExtractionService( $this->storage, $this->files );
		$this->events    = new RoomEventService();}
	public function availability(): array {
		return $this->storage->status();}
	public function list_for_user( int $room_id, int $user_id ): array {
		if ( ! $this->access->can_read_room( $room_id, $user_id ) ) {
			return array();
		}$room = $this->rooms->find( $room_id );
		if ( ! $room || ! $this->storage->available() ) {
			return array();
		}if ( empty( $room['room_files_enabled'] ) && ! $this->access->can_manage_room( $room_id, $user_id ) ) {
			return array();
		}return array_map( fn( $f )=>$this->public_file( $f ), $this->files->for_room( $room_id ) );}
	public function owned_assets( int $room_id, int $user_id ) {
		$guard = $this->manager( $room_id, $user_id );
		if ( is_wp_error( $guard ) ) {
			return $guard;
		}return $this->storage->owned_assets( $user_id, 200 );}
	public function attach( int $room_id, int $asset_id, int $user_id ) {
		$guard = $this->manager( $room_id, $user_id );
		if ( is_wp_error( $guard ) ) {
			return $guard;
		}$asset = $this->storage->metadata( $asset_id, $user_id );
		if ( is_wp_error( $asset ) ) {
			return $asset;
		}$file = $this->files->attach( $room_id, $asset, $user_id );
		if ( is_wp_error( $file ) ) {
			return $file;
		}$processed = $this->extractor->process( (int) $file['id'] );
		$this->audit( $room_id, $user_id, 'attached', (int) $file['id'] );
		return is_wp_error( $processed ) ? $this->public_file( $this->files->find( (int) $file['id'] ) ) : $this->public_file( $this->files->find( (int) $file['id'] ) );}
	public function upload( int $room_id, array $uploaded, int $user_id ) {
		$guard = $this->manager( $room_id, $user_id );
		if ( is_wp_error( $guard ) ) {
			return $guard;
		}$asset = $this->storage->upload( $uploaded, $user_id );
		if ( is_wp_error( $asset ) ) {
			return $asset;
		}return $this->attach( $room_id, (int) $asset['id'], $user_id );}
	public function replace( int $room_id, int $file_id, array $uploaded, int $user_id ) {
		$guard = $this->manager_file( $room_id, $file_id, $user_id );
		if ( is_wp_error( $guard ) ) {
			return $guard;
		}$asset = $this->storage->upload( $uploaded, $user_id );
		if ( is_wp_error( $asset ) ) {
			return $asset;
		}$file = $this->files->replace( $file_id, $asset, $user_id );
		if ( is_wp_error( $file ) ) {
			return $file;
		}$processed = $this->extractor->process( $file_id );
		$this->audit( $room_id, $user_id, 'replaced', $file_id );
		return $this->public_file( $this->files->find( $file_id ) );}
	public function update( int $room_id, int $file_id, array $data, int $user_id ) {
		$guard = $this->manager_file( $room_id, $file_id, $user_id );
		if ( is_wp_error( $guard ) ) {
			return $guard;
		}if ( isset( $data['room_label'] ) && trim( (string) $data['room_label'] ) === '' ) {
			return new \WP_Error( 'acl_ar_room_file_label_required', __( 'File label is required.', 'acl-agent-rooms' ), array( 'status' => 400 ) );
		}$this->files->update_metadata( $file_id, $data );
		$this->audit( $room_id, $user_id, 'updated', $file_id );
		return $this->public_file( $this->files->find( $file_id ) );}
	public function remove( int $room_id, int $file_id, int $user_id ) {
		$guard = $this->manager_file( $room_id, $file_id, $user_id );
		if ( is_wp_error( $guard ) ) {
			return $guard;
		}$this->files->clear_version_content( $file_id );
		$this->files->remove( $file_id );
		$this->audit( $room_id, $user_id, 'removed', $file_id );
		return true;}
	public function delete_asset( int $room_id, int $file_id, int $user_id ) {
		$guard = $this->manager_file( $room_id, $file_id, $user_id );
		if ( is_wp_error( $guard ) ) {
			return $guard;
		}$file   = $this->files->find( $file_id );
		$deleted = $this->storage->delete( (int) $file['storage_asset_id'], $user_id );
		if ( is_wp_error( $deleted ) ) {
			return $deleted;
		}$this->files->clear_version_content( $file_id );
		$this->files->remove( $file_id );
		$this->audit( $room_id, $user_id, 'asset_deleted', $file_id );
		return true;}
	public function retry( int $room_id, int $file_id, int $user_id ) {
		$guard = $this->manager_file( $room_id, $file_id, $user_id );
		if ( is_wp_error( $guard ) ) {
			return $guard;
		}return $this->extractor->process( $file_id );}
	public function validate_selection( array $room, array $ids, int $user_id ) {
		if ( empty( $room['room_files_enabled'] ) || empty( $room['room_files_agent_access'] ) ) {
			return empty( $ids ) ? array() : new \WP_Error( 'acl_ar_room_files_disabled', __( 'Room file context is disabled.', 'acl-agent-rooms' ), array( 'status' => 400 ) );
		}if ( ! $this->access->can_write_room( (int) $room['id'], $user_id ) ) {
			return new \WP_Error( 'acl_ar_room_file_forbidden', __( 'You cannot select files in this room.', 'acl-agent-rooms' ), array( 'status' => 403 ) );
		}$ids = array_values( array_unique( array_filter( array_map( 'absint', $ids ) ) ) );
		if ( count( $ids ) > max( 1, (int) $room['file_context_max_files'] ) ) {
			return new \WP_Error( 'acl_ar_room_file_selection_limit', __( 'Too many room files were selected.', 'acl-agent-rooms' ), array( 'status' => 400 ) );
		}$available = array_column( $this->list_for_user( (int) $room['id'], $user_id ), 'id' );
		foreach ( $ids as $id ) {
			$file = $this->files->find( $id );
			if ( ! in_array( $id, $available, true ) || ! $file || empty( $file['context_enabled'] ) || 'ready' !== (string) $file['indexing_status'] ) {
				return new \WP_Error( 'acl_ar_room_file_selection_invalid', __( 'A selected room file is unavailable.', 'acl-agent-rooms' ), array( 'status' => 400 ) );
			}
		}return $ids;}
	public function viewer( int $room_id, int $file_id, int $user_id, int $start = 1, int $end = 200 ) {
		if ( ! $this->access->can_read_room( $room_id, $user_id ) ) {
			return new \WP_Error( 'acl_ar_room_file_forbidden', __( 'You cannot view this room file.', 'acl-agent-rooms' ), array( 'status' => 403 ) );
		}$file   = $this->files->find( $file_id );
		$version = $file ? $this->files->active_version( $file_id ) : null;
		if ( ! $file || ! $version || (int) $file['room_id'] !== $room_id || ! empty( $file['removed_at'] ) || 'ready' !== (string) $version['extraction_status'] ) {
			return new \WP_Error( 'acl_ar_room_file_not_found', __( 'Room file not found.', 'acl-agent-rooms' ), array( 'status' => 404 ) );
		}$lines = explode( "\n", (string) $version['extracted_text'] );
		$start  = max( 1, min( count( $lines ), $start ) );
		$end    = max( $start, min( count( $lines ), $end ) );
		$slice  = array();for ( $i = $start;$i <= $end;$i++ ) {
			$slice[] = array(
				'number' => $i,
				'text'   => (string) $lines[ $i - 1 ],
			);
		}$download = $this->storage->download_url( (int) $file['storage_asset_id'], $user_id );
		return array(
			'file'         => $this->public_file( $file ),
			'version'      => array(
				'id'         => (int) $version['id'],
				'hash'       => (string) $version['content_hash'],
				'line_count' => (int) $version['line_count'],
			),
			'start_line'   => $start,
			'end_line'     => $end,
			'lines'        => $slice,
			'download_url' => is_wp_error( $download ) ? null : $download,
		);}
	private function manager( int $room_id, int $user_id ) {
		$room = $this->rooms->find( $room_id );
		if ( ! $room ) {
			return new \WP_Error( 'acl_ar_room_not_found', __( 'Room not found.', 'acl-agent-rooms' ), array( 'status' => 404 ) );
		}if ( ! $this->access->can_manage_room( $room_id, $user_id ) ) {
			return new \WP_Error( 'acl_ar_room_file_forbidden', __( 'Only room managers may change persistent room files.', 'acl-agent-rooms' ), array( 'status' => 403 ) );
		}if ( ! $this->storage->available() ) {
			return new \WP_Error( 'acl_ar_storage_unavailable', __( 'Compatible ACL Storage is unavailable.', 'acl-agent-rooms' ), array( 'status' => 503 ) );
		}return $room;}
	private function manager_file( int $room_id, int $file_id, int $user_id ) {
		$room = $this->manager( $room_id, $user_id );
		if ( is_wp_error( $room ) ) {
			return $room;
		}$file = $this->files->find( $file_id );
		return ! $file || (int) $file['room_id'] !== $room_id || ! empty( $file['removed_at'] ) ? new \WP_Error( 'acl_ar_room_file_not_found', __( 'Room file not found.', 'acl-agent-rooms' ), array( 'status' => 404 ) ) : $file;}
	private function public_file( array $file ): array {
		return array(
			'id'                => (int) $file['id'],
			'room_id'           => (int) $file['room_id'],
			'label'             => (string) $file['room_label'],
			'original_filename' => (string) $file['original_filename'],
			'mime_type'         => (string) $file['mime_type'],
			'extension'         => (string) $file['file_extension'],
			'size'              => (int) $file['file_size'],
			'hash'              => (string) $file['content_hash'],
			'context_enabled'   => ! empty( $file['context_enabled'] ),
			'priority'          => (int) $file['priority'],
			'extraction_status' => (string) $file['extraction_status'],
			'indexing_status'   => (string) $file['indexing_status'],
			'version_id'        => (int) $file['active_version_id'],
			'created_at'        => (string) $file['created_at'],
			'updated_at'        => (string) $file['updated_at'],
		);}
	private function audit( int $room_id, int $user_id, string $operation, int $file_id ): void {
		$this->events->create(
			array(
				'room_id'         => $room_id,
				'event_type'      => 'room_file',
				'actor_type'      => 'user',
				'actor_id'        => $user_id,
				'target_type'     => 'room_file',
				'target_id'       => $file_id,
				'audience_type'   => 'moderators',
				'idempotency_key' => hash( 'sha256', 'room-file:' . $room_id . ':' . $file_id . ':' . $operation . ':' . microtime( true ) ),
				'content'         => null,
				'content_format'  => 'plain',
				'metadata'        => array(
					'operation'    => $operation,
					'room_file_id' => $file_id,
				),
			)
		);}
}
