<?php
/** Creates one idempotent run per Brain group and preserves independent targets. @package ACL_Agent_Rooms */
namespace ACL\AgentRooms\Services;

use ACL\AgentRooms\Repositories\BrainRunRepository;

if ( ! defined( 'ABSPATH' ) ) {
	exit; }

class BrainRunService {
	private BrainGroupingService $grouping;
	private BrainRunRepository $runs;
	private BrainRuntime $runtime;
	private BrainStateProjector $states;

	public function __construct( ?BrainGroupingService $grouping = null, ?BrainRunRepository $runs = null, ?BrainRuntime $runtime = null ) {
		$this->grouping = $grouping ?: new BrainGroupingService();
		$this->runs     = $runs ?: new BrainRunRepository();
		$this->runtime  = $runtime ?: new BrainRuntime( $this->runs );
		$this->states   = new BrainStateProjector();
	}

	public function create_for_targets( array $room, array $trigger_event, array $targets, bool $run_inline = true, array $planned = array() ): array {
		$grouped = $this->grouping->group( $targets );
		$created = array();
		$plans   = array();
		foreach ( $planned as $item ) {
			$plans[ (int) ( $item['agent']['id'] ?? 0 ) ] = $item; }

		foreach ( $grouped['brains'] as $group ) {
			$brain    = $group['brain'];
			$provider = (string) ( $brain['provider'] ?? '' );
			$model    = (string) ( $brain['model'] ?? '' );
			$ids      = array_map( static fn( $agent ) => (int) $agent['id'], $group['agents'] );
			$run      = $this->runs->create( (int) $room['id'], (int) $group['brain_id'], (int) $trigger_event['id'], $ids, $provider, $model );
			if ( is_wp_error( $run ) ) {
				$created[] = array(
					'error'     => $run,
					'agent_ids' => $ids,
				);
				continue;
			}

			$turn_errors = array();
			if ( 'natural' === (string) ( $room['conversation_mode'] ?? 'immediate' ) ) {
				foreach ( $group['agents'] as $agent ) {
					$plan = $plans[ (int) $agent['id'] ] ?? null;
					if ( ! $plan ) {
						continue; }
					$turn = ( new ConversationTurnService() )->create( $room, $trigger_event, $plan, 'brain', (int) $run['id'], 0 );
					if ( is_wp_error( $turn ) ) {
						$turn_errors[] = $turn; }
				}
			} elseif ( ! in_array( (string) $run['status'], array( 'completed', 'canceled' ), true ) ) {
				$this->states->project( (int) $room['id'], $ids, 'queued', (int) $run['id'] );
			}

			if ( $run_inline && ! in_array( (string) $run['status'], array( 'completed', 'canceled' ), true ) ) {
				$result    = $this->runtime->run( (int) $run['id'] );
				$run       = $this->runs->find( (int) $run['id'] ) ?: $run;
				$created[] = array(
					'run'         => $run,
					'result'      => $result,
					'turn_errors' => $turn_errors,
				);
				continue;
			}

			$queued = true;
			if ( ! $run_inline && in_array( (string) $run['status'], array( 'pending', 'response_saved' ), true ) ) {
				$queued = ( new QueueService() )->enqueue_brain_run( (int) $run['id'] );
			}
			$created[] = array(
				'run'         => $run,
				'queued'      => $queued,
				'turn_errors' => $turn_errors,
			);
		}

		return array(
			'independent' => $grouped['independent'],
			'brain_runs'  => $created,
		);
	}
}
