<?php
/** Bounded, resumable legacy-message event backfill. @package ACL_Agent_Rooms */

namespace ACL\AgentRooms\Services;

use ACL\AgentRooms\Repositories\MessageRepository;
use ACL\AgentRooms\Repositories\JobRepository;
use ACL\AgentRooms\Models\RoomEvent;

if ( ! defined( 'ABSPATH' ) ) {
	exit; }

class EventBackfillService {
	private MessageRepository $messages;
	private RoomEventService $events;
	private JobRepository $jobs;

	public function __construct( ?MessageRepository $messages = null, ?RoomEventService $events = null, ?JobRepository $jobs = null ) {
		$this->messages = $messages ?: new MessageRepository();
		$this->events   = $events ?: new RoomEventService();
		$this->jobs     = $jobs ?: new JobRepository(); }

	public function run_batch( int $limit = 100 ) {
		$limit = max( 1, min( 500, $limit ) );
		update_option( 'acl_ar_event_backfill_status', 'running', false );
		$batch     = $this->messages->missing_event_batch( $limit );
		$processed = 0;
		$last      = (int) get_option( 'acl_ar_event_backfill_cursor', 0 );
		foreach ( $batch as $message ) {
			$result = $this->events->create_from_legacy_message( $message );
			if ( is_wp_error( $result ) ) {
				update_option( 'acl_ar_event_backfill_cursor', $last, false );
				update_option( 'acl_ar_event_backfill_status', 'error', false );
				update_option( 'acl_ar_event_backfill_error', PublicError::from_error( $result, __( 'Event backfill failed.', 'acl-agent-rooms' ) ), false );
				return $result;
			}
			$last = (int) $message['id'];
			++$processed;
		}
		update_option( 'acl_ar_event_backfill_cursor', $last, false );
		$remaining      = $this->messages->count_missing_events();
		$jobs_processed = 0;
		if ( 0 === $remaining ) {
			foreach ( $this->jobs->missing_lifecycle_batch( $limit ) as $job ) {
				$queued = $this->events->create_agent_lifecycle( $job, RoomEvent::TYPE_AGENT_QUEUED );
				if ( is_wp_error( $queued ) ) {
					update_option( 'acl_ar_event_backfill_status', 'error', false );
					update_option( 'acl_ar_event_backfill_error', PublicError::from_error( $queued ), false );
					return $queued;}
				$terminal = $this->events->reconcile_job( $job );
				if ( is_wp_error( $terminal ) ) {
					update_option( 'acl_ar_event_backfill_status', 'error', false );
					update_option( 'acl_ar_event_backfill_error', PublicError::from_error( $terminal ), false );
					return $terminal;}
				++$jobs_processed;
			}
		}
		$remaining_jobs = 0 === $remaining ? $this->jobs->count_missing_lifecycle() : 1;
		$has_more       = $remaining > 0 || $remaining_jobs > 0;
		update_option( 'acl_ar_event_backfill_status', $has_more ? 'pending' : 'complete', false );
		if ( ! $has_more ) {
			delete_option( 'acl_ar_event_backfill_error' );}
		return array(
			'processed'      => $processed,
			'jobs_processed' => $jobs_processed,
			'cursor'         => $last,
			'remaining'      => $remaining,
			'remaining_jobs' => $remaining_jobs,
			'has_more'       => $has_more,
		);
	}
}
