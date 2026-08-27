<?php
/** Focused no-cost regression coverage for the 1.3.1 installer-only schema repair. */

class ACL_AR_Release131SchemaNormalizationTest extends ACL_AR_TestCase {
	private string $original_prefix = '';
	private string $original_db_version = '';
	private array $prefixes = array();
	private array $moves = array();
	private array $failures = array();
	private $move_hook;
	private $failure_hook;

	private const AGENT_COLUMNS = array(
		'natural_participation_chance',
		'natural_question_tendency',
		'natural_delay_min_ms',
		'natural_delay_max_ms',
		'natural_cooldown_seconds',
		'natural_max_auto_responses_per_10m',
		'natural_conversation_role',
	);

	private const ROOM_COLUMNS = array(
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
	);

	public function run(): void {
		global $wpdb;

		$this->original_prefix = $wpdb->prefix;
		$this->original_db_version = (string) get_option( 'acl_ar_db_version', '' );
		$this->move_hook = function ( int $moved ): void { $this->moves[] = $moved; };
		$this->failure_hook = function ( string $code, array $data ): void { $this->failures[] = array( $code, $data ); };
		add_action( 'acl_ar_installer_schema_normalized', $this->move_hook );
		add_action( 'acl_ar_installer_schema_normalization_failed', $this->failure_hook, 10, 2 );

		$this->assert_same( '1.5.6', ACL_AR_VERSION, '1.4.1 plugin version mismatch.' );
		$this->assert_same( '1.4.1', ACL_AR_DB_VERSION, '1.4.1 database version mismatch.' );

		try {
			$this->canonical_and_upgrade_matrix();
			$this->missing_columns_are_added();
			$this->failure_does_not_advance_version();
		} finally {
			remove_action( 'acl_ar_installer_schema_normalized', $this->move_hook );
			remove_action( 'acl_ar_installer_schema_normalization_failed', $this->failure_hook, 10 );
			foreach ( array_reverse( $this->prefixes ) as $prefix ) {
				$this->drop_prefix( $prefix );
			}
			$wpdb->prefix = $this->original_prefix;
			update_option( 'acl_ar_db_version', $this->original_db_version, true );
		}
	}

	private function canonical_and_upgrade_matrix(): void {
		global $wpdb;

		$prefix = $this->new_prefix( 'matrix' );
		$wpdb->prefix = $prefix;
		\ACL\AgentRooms\Installer::install();
		$this->assert_same( '1.4.1', (string) get_option( 'acl_ar_db_version' ), 'Fresh install did not store 1.4.1.' );
		$this->assert_canonical( $prefix, 'fresh 1.3.1' );
		$this->seed_preservation_fixture( $prefix );

		$canonical_schema = $this->schema_fingerprint( $prefix );
		$canonical_definitions = $this->natural_definitions( $prefix );
		$canonical_indexes = $this->index_fingerprint( $prefix );
		$canonical_engines = $this->engine_fingerprint( $prefix );
		$data = $this->data_fingerprint( $prefix );

		\ACL\AgentRooms\Installer::install();
		$this->assert_same( $canonical_schema, $this->schema_fingerprint( $prefix ), 'Canonical installer rerun changed schema.' );
		$this->assert_same( 0, (int) end( $this->moves ), 'Canonical installer rerun moved a column.' );

		$versions = array( '1.3.0', '1.2.0', '1.1.0', '1.0.3', '0.9.0' );
		foreach ( $versions as $index => $version ) {
			$partial = 2 === $index;
			$this->scramble_natural_order( $prefix, $partial );
			$unrelated_before = $this->unrelated_order( $prefix );
			$index_before = $this->index_fingerprint( $prefix );
			$engine_before = $this->engine_fingerprint( $prefix );
			update_option( 'acl_ar_db_version', $version, true );

			\ACL\AgentRooms\Installer::install();

			$this->assert_same( '1.4.1', (string) get_option( 'acl_ar_db_version' ), $version . ' upgrade did not store 1.4.1. Last DB error: ' . (string) $wpdb->last_error . ' Safe failures: ' . wp_json_encode( $this->failures ) );
			$this->assert_canonical( $prefix, $version . ' upgrade' );
			$this->assert_true( (int) end( $this->moves ) > 0, $version . ' upgrade did not normalize an out-of-order column.' );
			$this->assert_same( $canonical_schema, $this->schema_fingerprint( $prefix ), $version . ' upgrade schema differs from fresh.' );
			$this->assert_same( $canonical_definitions, $this->natural_definitions( $prefix ), $version . ' changed Natural definitions.' );
			$this->assert_same( $canonical_indexes, $this->index_fingerprint( $prefix ), $version . ' index fingerprint differs from fresh.' );
			$this->assert_same( $index_before, $this->index_fingerprint( $prefix ), $version . ' normalization changed indexes.' );
			$this->assert_same( $canonical_engines, $this->engine_fingerprint( $prefix ), $version . ' engine fingerprint differs from fresh.' );
			$this->assert_same( $engine_before, $this->engine_fingerprint( $prefix ), $version . ' normalization changed table engines.' );
			$this->assert_same( $unrelated_before, $this->unrelated_order( $prefix ), $version . ' reordered unrelated columns.' );
			$this->assert_same( $data, $this->data_fingerprint( $prefix ), $version . ' rewrote or lost fixture data.' );
			$this->assert_preserved_fixture( $prefix, $version );

			$once = $this->schema_fingerprint( $prefix );
			\ACL\AgentRooms\Installer::install();
			$this->assert_same( $once, $this->schema_fingerprint( $prefix ), $version . ' installer-twice changed schema.' );
			$this->assert_same( 0, (int) end( $this->moves ), $version . ' second installer run moved a column.' );
		}
	}

	private function missing_columns_are_added(): void {
		global $wpdb;

		$prefix = $this->new_prefix( 'missing' );
		$wpdb->prefix = $prefix;
		\ACL\AgentRooms\Installer::install();
		foreach ( self::AGENT_COLUMNS as $column ) {
			$wpdb->query( "ALTER TABLE `{$prefix}acl_ar_agents` DROP COLUMN `{$column}`" );
		}
		foreach ( self::ROOM_COLUMNS as $column ) {
			$wpdb->query( "ALTER TABLE `{$prefix}acl_ar_rooms` DROP COLUMN `{$column}`" );
		}
		update_option( 'acl_ar_db_version', '0.9.0', true );
		\ACL\AgentRooms\Installer::install();
		$this->assert_same( '1.4.1', (string) get_option( 'acl_ar_db_version' ), 'Missing-column repair did not store 1.4.1.' );
		$this->assert_canonical( $prefix, 'missing-column repair' );
		$this->assert_same( 20, count( $this->natural_definitions( $prefix ) ), 'Missing Natural columns were not recreated.' );
		$this->assert_true( (int) end( $this->moves ) > 0, 'Missing-column repair did not normalize appended columns.' );
	}

	private function failure_does_not_advance_version(): void {
		global $wpdb;

		$prefix = $this->new_prefix( 'failure' );
		$wpdb->prefix = $prefix;
		$view = $prefix . 'acl_ar_agents';
		$wpdb->query( "CREATE VIEW `{$view}` AS SELECT 1 AS id" );
		update_option( 'acl_ar_db_version', '1.3.0', true );
		$before_failures = count( $this->failures );
		$suppressed = $wpdb->suppress_errors( true );
		\ACL\AgentRooms\Installer::install();
		$wpdb->suppress_errors( $suppressed );

		$this->assert_same( '1.3.0', (string) get_option( 'acl_ar_db_version' ), 'Failed installer advanced the stored DB version.' );
		$this->assert_true( count( $this->failures ) > $before_failures, 'Failed installer did not emit a safe diagnostic action.' );
		$failure = end( $this->failures );
		$this->assert_same( 'acl_ar_schema_update_failed', $failure[0], 'Failed installer exposed an unexpected diagnostic code.' );
	}

	private function assert_canonical( string $prefix, string $label ): void {
		$this->assert_chain( $prefix . 'acl_ar_agents', 'max_tokens', self::AGENT_COLUMNS, $label . ' agent order' );
		$this->assert_chain( $prefix . 'acl_ar_rooms', 'max_agents_per_turn', self::ROOM_COLUMNS, $label . ' room order' );
	}

	private function assert_chain( string $table, string $anchor, array $chain, string $label ): void {
		$names = $this->column_names( $table );
		$offset = array_search( $anchor, $names, true );
		$this->assert_true( false !== $offset, $label . ' anchor is missing.' );
		$this->assert_same( $chain, array_slice( $names, $offset + 1, count( $chain ) ), $label . ' is not canonical.' );
	}

	private function scramble_natural_order( string $prefix, bool $partial ): void {
		if ( $partial ) {
			$this->move_column( $prefix . 'acl_ar_agents', 'natural_delay_max_ms', 'int unsigned NULL DEFAULT NULL', 'updated_at' );
			$this->move_column( $prefix . 'acl_ar_rooms', 'natural_silence_chance', "tinyint unsigned NOT NULL DEFAULT '10'", 'updated_at' );
			return;
		}

		$agent_definitions = array(
			'natural_participation_chance'          => "tinyint unsigned NOT NULL DEFAULT '60'",
			'natural_question_tendency'              => "tinyint unsigned NOT NULL DEFAULT '20'",
			'natural_delay_min_ms'                   => 'int unsigned NULL DEFAULT NULL',
			'natural_delay_max_ms'                   => 'int unsigned NULL DEFAULT NULL',
			'natural_cooldown_seconds'               => "int unsigned NOT NULL DEFAULT '20'",
			'natural_max_auto_responses_per_10m'     => "tinyint unsigned NOT NULL DEFAULT '4'",
			'natural_conversation_role'              => "varchar(32) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_520_ci NOT NULL DEFAULT 'balanced'",
		);
		$room_definitions = array(
			'conversation_mode'                       => "varchar(16) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_520_ci NOT NULL DEFAULT 'immediate'",
			'natural_min_responders'                  => "tinyint unsigned NOT NULL DEFAULT '1'",
			'natural_max_responders'                  => "tinyint unsigned NOT NULL DEFAULT '2'",
			'natural_initial_delay_min_ms'            => "int unsigned NOT NULL DEFAULT '1500'",
			'natural_initial_delay_max_ms'            => "int unsigned NOT NULL DEFAULT '4500'",
			'natural_inter_turn_delay_min_ms'         => "int unsigned NOT NULL DEFAULT '2500'",
			'natural_inter_turn_delay_max_ms'         => "int unsigned NOT NULL DEFAULT '8000'",
			'natural_allow_silence'                   => "tinyint(1) NOT NULL DEFAULT '0'",
			'natural_silence_chance'                  => "tinyint unsigned NOT NULL DEFAULT '10'",
			'natural_cancel_pending_on_new_message'   => "tinyint(1) NOT NULL DEFAULT '1'",
			'natural_max_pending_turns'               => "tinyint unsigned NOT NULL DEFAULT '4'",
			'natural_steering_question_bias'          => "tinyint unsigned NOT NULL DEFAULT '35'",
			'natural_active_trigger_event_id'         => 'bigint unsigned NULL DEFAULT NULL',
		);

		$anchor = 'updated_at';
		foreach ( $agent_definitions as $column => $definition ) {
			$this->move_column( $prefix . 'acl_ar_agents', $column, $definition, $anchor );
			$anchor = $column;
		}
		$anchor = 'updated_at';
		foreach ( $room_definitions as $column => $definition ) {
			$this->move_column( $prefix . 'acl_ar_rooms', $column, $definition, $anchor );
			$anchor = $column;
		}
	}

	private function move_column( string $table, string $column, string $definition, string $anchor ): void {
		global $wpdb;
		$result = $wpdb->query( "ALTER TABLE `{$table}` MODIFY COLUMN `{$column}` {$definition} AFTER `{$anchor}`" );
		if ( false === $result ) {
			throw new RuntimeException( 'Could not prepare schema-order fixture for ' . $column . '.' );
		}
	}

	private function seed_preservation_fixture( string $prefix ): void {
		global $wpdb;
		$now = '2026-07-15 12:00:00';
		$wpdb->insert( $prefix . 'acl_ar_brains', array( 'id' => 100, 'owner_user_id' => 1, 'name' => 'Schema Brain', 'slug' => 'schema-brain', 'description' => 'preserve', 'provider' => 'controlled-fake', 'model' => 'schema-model', 'orchestration_prompt' => 'preserve brain data', 'temperature' => 0.5, 'max_tokens_per_agent' => 321, 'max_total_tokens' => 654, 'settings_json' => '{}', 'enabled' => 1, 'created_at' => $now, 'updated_at' => $now ) );
		$wpdb->insert( $prefix . 'acl_ar_agents', array( 'id' => 100, 'owner_user_id' => 1, 'name' => 'Schema Agent', 'slug' => 'schema-agent', 'description' => 'preserve agent', 'avatar_attachment_id' => 0, 'avatar_url' => '', 'config_mode' => 'independent', 'shared_config_id' => null, 'execution_mode' => 'brain', 'brain_id' => 100, 'provider_route' => 'controlled-fake', 'model' => 'schema-model', 'system_prompt' => 'preserve prompt', 'temperature' => 0.33, 'max_tokens' => 777, 'natural_participation_chance' => 73, 'natural_question_tendency' => 41, 'natural_delay_min_ms' => 2222, 'natural_delay_max_ms' => 3333, 'natural_cooldown_seconds' => 44, 'natural_max_auto_responses_per_10m' => 5, 'natural_conversation_role' => 'facilitator', 'visibility' => 'private', 'enabled' => 1, 'created_at' => $now, 'updated_at' => $now ) );
		$wpdb->insert( $prefix . 'acl_ar_rooms', array( 'id' => 100, 'owner_user_id' => 1, 'title' => 'Schema Room', 'slug' => 'schema-room', 'description' => 'preserve room', 'top_context' => 'preserve context', 'type' => 'private', 'visibility' => 'private', 'status' => 'active', 'agent_reply_mode' => 'auto', 'max_context_messages' => 31, 'max_agents_per_turn' => 3, 'conversation_mode' => 'natural', 'natural_min_responders' => 2, 'natural_max_responders' => 3, 'natural_initial_delay_min_ms' => 2222, 'natural_initial_delay_max_ms' => 4444, 'natural_inter_turn_delay_min_ms' => 5555, 'natural_inter_turn_delay_max_ms' => 9999, 'natural_allow_silence' => 1, 'natural_silence_chance' => 17, 'natural_cancel_pending_on_new_message' => 0, 'natural_max_pending_turns' => 7, 'natural_steering_question_bias' => 61, 'natural_active_trigger_event_id' => 77, 'allow_chat_clear' => 1, 'cleared_through_event_id' => 77, 'chat_cleared_at' => $now, 'chat_cleared_by_user_id' => 1, 'created_at' => $now, 'updated_at' => $now ) );
		$wpdb->insert( $prefix . 'acl_ar_events', array( 'id' => 77, 'room_id' => 100, 'event_type' => 'message', 'actor_type' => 'user', 'actor_id' => 1, 'audience_type' => 'room', 'idempotency_key' => hash( 'sha256', 'schema-event' ), 'content' => 'preserve event', 'content_format' => 'plain', 'created_at' => $now ) );
		$wpdb->insert( $prefix . 'acl_ar_brain_runs', array( 'id' => 80, 'room_id' => 100, 'brain_id' => 100, 'trigger_event_id' => 77, 'request_key' => hash( 'sha256', 'schema-run' ), 'status' => 'response_saved', 'target_agent_ids_json' => '[100]', 'validated_response_json' => '[{"agent_id":100}]', 'response_event_ids_json' => '[]', 'provider' => 'controlled-fake', 'model' => 'schema-model', 'attempts' => 1, 'prompt_tokens' => 10, 'completion_tokens' => 5, 'total_tokens' => 15, 'estimated_cost' => 0, 'created_at' => $now, 'updated_at' => $now ) );
		$wpdb->insert( $prefix . 'acl_ar_conversation_turns', array( 'id' => 90, 'room_id' => 100, 'trigger_event_id' => 77, 'agent_id' => 100, 'brain_run_id' => 80, 'job_id' => null, 'source_type' => 'brain', 'status' => 'pending', 'purpose' => 'steer', 'content' => 'preserve pending content', 'due_at' => '2026-07-15 12:10:00', 'typing_at' => null, 'published_event_id' => null, 'idempotency_key' => hash( 'sha256', 'schema-turn' ), 'cancel_reason' => null, 'created_at' => $now, 'updated_at' => $now ) );
	}

	private function assert_preserved_fixture( string $prefix, string $label ): void {
		global $wpdb;
		$room = $wpdb->get_row( "SELECT * FROM `{$prefix}acl_ar_rooms` WHERE id=100", ARRAY_A );
		$agent = $wpdb->get_row( "SELECT * FROM `{$prefix}acl_ar_agents` WHERE id=100", ARRAY_A );
		$this->assert_same( 'natural', (string) $room['conversation_mode'], $label . ' lost the Natural room value.' );
		$this->assert_same( '17', (string) $room['natural_silence_chance'], $label . ' reset a Natural room setting.' );
		$this->assert_same( '77', (string) $room['cleared_through_event_id'], $label . ' lost Clear Chat data.' );
		$this->assert_same( '73', (string) $agent['natural_participation_chance'], $label . ' reset a Natural agent setting.' );
		$this->assert_same( 'facilitator', (string) $agent['natural_conversation_role'], $label . ' lost the Natural role.' );
		$this->assert_same( '100', (string) $agent['brain_id'], $label . ' lost the Shared Brain assignment.' );
		$this->assert_same( 1, (int) $wpdb->get_var( "SELECT COUNT(*) FROM `{$prefix}acl_ar_conversation_turns` WHERE id=90 AND content='preserve pending content'" ), $label . ' lost conversation-turn data.' );
	}

	private function schema_fingerprint( string $prefix ): string {
		global $wpdb;
		$like = $wpdb->esc_like( $prefix . 'acl_ar_' ) . '%';
		$schema = array(
			'tables' => $wpdb->get_results( $wpdb->prepare( 'SELECT TABLE_NAME,ENGINE,TABLE_COLLATION FROM information_schema.TABLES WHERE TABLE_SCHEMA=%s AND TABLE_NAME LIKE %s ORDER BY TABLE_NAME', DB_NAME, $like ), ARRAY_A ),
			'columns' => $wpdb->get_results( $wpdb->prepare( 'SELECT TABLE_NAME,ORDINAL_POSITION,COLUMN_NAME,COLUMN_TYPE,IS_NULLABLE,COLUMN_DEFAULT,EXTRA,COLLATION_NAME,COLUMN_COMMENT FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=%s AND TABLE_NAME LIKE %s ORDER BY TABLE_NAME,ORDINAL_POSITION', DB_NAME, $like ), ARRAY_A ),
			'indexes' => $wpdb->get_results( $wpdb->prepare( 'SELECT TABLE_NAME,INDEX_NAME,NON_UNIQUE,SEQ_IN_INDEX,COLUMN_NAME,SUB_PART,INDEX_TYPE FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=%s AND TABLE_NAME LIKE %s ORDER BY TABLE_NAME,INDEX_NAME,SEQ_IN_INDEX', DB_NAME, $like ), ARRAY_A ),
		);
		return hash( 'sha256', wp_json_encode( $schema ) );
	}

	private function natural_definitions( string $prefix ): array {
		global $wpdb;
		$wanted = array_merge( self::AGENT_COLUMNS, self::ROOM_COLUMNS );
		$placeholders = implode( ',', array_fill( 0, count( $wanted ), '%s' ) );
		$sql = $wpdb->prepare( "SELECT TABLE_NAME,COLUMN_NAME,COLUMN_TYPE,IS_NULLABLE,COLUMN_DEFAULT,EXTRA,CHARACTER_SET_NAME,COLLATION_NAME,COLUMN_COMMENT FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=%s AND TABLE_NAME IN (%s,%s) AND COLUMN_NAME IN ({$placeholders}) ORDER BY TABLE_NAME,COLUMN_NAME", array_merge( array( DB_NAME, $prefix . 'acl_ar_agents', $prefix . 'acl_ar_rooms' ), $wanted ) );
		return (array) $wpdb->get_results( $sql, ARRAY_A );
	}

	private function index_fingerprint( string $prefix ): string {
		global $wpdb;
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT TABLE_NAME,INDEX_NAME,NON_UNIQUE,SEQ_IN_INDEX,COLUMN_NAME,SUB_PART,INDEX_TYPE FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=%s AND TABLE_NAME LIKE %s ORDER BY TABLE_NAME,INDEX_NAME,SEQ_IN_INDEX', DB_NAME, $wpdb->esc_like( $prefix . 'acl_ar_' ) . '%' ), ARRAY_A );
		return hash( 'sha256', wp_json_encode( $rows ) );
	}

	private function engine_fingerprint( string $prefix ): string {
		global $wpdb;
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT TABLE_NAME,ENGINE,TABLE_COLLATION FROM information_schema.TABLES WHERE TABLE_SCHEMA=%s AND TABLE_NAME LIKE %s ORDER BY TABLE_NAME', DB_NAME, $wpdb->esc_like( $prefix . 'acl_ar_' ) . '%' ), ARRAY_A );
		return hash( 'sha256', wp_json_encode( $rows ) );
	}

	private function data_fingerprint( string $prefix ): string {
		global $wpdb;
		$tables = array( 'brains', 'agents', 'rooms', 'events', 'brain_runs', 'conversation_turns' );
		$data = array();
		foreach ( $tables as $suffix ) {
			$table = $prefix . 'acl_ar_' . $suffix;
			$columns = $this->column_names( $table );
			sort( $columns );
			$select = implode( ',', array_map( static fn( string $column ): string => '`' . $column . '`', $columns ) );
			$data[ $suffix ] = $wpdb->get_results( "SELECT {$select} FROM `{$table}` ORDER BY id", ARRAY_A );
		}
		return hash( 'sha256', wp_json_encode( $data ) );
	}

	private function unrelated_order( string $prefix ): array {
		return array(
			'agents' => array_values( array_diff( $this->column_names( $prefix . 'acl_ar_agents' ), self::AGENT_COLUMNS ) ),
			'rooms'  => array_values( array_diff( $this->column_names( $prefix . 'acl_ar_rooms' ), self::ROOM_COLUMNS ) ),
		);
	}

	private function column_names( string $table ): array {
		global $wpdb;
		return array_map( 'strval', (array) $wpdb->get_col( $wpdb->prepare( 'SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=%s AND TABLE_NAME=%s ORDER BY ORDINAL_POSITION', DB_NAME, $table ) ) );
	}

	private function new_prefix( string $label ): string {
		$prefix = 'acl131_' . sanitize_key( $label ) . '_' . strtolower( wp_generate_password( 6, false, false ) ) . '_';
		$this->prefixes[] = $prefix;
		return $prefix;
	}

	private function drop_prefix( string $prefix ): void {
		global $wpdb;
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT TABLE_NAME,TABLE_TYPE FROM information_schema.TABLES WHERE TABLE_SCHEMA=%s AND TABLE_NAME LIKE %s ORDER BY TABLE_TYPE DESC,TABLE_NAME', DB_NAME, $wpdb->esc_like( $prefix ) . '%' ), ARRAY_A );
		foreach ( (array) $rows as $row ) {
			$name = (string) $row['TABLE_NAME'];
			if ( 'VIEW' === (string) $row['TABLE_TYPE'] ) {
				$wpdb->query( "DROP VIEW IF EXISTS `{$name}`" );
			} else {
				$wpdb->query( "DROP TABLE IF EXISTS `{$name}`" );
			}
		}
	}
}
