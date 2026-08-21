<?php
/** Deterministic partitioning of independent agents and Shared Brain groups. @package ACL_Agent_Rooms */
namespace ACL\AgentRooms\Services;

use ACL\AgentRooms\Repositories\BrainRepository;

if ( ! defined( 'ABSPATH' ) ) {
	exit; }

class BrainGroupingService {
	private BrainRepository $brains;
	public function __construct( ?BrainRepository $brains = null ) {
		$this->brains = $brains ?: new BrainRepository();}
	public function group( array $targets ): array {
		$seen        = array();
		$independent = array();
		$groups      = array();
		usort( $targets, static fn( $a, $b )=>( (int) ( $a['sort_order'] ?? 0 ) <=> (int) ( $b['sort_order'] ?? 0 ) ) ?: ( (int) $a['id'] <=> (int) $b['id'] ) );
		foreach ( $targets as $agent ) {
			$id = absint( $agent['id'] ?? 0 );
			if ( ! $id || isset( $seen[ $id ] ) ) {
				continue;
			}$seen[ $id ] = true;
			if ( 'brain' !== (string) ( $agent['execution_mode'] ?? 'independent' ) ) {
				$independent[] = $agent;
				continue;
			}$brain_id = absint( $agent['brain_id'] ?? 0 );
			if ( ! isset( $groups[ $brain_id ] ) ) {
				$groups[ $brain_id ] = array(
					'brain_id' => $brain_id,
					'brain'    => $brain_id ? $this->brains->find( $brain_id ) : null,
					'agents'   => array(),
				);
			}$groups[ $brain_id ]['agents'][] = $agent;}
		return array(
			'independent' => $independent,
			'brains'      => array_values( $groups ),
		);
	}
}
