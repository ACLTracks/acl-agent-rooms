<?php
/** Regression coverage for the public Switchboard default-routing contract. */

use ACL\AgentRooms\Services\SwitchboardClient;

class ACL_AR_SwitchboardCompatibilityTest extends ACL_AR_TestCase {
	public function run(): void {
		$this->default_sentinels_are_omitted();
		$this->explicit_selections_are_preserved();
		$this->structured_output_is_forwarded();
	}

	private function default_sentinels_are_omitted(): void {
		$prepared = $this->prepare(
			array(
				'provider_route' => 'default',
				'model'          => 'default',
				'system_prompt'  => 'System contract',
				'messages'       => array( array( 'role' => 'user', 'content' => 'Hello' ) ),
				'metadata'       => array( 'source' => 'compatibility-test' ),
			)
		);

		$this->assert_true( ! array_key_exists( 'provider', $prepared ), 'Default provider was forwarded as a literal Switchboard selection.' );
		$this->assert_true( ! array_key_exists( 'model', $prepared ), 'Default model was forwarded as a literal Switchboard selection.' );
		$this->assert_same( array(), $prepared['preferences'], 'Default routing populated explicit Switchboard preferences.' );
		$this->assert_same( 'system', $prepared['messages'][0]['role'], 'System prompt was not placed first.' );
		$this->assert_same( 'System contract', $prepared['messages'][0]['content'], 'System prompt content changed.' );
		$this->assert_same( 'Hello', $prepared['messages'][1]['content'], 'User message changed.' );
		$this->assert_same( 'compatibility-test', $prepared['metadata']['source'], 'Request metadata changed.' );
		$this->assert_true( ! array_key_exists( 'model', $prepared['payload'] ), 'Default model leaked into the provider payload.' );
	}

	private function explicit_selections_are_preserved(): void {
		$prepared = $this->prepare(
			array(
				'provider_route' => 'openrouter',
				'model'          => 'openrouter/free',
				'messages'       => array( array( 'role' => 'user', 'content' => 'Hello' ) ),
				'tools'          => array( array( 'type' => 'function' ) ),
			)
		);

		$this->assert_same( 'openrouter', $prepared['provider'], 'Explicit provider was not forwarded.' );
		$this->assert_same( 'openrouter/free', $prepared['model'], 'Explicit model was not forwarded.' );
		$this->assert_same( 'openrouter', $prepared['preferences']['provider'], 'Explicit provider preference changed.' );
		$this->assert_same( 'openrouter/free', $prepared['preferences']['model'], 'Explicit model preference changed.' );
		$this->assert_same( 'function', $prepared['payload']['tools'][0]['type'], 'Tool payload changed.' );
	}

	private function structured_output_is_forwarded(): void {
		$prepared = $this->prepare(
			array(
				'messages'   => array( array( 'role' => 'user', 'content' => 'Return JSON' ) ),
				'structured' => array(
					'type'   => 'json_object',
					'fields' => array( 'responses' => 'array' ),
				),
			)
		);

		$this->assert_same( array( 'type' => 'json_object', 'fields' => array( 'responses' => 'array' ) ), $prepared['structured'] ?? array(), 'Structured output configuration did not reach Switchboard.' );
	}

	private function prepare( array $request ): array {
		$method = new ReflectionMethod( SwitchboardClient::class, 'prepare_switchboard_request' );
		$method->setAccessible( true );
		return $method->invoke( new SwitchboardClient(), $request );
	}
}
