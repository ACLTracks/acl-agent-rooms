<?php
/** Focused no-cost real-table coverage for the 1.4.1 legacy schema-order repair. */

class ACL_AR_Release132SchemaNormalizationTest extends ACL_AR_TestCase {
	private string $original_prefix = '';
	private string $original_db_version = '';
	private array $prefixes = array();
	private array $moves = array();
	private array $failures = array();
	private $move_hook;
	private $failure_hook;

	private const CHAINS = array(
		'rooms'    => array(
			'anchor'  => 'natural_active_trigger_event_id',
			'columns' => array( 'allow_chat_clear', 'cleared_through_event_id', 'chat_cleared_at', 'chat_cleared_by_user_id' ),
		),
		'agents'   => array(
			'anchor'  => 'shared_config_id',
			'columns' => array( 'execution_mode', 'brain_id' ),
		),
		'messages' => array(
			'anchor'  => 'response_job_id',
			'columns' => array( 'brain_run_id', 'brain_agent_id' ),
		),
		'usage'    => array(
			'anchor'  => 'agent_id',
			'columns' => array( 'brain_id', 'brain_run_id' ),
		),
	);

	private const NATURAL_COLUMNS = array(
		'natural_participation_chance',
		'natural_question_tendency',
		'natural_delay_min_ms',
		'natural_delay_max_ms',
		'natural_cooldown_seconds',
		'natural_max_auto_responses_per_10m',
		'natural_conversation_role',
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
			$this->supported_path_matrix();
			$this->partial_normalization_repairs_safely();
			$this->missing_columns_are_added_and_ordered();
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

	private function supported_path_matrix(): void {
		global $wpdb;

		$paths = array(
			'fresh-132'  => array( 'source' => '',      'legacy' => 'none' ),
			'upgrade-131' => array( 'source' => '1.3.1', 'legacy' => 'none' ),
			'upgrade-130' => array( 'source' => '1.3.0', 'legacy' => 'none' ),
			'upgrade-120' => array( 'source' => '1.2.0', 'legacy' => 'none' ),
			'upgrade-110' => array( 'source' => '1.1.0', 'legacy' => 'rooms' ),
			'upgrade-103' => array( 'source' => '1.0.3', 'legacy' => 'all' ),
			'upgrade-090' => array( 'source' => '0.9.0', 'legacy' => 'all' ),
		);
		$canonical = '';

		foreach ( $paths as $label => $path ) {
			$prefix = $this->new_prefix( $label );
			$wpdb->prefix = $prefix;
			\ACL\AgentRooms\Installer::install();
			$this->seed_preservation_fixture( $prefix );

			if ( 'rooms' === $path['legacy'] || 'all' === $path['legacy'] ) {
				$this->scramble_chain_to_tail( $prefix, 'rooms' );
			}
			if ( 'all' === $path['legacy'] ) {
				foreach ( array( 'agents', 'messages', 'usage' ) as $suffix ) {
					$this->scramble_chain_to_tail( $prefix, $suffix );
				}
			}
			if ( 'none' !== $path['legacy'] ) {
				$this->assert_legacy_order( $prefix, $path['legacy'], $label );
			}

			$definitions = $this->target_definitions( $prefix );
			$natural_definitions = $this->natural_definitions( $prefix );
			$indexes = $this->index_fingerprint( $prefix );
			$engines = $this->engine_fingerprint( $prefix );
			$unrelated = $this->unrelated_order( $prefix );
			$data = $this->data_fingerprint( $prefix );
			if ( '' !== $path['source'] ) {
				update_option( 'acl_ar_db_version', $path['source'], true );
			}

			\ACL\AgentRooms\Installer::install();
			$this->assert_same( '1.4.1', (string) get_option( 'acl_ar_db_version' ), $label . ' did not store 1.4.1.' );
			$this->assert_canonical( $prefix, $label );
			$this->assert_same( $definitions, $this->target_definitions( $prefix ), $label . ' changed affected column definitions.' );
			$this->assert_same( $natural_definitions, $this->natural_definitions( $prefix ), $label . ' changed Natural definitions.' );
			$this->assert_same( $indexes, $this->index_fingerprint( $prefix ), $label . ' changed index rows.' );
			$this->assert_same( $engines, $this->engine_fingerprint( $prefix ), $label . ' changed engines or collations.' );
			$this->assert_same( $unrelated, $this->unrelated_order( $prefix ), $label . ' moved an unrelated column.' );
			$this->assert_same( $data, $this->data_fingerprint( $prefix ), $label . ' changed stored data.' );
			$this->assert_preserved_fixture( $prefix, $label );

			$fingerprint = $this->schema_fingerprint( $prefix );
			if ( '' === $canonical ) {
				$canonical = $fingerprint;
			}
			$this->assert_same( $canonical, $fingerprint, $label . ' differs from the fresh schema fingerprint.' );
			$this->assert_same( 'none' === $path['legacy'] ? 0 : true, 'none' === $path['legacy'] ? (int) end( $this->moves ) : ( (int) end( $this->moves ) > 0 ), $label . ' reported an unexpected move count.' );

			$once = $this->schema_fingerprint( $prefix );
			\ACL\AgentRooms\Installer::install();
			$this->assert_same( $once, $this->schema_fingerprint( $prefix ), $label . ' installer-twice changed schema.' );
			$this->assert_same( 0, (int) end( $this->moves ), $label . ' second installer run moved a column.' );
		}
	}

	private function partial_normalization_repairs_safely(): void {
		global $wpdb;

		$prefix = $this->new_prefix( 'partial' );
		$wpdb->prefix = $prefix;
		\ACL\AgentRooms\Installer::install();
		$this->seed_preservation_fixture( $prefix );
		foreach ( array( 'rooms', 'agents', 'messages', 'usage' ) as $suffix ) {
			$column = self::CHAINS[ $suffix ]['columns'][0];
			$this->move_column( $prefix . 'acl_ar_' . $suffix, $column, $this->definition_for( $prefix . 'acl_ar_' . $suffix, $column ), $this->last_column( $prefix . 'acl_ar_' . $suffix ) );
		}
		$definitions = $this->target_definitions( $prefix );
		$indexes = $this->index_fingerprint( $prefix );
		$engines = $this->engine_fingerprint( $prefix );
		$data = $this->data_fingerprint( $prefix );
		$unrelated = $this->unrelated_order( $prefix );
		update_option( 'acl_ar_db_version', '1.1.0', true );
		\ACL\AgentRooms\Installer::install();
		$this->assert_canonical( $prefix, 'partial repair' );
		$this->assert_same( $definitions, $this->target_definitions( $prefix ), 'Partial repair changed definitions.' );
		$this->assert_same( $indexes, $this->index_fingerprint( $prefix ), 'Partial repair changed indexes.' );
		$this->assert_same( $engines, $this->engine_fingerprint( $prefix ), 'Partial repair changed engines or collations.' );
		$this->assert_same( $data, $this->data_fingerprint( $prefix ), 'Partial repair changed data.' );
		$this->assert_same( $unrelated, $this->unrelated_order( $prefix ), 'Partial repair moved unrelated columns.' );
	}

	private function missing_columns_are_added_and_ordered(): void {
		global $wpdb;

		$prefix = $this->new_prefix( 'missing' );
		$wpdb->prefix = $prefix;
		\ACL\AgentRooms\Installer::install();
		$wpdb->query( "ALTER TABLE `{$prefix}acl_ar_agents` DROP INDEX `brain_execution`" );
		$wpdb->query( "ALTER TABLE `{$prefix}acl_ar_messages` DROP INDEX `brain_response`" );
		$wpdb->query( "ALTER TABLE `{$prefix}acl_ar_usage` DROP INDEX `brain_id`, DROP INDEX `brain_run_id`" );
		foreach ( self::CHAINS as $suffix => $target ) {
			foreach ( array_reverse( $target['columns'] ) as $column ) {
				$wpdb->query( "ALTER TABLE `{$prefix}acl_ar_{$suffix}` DROP COLUMN `{$column}`" );
			}
		}
		update_option( 'acl_ar_db_version', '0.9.0', true );
		\ACL\AgentRooms\Installer::install();
		$this->assert_same( '1.4.1', (string) get_option( 'acl_ar_db_version' ), 'Missing-column repair did not store 1.4.1.' );
		$this->assert_canonical( $prefix, 'missing-column repair' );
		$this->assert_same( 10, count( $this->target_definitions( $prefix ) ), 'Missing affected columns were not recreated.' );
		$this->assert_true( (int) end( $this->moves ) > 0, 'Missing-column repair did not order appended columns.' );
	}

	private function failure_does_not_advance_version(): void {
		global $wpdb;

		$prefix = $this->new_prefix( 'failure' );
		$wpdb->prefix = $prefix;
		$view = $prefix . 'acl_ar_usage';
		$wpdb->query( "CREATE VIEW `{$view}` AS SELECT 1 AS id" );
		update_option( 'acl_ar_db_version', '1.3.1', true );
		$before_failures = count( $this->failures );
		\ACL\AgentRooms\Installer::install();
		$this->assert_same( '1.3.1', (string) get_option( 'acl_ar_db_version' ), 'Failed installer advanced the stored DB version.' );
		$this->assert_true( count( $this->failures ) > $before_failures, 'Failed installer did not emit a safe diagnostic.' );
		$failure = end( $this->failures );
		$this->assert_same( 'acl_ar_schema_update_failed', $failure[0], 'Failed installer emitted an unexpected code.' );
	}

	private function assert_canonical( string $prefix, string $label ): void {
		foreach ( self::CHAINS as $suffix => $target ) {
			$names = $this->column_names( $prefix . 'acl_ar_' . $suffix );
			$offset = array_search( $target['anchor'], $names, true );
			$this->assert_true( false !== $offset, $label . ' missing ' . $suffix . ' anchor.' );
			$this->assert_same( $target['columns'], array_slice( $names, $offset + 1, count( $target['columns'] ) ), $label . ' ' . $suffix . ' chain is not canonical.' );
		}
	}

	private function assert_legacy_order( string $prefix, string $scope, string $label ): void {
		$rooms = $this->column_names( $prefix . 'acl_ar_rooms' );
		$this->assert_same( self::CHAINS['rooms']['columns'], array_slice( $rooms, -4 ), $label . ' room legacy order simulation is wrong.' );
		if ( 'all' === $scope ) {
			foreach ( array( 'agents', 'messages', 'usage' ) as $suffix ) {
				$chain = self::CHAINS[ $suffix ]['columns'];
				$this->assert_same( $chain, array_slice( $this->column_names( $prefix . 'acl_ar_' . $suffix ), -count( $chain ) ), $label . ' ' . $suffix . ' legacy order simulation is wrong.' );
			}
		}
	}

	private function scramble_chain_to_tail( string $prefix, string $suffix ): void {
		$table = $prefix . 'acl_ar_' . $suffix;
		$anchor = $this->last_column( $table );
		foreach ( self::CHAINS[ $suffix ]['columns'] as $column ) {
			$this->move_column( $table, $column, $this->definition_for( $table, $column ), $anchor );
			$anchor = $column;
		}
	}

	private function move_column( string $table, string $column, string $definition, string $anchor ): void {
		global $wpdb;
		$result = $wpdb->query( "ALTER TABLE `{$table}` MODIFY COLUMN `{$column}` {$definition} AFTER `{$anchor}`" );
		if ( false === $result ) {
			throw new RuntimeException( 'Unable to simulate legacy order: ' . $wpdb->last_error );
		}
	}

	private function definition_for( string $table, string $column ): string {
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT COLUMN_TYPE,IS_NULLABLE,COLUMN_DEFAULT,EXTRA,CHARACTER_SET_NAME,COLLATION_NAME,COLUMN_COMMENT FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=%s AND TABLE_NAME=%s AND COLUMN_NAME=%s', DB_NAME, $table, $column ), ARRAY_A );
		if ( ! $row ) {
			throw new RuntimeException( 'Missing definition for ' . $table . '.' . $column );
		}
		$definition = (string) $row['COLUMN_TYPE'];
		if ( $row['CHARACTER_SET_NAME'] ) {
			$definition .= ' CHARACTER SET ' . $row['CHARACTER_SET_NAME'] . ' COLLATE ' . $row['COLLATION_NAME'];
		}
		$nullable = 'YES' === $row['IS_NULLABLE'];
		$definition .= $nullable ? ' NULL' : ' NOT NULL';
		if ( null === $row['COLUMN_DEFAULT'] ) {
			$definition .= $nullable ? ' DEFAULT NULL' : '';
		} else {
			$definition .= ' DEFAULT ' . $wpdb->prepare( '%s', (string) $row['COLUMN_DEFAULT'] );
		}
		$extra = trim( str_ireplace( 'DEFAULT_GENERATED', '', (string) $row['EXTRA'] ) );
		if ( '' !== $extra ) {
			$definition .= ' ' . $extra;
		}
		if ( '' !== (string) $row['COLUMN_COMMENT'] ) {
			$definition .= ' COMMENT ' . $wpdb->prepare( '%s', (string) $row['COLUMN_COMMENT'] );
		}
		return $definition;
	}

	private function seed_preservation_fixture( string $prefix ): void {
		global $wpdb;
		$now = '2026-07-16 12:00:00';
		$wpdb->insert( $prefix . 'acl_ar_brains', array( 'id' => 132, 'owner_user_id' => 1, 'name' => 'Schema Brain', 'slug' => 'schema-brain', 'provider' => 'controlled-fake', 'model' => 'schema-model', 'orchestration_prompt' => 'preserve brain', 'max_tokens_per_agent' => 321, 'max_total_tokens' => 654, 'enabled' => 1, 'created_at' => $now, 'updated_at' => $now ) );
		$wpdb->insert( $prefix . 'acl_ar_agents', array( 'id' => 132, 'owner_user_id' => 1, 'name' => 'Schema Agent', 'slug' => 'schema-agent', 'config_mode' => 'independent', 'execution_mode' => 'brain', 'brain_id' => 132, 'provider_route' => 'controlled-fake', 'model' => 'schema-model', 'system_prompt' => 'preserve agent', 'natural_participation_chance' => 73, 'natural_question_tendency' => 41, 'natural_delay_min_ms' => 2222, 'natural_delay_max_ms' => 3333, 'natural_cooldown_seconds' => 44, 'natural_max_auto_responses_per_10m' => 5, 'natural_conversation_role' => 'facilitator', 'enabled' => 1, 'created_at' => $now, 'updated_at' => $now ) );
		$wpdb->insert( $prefix . 'acl_ar_rooms', array( 'id' => 132, 'owner_user_id' => 1, 'title' => 'Schema Room', 'slug' => 'schema-room', 'type' => 'private', 'visibility' => 'private', 'status' => 'active', 'agent_reply_mode' => 'manual', 'max_context_messages' => 19, 'max_agents_per_turn' => 3, 'conversation_mode' => 'natural', 'natural_min_responders' => 2, 'natural_max_responders' => 3, 'natural_initial_delay_min_ms' => 2222, 'natural_initial_delay_max_ms' => 4444, 'natural_inter_turn_delay_min_ms' => 5555, 'natural_inter_turn_delay_max_ms' => 9999, 'natural_allow_silence' => 1, 'natural_silence_chance' => 17, 'natural_cancel_pending_on_new_message' => 0, 'natural_max_pending_turns' => 7, 'natural_steering_question_bias' => 61, 'natural_active_trigger_event_id' => 77, 'allow_chat_clear' => 1, 'cleared_through_event_id' => 77, 'chat_cleared_at' => $now, 'chat_cleared_by_user_id' => 1, 'created_at' => $now, 'updated_at' => $now ) );
		$wpdb->insert( $prefix . 'acl_ar_messages', array( 'id' => 132, 'room_id' => 132, 'sender_type' => 'agent', 'sender_agent_id' => 132, 'content' => 'preserve message', 'status' => 'sent', 'client_request_id' => 'schema-132', 'response_job_id' => null, 'brain_run_id' => 88, 'brain_agent_id' => 132, 'metadata_json' => '{"preserve":true}', 'provider_route' => 'controlled-fake', 'model' => 'schema-model', 'prompt_tokens' => 11, 'completion_tokens' => 7, 'total_tokens' => 18, 'created_at' => $now ) );
		$wpdb->insert( $prefix . 'acl_ar_usage', array( 'id' => 132, 'user_id' => 1, 'room_id' => 132, 'agent_id' => 132, 'brain_id' => 132, 'brain_run_id' => 88, 'provider_route' => 'controlled-fake', 'model' => 'schema-model', 'prompt_tokens' => 11, 'completion_tokens' => 7, 'total_tokens' => 18, 'estimated_cost' => 0, 'created_at' => $now ) );
	}

	private function assert_preserved_fixture( string $prefix, string $label ): void {
		global $wpdb;
		$this->assert_same( 1, (int) $wpdb->get_var( "SELECT COUNT(*) FROM `{$prefix}acl_ar_rooms` WHERE id=132 AND conversation_mode='natural' AND allow_chat_clear=1 AND cleared_through_event_id=77" ), $label . ' lost room, Clear Chat, or Natural values.' );
		$this->assert_same( 1, (int) $wpdb->get_var( "SELECT COUNT(*) FROM `{$prefix}acl_ar_agents` WHERE id=132 AND execution_mode='brain' AND brain_id=132 AND natural_participation_chance=73" ), $label . ' lost agent, Brain, or Natural values.' );
		$this->assert_same( 1, (int) $wpdb->get_var( "SELECT COUNT(*) FROM `{$prefix}acl_ar_messages` WHERE id=132 AND brain_run_id=88 AND brain_agent_id=132 AND content='preserve message'" ), $label . ' lost message Brain identifiers.' );
		$this->assert_same( 1, (int) $wpdb->get_var( "SELECT COUNT(*) FROM `{$prefix}acl_ar_usage` WHERE id=132 AND brain_id=132 AND brain_run_id=88 AND total_tokens=18" ), $label . ' lost usage Brain identifiers.' );
		$this->assert_same( 1, (int) $wpdb->get_var( "SELECT COUNT(*) FROM `{$prefix}acl_ar_brains` WHERE id=132 AND orchestration_prompt='preserve brain'" ), $label . ' lost Brain data.' );
	}

	private function schema_fingerprint( string $prefix ): string {
		global $wpdb;
		$like = $wpdb->esc_like( $prefix . 'acl_ar_' ) . '%';
		$schema = array(
			'tables'  => $wpdb->get_results( $wpdb->prepare( 'SELECT REPLACE(TABLE_NAME,%s,\'\') TABLE_NAME,ENGINE,TABLE_COLLATION FROM information_schema.TABLES WHERE TABLE_SCHEMA=%s AND TABLE_NAME LIKE %s ORDER BY TABLE_NAME', $prefix, DB_NAME, $like ), ARRAY_A ),
			'columns' => $wpdb->get_results( $wpdb->prepare( 'SELECT REPLACE(TABLE_NAME,%s,\'\') TABLE_NAME,ORDINAL_POSITION,COLUMN_NAME,COLUMN_TYPE,IS_NULLABLE,COLUMN_DEFAULT,EXTRA,CHARACTER_SET_NAME,COLLATION_NAME,COLUMN_COMMENT FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=%s AND TABLE_NAME LIKE %s ORDER BY TABLE_NAME,ORDINAL_POSITION', $prefix, DB_NAME, $like ), ARRAY_A ),
			'indexes' => $wpdb->get_results( $wpdb->prepare( 'SELECT REPLACE(TABLE_NAME,%s,\'\') TABLE_NAME,INDEX_NAME,NON_UNIQUE,SEQ_IN_INDEX,COLUMN_NAME,SUB_PART,INDEX_TYPE FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=%s AND TABLE_NAME LIKE %s ORDER BY TABLE_NAME,INDEX_NAME,SEQ_IN_INDEX', $prefix, DB_NAME, $like ), ARRAY_A ),
		);
		return hash( 'sha256', wp_json_encode( $schema ) );
	}

	private function target_definitions( string $prefix ): array {
		global $wpdb;
		$out = array();
		foreach ( self::CHAINS as $suffix => $target ) {
			foreach ( $target['columns'] as $column ) {
				$out[ $suffix . '.' . $column ] = $wpdb->get_row( $wpdb->prepare( 'SELECT COLUMN_TYPE,IS_NULLABLE,COLUMN_DEFAULT,EXTRA,CHARACTER_SET_NAME,COLLATION_NAME,COLUMN_COMMENT FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=%s AND TABLE_NAME=%s AND COLUMN_NAME=%s', DB_NAME, $prefix . 'acl_ar_' . $suffix, $column ), ARRAY_A );
			}
		}
		return $out;
	}

	private function natural_definitions( string $prefix ): array {
		global $wpdb;
		$placeholders = implode( ',', array_fill( 0, count( self::NATURAL_COLUMNS ), '%s' ) );
		$sql = $wpdb->prepare( "SELECT REPLACE(TABLE_NAME,%s,'') TABLE_NAME,COLUMN_NAME,COLUMN_TYPE,IS_NULLABLE,COLUMN_DEFAULT,EXTRA,CHARACTER_SET_NAME,COLLATION_NAME,COLUMN_COMMENT FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=%s AND TABLE_NAME LIKE %s AND COLUMN_NAME IN ({$placeholders}) ORDER BY TABLE_NAME,COLUMN_NAME", array_merge( array( $prefix, DB_NAME, $wpdb->esc_like( $prefix . 'acl_ar_' ) . '%' ), self::NATURAL_COLUMNS ) );
		return (array) $wpdb->get_results( $sql, ARRAY_A );
	}

	private function index_fingerprint( string $prefix ): string {
		global $wpdb;
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT REPLACE(TABLE_NAME,%s,\'\') TABLE_NAME,INDEX_NAME,NON_UNIQUE,SEQ_IN_INDEX,COLUMN_NAME,SUB_PART,INDEX_TYPE FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=%s AND TABLE_NAME LIKE %s ORDER BY TABLE_NAME,INDEX_NAME,SEQ_IN_INDEX', $prefix, DB_NAME, $wpdb->esc_like( $prefix . 'acl_ar_' ) . '%' ), ARRAY_A );
		return hash( 'sha256', wp_json_encode( $rows ) );
	}

	private function engine_fingerprint( string $prefix ): string {
		global $wpdb;
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT REPLACE(TABLE_NAME,%s,\'\') TABLE_NAME,ENGINE,TABLE_COLLATION FROM information_schema.TABLES WHERE TABLE_SCHEMA=%s AND TABLE_NAME LIKE %s ORDER BY TABLE_NAME', $prefix, DB_NAME, $wpdb->esc_like( $prefix . 'acl_ar_' ) . '%' ), ARRAY_A );
		return hash( 'sha256', wp_json_encode( $rows ) );
	}

	private function data_fingerprint( string $prefix ): string {
		global $wpdb;
		$out = array();
		foreach ( array( 'brains', 'agents', 'rooms', 'messages', 'usage' ) as $suffix ) {
			$rows = $wpdb->get_results( "SELECT * FROM `{$prefix}acl_ar_{$suffix}` ORDER BY id", ARRAY_A );
			foreach ( $rows as &$row ) {
				ksort( $row );
			}
			unset( $row );
			$out[ $suffix ] = $rows;
		}
		return hash( 'sha256', wp_json_encode( $out ) );
	}

	private function unrelated_order( string $prefix ): array {
		$out = array();
		foreach ( self::CHAINS as $suffix => $target ) {
			$out[ $suffix ] = array_values( array_diff( $this->column_names( $prefix . 'acl_ar_' . $suffix ), $target['columns'] ) );
		}
		return $out;
	}

	private function column_names( string $table ): array {
		global $wpdb;
		return array_map( 'strval', (array) $wpdb->get_col( $wpdb->prepare( 'SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=%s AND TABLE_NAME=%s ORDER BY ORDINAL_POSITION', DB_NAME, $table ) ) );
	}

	private function last_column( string $table ): string {
		$names = $this->column_names( $table );
		return (string) end( $names );
	}

	private function new_prefix( string $label ): string {
		$prefix = 'acl132_' . str_replace( '-', '_', sanitize_key( $label ) ) . '_' . strtolower( wp_generate_password( 6, false, false ) ) . '_';
		$this->prefixes[] = $prefix;
		return $prefix;
	}

	private function drop_prefix( string $prefix ): void {
		global $wpdb;
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT TABLE_NAME,TABLE_TYPE FROM information_schema.TABLES WHERE TABLE_SCHEMA=%s AND TABLE_NAME LIKE %s ORDER BY TABLE_TYPE DESC,TABLE_NAME', DB_NAME, $wpdb->esc_like( $prefix ) . '%' ), ARRAY_A );
		foreach ( (array) $rows as $row ) {
			$name = (string) $row['TABLE_NAME'];
			$wpdb->query( ( 'VIEW' === (string) $row['TABLE_TYPE'] ? 'DROP VIEW IF EXISTS ' : 'DROP TABLE IF EXISTS ' ) . "`{$name}`" );
		}
	}
}
