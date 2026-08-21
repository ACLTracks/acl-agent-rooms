<?php
/** Canonical event search indexing. @package ACL_Agent_Rooms */
namespace ACL\AgentRooms\Services;

use ACL\AgentRooms\Repositories\EventSearchRepository;

if ( ! defined( 'ABSPATH' ) ) {
	exit; }

class EventSearchIndexer {
	private EventSearchRepository $search;

	public function __construct( ?EventSearchRepository $search = null ) {
		$this->search = $search ?: new EventSearchRepository();
	}

	public function index( array $event ): bool {
		return $this->index_with_content( $event, (string) ( $event['content'] ?? '' ) );
	}

	public function index_with_content( array $event, string $content ): bool {
		$event_id = (int) ( $event['id'] ?? 0 );
		if ( ! in_array( (string) ( $event['event_type'] ?? '' ), array( 'message', 'system_notice', 'action' ), true ) || ! empty( $event['deleted_at'] ) ) {
			return $this->delete( $event_id );
		}

		$text = trim( wp_strip_all_tags( $content ) );
		if ( '' === $text ) {
			return $this->delete( $event_id );
		}

		return $this->search->upsert(
			$event_id,
			(int) $event['room_id'],
			$text,
			(string) ( $event['actor_type'] ?? '' ),
			(string) $event['created_at']
		);
	}

	public function delete( int $event_id ): bool {
		return $event_id <= 0 || $this->search->delete( $event_id );
	}
}
