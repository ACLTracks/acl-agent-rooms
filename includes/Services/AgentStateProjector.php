<?php
/** Durable agent activity projection ordered by lifecycle event ID. @package ACL_Agent_Rooms */
namespace ACL\AgentRooms\Services;

use ACL\AgentRooms\Repositories\PresenceRepository;
if ( ! defined( 'ABSPATH' ) ) {
	exit;}
class AgentStateProjector {
	private PresenceRepository $presence;
	public function __construct( ?PresenceRepository $presence = null ) {
		$this->presence = $presence ?: new PresenceRepository();}
	public function project_event( array $event ): bool {
		$map  = array(
			'agent_queued'     => 'queued',
			'agent_thinking'   => 'thinking',
			'agent_responding' => 'responding',
			'agent_completed'  => 'ready',
			'agent_failed'     => 'error',
		);
		$type = (string) ( $event['event_type'] ?? '' );
		if ( ! isset( $map[ $type ] ) ) {
			return false;
		}
		$state = 'agent_failed' === $type && ! empty( $event['metadata']['retryable'] ) ? 'queued' : $map[ $type ];
		$room   = (int) $event['room_id'];
		$agent   = (int) $event['actor_id'];
		$id      = (int) $event['id'];
		$current = $this->presence->find( $room, 'agent', $agent );
		if ( $current && $id <= (int) ( $current['last_event_id'] ?? 0 ) ) {
			return false;
		}$expires = 'error' === $state ? gmdate( 'Y-m-d H:i:s', time() + max( 60, min( 1800, (int) apply_filters( 'acl_ar_agent_error_ttl', 300 ) ) ) ) : null;
		return $this->presence->upsert( $room, 'agent', $agent, $state, (string) ( $event['created_at'] ?? null ), $id, $expires );}
}
