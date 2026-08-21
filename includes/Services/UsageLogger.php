<?php
/**
 * Usage logger.
 *
 * @package ACL_Agent_Rooms
 */

namespace ACL\AgentRooms\Services;

use ACL\AgentRooms\Repositories\UsageRepository;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class UsageLogger {
	private UsageRepository $usage;

	public function __construct( ?UsageRepository $usage = null ) {
		$this->usage = $usage ?: new UsageRepository();
	}

	public function log( array $data ): bool {
		return $this->usage->create( $data );
	}
}
