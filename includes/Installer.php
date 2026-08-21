<?php
// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange -- This file operates on plugin-owned operational tables and requires uncached transaction/read-after-write truth.
// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- Identifiers are fixed $wpdb->prefix suffixes, fixed internal schema fragments, or allowlisted names; runtime values use placeholders or wpdb mutation arrays.
// phpcs:disable PluginCheck.Security.DirectDB.UnescapedDBParameter -- PHPCS cannot model the fixed identifier provenance above; request data is never used as an SQL identifier.
/**
 * Database schema installer.
 *
 * @package ACL_Agent_Rooms
 */

namespace ACL\AgentRooms;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Installer {
	public static function maybe_upgrade(): void {
		$installed = (string) get_option( 'acl_ar_db_version', '' );

		if ( ACL_AR_DB_VERSION !== $installed ) {
			self::install();
		}
	}

	public static function install(): void {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$charset = $wpdb->get_charset_collate();
		$prefix  = $wpdb->prefix;

		$statements = array(
			"CREATE TABLE {$prefix}acl_ar_brains (
				id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
				owner_user_id BIGINT UNSIGNED NOT NULL,
				name VARCHAR(191) NOT NULL,
				slug VARCHAR(191) NOT NULL,
				description TEXT NULL,
				provider VARCHAR(100) NOT NULL,
				model VARCHAR(191) NOT NULL,
				orchestration_prompt LONGTEXT NULL,
				temperature DECIMAL(5,3) NULL,
				max_tokens_per_agent INT UNSIGNED NOT NULL DEFAULT 600,
				max_total_tokens INT UNSIGNED NOT NULL DEFAULT 6000,
				settings_json LONGTEXT NULL,
				enabled TINYINT(1) NOT NULL DEFAULT 1,
				created_at DATETIME NOT NULL,
				updated_at DATETIME NOT NULL,
				PRIMARY KEY  (id),
				UNIQUE KEY slug (slug),
				KEY owner_user_id (owner_user_id),
				KEY enabled (enabled,id),
				KEY provider_model (provider,model,id)
			) {$charset};",
			"CREATE TABLE {$prefix}acl_ar_agents (
				id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
				owner_user_id BIGINT UNSIGNED NULL,
				name VARCHAR(190) NOT NULL,
				slug VARCHAR(190) NOT NULL,
				description LONGTEXT NULL,
				avatar_attachment_id BIGINT UNSIGNED DEFAULT 0,
				avatar_url TEXT NULL,
				config_mode VARCHAR(20) DEFAULT 'independent',
				shared_config_id BIGINT UNSIGNED NULL,
				execution_mode VARCHAR(16) NOT NULL DEFAULT 'independent',
				brain_id BIGINT UNSIGNED NULL,
				provider_route VARCHAR(190) NOT NULL,
				model VARCHAR(190) NOT NULL,
				system_prompt LONGTEXT NOT NULL,
				temperature DECIMAL(4,2) DEFAULT 0.70,
				max_tokens INT UNSIGNED DEFAULT 1200,
				natural_participation_chance TINYINT UNSIGNED NOT NULL DEFAULT 60,
				natural_question_tendency TINYINT UNSIGNED NOT NULL DEFAULT 20,
				natural_delay_min_ms INT UNSIGNED NULL,
				natural_delay_max_ms INT UNSIGNED NULL,
				natural_cooldown_seconds INT UNSIGNED NOT NULL DEFAULT 20,
				natural_max_auto_responses_per_10m TINYINT UNSIGNED NOT NULL DEFAULT 4,
				natural_conversation_role VARCHAR(32) NOT NULL DEFAULT 'balanced',
				visibility VARCHAR(20) DEFAULT 'private',
				enabled TINYINT(1) DEFAULT 1,
				created_at DATETIME NOT NULL,
				updated_at DATETIME NOT NULL,
				PRIMARY KEY  (id),
				UNIQUE KEY slug (slug),
				KEY owner_user_id (owner_user_id),
				KEY avatar_attachment_id (avatar_attachment_id),
				KEY shared_config_id (shared_config_id),
				KEY brain_execution (brain_id,execution_mode,enabled,id),
				KEY enabled (enabled)
			) {$charset};",
			"CREATE TABLE {$prefix}acl_ar_shared_configs (
				id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
				owner_user_id BIGINT UNSIGNED NULL,
				name VARCHAR(190) NOT NULL,
				slug VARCHAR(190) NOT NULL,
				provider_route VARCHAR(190) NOT NULL,
				model VARCHAR(190) NOT NULL,
				system_prompt LONGTEXT NOT NULL,
				temperature DECIMAL(4,2) DEFAULT 0.70,
				max_tokens INT UNSIGNED DEFAULT 1200,
				enabled TINYINT(1) DEFAULT 1,
				created_at DATETIME NOT NULL,
				updated_at DATETIME NOT NULL,
				PRIMARY KEY  (id),
				UNIQUE KEY slug (slug),
				KEY owner_user_id (owner_user_id),
				KEY enabled (enabled)
			) {$charset};",
			"CREATE TABLE {$prefix}acl_ar_rooms (
				id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
				owner_user_id BIGINT UNSIGNED NOT NULL,
				title VARCHAR(190) NOT NULL,
				slug VARCHAR(190) NOT NULL,
				description LONGTEXT NULL,
				top_context LONGTEXT NULL,
				type VARCHAR(20) DEFAULT 'solo',
				visibility VARCHAR(20) DEFAULT 'private',
				status VARCHAR(20) DEFAULT 'active',
				agent_reply_mode VARCHAR(20) DEFAULT 'manual',
				max_context_messages INT UNSIGNED DEFAULT 20,
				max_agents_per_turn INT UNSIGNED DEFAULT 1,
				conversation_mode VARCHAR(16) NOT NULL DEFAULT 'immediate',
				natural_min_responders TINYINT UNSIGNED NOT NULL DEFAULT 1,
				natural_max_responders TINYINT UNSIGNED NOT NULL DEFAULT 2,
				natural_initial_delay_min_ms INT UNSIGNED NOT NULL DEFAULT 1500,
				natural_initial_delay_max_ms INT UNSIGNED NOT NULL DEFAULT 4500,
				natural_inter_turn_delay_min_ms INT UNSIGNED NOT NULL DEFAULT 2500,
				natural_inter_turn_delay_max_ms INT UNSIGNED NOT NULL DEFAULT 8000,
				natural_allow_silence TINYINT(1) NOT NULL DEFAULT 0,
				natural_silence_chance TINYINT UNSIGNED NOT NULL DEFAULT 10,
				natural_cancel_pending_on_new_message TINYINT(1) NOT NULL DEFAULT 1,
				natural_max_pending_turns TINYINT UNSIGNED NOT NULL DEFAULT 4,
				natural_steering_question_bias TINYINT UNSIGNED NOT NULL DEFAULT 35,
				natural_active_trigger_event_id BIGINT UNSIGNED NULL,
				allow_chat_clear TINYINT(1) NOT NULL DEFAULT 0,
				cleared_through_event_id BIGINT UNSIGNED NULL,
				chat_cleared_at DATETIME NULL,
				chat_cleared_by_user_id BIGINT UNSIGNED NULL,
				project_instructions LONGTEXT NULL,
				room_files_enabled TINYINT(1) NOT NULL DEFAULT 0,
				room_files_agent_access TINYINT(1) NOT NULL DEFAULT 0,
				file_context_mode VARCHAR(16) NOT NULL DEFAULT 'hybrid',
				file_context_max_files TINYINT UNSIGNED NOT NULL DEFAULT 5,
				file_context_max_chars INT UNSIGNED NOT NULL DEFAULT 12000,
				created_at DATETIME NOT NULL,
				updated_at DATETIME NOT NULL,
				PRIMARY KEY  (id),
				UNIQUE KEY slug (slug),
				KEY owner_user_id (owner_user_id),
				KEY type (type),
				KEY visibility (visibility)
			) {$charset};",
			"CREATE TABLE {$prefix}acl_ar_room_members (
				id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
				room_id BIGINT UNSIGNED NOT NULL,
				user_id BIGINT UNSIGNED NOT NULL,
				role VARCHAR(30) DEFAULT 'member',
				created_at DATETIME NOT NULL,
				PRIMARY KEY  (id),
				UNIQUE KEY room_id_user_id (room_id,user_id),
				KEY room_id (room_id),
				KEY user_id (user_id)
			) {$charset};",
			"CREATE TABLE {$prefix}acl_ar_room_agents (
				id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
				room_id BIGINT UNSIGNED NOT NULL,
				agent_id BIGINT UNSIGNED NOT NULL,
				sort_order INT UNSIGNED DEFAULT 0,
				enabled TINYINT(1) DEFAULT 1,
				participation_state VARCHAR(16) NOT NULL DEFAULT 'active',
				auto_muted TINYINT(1) NOT NULL DEFAULT 0,
				state_updated_at DATETIME NULL,
				created_at DATETIME NOT NULL,
				PRIMARY KEY  (id),
				UNIQUE KEY room_id_agent_id (room_id,agent_id),
				KEY room_id (room_id),
				KEY agent_id (agent_id),
				KEY room_participation (room_id,participation_state,auto_muted,sort_order,id)
			) {$charset};",
			"CREATE TABLE {$prefix}acl_ar_messages (
				id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
				room_id BIGINT UNSIGNED NOT NULL,
				sender_type VARCHAR(20) NOT NULL,
				sender_user_id BIGINT UNSIGNED NULL,
				sender_agent_id BIGINT UNSIGNED NULL,
				content LONGTEXT NOT NULL,
				status VARCHAR(30) DEFAULT 'sent',
				client_request_id VARCHAR(64) NULL,
				response_job_id BIGINT UNSIGNED NULL,
				brain_run_id BIGINT UNSIGNED NULL,
				brain_agent_id BIGINT UNSIGNED NULL,
				metadata_json LONGTEXT NULL,
				provider_route VARCHAR(190) NULL,
				model VARCHAR(190) NULL,
				prompt_tokens INT UNSIGNED DEFAULT 0,
				completion_tokens INT UNSIGNED DEFAULT 0,
				total_tokens INT UNSIGNED DEFAULT 0,
				created_at DATETIME NOT NULL,
				PRIMARY KEY  (id),
				KEY room_id (room_id),
				KEY room_id_id (room_id,id),
				KEY room_sender_id (room_id,sender_type,id),
				KEY sender_type (sender_type),
				KEY sender_user_id (sender_user_id),
				KEY sender_agent_id (sender_agent_id),
				KEY created_at (created_at),
				UNIQUE KEY room_user_request (room_id,sender_user_id,client_request_id),
				UNIQUE KEY response_job_id (response_job_id),
				UNIQUE KEY brain_response (brain_run_id,brain_agent_id)
			) {$charset};",
			"CREATE TABLE {$prefix}acl_ar_agent_jobs (
				id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
				room_id BIGINT UNSIGNED NOT NULL,
				trigger_message_id BIGINT UNSIGNED NOT NULL,
				agent_id BIGINT UNSIGNED NOT NULL,
				request_key VARCHAR(64) NULL,
				status VARCHAR(30) DEFAULT 'pending',
				attempts INT UNSIGNED DEFAULT 0,
				retryable TINYINT(1) DEFAULT 0,
				error_code VARCHAR(64) NULL,
				error_message TEXT NULL,
				public_error TEXT NULL,
				response_message_id BIGINT UNSIGNED NULL,
				lease_token VARCHAR(64) NULL,
				locked_at DATETIME NULL,
				lease_expires_at DATETIME NULL,
				next_attempt_at DATETIME NULL,
				completed_at DATETIME NULL,
				created_at DATETIME NOT NULL,
				updated_at DATETIME NOT NULL,
				PRIMARY KEY  (id),
				KEY room_id (room_id),
				KEY agent_id (agent_id),
				UNIQUE KEY request_key (request_key),
				KEY status (status),
				KEY response_message_id (response_message_id),
				KEY worker_ready (status,retryable,next_attempt_at,id),
				KEY stale_lease (status,lease_expires_at,id),
				KEY created_at (created_at)
			) {$charset};",
			"CREATE TABLE {$prefix}acl_ar_brain_runs (
				id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
				room_id BIGINT UNSIGNED NOT NULL,
				brain_id BIGINT UNSIGNED NOT NULL,
				trigger_event_id BIGINT UNSIGNED NOT NULL,
				request_key CHAR(64) NOT NULL,
				status VARCHAR(20) NOT NULL,
				target_agent_ids_json LONGTEXT NOT NULL,
				validated_response_json LONGTEXT NULL,
				response_event_ids_json LONGTEXT NULL,
				provider VARCHAR(100) NOT NULL,
				model VARCHAR(191) NOT NULL,
				attempts INT UNSIGNED NOT NULL DEFAULT 0,
				lease_token CHAR(64) NULL,
				locked_at DATETIME NULL,
				next_attempt_at DATETIME NULL,
				prompt_tokens INT UNSIGNED NOT NULL DEFAULT 0,
				completion_tokens INT UNSIGNED NOT NULL DEFAULT 0,
				total_tokens INT UNSIGNED NOT NULL DEFAULT 0,
				estimated_cost DECIMAL(18,8) NOT NULL DEFAULT 0,
				error_code VARCHAR(64) NULL,
				public_error TEXT NULL,
				created_at DATETIME NOT NULL,
				started_at DATETIME NULL,
				completed_at DATETIME NULL,
				updated_at DATETIME NOT NULL,
				PRIMARY KEY  (id),
				UNIQUE KEY request_key (request_key),
				KEY room_id (room_id,id),
				KEY brain_id (brain_id,id),
				KEY trigger_event_id (trigger_event_id,id),
				KEY worker (status,next_attempt_at,id),
				KEY lease (status,locked_at,id)
			) {$charset};",
			"CREATE TABLE {$prefix}acl_ar_usage (
				id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
				user_id BIGINT UNSIGNED NULL,
				room_id BIGINT UNSIGNED NULL,
				agent_id BIGINT UNSIGNED NULL,
				brain_id BIGINT UNSIGNED NULL,
				brain_run_id BIGINT UNSIGNED NULL,
				provider_route VARCHAR(190) NULL,
				model VARCHAR(190) NULL,
				prompt_tokens INT UNSIGNED DEFAULT 0,
				completion_tokens INT UNSIGNED DEFAULT 0,
				total_tokens INT UNSIGNED DEFAULT 0,
				estimated_cost DECIMAL(12,6) DEFAULT 0,
				created_at DATETIME NOT NULL,
				PRIMARY KEY  (id),
				KEY user_id (user_id),
				KEY room_id (room_id),
				KEY agent_id (agent_id),
				KEY brain_id (brain_id),
				UNIQUE KEY brain_run_id (brain_run_id),
				KEY provider_route (provider_route),
				KEY created_at (created_at)
			) {$charset};",
			"CREATE TABLE {$prefix}acl_ar_events (
				id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
				room_id BIGINT UNSIGNED NOT NULL,
				event_type VARCHAR(32) NOT NULL,
				actor_type VARCHAR(16) NOT NULL,
				actor_id BIGINT UNSIGNED NULL,
				target_type VARCHAR(16) NULL,
				target_id BIGINT UNSIGNED NULL,
				audience_type VARCHAR(16) NOT NULL DEFAULT 'room',
				audience_id BIGINT UNSIGNED NULL,
				parent_event_id BIGINT UNSIGNED NULL,
				legacy_message_id BIGINT UNSIGNED NULL,
				job_id BIGINT UNSIGNED NULL,
				idempotency_key VARCHAR(64) NULL,
				content LONGTEXT NULL,
				content_format VARCHAR(16) NOT NULL DEFAULT 'plain',
				metadata_json LONGTEXT NULL,
				created_at DATETIME NOT NULL,
				edited_at DATETIME NULL,
				deleted_at DATETIME NULL,
				PRIMARY KEY  (id),
				KEY room_id_id (room_id,id),
				KEY room_event_id (room_id,event_type,id),
				KEY room_actor_id (room_id,actor_type,actor_id,id),
				KEY room_audience_id (room_id,audience_type,audience_id,id),
				KEY parent_event_id (parent_event_id,id),
				KEY job_id (job_id),
				UNIQUE KEY legacy_message_id (legacy_message_id),
				UNIQUE KEY idempotency_key (idempotency_key)
			) {$charset};",
			"CREATE TABLE {$prefix}acl_ar_event_reactions (
				id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
				event_id BIGINT UNSIGNED NOT NULL,
				user_id BIGINT UNSIGNED NOT NULL,
				reaction VARCHAR(32) NOT NULL,
				created_at DATETIME NOT NULL,
				PRIMARY KEY  (id),
				UNIQUE KEY event_user_reaction (event_id,user_id,reaction),
				KEY event_id_id (event_id,id),
				KEY user_id_id (user_id,id)
			) {$charset};",
			"CREATE TABLE {$prefix}acl_ar_room_reads (
				id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
				room_id BIGINT UNSIGNED NOT NULL,
				user_id BIGINT UNSIGNED NOT NULL,
				last_read_event_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
				updated_at DATETIME NOT NULL,
				PRIMARY KEY  (id),
				UNIQUE KEY room_user (room_id,user_id),
				KEY user_updated (user_id,updated_at)
			) {$charset};",
			"CREATE TABLE {$prefix}acl_ar_conversation_turns (
				id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
				room_id BIGINT UNSIGNED NOT NULL,
				trigger_event_id BIGINT UNSIGNED NOT NULL,
				agent_id BIGINT UNSIGNED NOT NULL,
				brain_run_id BIGINT UNSIGNED NULL,
				job_id BIGINT UNSIGNED NULL,
				source_type VARCHAR(16) NOT NULL,
				status VARCHAR(20) NOT NULL,
				purpose VARCHAR(16) NOT NULL DEFAULT 'reply',
				content LONGTEXT NULL,
				due_at DATETIME NOT NULL,
				typing_at DATETIME NULL,
				published_event_id BIGINT UNSIGNED NULL,
				idempotency_key CHAR(64) NOT NULL,
				cancel_reason VARCHAR(32) NULL,
				created_at DATETIME NOT NULL,
				updated_at DATETIME NOT NULL,
				PRIMARY KEY  (id),
				UNIQUE KEY idempotency_key (idempotency_key),
				KEY due_worker (status,due_at,id),
				KEY room_pending (room_id,status,due_at,id),
				KEY trigger_event_id (trigger_event_id,id),
				KEY brain_run_id (brain_run_id,id),
				KEY job_id (job_id,id),
				KEY agent_id (agent_id,id)
			) {$charset};",
			"CREATE TABLE {$prefix}acl_ar_room_presence (
				id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
				room_id BIGINT UNSIGNED NOT NULL,
				actor_type VARCHAR(16) NOT NULL,
				actor_id BIGINT UNSIGNED NOT NULL,
				state VARCHAR(24) NOT NULL DEFAULT 'offline',
				last_seen_at DATETIME NULL,
				typing_expires_at DATETIME NULL,
				metadata_json LONGTEXT NULL,
				last_event_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
				expires_at DATETIME NULL,
				updated_at DATETIME NOT NULL,
				PRIMARY KEY  (id),
				UNIQUE KEY room_actor (room_id,actor_type,actor_id),
				KEY room_state_updated (room_id,state,updated_at),
				KEY typing_expires_at (typing_expires_at),
				KEY expires_at (expires_at)
			) {$charset};",
			"CREATE TABLE {$prefix}acl_ar_room_presence_sessions (
				id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
				room_id BIGINT UNSIGNED NOT NULL,
				user_id BIGINT UNSIGNED NOT NULL,
				session_hash CHAR(64) NOT NULL,
				visibility_state VARCHAR(16) NOT NULL DEFAULT 'visible',
				activity_state VARCHAR(16) NOT NULL DEFAULT 'active',
				last_seen_at DATETIME NOT NULL,
				last_active_at DATETIME NULL,
				expires_at DATETIME NOT NULL,
				created_at DATETIME NOT NULL,
				updated_at DATETIME NOT NULL,
				PRIMARY KEY  (id),
				UNIQUE KEY room_user_session (room_id,user_id,session_hash),
				KEY room_expires_id (room_id,expires_at,id),
				KEY room_user_expires (room_id,user_id,expires_at),
				KEY user_updated (user_id,updated_at)
			) {$charset};",
			"CREATE TABLE {$prefix}acl_ar_room_restrictions (
				id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
				room_id BIGINT UNSIGNED NOT NULL,
				user_id BIGINT UNSIGNED NOT NULL,
				restriction_type VARCHAR(16) NOT NULL,
				reason TEXT NULL,
				created_by BIGINT UNSIGNED NOT NULL,
				created_at DATETIME NOT NULL,
				expires_at DATETIME NULL,
				revoked_by BIGINT UNSIGNED NULL,
				revoked_at DATETIME NULL,
				PRIMARY KEY  (id),
				KEY room_user_active (room_id,user_id,restriction_type,revoked_at,expires_at),
				KEY expires_at (expires_at),
				KEY created_by (created_by)
			) {$charset};",
			"CREATE TABLE {$prefix}acl_ar_event_search (
				event_id BIGINT UNSIGNED NOT NULL,
				room_id BIGINT UNSIGNED NOT NULL,
				searchable_text LONGTEXT NOT NULL,
				actor_label VARCHAR(190) NULL,
				created_at DATETIME NOT NULL,
				updated_at DATETIME NOT NULL,
				PRIMARY KEY  (event_id),
				KEY room_event (room_id,event_id),
				KEY room_created (room_id,created_at,event_id),
				FULLTEXT KEY searchable_text (searchable_text)
			) {$charset};",
			"CREATE TABLE {$prefix}acl_ar_maintenance_runs (
				id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
				task VARCHAR(40) NOT NULL,
				status VARCHAR(20) NOT NULL,
				started_at DATETIME NOT NULL,
				finished_at DATETIME NULL,
				items_scanned INT UNSIGNED NOT NULL DEFAULT 0,
				items_changed INT UNSIGNED NOT NULL DEFAULT 0,
				details_json LONGTEXT NULL,
				PRIMARY KEY  (id),
				KEY task_started (task,started_at),
				KEY status_started (status,started_at)
			) {$charset};",
			"CREATE TABLE {$prefix}acl_ar_room_files (
				id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
				room_id BIGINT UNSIGNED NOT NULL,
				storage_asset_id BIGINT UNSIGNED NOT NULL,
				storage_owner_user_id BIGINT UNSIGNED NOT NULL,
				added_by_user_id BIGINT UNSIGNED NOT NULL,
				room_label VARCHAR(255) NOT NULL,
				original_filename VARCHAR(255) NOT NULL,
				mime_type VARCHAR(190) NOT NULL,
				file_extension VARCHAR(32) NOT NULL,
				file_size BIGINT UNSIGNED NOT NULL DEFAULT 0,
				content_hash CHAR(64) NOT NULL,
				context_enabled TINYINT(1) NOT NULL DEFAULT 1,
				priority INT NOT NULL DEFAULT 0,
				extraction_status VARCHAR(20) NOT NULL DEFAULT 'pending',
				indexing_status VARCHAR(20) NOT NULL DEFAULT 'pending',
				active_version_id BIGINT UNSIGNED NULL,
				active_key CHAR(64) NULL,
				created_at DATETIME NOT NULL,
				updated_at DATETIME NOT NULL,
				removed_at DATETIME NULL,
				PRIMARY KEY  (id),
				UNIQUE KEY active_key (active_key),
				KEY room_asset (room_id,storage_asset_id),
				KEY room_active (room_id,removed_at,id),
				KEY room_priority (room_id,priority,id),
				KEY extraction_status (extraction_status,id),
				KEY indexing_status (indexing_status,id),
				KEY content_hash (content_hash)
			) {$charset};",
			"CREATE TABLE {$prefix}acl_ar_room_file_versions (
				id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
				room_id BIGINT UNSIGNED NOT NULL,
				room_file_id BIGINT UNSIGNED NOT NULL,
				storage_asset_id BIGINT UNSIGNED NOT NULL,
				storage_owner_user_id BIGINT UNSIGNED NOT NULL,
				created_by_user_id BIGINT UNSIGNED NOT NULL,
				original_filename VARCHAR(255) NOT NULL,
				mime_type VARCHAR(190) NOT NULL,
				file_extension VARCHAR(32) NOT NULL,
				file_size BIGINT UNSIGNED NOT NULL DEFAULT 0,
				content_hash CHAR(64) NOT NULL,
				extracted_text LONGTEXT NULL,
				search_text LONGTEXT NULL,
				line_count INT UNSIGNED NOT NULL DEFAULT 0,
				extracted_chars INT UNSIGNED NOT NULL DEFAULT 0,
				extraction_status VARCHAR(20) NOT NULL DEFAULT 'pending',
				indexing_status VARCHAR(20) NOT NULL DEFAULT 'pending',
				error_code VARCHAR(64) NULL,
				created_at DATETIME NOT NULL,
				activated_at DATETIME NULL,
				retired_at DATETIME NULL,
				PRIMARY KEY  (id),
				UNIQUE KEY file_asset (room_file_id,storage_asset_id),
				KEY room_file (room_id,room_file_id,id),
				KEY room_asset (room_id,storage_asset_id),
				KEY extraction_status (extraction_status,id),
				KEY indexing_status (indexing_status,id),
				KEY content_hash (content_hash)
			) {$charset};",
		);

		foreach ( $statements as $statement ) {
			$wpdb->last_error = '';
			dbDelta( $statement );
			if ( '' !== $wpdb->last_error ) {
				self::report_schema_normalization_failure( new \WP_Error( 'acl_ar_schema_update_failed' ) );
				return;
			}
		}

		$normalization = self::normalize_natural_conversation_column_order();
		if ( is_wp_error( $normalization ) ) {
			self::report_schema_normalization_failure( $normalization );
			return;
		}
		$legacy_normalization = self::normalize_legacy_column_order();
		if ( is_wp_error( $legacy_normalization ) ) {
			self::report_schema_normalization_failure( $legacy_normalization );
			return;
		}
		$normalization += $legacy_normalization;
		do_action( 'acl_ar_installer_schema_normalized', $normalization );

		// Validated response text is retained only while crash-safe fan-out is recoverable.
		$wpdb->query( "UPDATE {$prefix}acl_ar_brain_runs SET validated_response_json = NULL WHERE status = 'completed' AND validated_response_json IS NOT NULL" );

		update_option( 'acl_ar_db_version', ACL_AR_DB_VERSION, true );
		self::maybe_seed_demo_agent();
		self::maybe_backfill_events();
		self::maybe_backfill_search();
	}

	/**
	 * Put only the Natural Conversation columns into the canonical fresh-install order.
	 *
	 * Column definitions are reconstructed from information_schema so an order-only
	 * repair cannot reset data, defaults, collation, comments, or other attributes.
	 *
	 * @return int|\WP_Error Number of columns moved, or a safe failure code.
	 */
	private static function normalize_natural_conversation_column_order() {
		global $wpdb;

		$targets = array(
			'agents' => array(
				'anchor'  => 'max_tokens',
				'columns' => array(
					'natural_participation_chance',
					'natural_question_tendency',
					'natural_delay_min_ms',
					'natural_delay_max_ms',
					'natural_cooldown_seconds',
					'natural_max_auto_responses_per_10m',
					'natural_conversation_role',
				),
			),
			'rooms'  => array(
				'anchor'  => 'max_agents_per_turn',
				'columns' => array(
					'conversation_mode',
					'natural_min_responders',
					'natural_max_responders',
					'natural_initial_delay_min_ms',
					'natural_initial_delay_max_ms',
					'natural_inter_turn_delay_min_ms',
					'natural_inter_turn_delay_max_ms',
					'natural_allow_silence',
					'natural_silence_chance',
					'natural_cancel_pending_on_new_message',
					'natural_max_pending_turns',
					'natural_steering_question_bias',
					'natural_active_trigger_event_id',
				),
			),
		);
		$moved   = 0;

		foreach ( $targets as $suffix => $target ) {
			$table = $wpdb->prefix . 'acl_ar_' . $suffix;
			if ( ! self::valid_schema_identifier( $table ) ) {
				return new \WP_Error( 'acl_ar_schema_identifier_invalid', '', array( 'table' => $suffix ) );
			}
			$anchor = $target['anchor'];

			foreach ( $target['columns'] as $column_name ) {
				$columns = self::schema_columns( $table );
				if ( empty( $columns ) ) {
					return new \WP_Error( 'acl_ar_schema_table_missing', '', array( 'table' => $suffix ) );
				}
				$names = array_column( $columns, 'COLUMN_NAME' );
				if ( ! in_array( $anchor, $names, true ) ) {
					return new \WP_Error(
						'acl_ar_schema_anchor_missing',
						'',
						array(
							'table'  => $suffix,
							'column' => $anchor,
						)
					);
				}
				if ( ! in_array( $column_name, $names, true ) ) {
					return new \WP_Error(
						'acl_ar_schema_column_missing',
						'',
						array(
							'table'  => $suffix,
							'column' => $column_name,
						)
					);
				}

				$anchor_index = array_search( $anchor, $names, true );
				$column_index = array_search( $column_name, $names, true );
				if ( $column_index !== $anchor_index + 1 ) {
					$metadata   = $columns[ $column_index ];
					$definition = self::schema_column_definition( $metadata );
					if ( is_wp_error( $definition ) ) {
						$definition->add_data(
							array(
								'table'  => $suffix,
								'column' => $column_name,
							)
						);
						return $definition;
					}

					$sql              = sprintf(
						'ALTER TABLE `%s` MODIFY COLUMN `%s` %s AFTER `%s`',
						$table,
						$column_name,
						$definition,
						$anchor
					);
					$wpdb->last_error = '';
					$result           = $wpdb->query( $sql );
					if ( false === $result || '' !== $wpdb->last_error ) {
						return new \WP_Error(
							'acl_ar_schema_column_move_failed',
							'',
							array(
								'table'  => $suffix,
								'column' => $column_name,
							)
						);
					}
					++$moved;

					$verified = array_column( self::schema_columns( $table ), 'COLUMN_NAME' );
					if ( array_search( $column_name, $verified, true ) !== array_search( $anchor, $verified, true ) + 1 ) {
						return new \WP_Error(
							'acl_ar_schema_column_move_unverified',
							'',
							array(
								'table'  => $suffix,
								'column' => $column_name,
							)
						);
					}
				}
				$anchor = $column_name;
			}
		}

		return $moved;
	}

	/**
	 * Put only the documented legacy additive chains into fresh-install order.
	 *
	 * The Natural Conversation normalizer intentionally remains separate and runs
	 * first. These four chains repair older Clear Chat and Shared Brain installs.
	 *
	 * @return int|\WP_Error Number of columns moved, or a safe failure code.
	 */
	private static function normalize_legacy_column_order() {
		$targets = array(
			'rooms'    => array(
				'anchor'  => 'natural_active_trigger_event_id',
				'columns' => array(
					'allow_chat_clear',
					'cleared_through_event_id',
					'chat_cleared_at',
					'chat_cleared_by_user_id',
					'project_instructions',
					'room_files_enabled',
					'room_files_agent_access',
					'file_context_mode',
					'file_context_max_files',
					'file_context_max_chars',
				),
			),
			'agents'   => array(
				'anchor'  => 'shared_config_id',
				'columns' => array(
					'execution_mode',
					'brain_id',
				),
			),
			'messages' => array(
				'anchor'  => 'response_job_id',
				'columns' => array(
					'brain_run_id',
					'brain_agent_id',
				),
			),
			'usage'    => array(
				'anchor'  => 'agent_id',
				'columns' => array(
					'brain_id',
					'brain_run_id',
				),
			),
		);
		$moved   = 0;

		foreach ( $targets as $suffix => $target ) {
			$result = self::normalize_column_chain( $suffix, $target['anchor'], $target['columns'] );
			if ( is_wp_error( $result ) ) {
				return $result;
			}
			$moved += $result;
		}

		return $moved;
	}

	/**
	 * Normalize one documented column chain without changing installed definitions.
	 *
	 * @param string        $suffix  Plugin table suffix.
	 * @param string        $anchor  Required predecessor of the first column.
	 * @param array<string> $columns Ordered target columns.
	 * @return int|\WP_Error Number of columns moved, or a safe failure code.
	 */
	private static function normalize_column_chain( string $suffix, string $anchor, array $columns ) {
		global $wpdb;

		$table = $wpdb->prefix . 'acl_ar_' . $suffix;
		if ( ! self::valid_schema_identifier( $table ) ) {
			return new \WP_Error( 'acl_ar_schema_identifier_invalid', '', array( 'table' => $suffix ) );
		}
		$moved = 0;

		foreach ( $columns as $column_name ) {
			$columns_metadata = self::schema_columns( $table );
			if ( empty( $columns_metadata ) ) {
				return new \WP_Error( 'acl_ar_schema_table_missing', '', array( 'table' => $suffix ) );
			}
			$names = array_column( $columns_metadata, 'COLUMN_NAME' );
			if ( ! in_array( $anchor, $names, true ) ) {
				return new \WP_Error(
					'acl_ar_schema_anchor_missing',
					'',
					array(
						'table'  => $suffix,
						'column' => $anchor,
					)
				);
			}
			if ( ! in_array( $column_name, $names, true ) ) {
				return new \WP_Error(
					'acl_ar_schema_column_missing',
					'',
					array(
						'table'  => $suffix,
						'column' => $column_name,
					)
				);
			}

			$anchor_index = array_search( $anchor, $names, true );
			$column_index = array_search( $column_name, $names, true );
			if ( $column_index !== $anchor_index + 1 ) {
				$definition = self::schema_column_definition( $columns_metadata[ $column_index ] );
				if ( is_wp_error( $definition ) ) {
					$definition->add_data(
						array(
							'table'  => $suffix,
							'column' => $column_name,
						)
					);
					return $definition;
				}

				$wpdb->last_error = '';
				$result           = $wpdb->query(
					sprintf(
						'ALTER TABLE `%s` MODIFY COLUMN `%s` %s AFTER `%s`',
						$table,
						$column_name,
						$definition,
						$anchor
					)
				);
				if ( false === $result || '' !== $wpdb->last_error ) {
					return new \WP_Error(
						'acl_ar_schema_column_move_failed',
						'',
						array(
							'table'  => $suffix,
							'column' => $column_name,
						)
					);
				}
				++$moved;

				$verified = array_column( self::schema_columns( $table ), 'COLUMN_NAME' );
				if ( array_search( $column_name, $verified, true ) !== array_search( $anchor, $verified, true ) + 1 ) {
					return new \WP_Error(
						'acl_ar_schema_column_move_unverified',
						'',
						array(
							'table'  => $suffix,
							'column' => $column_name,
						)
					);
				}
			}
			$anchor = $column_name;
		}

		return $moved;
	}

	/**
	 * Read ordered column metadata for one plugin table.
	 *
	 * @param string $table Table name including the active WordPress prefix.
	 * @return array<int,array<string,mixed>>
	 */
	private static function schema_columns( string $table ): array {
		global $wpdb;

		return (array) $wpdb->get_results(
			$wpdb->prepare(
				'SELECT COLUMN_NAME,ORDINAL_POSITION,COLUMN_TYPE,IS_NULLABLE,COLUMN_DEFAULT,EXTRA,CHARACTER_SET_NAME,COLLATION_NAME,COLUMN_COMMENT FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=%s AND TABLE_NAME=%s ORDER BY ORDINAL_POSITION',
				DB_NAME,
				$table
			),
			ARRAY_A
		);
	}

	/**
	 * Reconstruct a safe exact MODIFY definition from information_schema.
	 *
	 * @param array<string,mixed> $column Column metadata.
	 * @return string|\WP_Error
	 */
	private static function schema_column_definition( array $column ) {
		global $wpdb;

		$type = (string) ( $column['COLUMN_TYPE'] ?? '' );
		if ( ! preg_match( '/^[a-z0-9_(), ]+$/i', $type ) ) {
			return new \WP_Error( 'acl_ar_schema_column_type_invalid' );
		}
		$definition = $type;

		$character_set = (string) ( $column['CHARACTER_SET_NAME'] ?? '' );
		$collation     = (string) ( $column['COLLATION_NAME'] ?? '' );
		if ( '' !== $character_set || '' !== $collation ) {
			if ( ! self::valid_schema_identifier( $character_set ) || ! self::valid_schema_identifier( $collation ) ) {
				return new \WP_Error( 'acl_ar_schema_column_collation_invalid' );
			}
			$definition .= ' CHARACTER SET ' . $character_set . ' COLLATE ' . $collation;
		}

		$nullable    = 'YES' === (string) ( $column['IS_NULLABLE'] ?? '' );
		$definition .= $nullable ? ' NULL' : ' NOT NULL';
		$default     = $column['COLUMN_DEFAULT'] ?? null;
		if ( null === $default ) {
			if ( $nullable ) {
				$definition .= ' DEFAULT NULL';
			}
		} else {
			$definition .= ' DEFAULT ' . $wpdb->prepare( '%s', (string) $default );
		}

		$extra = trim( str_ireplace( 'DEFAULT_GENERATED', '', (string) ( $column['EXTRA'] ?? '' ) ) );
		if ( '' !== $extra ) {
			if ( ! preg_match( '/^[a-z0-9_ ()]+$/i', $extra ) ) {
				return new \WP_Error( 'acl_ar_schema_column_extra_invalid' );
			}
			$definition .= ' ' . $extra;
		}

		$comment = (string) ( $column['COLUMN_COMMENT'] ?? '' );
		if ( '' !== $comment ) {
			$definition .= ' COMMENT ' . $wpdb->prepare( '%s', $comment );
		}

		return $definition;
	}

	private static function valid_schema_identifier( string $identifier ): bool {
		return 1 === preg_match( '/^[a-z0-9_$]+$/i', $identifier );
	}

	private static function report_schema_normalization_failure( \WP_Error $error ): void {
		$code = sanitize_key( $error->get_error_code() );
		$data = array_intersect_key(
			(array) $error->get_error_data(),
			array(
				'table'  => true,
				'column' => true,
			)
		);
		// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- This redacted install failure records only an internal code and no credentials or user content.
		error_log( '[ACL Agent Rooms] Installer schema normalization failed (' . $code . '); database version was not advanced.' );
		do_action( 'acl_ar_installer_schema_normalization_failed', $code, $data );
	}

	private static function maybe_backfill_search(): void {
		if ( class_exists( '\\ACL\\AgentRooms\\Services\\EventSearchBackfillService' ) ) {
			$result = ( new \ACL\AgentRooms\Services\EventSearchBackfillService() )->run_batch( 100 );
			if ( is_array( $result ) && ! empty( $result['has_more'] ) ) {
				( new \ACL\AgentRooms\Services\QueueService() )->enqueue_search_backfill();
			}
		}
	}

	private static function maybe_backfill_events(): void {
		if ( ! class_exists( '\\ACL\\AgentRooms\\Services\\EventBackfillService' ) ) {
			return;
		}
		$result = ( new \ACL\AgentRooms\Services\EventBackfillService() )->run_batch( 100 );
		if ( is_array( $result ) && ! empty( $result['has_more'] ) ) {
			( new \ACL\AgentRooms\Services\QueueService() )->enqueue_event_backfill();
		}
	}

	private static function maybe_seed_demo_agent(): void {
		if ( ! apply_filters( 'acl_ar_seed_demo_agent', false ) ) {
			return;
		}

		global $wpdb;

		$table = $wpdb->prefix . 'acl_ar_agents';
		$found = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE slug = %s", 'demo-agent' ) );
		if ( $found > 0 ) {
			return;
		}

		$now = current_time( 'mysql', true );
		$wpdb->insert(
			$table,
			array(
				'owner_user_id'        => null,
				'name'                 => 'Demo Agent',
				'slug'                 => 'demo-agent',
				'description'          => '',
				'avatar_attachment_id' => 0,
				'avatar_url'           => '',
				'config_mode'          => 'independent',
				'shared_config_id'     => null,
				'provider_route'       => 'default',
				'model'                => 'default',
				'system_prompt'        => 'You are a helpful agent.',
				'enabled'              => 0,
				'created_at'           => $now,
				'updated_at'           => $now,
			),
			array( '%d', '%s', '%s', '%s', '%d', '%s', '%s', '%d', '%s', '%s', '%s', '%d', '%s', '%s' )
		);
	}
}
