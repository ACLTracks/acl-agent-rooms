<?php
/**
 * Agent job REST controller.
 *
 * @package ACL_Agent_Rooms
 */

namespace ACL\AgentRooms\Rest;

use ACL\AgentRooms\Repositories\JobRepository;
use ACL\AgentRooms\Services\AccessService;
use ACL\AgentRooms\Services\AgentRuntime;
use ACL\AgentRooms\Services\AgentExecutionPolicy;
use ACL\AgentRooms\Services\PublicJob;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class JobsController extends AbstractController {
	private JobRepository $jobs;
	private AccessService $access;
	private AgentRuntime $runtime;
	private AgentExecutionPolicy $execution_policy;
	private PublicJob $public_jobs;

	public function __construct( JobRepository $jobs, AccessService $access, AgentRuntime $runtime ) {
		$this->jobs             = $jobs;
		$this->access           = $access;
		$this->runtime          = $runtime;
		$this->execution_policy = new AgentExecutionPolicy( $access );
		$this->public_jobs      = new PublicJob();
	}

	public function register_routes(): void {
		register_rest_route(
			self::NAMESPACE,
			'/agent-jobs/(?P<id>[\d]+)/run',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'run' ),
				'permission_callback' => array( $this, 'run_permissions' ),
			)
		);
	}

	public function run_permissions( \WP_REST_Request $request ) {
		$nonce = $this->verify_nonce( $request );
		if ( is_wp_error( $nonce ) ) {
			return $nonce;
		}

		$job = $this->jobs->find( absint( $request['id'] ) );
		if ( ! $job ) {
			return new \WP_Error( 'acl_ar_job_not_found', __( 'Agent job was not found.', 'acl-agent-rooms' ), array( 'status' => 404 ) );
		}
		if ( 'completed' === (string) $job['status'] ) {
			return new \WP_REST_Response( array( 'job' => $this->public_jobs->prepare( $job, $this->access->can_manage_room( (int) $job['room_id'] ) ) ), 200 );
		}

		return $this->access->can_access_room( (int) $job['room_id'], get_current_user_id(), true )
			? true
			: new \WP_Error( 'acl_ar_room_forbidden', __( 'You cannot run this agent job.', 'acl-agent-rooms' ), array( 'status' => 403 ) );
	}

	public function run( \WP_REST_Request $request ) {
		$job = $this->jobs->find( absint( $request['id'] ) );
		if ( ! $job ) {
			return new \WP_Error( 'acl_ar_job_not_found', __( 'Agent job was not found.', 'acl-agent-rooms' ), array( 'status' => 404 ) );
		}
		$authorization = $this->execution_policy->authorize( get_current_user_id(), (int) $job['room_id'], 'direct-job-run', array( 'job_id' => (int) $job['id'] ) );
		if ( is_wp_error( $authorization ) ) {
			return $authorization;
		}
		$result = $this->runtime->run_job( (int) $job['id'], true );
		if ( is_wp_error( $result ) ) {
			$stored = $this->jobs->find( (int) $job['id'] );
			if ( $stored ) {
				$data   = $result->get_error_data();
				$status = is_array( $data ) ? max( 400, (int) ( $data['status'] ?? 409 ) ) : 409;
				return new \WP_REST_Response( array( 'job' => $this->public_jobs->prepare( $stored, $this->access->can_manage_room( (int) $job['room_id'] ) ) ), $status );
			}
			return new \WP_Error( (string) $result->get_error_code(), __( 'Agent job could not be run.', 'acl-agent-rooms' ), array( 'status' => 409 ) );
		}

		return new \WP_REST_Response( array( 'job' => $this->public_jobs->prepare( $result, $this->access->can_manage_room( (int) $job['room_id'] ) ) ), 200 );
	}
}
