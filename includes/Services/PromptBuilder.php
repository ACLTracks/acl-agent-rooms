<?php
/**
 * Builds Switchboard prompt requests for room agents.
 *
 * @package ACL_Agent_Rooms
 */

namespace ACL\AgentRooms\Services;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class PromptBuilder {
	private ContextBuilder $context;
	private AgentConfigResolver $resolver;

	public function __construct( ?ContextBuilder $context = null, ?AgentConfigResolver $resolver = null ) {
		$this->context  = $context ?: new ContextBuilder();
		$this->resolver = $resolver ?: new AgentConfigResolver();
	}

	public function build_request( array $room, array $agent, array $trigger ): array {
		$config        = $this->resolver->resolve( $agent );
		$system_prompt = $this->build_system_prompt( $room, $agent, $config, $trigger );

		return apply_filters(
			'acl_ar_prompt_request',
			array(
				'provider_route'   => (string) $config['provider_route'],
				'model'            => (string) $config['model'],
				'system_prompt'    => $system_prompt,
				'messages'         => $this->context->build( $room, $agent ),
				'temperature'      => (float) $config['temperature'],
				'max_tokens'       => (int) $config['max_tokens'],
				'tools'            => array(),
				'metadata'         => array(
					'room_id'            => (int) $room['id'],
					'agent_id'           => (int) $agent['id'],
					'trigger_message_id' => (int) $trigger['id'],
					'config_mode'        => (string) $config['mode'],
					'shared_config_id'   => $config['shared_config_id'],
				),
				'effective_config' => $config,
			),
			$room,
			$agent,
			$trigger,
			$config
		);
	}

	private function build_system_prompt( array $room, array $agent, array $config, array $trigger ): string {
		$parts         = array();
		$master_prompt = trim( wp_strip_all_tags( (string) $config['system_prompt'] ) );

		if ( '' !== $master_prompt ) {
			$parts[] = "Master prompt:\n" . $master_prompt;
		}

		$identity = array(
			'Agent name: ' . (string) ( $agent['name'] ?? '' ),
			'Agent slug: @' . (string) ( $agent['slug'] ?? '' ),
		);

		$description = trim( wp_strip_all_tags( (string) ( $agent['description'] ?? '' ) ) );
		if ( '' !== $description ) {
			$identity[] = 'Agent description: ' . $description;
		}

		if ( 'shared' === (string) $config['mode'] && '' !== (string) $config['shared_config_name'] ) {
			$identity[] = 'Shared runtime configuration: ' . (string) $config['shared_config_name'];
		}

		$parts[] = implode( "\n", $identity );

		$room_bits = array(
			'Room name: ' . (string) ( $room['title'] ?? '' ),
		);

		$room_description = trim( wp_strip_all_tags( (string) ( $room['description'] ?? '' ) ) );
		if ( '' !== $room_description ) {
			$room_bits[] = 'Room description: ' . $room_description;
		}

		$top_context = trim( wp_strip_all_tags( (string) ( $room['top_context'] ?? '' ) ) );
		if ( '' !== $top_context ) {
			$room_bits[] = "Persistent room context:\n" . $top_context;
		}

		$parts[]         = implode( "\n", $room_bits );
		$project_context = ( new RoomFileRetrievalService() )->prompt_block( $room, $trigger );
		if ( '' !== $project_context ) {
			$parts[] = $project_context; }
		$parts[] = 'Acknowledge the persistent room context by using it in your answer. Respond as this agent in the chatroom context. Do not impersonate another assigned agent.';

		return implode( "\n\n", array_filter( $parts ) );
	}
}
