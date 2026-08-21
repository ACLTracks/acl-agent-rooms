<?php
/**
 * Resolves the effective AI config for an agent.
 *
 * @package ACL_Agent_Rooms
 */

namespace ACL\AgentRooms\Services;

use ACL\AgentRooms\Repositories\SharedConfigRepository;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class AgentConfigResolver {
	private SharedConfigRepository $shared_configs;

	public function __construct( ?SharedConfigRepository $shared_configs = null ) {
		$this->shared_configs = $shared_configs ?: new SharedConfigRepository();
	}

	public function resolve( array $agent ): array {
		$shared_config = null;

		if ( 'shared' === (string) ( $agent['config_mode'] ?? '' ) && ! empty( $agent['shared_config_id'] ) ) {
			$config = $this->shared_configs->find( (int) $agent['shared_config_id'] );
			if ( $config && ! empty( $config['enabled'] ) ) {
				$shared_config = $config;
			}
		}

		if ( $shared_config ) {
			return array(
				'mode'               => 'shared',
				'shared_config_id'   => (int) $shared_config['id'],
				'shared_config_name' => (string) $shared_config['name'],
				'provider_route'     => (string) $shared_config['provider_route'],
				'model'              => (string) $shared_config['model'],
				'system_prompt'      => (string) $shared_config['system_prompt'],
				'temperature'        => (float) $shared_config['temperature'],
				'max_tokens'         => (int) $shared_config['max_tokens'],
			);
		}

		return array(
			'mode'               => 'independent',
			'shared_config_id'   => null,
			'shared_config_name' => '',
			'provider_route'     => (string) ( $agent['provider_route'] ?? 'default' ),
			'model'              => (string) ( $agent['model'] ?? 'default' ),
			'system_prompt'      => (string) ( $agent['system_prompt'] ?? '' ),
			'temperature'        => (float) ( $agent['temperature'] ?? 0.7 ),
			'max_tokens'         => (int) ( $agent['max_tokens'] ?? 1200 ),
		);
	}
}
