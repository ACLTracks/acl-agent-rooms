<?php
/**
 * Authenticated, room-scoped foreground worker for live conversations.
 *
 * @package ACL_Agent_Rooms
 */

namespace ACL\AgentRooms\Rest;

use ACL\AgentRooms\Repositories\BrainRunRepository;
use ACL\AgentRooms\Repositories\ConversationTurnRepository;
use ACL\AgentRooms\Repositories\JobRepository;
use ACL\AgentRooms\Repositories\RoomRepository;
use ACL\AgentRooms\Services\AccessService;
use ACL\AgentRooms\Services\AgentRuntime;
use ACL\AgentRooms\Services\BrainRuntime;
use ACL\AgentRooms\Services\ConversationTurnService;
use ACL\AgentRooms\Support\Arr;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class RoomWorkController extends AbstractController {
	private RoomRepository $rooms;
	private AccessService $access;
	private BrainRunRepository $brain_runs;
	private JobRepository $jobs;
	private BrainRuntime $brain_runtime;
	private AgentRuntime $agent_runtime;
	private ConversationTurnService $turns;
	private ConversationTurnRepository $turn_repository;

	public function __construct(
		RoomRepository $rooms,
		AccessService $access,
		BrainRunRepository $brain_runs,
		JobRepository $jobs,
		BrainRuntime $brain_runtime,
		AgentRuntime $agent_runtime,
		?ConversationTurnService $turns = null,
		?ConversationTurnRepository $turn_repository = null
	) {
		$this->rooms           = $rooms;
		$this->access          = $access;
		$this->brain_runs      = $brain_runs;
		$this->jobs            = $jobs;
		$this->brain_runtime   = $brain_runtime;
		$this->agent_runtime   = $agent_runtime;
		$this->turns           = $turns ?: new ConversationTurnService();
		$this->turn_repository = $turn_repository ?: new ConversationTurnRepository();
	}

	public function register_routes(): void {
		register_rest_route(
			self::NAMESPACE,
			'/rooms/(?P<id>[\d]+)/work',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'run' ),
				'permission_callback' => array( $this, 'permissions' ),
				'args'                => array(
					'brain_run_ids' => array(
						'required' => false,
						'type'     => 'array',
						'items'    => array( 'type' => 'integer' ),
					),
					'job_ids'       => array(
						'required' => false,
						'type'     => 'array',
						'items'    => array( 'type' => 'integer' ),
					),
				),
			)
		);
	}

	public function permissions( \WP_REST_Request $request ) {
		$nonce = $this->verify_nonce( $request );
		if ( is_wp_error( $nonce ) ) {
			return $nonce;
		}

		$room_id = absint( $request['id'] );
		if ( ! $this->rooms->find( $room_id ) ) {
			return new \WP_Error( 'acl_ar_room_not_found', __( 'Room not found.', 'acl-agent-rooms' ), array( 'status' => 404 ) );
		}

		return $this->access->can_access_room( $room_id, get_current_user_id(), true )
			? true
			: new \WP_Error( 'acl_ar_room_forbidden', __( 'You cannot run work for this room.', 'acl-agent-rooms' ), array( 'status' => 403 ) );
	}

	public function run( \WP_REST_Request $request ) {
		$room_id       = absint( $request['id'] );
		$brain_run_ids = Arr::ids( (array) $request->get_param( 'brain_run_ids' ) );
		$job_ids       = Arr::ids( (array) $request->get_param( 'job_ids' ) );
		if ( count( $brain_run_ids ) > 5 || count( $job_ids ) > 5 ) {
			return new \WP_Error( 'acl_ar_room_work_limit', __( 'Too many room work items were requested.', 'acl-agent-rooms' ), array( 'status' => 400 ) );
		}

		$brain_runs = array();
		foreach ( $brain_run_ids as $run_id ) {
			$run = $this->brain_runs->find( $run_id );
			if ( ! $run || $room_id !== (int) $run['room_id'] ) {
				return new \WP_Error( 'acl_ar_brain_run_not_found', __( 'Shared Brain work was not found in this room.', 'acl-agent-rooms' ), array( 'status' => 404 ) );
			}
			$brain_runs[ $run_id ] = $run;
		}

		$jobs = array();
		foreach ( $job_ids as $job_id ) {
			$job = $this->jobs->find( $job_id );
			if ( ! $job || $room_id !== (int) $job['room_id'] ) {
				return new \WP_Error( 'acl_ar_job_not_found', __( 'Agent work was not found in this room.', 'acl-agent-rooms' ), array( 'status' => 404 ) );
			}
			$jobs[ $job_id ] = $job;
		}

		foreach ( array_keys( $brain_runs ) as $run_id ) {
			$this->brain_runtime->run( (int) $run_id );
		}
		foreach ( array_keys( $jobs ) as $job_id ) {
			$this->agent_runtime->run_job( (int) $job_id, true );
		}

		$turn_result = $this->turns->run_due_for_room( $room_id, 20 );
		$brain_rows  = array_map( fn( int $id ): array => $this->brain_summary( $this->brain_runs->find( $id ) ), $brain_run_ids );
		$job_rows    = array_map( fn( int $id ): array => $this->job_summary( $this->jobs->find( $id ) ), $job_ids );
		$pending     = $this->turn_repository->pending_count( $room_id ) > 0
			|| count( array_filter( $brain_rows, array( $this, 'brain_pending' ) ) ) > 0
			|| count( array_filter( $job_rows, array( $this, 'job_pending' ) ) ) > 0;

		return new \WP_REST_Response(
			array(
				'brain_runs'     => $brain_rows,
				'jobs'           => $job_rows,
				'turns'          => $turn_result,
				'pending_turns'  => $this->turn_repository->pending_count( $room_id ),
				'pending'        => $pending,
				'retry_after_ms' => $pending ? $this->retry_after_ms( $room_id, $brain_rows ) : 0,
			),
			200,
			array( 'Cache-Control' => 'private, no-store' )
		);
	}

	private function brain_summary( ?array $run ): array {
		return array(
			'id'              => (int) ( $run['id'] ?? 0 ),
			'status'          => (string) ( $run['status'] ?? 'missing' ),
			'attempts'        => (int) ( $run['attempts'] ?? 0 ),
			'next_attempt_at' => $run['next_attempt_at'] ?? null,
			'error'           => (string) ( $run['public_error'] ?? '' ),
		);
	}

	private function job_summary( ?array $job ): array {
		return array(
			'id'     => (int) ( $job['id'] ?? 0 ),
			'status' => (string) ( $job['status'] ?? 'missing' ),
			'error'  => (string) ( $job['public_error'] ?? '' ),
		);
	}

	public function brain_pending( array $run ): bool {
		return in_array( (string) $run['status'], array( 'pending', 'running', 'response_saved' ), true )
			|| ( 'failed' === (string) $run['status'] && ! empty( $run['next_attempt_at'] ) );
	}

	public function job_pending( array $job ): bool {
		return in_array( (string) $job['status'], array( 'pending', 'running' ), true );
	}

	private function retry_after_ms( int $room_id, array $runs ): int {
		$candidates = array();
		$now        = time();
		foreach ( $runs as $run ) {
			if ( ! empty( $run['next_attempt_at'] ) ) {
				$candidates[] = max( 1, strtotime( (string) $run['next_attempt_at'] . ' UTC' ) - $now );
			}
		}
		$next_turn = $this->turn_repository->next_work_at( $room_id );
		if ( null !== $next_turn ) {
			$candidates[] = max( 1, strtotime( $next_turn . ' UTC' ) - $now );
		}
		$seconds = empty( $candidates ) ? 1 : min( $candidates );
		return max( 750, min( 5000, $seconds * 1000 ) );
	}
}
