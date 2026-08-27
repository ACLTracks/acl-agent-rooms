<?php
/**
 * Switchboard client.
 *
 * @package ACL_Agent_Rooms
 */

namespace ACL\AgentRooms\Services;

use ACL\AgentRooms\Contracts\SwitchboardClientInterface;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SwitchboardClient implements SwitchboardClientInterface {
	public function send( array $request ) {
		$alternate = apply_filters( 'acl_ar_switchboard_client', $this, $request );
		if ( $alternate instanceof SwitchboardClientInterface && $alternate !== $this ) {
			return $alternate->send( $request );
		}

		$request = apply_filters( 'acl_ar_switchboard_request', $request );

		if ( ! $this->is_available() ) {
			if ( apply_filters( 'acl_ar_enable_switchboard_stub_mode', false, $request ) ) {
				return apply_filters(
					'acl_ar_switchboard_stub_response',
					array(
						'content'       => '',
						'raw_provider'  => 'stub',
						'usage'         => array(
							'prompt_tokens'     => 0,
							'completion_tokens' => 0,
							'total_tokens'      => 0,
						),
						'finish_reason' => 'stub',
						'error'         => null,
					),
					$request
				);
			}

			return new \WP_Error(
				'acl_ar_switchboard_unavailable',
				__( 'ACL Switchboard is not configured for agent responses.', 'acl-agent-rooms' ),
				array( 'status' => 503 )
			);
		}

		$switchboard_request = $this->prepare_switchboard_request( $request );
		$provider_route      = (string) ( $switchboard_request['provider'] ?? '' );

		try {
			$result = acl_switchboard_chat( $switchboard_request );
		} catch ( \Throwable $throwable ) {
			return new \WP_Error(
				'acl_ar_switchboard_exception',
				__( 'ACL Switchboard request failed unexpectedly.', 'acl-agent-rooms' ),
				array( 'status' => 502 )
			);
		}

		if ( is_wp_error( $result ) ) {
			return new \WP_Error(
				(string) $result->get_error_code(),
				PublicError::from_error( $result, __( 'The agent response request failed.', 'acl-agent-rooms' ) ),
				array( 'status' => (int) ( is_array( $result->get_error_data() ) ? ( $result->get_error_data()['status'] ?? 502 ) : 502 ) )
			);
		}

		if ( ! is_array( $result ) ) {
			return new \WP_Error(
				'acl_ar_switchboard_invalid_response',
				__( 'ACL Switchboard returned an invalid response.', 'acl-agent-rooms' ),
				array( 'status' => 502 )
			);
		}

		if ( empty( $result['ok'] ) ) {
			$error = is_array( $result['error'] ?? null ) ? $result['error'] : array();
			return new \WP_Error(
				(string) ( $error['code'] ?? 'acl_ar_switchboard_request_failed' ),
				PublicError::message( (string) ( $error['message'] ?? '' ), __( 'The agent response request failed.', 'acl-agent-rooms' ) ),
				array( 'status' => 502 )
			);
		}

		$output = is_array( $result['output'] ?? null ) ? $result['output'] : array();
		$usage  = is_array( $result['usage'] ?? null ) ? $result['usage'] : array();
		if ( empty( $usage ) && is_array( $output['usage'] ?? null ) ) {
			$usage = $output['usage'];
		}
		$meta = is_array( $result['meta'] ?? null ) ? $result['meta'] : array();

		$response = array(
			'content'       => $this->extract_content( $result, $output ),
			'raw_provider'  => (string) ( $result['provider'] ?? $provider_route ),
			'usage'         => array(
				'prompt_tokens'     => (int) ( $usage['prompt_tokens'] ?? $usage['input_tokens'] ?? 0 ),
				'completion_tokens' => (int) ( $usage['completion_tokens'] ?? $usage['output_tokens'] ?? 0 ),
				'total_tokens'      => (int) ( $usage['total_tokens'] ?? 0 ),
			),
			'finish_reason' => (string) ( $meta['finish_reason'] ?? $output['finish_reason'] ?? '' ),
			'error'         => null,
			'raw_result'    => $result,
		);

		return apply_filters( 'acl_ar_switchboard_response', $response, $request, $result );
	}

	public function is_available(): bool {
		if ( ! function_exists( 'acl_switchboard_chat' ) || ! function_exists( 'acl_switchboard_is_available' ) ) {
			return false;
		}

		try {
			return (bool) acl_switchboard_is_available();
		} catch ( \Throwable $throwable ) {
			return false;
		}
	}

	private function extract_content( array $result, array $output ): string {
		$candidates = array(
			$output['text'] ?? null,
			$result['content'] ?? null,
			$result['text'] ?? null,
			$result['message']['content'] ?? null,
			$result['choices'][0]['message']['content'] ?? null,
			$result['choices'][0]['text'] ?? null,
		);

		foreach ( $candidates as $candidate ) {
			if ( is_string( $candidate ) && '' !== trim( $candidate ) ) {
				return trim( $candidate );
			}
		}

		return '';
	}

	/**
	 * Builds the public Switchboard request without forwarding local sentinel values.
	 *
	 * An empty or "default" provider/model means Switchboard should apply its own
	 * approved fallback. Sending either sentinel as a literal selection causes the
	 * public API to reject an otherwise valid default-routed request.
	 *
	 * @param array<string,mixed> $request Agent Rooms request.
	 * @return array<string,mixed> Public Switchboard request.
	 */
	private function prepare_switchboard_request( array $request ): array {
		$messages      = is_array( $request['messages'] ?? null ) ? $request['messages'] : array();
		$system_prompt = trim( (string) ( $request['system_prompt'] ?? '' ) );
		if ( '' !== $system_prompt ) {
			array_unshift(
				$messages,
				array(
					'role'    => 'system',
					'content' => $system_prompt,
				)
			);
		}

		$payload = array(
			'messages'      => $messages,
			'system_prompt' => $system_prompt,
			'temperature'   => (float) ( $request['temperature'] ?? 0.7 ),
			'max_tokens'    => (int) ( $request['max_tokens'] ?? 1200 ),
		);

		if ( ! empty( $request['tools'] ) && is_array( $request['tools'] ) ) {
			$payload['tools'] = $request['tools'];
		}

		$provider_route = sanitize_text_field( (string) ( $request['provider_route'] ?? '' ) );
		$model          = sanitize_text_field( (string) ( $request['model'] ?? '' ) );
		$provider_route = 'default' === $provider_route ? '' : $provider_route;
		$model          = 'default' === $model ? '' : $model;

		$switchboard_request = array(
			'system_prompt' => $system_prompt,
			'messages'      => $messages,
			'payload'       => $payload,
			'feature'       => 'acl-agent-rooms',
			'task'          => 'agent-room-response',
			'metadata'      => is_array( $request['metadata'] ?? null ) ? $request['metadata'] : array(),
			'preferences'   => array(),
		);
		$structured = is_array( $request['structured'] ?? null ) ? $request['structured'] : array();
		if ( 'json_object' === (string) ( $structured['type'] ?? '' ) ) {
			$fields = array();
			foreach ( (array) ( $structured['fields'] ?? array() ) as $field => $type ) {
				$field = sanitize_key( (string) $field );
				$type  = sanitize_key( (string) $type );
				if ( '' !== $field && in_array( $type, array( 'string', 'number', 'boolean', 'array', 'object' ), true ) ) {
					$fields[ $field ] = $type;
				}
			}
			$switchboard_request['structured'] = array(
				'type'   => 'json_object',
				'fields' => $fields,
			);
		}

		if ( '' !== $model ) {
			$switchboard_request['model']                = $model;
			$switchboard_request['preferences']['model'] = $model;
		}

		if ( '' !== $provider_route ) {
			$switchboard_request['provider']                = $provider_route;
			$switchboard_request['preferences']['provider'] = $provider_route;
		}

		return $switchboard_request;
	}
}
