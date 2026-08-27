<?php
/** Batched safe public room-event projection. @package ACL_Agent_Rooms */
namespace ACL\AgentRooms\Services;

use ACL\AgentRooms\Repositories\AgentRepository;
use ACL\AgentRooms\Repositories\EventRepository;
use ACL\AgentRooms\Repositories\ReactionRepository;
if ( ! defined( 'ABSPATH' ) ) {
	exit;}
class EventProjectionService {
	private AgentRepository $agents;
	private EventRepository $events;
	private ReactionRepository $reactions;
	public function __construct( ?AgentRepository $agents = null, ?EventRepository $events = null, ?ReactionRepository $reactions = null ) {
		$this->agents    = $agents ?: new AgentRepository();
		$this->events    = $events ?: new EventRepository();
		$this->reactions = $reactions ?: new ReactionRepository();}
	public function project_page( array $events, bool $can_manage = false, int $user_id = 0 ): array {
		$message_ids = array();
		$parent_ids  = array();
		$user_ids    = array();
		$agent_ids   = array();
		foreach ( $events as $e ) {
			if ( in_array( (string) ( $e['event_type'] ?? '' ), array( 'message', 'system_notice' ), true ) ) {
				$message_ids[] = (int) $e['id'];
			}if ( ! empty( $e['parent_event_id'] ) ) {
				$parent_ids[] = (int) $e['parent_event_id'];
			}$this->collect_actor( $e['actor_type'] ?? '', (int) ( $e['actor_id'] ?? 0 ), $user_ids, $agent_ids );
			$this->collect_actor( $e['target_type'] ?? '', (int) ( $e['target_id'] ?? 0 ), $user_ids, $agent_ids );
			if ( 'whisper' === ( $e['event_type'] ?? '' ) && ! empty( $e['audience_id'] ) ) {
				$user_ids[] = (int) $e['audience_id'];}
		}
		$parents      = $this->events->find_many( $parent_ids );
		$edits        = $this->events->latest_edits_for( array_values( array_unique( array_merge( $message_ids, $parent_ids ) ) ) );
		$reaction_map = $this->reactions->summaries( $message_ids, $user_id );
		foreach ( array_merge( array_values( $parents ), array_values( $edits ) ) as $e ) {
			$this->collect_actor( $e['actor_type'] ?? '', (int) ( $e['actor_id'] ?? 0 ), $user_ids, $agent_ids );}
		$users   = $this->load_users( array_values( array_unique( $user_ids ) ) );
		$agents  = $this->agents->find_many( array_values( array_unique( $agent_ids ) ) );
		$allowed = MessageInteractionPolicy::reactions();
		return array_map( fn( $e )=>$this->project( $e, $users, $agents, $parents, $edits, $reaction_map, $allowed, $can_manage, $user_id ), $events );
	}
	private function collect_actor( string $type, int $id, array &$users, array &$agents ): void {
		if ( ! $id ) {
			return;
		}if ( 'user' === $type ) {
			$users[] = $id;
		} elseif ( 'agent' === $type ) {
			$agents[] = $id;}}
	private function project( array $event, array $users, array $agents, array $parents, array $edits, array $reaction_map, array $allowed, bool $can_manage, int $user_id ): array {
		$id        = (int) $event['id'];
		$type      = (string) $event['event_type'];
		$deleted   = ! empty( $event['deleted_at'] );
		$content   = $deleted ? ModerationService::PLACEHOLDER : (string) $event['content'];
		$edited    = false;
		$edited_at = null;
		if ( ! $deleted && isset( $edits[ $id ] ) ) {
			$content   = (string) $edits[ $id ]['content'];
			$edited    = true;
			$edited_at = $this->rfc3339( (string) $edits[ $id ]['created_at'] );}
		$result = array(
			'id'              => $id,
			'room_id'         => (int) $event['room_id'],
			'type'            => $type,
			'actor'           => $this->actor( (string) $event['actor_type'], $event['actor_id'], $users, $agents ),
			'target'          => empty( $event['target_type'] ) ? null : $this->actor( (string) $event['target_type'], $event['target_id'], $users, $agents ),
			'audience'        => array( 'type' => (string) $event['audience_type'] ),
			'parent_event_id' => $event['parent_event_id'],
			'content'         => $content,
			'content_format'  => 'plain',
			'metadata'        => $this->public_metadata( (array) $event['metadata'], $type ),
			'created_at'      => $this->rfc3339( (string) $event['created_at'] ),
			'edited_at'       => $edited_at ?: ( $event['edited_at'] ? $this->rfc3339( (string) $event['edited_at'] ) : null ),
			'deleted_at'      => $event['deleted_at'] ? $this->rfc3339( (string) $event['deleted_at'] ) : null,
		);
		if ( in_array( $type, array( 'message', 'system_notice' ), true ) ) {
			$result['edited']   = $edited;
			$result['reply_to'] = null;
			if ( ! empty( $event['parent_event_id'] ) ) {
				$parent  = $parents[ (int) $event['parent_event_id'] ] ?? null;
				$visible = $parent && ! ( new RoomCutoffPolicy() )->event_is_cleared( $parent ) && empty( $parent['deleted_at'] ) && ( (string) $parent['audience_type'] === 'room' || ( (string) $parent['audience_type'] === 'user' && (int) $parent['audience_id'] === $user_id ) || $can_manage );
				if ( $visible && in_array( (string) $parent['event_type'], array( 'message', 'system_notice' ), true ) ) {
					$parent_content     = isset( $edits[ (int) $parent['id'] ] ) ? (string) $edits[ (int) $parent['id'] ]['content'] : (string) $parent['content'];
					$result['reply_to'] = array(
						'event_id'    => (int) $parent['id'],
						'actor'       => $this->actor( (string) $parent['actor_type'], $parent['actor_id'], $users, $agents ),
						'excerpt'     => wp_html_excerpt( wp_strip_all_tags( $parent_content ), 140, '…' ),
						'unavailable' => false,
					);
				} else {
					$result['reply_to'] = array(
						'event_id'    => (int) $event['parent_event_id'],
						'actor'       => null,
						'excerpt'     => '',
						'unavailable' => true,
					);
				}
			}$rows = $reaction_map[ $id ] ?? array();
			usort( $rows, static fn( $a, $b )=>array_search( $a['reaction'], $allowed, true ) <=> array_search( $b['reaction'], $allowed, true ) );
			$result['reactions'] = $rows;}
		if ( 'whisper' === $type ) {
			$incoming          = (int) $event['audience_id'] === $user_id;
			$counterpart       = $incoming ? $this->actor( 'user', $event['actor_id'], $users, $agents ) : $this->actor( 'user', $event['audience_id'], $users, $agents );
			$result['whisper'] = array(
				'direction'   => $incoming ? 'incoming' : ( $can_manage && (int) $event['actor_id'] !== $user_id ? 'manager' : 'outgoing' ),
				'counterpart' => $counterpart,
			);}
		if ( $can_manage ) {
			$result['diagnostics'] = $this->diagnostics( (array) $event['metadata'] );
			if ( 'message' === $type ) {
				$result['moderation'] = array( 'can_remove' => empty( $event['deleted_at'] ) );
			}
		}return $result;
	}
	private function actor( string $type, $id, array $users, array $agents ): array {
		$id = $id ? (int) $id : null;
		if ( 'user' === $type ) {
			$u = $id && isset( $users[ $id ] ) ? $users[ $id ] : null;
			return array(
				'type'       => 'user',
				'id'         => $id,
				'name'       => $u ? (string) $u['name'] : __( 'Former user', 'acl-agent-rooms' ),
				'avatar_url' => $u['avatar_url'] ?? null,
			);
		}if ( 'agent' === $type ) {
			$a = $id && isset( $agents[ $id ] ) ? $agents[ $id ] : null;
			return array(
				'type'       => 'agent',
				'id'         => $id,
				'name'       => $a ? (string) $a['name'] : __( 'Former agent', 'acl-agent-rooms' ),
				'avatar_url' => $a && ! empty( $a['avatar_url'] ) ? esc_url_raw( (string) $a['avatar_url'] ) : null,
			);
		}return array(
			'type'       => 'system',
			'id'         => null,
			'name'       => __( 'Room system', 'acl-agent-rooms' ),
			'avatar_url' => null,
		);}
	private function load_users( array $ids ): array {
		if ( ! $ids ) {
			return array();
		}$rows = get_users(
			array(
				'include' => $ids,
				'fields'  => array( 'ID', 'display_name' ),
			)
		);
		$out   = array();
		foreach ( $rows as $u ) {
			$out[ (int) $u->ID ] = array(
				'name'       => (string) $u->display_name,
				'avatar_url' => get_avatar_url( (int) $u->ID, array( 'size' => 96 ) ) ?: null,
			);
		}return $out;}
	private function public_metadata( array $m, string $type ): array {
		$out = array();
		if ( 'room_clear' === $type ) {
			return array( 'cleared_through_event_id' => absint( $m['cleared_through_event_id'] ?? 0 ) );
		}if ( 'presence_change' === $type ) {
			return array(
				'participation_state' => in_array( ( $m['participation_state'] ?? '' ), array( 'active', 'paused' ), true ) ? $m['participation_state'] : 'active',
				'auto_muted'          => ! empty( $m['auto_muted'] ),
			);
		}if ( 'brain_run' === $type ) {
			$out = array(
				'status'         => sanitize_key( (string) ( $m['status'] ?? '' ) ),
				'agent_ids'      => array_values( array_unique( array_filter( array_map( 'absint', (array) ( $m['agent_ids'] ?? array() ) ) ) ) ),
				'response_count' => absint( $m['response_count'] ?? 0 ),
			);
			if ( 'failed' === $out['status'] ) {
				$out['retryable'] = ! empty( $m['retryable'] );
				$out['error']     = PublicError::message( (string) ( $m['error'] ?? '' ), __( 'Shared Brain reply failed.', 'acl-agent-rooms' ) );
			}
			return $out;
		}if ( isset( $m['status'] ) ) {
			$out['status'] = sanitize_key( (string) $m['status'] );
		}if ( isset( $m['retryable'] ) ) {
			$out['retryable'] = (bool) $m['retryable'];
		}if ( 'agent_failed' === $type && isset( $m['error'] ) ) {
			$out['error'] = PublicError::message( (string) $m['error'] );
		}if ( 'reaction' === $type && in_array( $m['reaction'] ?? '', MessageInteractionPolicy::reactions(), true ) ) {
			$out['reaction']  = $m['reaction'];
			$out['operation'] = 'remove' === ( $m['operation'] ?? '' ) ? 'remove' : 'add';
		}if ( 'dice_roll' === $type ) {
			$out = array(
				'notation' => sanitize_text_field( (string) ( $m['notation'] ?? '' ) ),
				'count'    => (int) ( $m['count'] ?? 0 ),
				'sides'    => (int) ( $m['sides'] ?? 0 ),
				'modifier' => (int) ( $m['modifier'] ?? 0 ),
				'rolls'    => array_values( array_map( 'intval', (array) ( $m['rolls'] ?? array() ) ) ),
				'subtotal' => (int) ( $m['subtotal'] ?? 0 ),
				'total'    => (int) ( $m['total'] ?? 0 ),
			);
		}if ( 'coin_flip' === $type && in_array( $m['result'] ?? '', array( 'heads', 'tails' ), true ) ) {
			$out = array( 'result' => $m['result'] );
		}return $out;}
	private function diagnostics( array $m ): array {
		$allowed = array( 'status', 'stage', 'error_code', 'result_status', 'provider_route', 'model', 'prompt_tokens', 'completion_tokens', 'total_tokens', 'finish_reason', 'attempts', 'retryable', 'response_message_id', 'error' );
		$out     = array();
		foreach ( $allowed as $k ) {
			if ( ! array_key_exists( $k, $m ) || is_array( $m[ $k ] ) || is_object( $m[ $k ] ) ) {
				continue;
			}$out[ $k ] = 'error' === $k ? PublicError::message( (string) $m[ $k ] ) : ( is_string( $m[ $k ] ) ? sanitize_text_field( $m[ $k ] ) : $m[ $k ] );
		}return $out;}
	private function rfc3339( string $mysql ): string {
		$t = strtotime( $mysql . ' UTC' );
		return $t ? gmdate( 'c', $t ) : '';}
}
