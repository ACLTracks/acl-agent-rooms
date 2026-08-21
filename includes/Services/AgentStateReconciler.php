<?php
/** Reconcile reloadable agent projections against assignment and execution truth. @package ACL_Agent_Rooms */
namespace ACL\AgentRooms\Services;

use ACL\AgentRooms\Repositories\AgentRepository;
use ACL\AgentRooms\Repositories\BrainRepository;
use ACL\AgentRooms\Repositories\BrainRunRepository;
use ACL\AgentRooms\Repositories\JobRepository;
use ACL\AgentRooms\Repositories\PresenceRepository;
use ACL\AgentRooms\Repositories\RoomRepository;
use ACL\AgentRooms\Repositories\ConversationTurnRepository;

if ( ! defined( 'ABSPATH' ) ) {
	exit;}
class AgentStateReconciler {
	private RoomRepository $rooms;
	private JobRepository $jobs;
	private PresenceRepository $presence;
	private AgentRepository $agents;
	private BrainRepository $brains;
	private BrainRunRepository $brain_runs;
	private ConversationTurnRepository $turns;
	public function __construct() {
		$this->rooms      = new RoomRepository();
		$this->jobs       = new JobRepository();
		$this->presence   = new PresenceRepository();
		$this->agents     = new AgentRepository();
		$this->brains     = new BrainRepository();
		$this->brain_runs = new BrainRunRepository();
		$this->turns      = new ConversationTurnRepository(); }
	public function reconcile_assignment( int $room_id, int $agent_id ): string {
		$p     = $this->rooms->get_assignment( $room_id, $agent_id );
		$a     = $this->agents->find( $agent_id );
		$state = 'ready';
		$turn  = $this->turns->active_for_assignment( $room_id, $agent_id );
		if ( ! $p || empty( $p['enabled'] ) || ! $a || empty( $a['enabled'] ) ) {
			$state = 'unavailable';
		} elseif ( 'paused' === (string) $p['participation_state'] ) {
			$state = $this->jobs->has_running( $room_id, $agent_id ) ? 'pausing' : 'paused';
		} elseif ( $turn ) {
			$state = 'typing' === $turn['status'] ? 'typing' : ( 'publishing' === $turn['status'] ? 'responding' : 'ready' );
		} elseif ( 'brain' === (string) ( $a['execution_mode'] ?? 'independent' ) ) {
			$brain = ! empty( $a['brain_id'] ) ? $this->brains->find( (int) $a['brain_id'] ) : null;
			if ( ! $brain || empty( $brain['enabled'] ) ) {
				$state = 'unavailable';
			} else {
				$run   = $this->brain_runs->active_for_assignment( $room_id, $agent_id );
				$map   = array(
					'pending'        => 'queued',
					'running'        => 'thinking',
					'response_saved' => 'responding',
				);
				$state = $run ? ( $map[ $run['status'] ] ?? 'ready' ) : 'ready';
			}
		} elseif ( $this->jobs->has_running( $room_id, $agent_id ) ) {
			$state = 'thinking';
		}$current = $this->presence->find( $room_id, 'agent', $agent_id );
		if ( $current && 'error' === $current['state'] && ! empty( $current['expires_at'] ) && strtotime( $current['expires_at'] . ' UTC' ) > time() && 'ready' === $state ) {
			return 'error';
		}$this->presence->upsert(
			$room_id,
			'agent',
			$agent_id,
			$state,
			null,
			(int) ( $current['last_event_id'] ?? 0 ),
			'typing' === $state ? (string) $turn['due_at'] : null,
			array(
				'auto_muted'           => ! empty( $p['auto_muted'] ),
				'runtime'              => $turn ? 'natural' : ( 'brain' === (string) ( $a['execution_mode'] ?? 'independent' ) ? 'brain' : 'independent' ),
				'conversation_turn_id' => $turn ? (int) $turn['id'] : 0,
			)
		);
		return $state;}
	public function reconcile_room( int $room_id ): void {
		foreach ( $this->rooms->get_agents( $room_id ) as $agent ) {
			$this->reconcile_assignment( $room_id, (int) $agent['id'] );}}
}
