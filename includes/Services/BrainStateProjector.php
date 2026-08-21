<?php
/** Projects one Brain execution stage onto every targeted agent. @package ACL_Agent_Rooms */
namespace ACL\AgentRooms\Services;

use ACL\AgentRooms\Repositories\PresenceRepository;

if ( ! defined( 'ABSPATH' ) ) {
	exit; }

class BrainStateProjector {
	private PresenceRepository $presence;
	public function __construct( ?PresenceRepository $presence = null ) {
		$this->presence = $presence ?: new PresenceRepository();}
	public function project( int $room_id, array $agent_ids, string $state, int $brain_run_id ): void {
		if ( ! in_array( $state, array( 'queued', 'thinking', 'responding', 'ready', 'error', 'paused', 'unavailable' ), true ) ) {
			$state = 'unavailable';
		}foreach ( array_values( array_unique( array_map( 'absint', $agent_ids ) ) ) as $agent_id ) {
			$current  = $this->presence->find( $room_id, 'agent', $agent_id );
			$meta     = is_array( $current ) && ! empty( $current['metadata_json'] ) ? json_decode( (string) $current['metadata_json'], true ) : array();
			$previous = absint( $meta['brain_run_id'] ?? 0 );
			if ( $previous > $brain_run_id ) {
				continue;
			}$expires = 'error' === $state ? gmdate( 'Y-m-d H:i:s', time() + 300 ) : null;
			$this->presence->upsert(
				$room_id,
				'agent',
				$agent_id,
				$state,
				null,
				(int) ( $current['last_event_id'] ?? 0 ),
				$expires,
				array(
					'brain_run_id' => $brain_run_id,
					'runtime'      => 'brain',
				)
			);}}
}
