<?php
/**
 * Safe public job projection.
 *
 * @package ACL_Agent_Rooms
 */

namespace ACL\AgentRooms\Services;

use ACL\AgentRooms\Repositories\AgentRepository;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class PublicJob {
	private AgentRepository $agents;

	public function __construct( ?AgentRepository $agents = null ) {
		$this->agents = $agents ?: new AgentRepository();
	}

	public function prepare( array $job, bool $manager = false ): array {
		$agent            = $this->agents->find( (int) ( $job['agent_id'] ?? 0 ) );
		$public           = array(
			'id'                  => (int) ( $job['id'] ?? 0 ),
			'agent_id'            => (int) ( $job['agent_id'] ?? 0 ),
			'agent'               => $agent ? array(
				'id'         => (int) $agent['id'],
				'name'       => (string) $agent['name'],
				'slug'       => (string) $agent['slug'],
				'avatar_url' => (string) $agent['avatar_url'],
				'avatar_alt' => (string) $agent['avatar_alt'],
			) : null,
			'status'              => (string) ( $job['status'] ?? '' ),
			'attempts'            => (int) ( $job['attempts'] ?? 0 ),
			'retryable'           => ! empty( $job['retryable'] ),
			'response_message_id' => isset( $job['response_message_id'] ) ? (int) $job['response_message_id'] : null,
			'error'               => 'failed' === (string) ( $job['status'] ?? '' ) ? PublicError::message( (string) ( $job['public_error'] ?? '' ), __( 'Agent reply failed.', 'acl-agent-rooms' ) ) : '',
			'created_at'          => (string) ( $job['created_at'] ?? '' ),
			'updated_at'          => (string) ( $job['updated_at'] ?? '' ),
			'completed_at'        => (string) ( $job['completed_at'] ?? '' ),
		);
		$public['result'] = array(
			'ok'      => 'failed' !== $public['status'],
			'status'  => $public['status'],
			'message' => $public['error'],
		);

		if ( $manager ) {
			$public['diagnostic_code'] = sanitize_key( (string) ( $job['error_code'] ?? '' ) );
		}

		return $public;
	}
}
