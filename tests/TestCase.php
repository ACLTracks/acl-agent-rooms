<?php

abstract class ACL_AR_TestCase {
	private int $assertions = 0;

	protected function assert_true( $value, string $message ): void {
		$this->assertions++;
		if ( true !== $value ) {
			throw new RuntimeException( $message );
		}
	}

	protected function assert_same( $expected, $actual, string $message ): void {
		$this->assertions++;
		if ( $expected !== $actual ) {
			throw new RuntimeException( $message . ' Expected ' . var_export( $expected, true ) . ', got ' . var_export( $actual, true ) );
		}
	}

	protected function assert_wp_error( $value, string $message ): void {
		$this->assertions++;
		if ( ! is_wp_error( $value ) ) {
			throw new RuntimeException( $message );
		}
	}

	public function assertion_count(): int {
		return $this->assertions;
	}
}
