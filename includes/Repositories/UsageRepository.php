<?php
// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange -- This file operates on plugin-owned operational tables and requires uncached transaction/read-after-write truth.
// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- Identifiers are fixed $wpdb->prefix suffixes, fixed internal schema fragments, or allowlisted names; runtime values use placeholders or wpdb mutation arrays.
// phpcs:disable PluginCheck.Security.DirectDB.UnescapedDBParameter -- PHPCS cannot model the fixed identifier provenance above; request data is never used as an SQL identifier.
/**
 * Usage persistence.
 *
 * @package ACL_Agent_Rooms
 */

namespace ACL\AgentRooms\Repositories;

use ACL\AgentRooms\Support\Time;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class UsageRepository {
	private function table(): string {
		global $wpdb;
		return $wpdb->prefix . 'acl_ar_usage';
	}

	public function create( array $data ): bool {
		global $wpdb;

		$inserted = $wpdb->insert(
			$this->table(),
			array(
				'user_id'           => isset( $data['user_id'] ) ? absint( $data['user_id'] ) : null,
				'room_id'           => isset( $data['room_id'] ) ? absint( $data['room_id'] ) : null,
				'agent_id'          => isset( $data['agent_id'] ) ? absint( $data['agent_id'] ) : null,
				'brain_id'          => isset( $data['brain_id'] ) ? absint( $data['brain_id'] ) : null,
				'brain_run_id'      => isset( $data['brain_run_id'] ) ? absint( $data['brain_run_id'] ) : null,
				'provider_route'    => isset( $data['provider_route'] ) ? sanitize_text_field( (string) $data['provider_route'] ) : null,
				'model'             => isset( $data['model'] ) ? sanitize_text_field( (string) $data['model'] ) : null,
				'prompt_tokens'     => absint( $data['prompt_tokens'] ?? 0 ),
				'completion_tokens' => absint( $data['completion_tokens'] ?? 0 ),
				'total_tokens'      => absint( $data['total_tokens'] ?? 0 ),
				'estimated_cost'    => isset( $data['estimated_cost'] ) ? (float) $data['estimated_cost'] : 0,
				'created_at'        => Time::mysql_gmt(),
			),
			array( '%d', '%d', '%d', '%d', '%d', '%s', '%s', '%d', '%d', '%d', '%f', '%s' )
		);

		if ( false !== $inserted ) {
			return true; }
		if ( ! empty( $data['brain_run_id'] ) ) {
			return (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . $this->table() . ' WHERE brain_run_id=%d', absint( $data['brain_run_id'] ) ) ) > 0; }
		return false;
	}
}
