<?php
// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange -- This file operates on plugin-owned operational tables and requires uncached transaction/read-after-write truth.
// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- Identifiers are fixed $wpdb->prefix suffixes, fixed internal schema fragments, or allowlisted names; runtime values use placeholders or wpdb mutation arrays.
// phpcs:disable PluginCheck.Security.DirectDB.UnescapedDBParameter -- PHPCS cannot model the fixed identifier provenance above; request data is never used as an SQL identifier.
/** Room event persistence. @package ACL_Agent_Rooms */

namespace ACL\AgentRooms\Repositories;

use ACL\AgentRooms\Models\RoomEvent;
use ACL\AgentRooms\Support\Json;

if ( ! defined( 'ABSPATH' ) ) {
	exit; }

class EventRepository {
	private function table(): string {
		global $wpdb;
		return $wpdb->prefix . 'acl_ar_events'; }

	public function create( array $data ) {
		global $wpdb;
		$inserted = $wpdb->insert(
			$this->table(),
			array(
				'room_id'           => (int) $data['room_id'],
				'event_type'        => (string) $data['event_type'],
				'actor_type'        => (string) $data['actor_type'],
				'actor_id'          => $data['actor_id'],
				'target_type'       => $data['target_type'],
				'target_id'         => $data['target_id'],
				'audience_type'     => (string) $data['audience_type'],
				'audience_id'       => $data['audience_id'],
				'parent_event_id'   => $data['parent_event_id'],
				'legacy_message_id' => $data['legacy_message_id'],
				'job_id'            => $data['job_id'],
				'idempotency_key'   => $data['idempotency_key'],
				'content'           => $data['content'],
				'content_format'    => (string) $data['content_format'],
				'metadata_json'     => Json::encode( $data['metadata'] ),
				'created_at'        => (string) $data['created_at'],
				'edited_at'         => $data['edited_at'],
				'deleted_at'        => $data['deleted_at'],
			),
			array( '%d', '%s', '%s', '%d', '%s', '%d', '%s', '%d', '%d', '%d', '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s' )
		);
		if ( false !== $inserted ) {
			return (int) $wpdb->insert_id; }
		$existing = ! empty( $data['legacy_message_id'] ) ? $this->find_by_legacy_message_id( (int) $data['legacy_message_id'] ) : null;
		$existing = $existing ?: ( ! empty( $data['idempotency_key'] ) ? $this->find_by_idempotency_key( (string) $data['idempotency_key'] ) : null );
		return $existing ? (int) $existing['id'] : new \WP_Error( 'acl_ar_event_not_created', $wpdb->last_error ?: __( 'Room event could not be created.', 'acl-agent-rooms' ) );
	}

	public function find( int $id ): ?array {
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . $this->table() . ' WHERE id = %d', $id ), ARRAY_A );
		return $row ? RoomEvent::from_row( $row ) : null; }
	public function find_by_legacy_message_id( int $id ): ?array {
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . $this->table() . ' WHERE legacy_message_id = %d', $id ), ARRAY_A );
		return $row ? RoomEvent::from_row( $row ) : null; }
	public function find_by_job_id( int $job_id, string $event_type = '' ): ?array {
		global $wpdb;
		$sql = '' !== $event_type ? $wpdb->prepare( 'SELECT * FROM ' . $this->table() . ' WHERE job_id = %d AND event_type = %s ORDER BY id ASC LIMIT 1', $job_id, $event_type ) : $wpdb->prepare( 'SELECT * FROM ' . $this->table() . ' WHERE job_id = %d ORDER BY id ASC LIMIT 1', $job_id );
		$row = $wpdb->get_row( $sql, ARRAY_A );
		return $row ? RoomEvent::from_row( $row ) : null; }
	public function find_by_idempotency_key( string $key ): ?array {
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . $this->table() . ' WHERE idempotency_key = %s', $key ), ARRAY_A );
		return $row ? RoomEvent::from_row( $row ) : null; }
	public function exists_for_legacy_message( int $id ): bool {
		return null !== $this->find_by_legacy_message_id( $id ); }
	public function find_many( array $ids ): array {
		global $wpdb;
		$ids = array_values( array_unique( array_filter( array_map( 'absint', $ids ) ) ) );
		if ( ! $ids ) {
			return array();
		}$sql = 'SELECT * FROM ' . $this->table() . ' WHERE id IN (' . implode( ',', array_fill( 0, count( $ids ), '%d' ) ) . ')';
		$rows = $wpdb->get_results( $wpdb->prepare( $sql, ...$ids ), ARRAY_A );
		$out  = array();
		foreach ( $this->normalize( $rows ) as $row ) {
			$out[ (int) $row['id'] ] = $row;
		}return $out; }
	public function latest_edits_for( array $parent_ids ): array {
		global $wpdb;
		$parent_ids = array_values( array_unique( array_filter( array_map( 'absint', $parent_ids ) ) ) );
		if ( ! $parent_ids ) {
			return array();
		}$sql = 'SELECT e.* FROM ' . $this->table() . ' e INNER JOIN (SELECT parent_event_id,MAX(id) id FROM ' . $this->table() . ' WHERE event_type=%s AND parent_event_id IN (' . implode( ',', array_fill( 0, count( $parent_ids ), '%d' ) ) . ') GROUP BY parent_event_id) x ON x.id=e.id';
		$rows = $wpdb->get_results( $wpdb->prepare( $sql, 'message_edit', ...$parent_ids ), ARRAY_A );
		$out  = array();
		foreach ( $this->normalize( $rows ) as $row ) {
			$out[ (int) $row['parent_event_id'] ] = $row;
		}return $out; }
	public function update_parent_if_empty( int $id, int $parent_id ): bool {
		global $wpdb;
		return false !== $wpdb->query( $wpdb->prepare( 'UPDATE ' . $this->table() . ' SET parent_event_id=%d WHERE id=%d AND parent_event_id IS NULL', $parent_id, $id ) ); }

	public function for_room_after( int $room_id, int $after_id, int $limit = 50 ): array {
		global $wpdb;
		$limit = max( 1, min( 200, $limit ) );
		$rows  = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . $this->table() . ' WHERE room_id = %d AND id > %d ORDER BY id ASC LIMIT %d', $room_id, $after_id, $limit ), ARRAY_A );
		return $this->normalize( $rows ); }
	public function for_room_before( int $room_id, int $before_id, int $limit = 50 ): array {
		global $wpdb;
		$limit = max( 1, min( 200, $limit ) );
		$rows  = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . $this->table() . ' WHERE room_id = %d AND id < %d ORDER BY id DESC LIMIT %d', $room_id, $before_id, $limit ), ARRAY_A );
		return array_reverse( $this->normalize( $rows ) ); }
	public function newest_for_room( int $room_id, int $limit = 50 ): array {
		global $wpdb;
		$limit = max( 1, min( 200, $limit ) );
		$rows  = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . $this->table() . ' WHERE room_id = %d ORDER BY id DESC LIMIT %d', $room_id, $limit ), ARRAY_A );
		return array_reverse( $this->normalize( $rows ) ); }
	public function count_for_room( int $room_id ): int {
		global $wpdb;
		return (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . $this->table() . ' WHERE room_id = %d', $room_id ) ); }
	public function highest_id( int $room_id ): int {
		global $wpdb;
		return (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COALESCE(MAX(id),0) FROM ' . $this->table() . ' WHERE room_id=%d', $room_id ) ); }
	public function highest_transcript_id( int $room_id ): int {
		global $wpdb;
		$types = \ACL\AgentRooms\Services\RoomCutoffPolicy::TRANSCRIPT_TYPES;
		$marks = implode( ',', array_fill( 0, count( $types ), '%s' ) );
		return (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COALESCE(MAX(id),0) FROM ' . $this->table() . ' WHERE room_id=%d AND event_type IN (' . $marks . ')', $room_id, ...$types ) ); }
	public function scan_room_after( int $room_id, int $after_id, int $limit ): array {
		global $wpdb;
		$limit = max( 1, min( 500, $limit ) );
		$rows  = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . $this->table() . ' WHERE room_id = %d AND id > %d ORDER BY id ASC LIMIT %d', $room_id, $after_id, $limit ), ARRAY_A );
		return $this->normalize( $rows ); }
	public function scan_room_before( int $room_id, int $before_id, int $limit ): array {
		global $wpdb;
		$limit = max( 1, min( 500, $limit ) );
		$rows  = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . $this->table() . ' WHERE room_id = %d AND id < %d ORDER BY id DESC LIMIT %d', $room_id, $before_id, $limit ), ARRAY_A );
		return $this->normalize( $rows ); }
	public function scan_newest_for_room( int $room_id, int $limit ): array {
		global $wpdb;
		$limit = max( 1, min( 500, $limit ) );
		$rows  = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . $this->table() . ' WHERE room_id = %d ORDER BY id DESC LIMIT %d', $room_id, $limit ), ARRAY_A );
		return $this->normalize( $rows ); }
	public function update_metadata( int $id, array $metadata ): bool {
		global $wpdb;
		return false !== $wpdb->update( $this->table(), array( 'metadata_json' => Json::encode( $metadata ) ), array( 'id' => $id ), array( '%s' ), array( '%d' ) ); }
	public function soft_delete( int $id, string $placeholder, array $metadata = array() ): bool {
		global $wpdb;
		return false !== $wpdb->update(
			$this->table(),
			array(
				'content'       => $placeholder,
				'metadata_json' => Json::encode( $metadata ),
				'deleted_at'    => current_time( 'mysql', true ),
			),
			array( 'id' => $id ),
			array( '%s', '%s', '%s' ),
			array( '%d' )
		); }
	public function expired_candidates( int $room_id, string $before, int $limit = 100 ): array {
		global $wpdb;
		$limit = max( 1, min( 500, $limit ) );
		$rows  = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . $this->table() . ' WHERE room_id=%d AND event_type=%s AND deleted_at IS NULL AND created_at<%s ORDER BY id ASC LIMIT %d', $room_id, 'message', $before, $limit ), ARRAY_A );
		return $this->normalize( $rows ); }
	private function normalize( $rows ): array {
		return array_map( array( RoomEvent::class, 'from_row' ), is_array( $rows ) ? $rows : array() ); }
}
