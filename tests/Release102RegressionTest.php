<?php
/** Focused regression coverage for the 1.0.2 frontend REST URL repair. */
class ACL_AR_Release102RegressionTest extends ACL_AR_TestCase {
	public function run(): void {
		$this->version_and_schema_contract();
		$this->shortcode_bootstrap_contract();
		$this->composer_source_contract();
	}

	private function version_and_schema_contract(): void {
		$this->assert_same( '1.5.0', ACL_AR_VERSION, 'Current plugin version does not preserve the 1.0.2 repair.' );
		$this->assert_same( '1.4.1', ACL_AR_DB_VERSION, 'Current database version does not preserve the 1.0.2 repair.' );
		$before = $this->schema_fingerprint();
		\ACL\AgentRooms\Installer::install();
		\ACL\AgentRooms\Installer::install();
		$this->assert_same( $before, $this->schema_fingerprint(), 'The 1.0.2 installer changed schema or indexes.' );
		$this->assert_same( '1.4.1', (string) get_option( 'acl_ar_db_version' ), 'Installed database version is not current.' );
	}

	private function shortcode_bootstrap_contract(): void {
		$root   = dirname( __DIR__ );
		$source = (string) file_get_contents( $root . '/includes/Shortcodes/AgentRoomShortcode.php' );
		$compact_source = preg_replace( '/\s+/', '', $source );
		$this->assert_true( false !== strpos( $compact_source, "rest_url('acl-agent-rooms/v1')" ), 'Shortcode does not use WordPress-generated REST URLs.' );
		$this->assert_true( false === strpos( $source, '/wp-json/' ), 'Shortcode introduced a hard-coded wp-json fallback.' );

		$administrator_ids = get_users( array( 'role' => 'administrator', 'fields' => 'ids', 'number' => 1 ) );
		$this->assert_true( ! empty( $administrator_ids ), 'No administrator is available for shortcode verification.' );
		wp_set_current_user( (int) $administrator_ids[0] );
		$rooms = new \ACL\AgentRooms\Repositories\RoomRepository();
		$slug  = 'release-102-' . gmdate( 'YmdHis' ) . '-' . wp_generate_password( 5, false, false );
		$id    = $rooms->create(
			array(
				'owner_user_id' => (int) $administrator_ids[0],
				'title'         => 'Release 1.0.2 bootstrap',
				'slug'          => $slug,
				'type'          => 'private',
				'visibility'    => 'private',
				'status'        => 'active',
			)
		);
		$this->assert_true( is_int( $id ) && $id > 0, 'Shortcode fixture room was not created.' );

		try {
			$pretty = $this->render_bootstrap( (int) $id, '/%postname%/' );
			$plain  = $this->render_bootstrap( (int) $id, '' );

			$pretty_parts = wp_parse_url( (string) $pretty['restBase'] );
			$plain_parts  = wp_parse_url( (string) $plain['restBase'] );
			parse_str( isset( $plain_parts['query'] ) ? $plain_parts['query'] : '', $plain_query );
			parse_str( isset( $pretty_parts['query'] ) ? $pretty_parts['query'] : '', $pretty_query );

			$this->assert_true( false !== strpos( (string) $pretty_parts['path'], '/wp-json/acl-agent-rooms/v1' ), 'Pretty-permalink bootstrap is not valid.' );
			$this->assert_true( ! isset( $pretty_query['rest_route'] ), 'Pretty-permalink bootstrap unexpectedly uses rest_route.' );
			$this->assert_same( '/acl-agent-rooms/v1', isset( $plain_query['rest_route'] ) ? $plain_query['rest_route'] : null, 'Plain-permalink bootstrap rest_route is invalid.' );
			$this->assert_same( wp_parse_url( home_url(), PHP_URL_HOST ), isset( $plain_parts['host'] ) ? $plain_parts['host'] : null, 'Plain-permalink bootstrap changed origin.' );
			$this->assert_true( ! empty( $pretty['nonce'] ) && is_string( $pretty['nonce'] ), 'Pretty-permalink bootstrap lost the REST nonce.' );
			$this->assert_true( ! empty( $plain['nonce'] ) && is_string( $plain['nonce'] ), 'Plain-permalink bootstrap lost the REST nonce.' );
			$this->assert_same( '/rooms/' . $id . '/events', $pretty['eventEndpoint'], 'Pretty endpoint contract changed.' );
			$this->assert_same( $pretty['eventEndpoint'], $plain['eventEndpoint'], 'Permalink mode changed the frontend endpoint contract.' );
			$this->assert_same( $pretty['legacyMessageEndpoint'], $plain['legacyMessageEndpoint'], 'Permalink mode changed the message endpoint contract.' );
			$encoded = wp_json_encode( array( $pretty, $plain ) );
			$this->assert_true( false === stripos( $encoded, 'E:\\' ) && false === stripos( $encoded, 'C:\\Users' ), 'Bootstrap exposed a local filesystem path.' );
		} finally {
			$rooms->delete( (int) $id );
		}
	}

	private function composer_source_contract(): void {
		$api = (string) file_get_contents( dirname( __DIR__ ) . '/assets/js/room/api.js' );
		$this->assert_true( false !== strpos( $api, 'function buildRestUrl(' ) && false !== strpos( $api, 'Api.buildRestUrl = buildRestUrl' ), 'Central REST URL composer is missing.' );
		$this->assert_true( false !== strpos( $api, 'new URL(' ) && false !== strpos( $api, 'URLSearchParams' ), 'Composer does not use platform URL APIs.' );
		$this->assert_true( false !== strpos( $api, "searchParams.has('rest_route')" ), 'Composer does not detect rest_route.' );
		$this->assert_true( false === strpos( $api, "(this.config.restBase||'').replace" ), 'Unsafe pre-1.0.2 REST concatenation remains.' );
		$this->assert_true( 0 === preg_match( '#https?://[^\s\'\"]+(openai|anthropic|replicate|fal\.ai)#i', $api ), 'Frontend introduced a direct provider request.' );
	}

	private function render_bootstrap( int $room_id, string $permalink_structure ): array {
		$filter = static function () use ( $permalink_structure ): string {
			return $permalink_structure;
		};
		add_filter( 'pre_option_permalink_structure', $filter );
		try {
			$html = ( new \ACL\AgentRooms\Shortcodes\AgentRoomShortcode() )->render( array( 'id' => $room_id ) );
		} finally {
			remove_filter( 'pre_option_permalink_structure', $filter );
		}
		$this->assert_true( 1 === preg_match( '/data-bootstrap="([^"]+)"/', $html, $match ), 'Rendered room bootstrap is missing.' );
		$bootstrap = json_decode( html_entity_decode( $match[1], ENT_QUOTES | ENT_HTML5, 'UTF-8' ), true );
		$this->assert_true( is_array( $bootstrap ), 'Rendered room bootstrap is not valid JSON.' );
		return $bootstrap;
	}

	private function schema_fingerprint(): string {
		global $wpdb;
		$like    = $wpdb->esc_like( $wpdb->prefix . 'acl_ar_' ) . '%';
		$columns = $wpdb->get_results( $wpdb->prepare( 'SELECT TABLE_NAME,COLUMN_NAME,ORDINAL_POSITION,COLUMN_TYPE,IS_NULLABLE,COLUMN_DEFAULT,EXTRA FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=%s AND TABLE_NAME LIKE %s ORDER BY TABLE_NAME,ORDINAL_POSITION', DB_NAME, $like ), ARRAY_A );
		$indexes = $wpdb->get_results( $wpdb->prepare( 'SELECT TABLE_NAME,INDEX_NAME,NON_UNIQUE,SEQ_IN_INDEX,COLUMN_NAME,SUB_PART,INDEX_TYPE FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=%s AND TABLE_NAME LIKE %s ORDER BY TABLE_NAME,INDEX_NAME,SEQ_IN_INDEX', DB_NAME, $like ), ARRAY_A );
		return hash( 'sha256', wp_json_encode( array( $columns, $indexes ) ) );
	}
}
