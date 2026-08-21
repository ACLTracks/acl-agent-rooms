<?php
/** Optional ACL Storage integration boundary. @package ACL_Agent_Rooms */
namespace ACL\AgentRooms\Services;

if ( ! defined( 'ABSPATH' ) ) {
	exit; }

class StorageBridge {
	public const MINIMUM_PLUGIN_VERSION   = '0.6.0';
	public const MINIMUM_CONTRACT_VERSION = '1.0.0';

	public function available(): bool {
		$plugin_version = (string) apply_filters( 'acl_ar_storage_plugin_version', defined( 'ACL_STORAGE_VERSION' ) ? ACL_STORAGE_VERSION : '' );
		return function_exists( 'acl_storage_asset_service_v1' )
			&& '' !== $plugin_version
			&& version_compare( $plugin_version, self::MINIMUM_PLUGIN_VERSION, '>=' )
			&& is_object( $this->service() )
			&& method_exists( $this->service(), 'isCompatible' )
			&& $this->service()->isCompatible( self::MINIMUM_CONTRACT_VERSION );
	}

	public function status(): array {
		$plugin_version = (string) apply_filters( 'acl_ar_storage_plugin_version', defined( 'ACL_STORAGE_VERSION' ) ? ACL_STORAGE_VERSION : '' );
		return array(
			'available'                => $this->available(),
			'plugin_version'           => '' !== $plugin_version ? $plugin_version : null,
			'minimum_plugin_version'   => self::MINIMUM_PLUGIN_VERSION,
			'contract_version'         => $this->service() && method_exists( $this->service(), 'contractVersion' ) ? (string) $this->service()->contractVersion() : null,
			'minimum_contract_version' => self::MINIMUM_CONTRACT_VERSION,
		);
	}

	public function metadata( int $asset_id, int $owner_id = 0 ) {
		return $this->call( 'metadata', array( $asset_id, $owner_id ) ); }
	public function owned_assets( int $owner_id, int $limit = 200 ) {
		return $this->call( 'ownedAssets', array( $owner_id, $limit ) ); }
	public function upload( array $file, int $owner_id ) {
		return $this->call( 'createPrivateTextAsset', array( $file, $owner_id ) ); }
	public function read( int $asset_id, int $owner_id, int $maximum_bytes ) {
		return $this->call( 'readPrivateAsset', array( $asset_id, $owner_id, $maximum_bytes ) ); }
	public function delete( int $asset_id, int $actor_id ) {
		return $this->call( 'deleteAsset', array( $asset_id, $actor_id ) ); }
	public function download_url( int $asset_id, int $actor_id ) {
		return $this->call( 'downloadUrl', array( $asset_id, $actor_id ) ); }

	private function service() {
		if ( ! function_exists( 'acl_storage_asset_service_v1' ) ) {
			return null; }
		try {
			return apply_filters( 'acl_ar_storage_service_v1', acl_storage_asset_service_v1() );
		} catch ( \Throwable $error ) {
			return null; }
	}

	private function call( string $method, array $arguments ) {
		if ( ! $this->available() ) {
			return new \WP_Error( 'acl_ar_storage_unavailable', __( 'Compatible ACL Storage is unavailable.', 'acl-agent-rooms' ), array( 'status' => 503 ) ); }
		$service = $this->service();
		if ( ! method_exists( $service, $method ) ) {
			return new \WP_Error( 'acl_ar_storage_contract_invalid', __( 'ACL Storage integration contract is incomplete.', 'acl-agent-rooms' ), array( 'status' => 503 ) ); }
		return $service->{$method}( ...$arguments );
	}
}
