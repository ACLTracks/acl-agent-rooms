<?php
// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange -- This file operates on plugin-owned operational tables and requires uncached transaction/read-after-write truth.
// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- Identifiers are fixed $wpdb->prefix suffixes, fixed internal schema fragments, or allowlisted names; runtime values use placeholders or wpdb mutation arrays.
// phpcs:disable PluginCheck.Security.DirectDB.UnescapedDBParameter -- PHPCS cannot model the fixed identifier provenance above; request data is never used as an SQL identifier.
/**
 * Uninstall handler for ACL Agent Rooms.
 *
 * @package ACL_Agent_Rooms
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

$settings    = get_option( 'acl_ar_settings', array() );
$delete_data = ! empty( $settings['delete_data_on_uninstall'] );

if ( defined( 'ACL_AR_DELETE_DATA_ON_UNINSTALL' ) && ACL_AR_DELETE_DATA_ON_UNINSTALL ) {
	$delete_data = true;
}

$delete_data = (bool) apply_filters( 'acl_ar_delete_data_on_uninstall', $delete_data );

if ( ! $delete_data ) {
	return;
}

global $wpdb;

$tables = array(
	'acl_ar_conversation_turns',
	'acl_ar_maintenance_runs',
	'acl_ar_room_restrictions',
	'acl_ar_event_search',
	'acl_ar_room_presence_sessions',
	'acl_ar_room_presence',
	'acl_ar_room_reads',
	'acl_ar_event_reactions',
	'acl_ar_events',
	'acl_ar_usage',
	'acl_ar_brain_runs',
	'acl_ar_agent_jobs',
	'acl_ar_messages',
	'acl_ar_room_agents',
	'acl_ar_room_file_versions',
	'acl_ar_room_files',
	'acl_ar_room_members',
	'acl_ar_rooms',
	'acl_ar_shared_configs',
	'acl_ar_brains',
	'acl_ar_agents',
);

foreach ( $tables as $table ) {
	$wpdb->query( 'DROP TABLE IF EXISTS ' . $wpdb->prefix . $table );
}

delete_option( 'acl_ar_db_version' );
delete_option( 'acl_ar_settings' );
delete_option( 'acl_ar_event_backfill_cursor' );
delete_option( 'acl_ar_event_backfill_status' );
delete_option( 'acl_ar_event_backfill_error' );
delete_option( 'acl_ar_clear_health' );
delete_option( 'acl_ar_orchestration_diagnostics' );
delete_option( 'acl_ar_natural_health' );

$capabilities = array(
	'acl_ar_use_rooms',
	'acl_ar_create_rooms',
	'acl_ar_manage_own_rooms',
	'acl_ar_manage_agents',
	'acl_ar_manage_all_rooms',
	'acl_ar_manage_settings',
);

foreach ( wp_roles()->roles as $role_name => $role_data ) {
	$role = get_role( (string) $role_name );
	if ( ! $role ) {
		continue;
	}

	foreach ( $capabilities as $capability ) {
		$role->remove_cap( $capability );
	}
}
