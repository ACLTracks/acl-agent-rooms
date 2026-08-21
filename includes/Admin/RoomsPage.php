<?php
/**
 * Rooms admin page.
 *
 * @package ACL_Agent_Rooms
 */

namespace ACL\AgentRooms\Admin;

use ACL\AgentRooms\Capabilities;
use ACL\AgentRooms\Repositories\AgentRepository;
use ACL\AgentRooms\Repositories\RoomRepository;
use ACL\AgentRooms\Support\Arr;
use ACL\AgentRooms\Services\StorageBridge;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class RoomsPage {
	private RoomRepository $rooms;
	private AgentRepository $agents;

	public function __construct( ?RoomRepository $rooms = null, ?AgentRepository $agents = null ) {
		$this->rooms  = $rooms ?: new RoomRepository();
		$this->agents = $agents ?: new AgentRepository();
	}

	public function render(): void {
		if ( ! Capabilities::current_user_can( Capabilities::MANAGE_ALL_ROOMS ) ) {
			wp_die( esc_html__( 'You cannot manage rooms.', 'acl-agent-rooms' ) );
		}

		$editing = null;
		$missing = false;
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- This read-only filter changes no state.
		if ( isset( $_GET['action'], $_GET['id'] ) && 'edit' === sanitize_key( wp_unslash( $_GET['action'] ) ) ) {
			$editing = $this->rooms->find( absint( $_GET['id'] ) );
			$missing = ! $editing;
		}
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		$add_url = add_query_arg(
			array(
				'page'   => 'acl-agent-rooms-rooms',
				'action' => 'add',
			),
			admin_url( 'admin.php' )
		);

		echo '<div class="wrap acl-ar-admin">';
		echo '<h1 class="wp-heading-inline">' . esc_html__( 'ACL Agent Rooms - Rooms', 'acl-agent-rooms' ) . '</h1>';
		echo ' <a href="' . esc_url( $add_url ) . '" class="page-title-action">' . esc_html__( 'Add New Room', 'acl-agent-rooms' ) . '</a>';
		echo '<hr class="wp-header-end">';
		$this->notice();
		if ( $missing ) {
			echo '<div class="notice notice-error"><p>' . esc_html__( 'Room was not found. Create a new room or choose one from the list.', 'acl-agent-rooms' ) . '</p></div>';
		}
		echo '<div class="acl-ar-admin-grid">';
		$this->render_list();
		$this->render_form( $editing );
		echo '</div></div>';
	}

	public function process_request(): void {
		$has_request = ! empty( $_POST['acl_ar_room_action'] ) || ( isset( $_GET['acl_ar_action'], $_GET['id'] ) && 'delete_room' === sanitize_key( wp_unslash( $_GET['acl_ar_action'] ) ) );
		if ( ! $has_request || wp_doing_ajax() || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) ) {
			return;
		}
		if ( ! Capabilities::current_user_can( Capabilities::MANAGE_ALL_ROOMS ) ) {
			wp_die( esc_html__( 'You cannot manage rooms.', 'acl-agent-rooms' ) );
		}

		if ( isset( $_GET['acl_ar_action'], $_GET['id'] ) && 'delete_room' === sanitize_key( wp_unslash( $_GET['acl_ar_action'] ) ) ) {
			$id = absint( $_GET['id'] );
			check_admin_referer( 'acl_ar_delete_room_' . $id );
			if ( ! $this->rooms->find( $id ) ) {
				wp_safe_redirect( add_query_arg( 'acl_ar_notice', 'room_missing', admin_url( 'admin.php?page=acl-agent-rooms-rooms' ) ) );
				exit;
			}
			$deleted = $this->rooms->delete( $id );
			wp_safe_redirect( add_query_arg( 'acl_ar_notice', is_wp_error( $deleted ) ? 'room_error' : 'room_deleted', admin_url( 'admin.php?page=acl-agent-rooms-rooms' ) ) );
			exit;
		}

		if ( empty( $_POST['acl_ar_room_action'] ) ) {
			return;
		}

		check_admin_referer( 'acl_ar_save_room', 'acl_ar_room_nonce' );

		$id       = absint( $_POST['room_id'] ?? 0 );
		$existing = $id > 0 ? $this->rooms->find( $id ) : null;
		if ( $id > 0 && ! $existing ) {
			wp_safe_redirect( add_query_arg( 'acl_ar_notice', 'room_missing', admin_url( 'admin.php?page=acl-agent-rooms-rooms' ) ) );
			exit;
		}
		$posted_conversation_mode = sanitize_key( wp_unslash( $_POST['conversation_mode'] ?? 'immediate' ) );
		$storage_available        = ( new StorageBridge() )->available();
		$natural_initial_delay_min_seconds = isset( $_POST['natural_initial_delay_min_seconds'] ) ? sanitize_text_field( wp_unslash( $_POST['natural_initial_delay_min_seconds'] ) ) : null;
		$natural_initial_delay_max_seconds = isset( $_POST['natural_initial_delay_max_seconds'] ) ? sanitize_text_field( wp_unslash( $_POST['natural_initial_delay_max_seconds'] ) ) : null;
		$natural_inter_turn_delay_min_seconds = isset( $_POST['natural_inter_turn_delay_min_seconds'] ) ? sanitize_text_field( wp_unslash( $_POST['natural_inter_turn_delay_min_seconds'] ) ) : null;
		$natural_inter_turn_delay_max_seconds = isset( $_POST['natural_inter_turn_delay_max_seconds'] ) ? sanitize_text_field( wp_unslash( $_POST['natural_inter_turn_delay_max_seconds'] ) ) : null;
		$agent_ids = array_map( 'absint', (array) wp_unslash( $_POST['agent_ids'] ?? array() ) );
		$data                     = array(
			'owner_user_id'                         => (int) ( $existing['owner_user_id'] ?? get_current_user_id() ),
			'title'                                 => sanitize_text_field( wp_unslash( $_POST['title'] ?? '' ) ),
			'slug'                                  => sanitize_title( wp_unslash( $_POST['slug'] ?? '' ) ),
			'description'                           => wp_kses_post( wp_unslash( $_POST['description'] ?? '' ) ),
			'top_context'                           => wp_kses_post( wp_unslash( $_POST['top_context'] ?? '' ) ),
			'type'                                  => sanitize_key( wp_unslash( $_POST['type'] ?? 'solo' ) ),
			'visibility'                            => sanitize_key( wp_unslash( $_POST['visibility'] ?? 'private' ) ),
			'status'                                => sanitize_key( wp_unslash( $_POST['status'] ?? 'active' ) ),
			'agent_reply_mode'                      => sanitize_key( wp_unslash( $_POST['agent_reply_mode'] ?? 'manual' ) ),
			'max_context_messages'                  => absint( $_POST['max_context_messages'] ?? 20 ),
			'max_agents_per_turn'                   => absint( $_POST['max_agents_per_turn'] ?? 1 ),
			'conversation_mode'                     => $posted_conversation_mode,
			'natural_min_responders'                => absint( $_POST['natural_min_responders'] ?? ( $existing['natural_min_responders'] ?? 1 ) ),
			'natural_max_responders'                => absint( $_POST['natural_max_responders'] ?? ( $existing['natural_max_responders'] ?? 2 ) ),
			'natural_initial_delay_min_ms'          => null !== $natural_initial_delay_min_seconds ? $this->seconds_to_ms( $natural_initial_delay_min_seconds ) : (int) ( $existing['natural_initial_delay_min_ms'] ?? 1500 ),
			'natural_initial_delay_max_ms'          => null !== $natural_initial_delay_max_seconds ? $this->seconds_to_ms( $natural_initial_delay_max_seconds ) : (int) ( $existing['natural_initial_delay_max_ms'] ?? 4500 ),
			'natural_inter_turn_delay_min_ms'       => null !== $natural_inter_turn_delay_min_seconds ? $this->seconds_to_ms( $natural_inter_turn_delay_min_seconds ) : (int) ( $existing['natural_inter_turn_delay_min_ms'] ?? 2500 ),
			'natural_inter_turn_delay_max_ms'       => null !== $natural_inter_turn_delay_max_seconds ? $this->seconds_to_ms( $natural_inter_turn_delay_max_seconds ) : (int) ( $existing['natural_inter_turn_delay_max_ms'] ?? 8000 ),
			'natural_allow_silence'                 => 'natural' === $posted_conversation_mode ? ! empty( $_POST['natural_allow_silence'] ) : ! empty( $existing['natural_allow_silence'] ),
			'natural_silence_chance'                => absint( $_POST['natural_silence_chance'] ?? ( $existing['natural_silence_chance'] ?? 10 ) ),
			'natural_cancel_pending_on_new_message' => 'natural' === $posted_conversation_mode ? ! empty( $_POST['natural_cancel_pending_on_new_message'] ) : ! empty( $existing['natural_cancel_pending_on_new_message'] ?? true ),
			'natural_max_pending_turns'             => absint( $_POST['natural_max_pending_turns'] ?? ( $existing['natural_max_pending_turns'] ?? 4 ) ),
			'natural_steering_question_bias'        => absint( $_POST['natural_steering_question_bias'] ?? ( $existing['natural_steering_question_bias'] ?? 35 ) ),
			'allow_chat_clear'                      => ! empty( $_POST['allow_chat_clear'] ),
			'project_instructions'                  => sanitize_textarea_field( wp_unslash( $_POST['project_instructions'] ?? '' ) ),
			'room_files_enabled'                    => $storage_available ? ! empty( $_POST['room_files_enabled'] ) : ! empty( $existing['room_files_enabled'] ),
			'room_files_agent_access'               => $storage_available ? ! empty( $_POST['room_files_agent_access'] ) : ! empty( $existing['room_files_agent_access'] ),
			'file_context_mode'                     => sanitize_key( wp_unslash( $_POST['file_context_mode'] ?? ( $existing['file_context_mode'] ?? 'hybrid' ) ) ),
			'file_context_max_files'                => absint( $_POST['file_context_max_files'] ?? ( $existing['file_context_max_files'] ?? 5 ) ),
			'file_context_max_chars'                => absint( $_POST['file_context_max_chars'] ?? ( $existing['file_context_max_chars'] ?? 12000 ) ),
		);

		if ( '' === $data['slug'] ) {
			$data['slug'] = sanitize_title( $data['title'] );
		}

		$result = $id > 0 ? $this->rooms->update( $id, $data ) : $this->rooms->create( $data );
		if ( ! is_wp_error( $result ) ) {
			$room_id  = $id > 0 ? $id : (int) $result;
			$assigned = $this->rooms->assign_agents( $room_id, Arr::ids( $agent_ids ) );
			if ( is_wp_error( $assigned ) ) {
				$result = $assigned;
			}
		}

		$notice = is_wp_error( $result ) ? 'room_error' : 'room_saved';
		wp_safe_redirect( add_query_arg( 'acl_ar_notice', $notice, admin_url( 'admin.php?page=acl-agent-rooms-rooms' ) ) );
		exit;
	}

	private function notice(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- This read-only post-redirect notice changes no state.
		$notice   = isset( $_GET['acl_ar_notice'] ) ? sanitize_key( wp_unslash( $_GET['acl_ar_notice'] ) ) : '';
		$messages = array(
			'room_saved'   => __( 'Room saved.', 'acl-agent-rooms' ),
			'room_deleted' => __( 'Room deleted.', 'acl-agent-rooms' ),
			'room_error'   => __( 'Room could not be saved. Check the slug is unique.', 'acl-agent-rooms' ),
			'room_missing' => __( 'Room was not found.', 'acl-agent-rooms' ),
		);

		if ( isset( $messages[ $notice ] ) ) {
			$class = in_array( $notice, array( 'room_error', 'room_missing' ), true ) ? 'notice notice-error' : 'notice notice-success';
			echo '<div class="' . esc_attr( $class ) . '"><p>' . esc_html( $messages[ $notice ] ) . '</p></div>';
		}
	}

	private function render_list(): void {
		$rooms = $this->rooms->all();

		echo '<section class="acl-ar-panel">';
		echo '<h2>' . esc_html__( 'Rooms', 'acl-agent-rooms' ) . '</h2>';
		echo '<table class="widefat striped"><thead><tr>';
		echo '<th>' . esc_html__( 'Title', 'acl-agent-rooms' ) . '</th>';
		echo '<th>' . esc_html__( 'Type', 'acl-agent-rooms' ) . '</th>';
		echo '<th>' . esc_html__( 'Visibility', 'acl-agent-rooms' ) . '</th>';
		echo '<th>' . esc_html__( 'Status', 'acl-agent-rooms' ) . '</th>';
		echo '<th>' . esc_html__( 'Reply Mode', 'acl-agent-rooms' ) . '</th>';
		echo '<th>' . esc_html__( 'Agents', 'acl-agent-rooms' ) . '</th>';
		echo '<th>' . esc_html__( 'Shortcode', 'acl-agent-rooms' ) . '</th>';
		echo '<th>' . esc_html__( 'Actions', 'acl-agent-rooms' ) . '</th>';
		echo '</tr></thead><tbody>';

		if ( empty( $rooms ) ) {
			echo '<tr><td colspan="8" class="acl-ar-empty-cell"><p><strong>' . esc_html__( 'Create your first room', 'acl-agent-rooms' ) . '</strong></p><p class="description">' . esc_html__( 'Rooms hold conversations and define which agents can reply.', 'acl-agent-rooms' ) . '</p><p><a class="button button-primary" href="' . esc_url( admin_url( 'admin.php?page=acl-agent-rooms-rooms&action=add' ) ) . '">' . esc_html__( 'Create Room', 'acl-agent-rooms' ) . '</a></p></td></tr>';
		}

		foreach ( $rooms as $room ) {
			$assigned = $this->rooms->get_agents( (int) $room['id'] );
			$mentions = array_map(
				static function ( array $agent ): string {
					return '@' . (string) $agent['slug'];
				},
				$assigned
			);

			$edit_url   = add_query_arg(
				array(
					'page'   => 'acl-agent-rooms-rooms',
					'action' => 'edit',
					'id'     => (int) $room['id'],
				),
				admin_url( 'admin.php' )
			);
			$delete_url = wp_nonce_url(
				add_query_arg(
					array(
						'page'          => 'acl-agent-rooms-rooms',
						'acl_ar_action' => 'delete_room',
						'id'            => (int) $room['id'],
					),
					admin_url( 'admin.php' )
				),
				'acl_ar_delete_room_' . (int) $room['id']
			);

			echo '<tr>';
			echo '<td><strong>' . esc_html( $room['title'] ) . '</strong><br><code>' . esc_html( $room['slug'] ) . '</code></td>';
			echo '<td>' . esc_html( ucfirst( (string) $room['type'] ) ) . '</td>';
			echo '<td>' . esc_html( ucfirst( (string) $room['visibility'] ) ) . '</td>';
			echo '<td>' . esc_html( ucfirst( (string) $room['status'] ) ) . '</td>';
			echo '<td>' . esc_html( $this->reply_mode_label( (string) $room['agent_reply_mode'] ) ) . '</td>';
			echo '<td>' . esc_html( implode( ', ', $mentions ) ) . '</td>';
			$shortcode    = '[acl_agent_room id="' . (int) $room['id'] . '"]';
			$shortcode_id = 'acl-ar-shortcode-' . (int) $room['id'];
			echo '<td><div class="acl-ar-shortcode-copy"><input id="' . esc_attr( $shortcode_id ) . '" class="regular-text code acl-ar-copy-source" readonly value="' . esc_attr( $shortcode ) . '"><button type="button" class="button acl-ar-copy-button" data-acl-ar-copy="#' . esc_attr( $shortcode_id ) . '">' . esc_html__( 'Copy', 'acl-agent-rooms' ) . '</button><span class="acl-ar-copy-status" aria-live="polite"></span></div></td>';
			echo '<td><a href="' . esc_url( $edit_url ) . '">' . esc_html__( 'Edit', 'acl-agent-rooms' ) . '</a> | ';
			echo '<a class="acl-ar-danger" href="' . esc_url( $delete_url ) . '">' . esc_html__( 'Delete', 'acl-agent-rooms' ) . '</a></td>';
			echo '</tr>';
		}

		echo '</tbody></table></section>';
	}

	private function render_form( ?array $room ): void {
		$room = wp_parse_args(
			$room ?: array(),
			array(
				'id'                                    => 0,
				'title'                                 => '',
				'slug'                                  => '',
				'description'                           => '',
				'top_context'                           => '',
				'type'                                  => 'solo',
				'visibility'                            => 'private',
				'status'                                => 'active',
				'agent_reply_mode'                      => 'manual',
				'max_context_messages'                  => 20,
				'max_agents_per_turn'                   => 1,
				'conversation_mode'                     => 'immediate',
				'natural_min_responders'                => 1,
				'natural_max_responders'                => 2,
				'natural_initial_delay_min_ms'          => 1500,
				'natural_initial_delay_max_ms'          => 4500,
				'natural_inter_turn_delay_min_ms'       => 2500,
				'natural_inter_turn_delay_max_ms'       => 8000,
				'natural_allow_silence'                 => false,
				'natural_silence_chance'                => 10,
				'natural_cancel_pending_on_new_message' => true,
				'natural_max_pending_turns'             => 4,
				'natural_steering_question_bias'        => 35,
				'allow_chat_clear'                      => false,
				'project_instructions'                  => '',
				'room_files_enabled'                    => false,
				'room_files_agent_access'               => false,
				'file_context_mode'                     => 'hybrid',
				'file_context_max_files'                => 5,
				'file_context_max_chars'                => 12000,
			)
		);

		$assigned_ids = $room['id'] ? $this->rooms->get_agent_ids( (int) $room['id'] ) : array();
		$agents       = $this->agents->all( array( 'enabled' => true ) );

		echo '<section class="acl-ar-panel">';
		echo '<h2>' . esc_html( $room['id'] ? __( 'Edit Room', 'acl-agent-rooms' ) : __( 'Add Room', 'acl-agent-rooms' ) ) . '</h2>';
		echo '<form method="post">';
		wp_nonce_field( 'acl_ar_save_room', 'acl_ar_room_nonce' );
		echo '<input type="hidden" name="acl_ar_room_action" value="save">';
		echo '<input type="hidden" name="room_id" value="' . esc_attr( (string) $room['id'] ) . '">';
		echo '<table class="form-table" role="presentation"><tbody>';
		$this->text_row( 'title', __( 'Title', 'acl-agent-rooms' ), (string) $room['title'], true );
		$this->text_row( 'slug', __( 'Slug', 'acl-agent-rooms' ), (string) $room['slug'] );
		echo '<tr><th scope="row"><label for="description">' . esc_html__( 'Description', 'acl-agent-rooms' ) . '</label></th><td><textarea id="description" name="description" rows="3" class="large-text">' . esc_textarea( (string) $room['description'] ) . '</textarea></td></tr>';
		echo '<tr><th scope="row"><label for="top_context">' . esc_html__( 'Top Chat Text / Context', 'acl-agent-rooms' ) . '</label></th><td><textarea id="top_context" name="top_context" rows="5" class="large-text">' . esc_textarea( (string) $room['top_context'] ) . '</textarea><p class="description">' . esc_html__( 'Injected into the agent prompt as persistent room context, not saved as a user message.', 'acl-agent-rooms' ) . '</p></td></tr>';
		$this->select_row( 'type', __( 'Type', 'acl-agent-rooms' ), (string) $room['type'], array( 'solo', 'private', 'public' ) );
		$this->select_row( 'visibility', __( 'Visibility', 'acl-agent-rooms' ), (string) $room['visibility'], array( 'private', 'public' ) );
		$this->select_row( 'status', __( 'Status', 'acl-agent-rooms' ), (string) $room['status'], array( 'active', 'paused', 'archived' ) );
		$this->reply_mode_row( (string) $room['agent_reply_mode'] );
		echo '<tr><th scope="row"><label for="conversation_mode">' . esc_html__( 'Conversation style', 'acl-agent-rooms' ) . '</label></th><td><select id="conversation_mode" name="conversation_mode" data-acl-ar-conversation-mode><option value="immediate" ' . selected( (string) $room['conversation_mode'], 'immediate', false ) . '>' . esc_html__( 'Immediate', 'acl-agent-rooms' ) . '</option><option value="natural" ' . selected( (string) $room['conversation_mode'], 'natural', false ) . '>' . esc_html__( 'Natural', 'acl-agent-rooms' ) . '</option></select><p class="description">' . esc_html__( 'Natural style lets eligible agents intentionally stay silent and spaces selected replies several seconds apart.', 'acl-agent-rooms' ) . '</p></td></tr>';
		echo '<tr data-acl-ar-natural-room-row><th colspan="2"><h3>' . esc_html__( 'Natural Conversation', 'acl-agent-rooms' ) . '</h3><p class="description">' . esc_html__( 'The server selects participants locally. Unselected agents create no provider request or placeholder job.', 'acl-agent-rooms' ) . '</p></th></tr>';
		$this->number_row( 'natural_min_responders', __( 'Minimum responders', 'acl-agent-rooms' ), (float) $room['natural_min_responders'], 0, 10, 1, true );
		$this->number_row( 'natural_max_responders', __( 'Maximum responders', 'acl-agent-rooms' ), (float) $room['natural_max_responders'], 0, 10, 1, true );
		echo '<tr data-acl-ar-natural-room-row><th scope="row">' . esc_html__( 'Allow occasional silence', 'acl-agent-rooms' ) . '</th><td><label><input name="natural_allow_silence" type="checkbox" value="1" ' . checked( ! empty( $room['natural_allow_silence'] ), true, false ) . '> ' . esc_html__( 'Allow no automatic agent reply', 'acl-agent-rooms' ) . '</label></td></tr>';
		$this->number_row( 'natural_silence_chance', __( 'Silence chance (%)', 'acl-agent-rooms' ), (float) $room['natural_silence_chance'], 0, 100, 1, true );
		$this->number_row( 'natural_initial_delay_min_seconds', __( 'First-response delay minimum (seconds)', 'acl-agent-rooms' ), (float) $room['natural_initial_delay_min_ms'] / 1000, 0, 60, 0.1, true );
		$this->number_row( 'natural_initial_delay_max_seconds', __( 'First-response delay maximum (seconds)', 'acl-agent-rooms' ), (float) $room['natural_initial_delay_max_ms'] / 1000, 0, 60, 0.1, true );
		$this->number_row( 'natural_inter_turn_delay_min_seconds', __( 'Delay between speakers minimum (seconds)', 'acl-agent-rooms' ), (float) $room['natural_inter_turn_delay_min_ms'] / 1000, 0, 60, 0.1, true );
		$this->number_row( 'natural_inter_turn_delay_max_seconds', __( 'Delay between speakers maximum (seconds)', 'acl-agent-rooms' ), (float) $room['natural_inter_turn_delay_max_ms'] / 1000, 0, 60, 0.1, true );
		echo '<tr data-acl-ar-natural-room-row><th scope="row">' . esc_html__( 'Cancel pending replies', 'acl-agent-rooms' ) . '</th><td><label><input name="natural_cancel_pending_on_new_message" type="checkbox" value="1" ' . checked( ! empty( $room['natural_cancel_pending_on_new_message'] ), true, false ) . '> ' . esc_html__( 'Cancel unpublished replies when a new human message advances the conversation', 'acl-agent-rooms' ) . '</label></td></tr>';
		$this->number_row( 'natural_max_pending_turns', __( 'Maximum pending turns', 'acl-agent-rooms' ), (float) $room['natural_max_pending_turns'], 1, 10, 1, true );
		$this->number_row( 'natural_steering_question_bias', __( 'Steering-question tendency (%)', 'acl-agent-rooms' ), (float) $room['natural_steering_question_bias'], 0, 100, 1, true );
		echo '<tr><th scope="row"><label for="max_context_messages">' . esc_html__( 'Max Context Messages', 'acl-agent-rooms' ) . '</label></th><td><input id="max_context_messages" name="max_context_messages" type="number" min="1" value="' . esc_attr( (string) $room['max_context_messages'] ) . '"></td></tr>';
		echo '<tr><th scope="row"><label for="max_agents_per_turn">' . esc_html__( 'Max Agents Per Turn', 'acl-agent-rooms' ) . '</label></th><td><input id="max_agents_per_turn" name="max_agents_per_turn" type="number" min="1" value="' . esc_attr( (string) $room['max_agents_per_turn'] ) . '"></td></tr>';
		$storage = ( new StorageBridge() )->status();
		echo '<tr><th colspan="2"><h3>' . esc_html__( 'Project Context', 'acl-agent-rooms' ) . '</h3><p class="description">' . esc_html__( 'Persistent instructions and private room files shared across conversations. Direct chat uploads are not enabled.', 'acl-agent-rooms' ) . '</p></th></tr>';
		echo '<tr><th scope="row"><label for="project_instructions">' . esc_html__( 'Project instructions', 'acl-agent-rooms' ) . '</label></th><td><textarea id="project_instructions" name="project_instructions" rows="5" class="large-text">' . esc_textarea( (string) $room['project_instructions'] ) . '</textarea></td></tr>';
		$storage_disabled = empty( $storage['available'] );
		echo '<tr><th scope="row">' . esc_html__( 'Enable room files', 'acl-agent-rooms' ) . '</th><td><label><input name="room_files_enabled" type="checkbox" value="1" ' . checked( ! empty( $room['room_files_enabled'] ), true, false ) . disabled( $storage_disabled, true, false ) . '> ' . esc_html__( 'Enable a persistent private file library for this room', 'acl-agent-rooms' ) . '</label></td></tr>';
		echo '<tr><th scope="row">' . esc_html__( 'Agent file access', 'acl-agent-rooms' ) . '</th><td><label><input name="room_files_agent_access" type="checkbox" value="1" ' . checked( ! empty( $room['room_files_agent_access'] ), true, false ) . disabled( $storage_disabled, true, false ) . '> ' . esc_html__( 'Allow agents and Shared Brains to retrieve authorized file excerpts', 'acl-agent-rooms' ) . '</label></td></tr>';
		echo '<tr><th scope="row"><label for="file_context_mode">' . esc_html__( 'File-context mode', 'acl-agent-rooms' ) . '</label></th><td><select id="file_context_mode" name="file_context_mode"><option value="manual" ' . selected( $room['file_context_mode'], 'manual', false ) . '>' . esc_html__( 'Manual', 'acl-agent-rooms' ) . '</option><option value="automatic" ' . selected( $room['file_context_mode'], 'automatic', false ) . '>' . esc_html__( 'Automatic', 'acl-agent-rooms' ) . '</option><option value="hybrid" ' . selected( $room['file_context_mode'], 'hybrid', false ) . '>' . esc_html__( 'Hybrid (recommended)', 'acl-agent-rooms' ) . '</option></select></td></tr>';
		$this->number_row( 'file_context_max_files', __( 'Maximum files per request', 'acl-agent-rooms' ), (float) $room['file_context_max_files'], 1, 20, 1 );
		$this->number_row( 'file_context_max_chars', __( 'Maximum file-context characters', 'acl-agent-rooms' ), (float) $room['file_context_max_chars'], 1000, 100000, 500 );
		if ( $storage_disabled ) {
			echo '<tr><th scope="row">' . esc_html__( 'ACL Storage', 'acl-agent-rooms' ) . '</th><td><p class="notice notice-warning inline">' . esc_html__( 'Compatible ACL Storage is unavailable. Existing room behavior is unchanged and file controls are disabled.', 'acl-agent-rooms' ) . '</p></td></tr>'; }
		echo '<tr><th scope="row">' . esc_html__( 'Enable Clear Chat', 'acl-agent-rooms' ) . '</th><td><label><input id="allow_chat_clear" name="allow_chat_clear" type="checkbox" value="1" ' . checked( ! empty( $room['allow_chat_clear'] ), true, false ) . '> ' . esc_html__( 'Enable Clear Chat', 'acl-agent-rooms' ) . '</label><p class="description">' . esc_html__( 'Allows room managers to clear the visible room transcript for everyone. Earlier records remain preserved for audit and retention but are no longer shown or used as agent context.', 'acl-agent-rooms' ) . '</p></td></tr>';
		echo '<tr><th scope="row">' . esc_html__( 'Assigned Agents', 'acl-agent-rooms' ) . '</th><td>';
		if ( empty( $agents ) ) {
			echo '<p class="description">' . esc_html__( 'Create and enable an agent before assigning agents to this room.', 'acl-agent-rooms' ) . '</p>';
		} else {
			echo '<fieldset class="acl-ar-checkbox-list">';
			foreach ( $agents as $agent ) {
				echo '<label><input type="checkbox" name="agent_ids[]" value="' . esc_attr( (string) $agent['id'] ) . '" ' . checked( in_array( (int) $agent['id'], $assigned_ids, true ), true, false ) . '> ';
				if ( '' !== (string) ( $agent['avatar_url'] ?? '' ) ) {
					echo '<img class="acl-ar-agent-avatar acl-ar-agent-avatar-small" src="' . esc_url( (string) $agent['avatar_url'] ) . '" alt="' . esc_attr( (string) ( $agent['avatar_alt'] ?? $agent['name'] ?? '' ) ) . '"> ';
				}
				echo esc_html( $agent['name'] . ' (@' . $agent['slug'] . ')' ) . '</label>';
			}
			echo '</fieldset>';
		}
		echo '</td></tr>';
		echo '</tbody></table>';
		submit_button( $room['id'] ? __( 'Update Room', 'acl-agent-rooms' ) : __( 'Create Room', 'acl-agent-rooms' ) );
		echo '</form>';
		$this->render_room_files_panel( $room, $storage );
		echo '</section>';
	}

	private function render_room_files_panel( array $room, array $storage ): void {
		echo '<div class="acl-ar-room-files-admin" data-acl-ar-room-files-admin data-room-id="' . esc_attr( (string) $room['id'] ) . '" data-rest-base="' . esc_url( untrailingslashit( rest_url( 'acl-agent-rooms/v1' ) ) ) . '" data-nonce="' . esc_attr( wp_create_nonce( 'wp_rest' ) ) . '">';
		echo '<h3>' . esc_html__( 'Room Files', 'acl-agent-rooms' ) . '</h3>';
		if ( empty( $room['id'] ) ) {
			echo '<p class="description">' . esc_html__( 'Create the room first, then return here to add persistent files.', 'acl-agent-rooms' ) . '</p></div>';
			return; }
		if ( empty( $storage['available'] ) ) {
			echo '<p class="description">' . esc_html__( 'Room file controls are unavailable until a compatible ACL Storage version is active.', 'acl-agent-rooms' ) . '</p></div>';
			return; }
		echo '<div class="acl-ar-room-files-admin__actions"><label>' . esc_html__( 'Upload private text/code file', 'acl-agent-rooms' ) . ' <input type="file" data-room-file-upload></label><button type="button" class="button" data-room-file-upload-button>' . esc_html__( 'Upload', 'acl-agent-rooms' ) . '</button><label>' . esc_html__( 'Attach owned storage asset', 'acl-agent-rooms' ) . ' <select data-room-file-assets><option value="">' . esc_html__( 'Choose an asset', 'acl-agent-rooms' ) . '</option></select></label><button type="button" class="button" data-room-file-attach>' . esc_html__( 'Attach', 'acl-agent-rooms' ) . '</button></div>';
		echo '<p class="description">' . esc_html__( 'Removing a room association never deletes the ACL Storage asset. Storage deletion is a separate confirmed action.', 'acl-agent-rooms' ) . '</p><div data-room-file-status role="status" aria-live="polite"></div><div class="acl-ar-room-files-admin__list" data-room-file-list></div></div>';
	}

	private function text_row( string $name, string $label, string $value, bool $required = false ): void {
		echo '<tr><th scope="row"><label for="' . esc_attr( $name ) . '">' . esc_html( $label ) . '</label></th><td><input id="' . esc_attr( $name ) . '" name="' . esc_attr( $name ) . '" type="text" class="regular-text" value="' . esc_attr( $value ) . '"' . ( $required ? ' required' : '' ) . '></td></tr>';
	}
	private function number_row( string $name, string $label, float $value, float $min, float $max, float $step, bool $natural = false ): void {
		echo '<tr' . ( $natural ? ' data-acl-ar-natural-room-row' : '' ) . '><th scope="row"><label for="' . esc_attr( $name ) . '">' . esc_html( $label ) . '</label></th><td><input id="' . esc_attr( $name ) . '" name="' . esc_attr( $name ) . '" type="number" min="' . esc_attr( (string) $min ) . '" max="' . esc_attr( (string) $max ) . '" step="' . esc_attr( (string) $step ) . '" value="' . esc_attr( (string) $value ) . '"></td></tr>'; }
	private function seconds_to_ms( $value ): int {
		return (int) round( max( 0, min( 60, (float) wp_unslash( $value ) ) ) * 1000 ); }

	private function select_row( string $name, string $label, string $value, array $options ): void {
		echo '<tr><th scope="row"><label for="' . esc_attr( $name ) . '">' . esc_html( $label ) . '</label></th><td><select id="' . esc_attr( $name ) . '" name="' . esc_attr( $name ) . '">';
		foreach ( $options as $option ) {
			echo '<option value="' . esc_attr( $option ) . '" ' . selected( $value, $option, false ) . '>' . esc_html( ucfirst( (string) $option ) ) . '</option>';
		}
		echo '</select></td></tr>';
	}

	private function reply_mode_row( string $value ): void {
		$options = array(
			'manual' => __( 'Click-to-answer/manual', 'acl-agent-rooms' ),
			'auto'   => __( 'Auto-answer', 'acl-agent-rooms' ),
		);

		if ( in_array( $value, array( 'mention', 'slash' ), true ) ) {
			/* translators: %s: Legacy room reply mode. */
			$options[ $value ] = sprintf( __( 'Legacy: %s', 'acl-agent-rooms' ), ucfirst( $value ) );
		}

		echo '<tr><th scope="row"><label for="agent_reply_mode">' . esc_html__( 'Response Mode', 'acl-agent-rooms' ) . '</label></th><td><select id="agent_reply_mode" name="agent_reply_mode">';
		foreach ( $options as $option => $label ) {
			echo '<option value="' . esc_attr( $option ) . '" ' . selected( $value, $option, false ) . '>' . esc_html( $label ) . '</option>';
		}
		echo '</select></td></tr>';
	}

	private function reply_mode_label( string $value ): string {
		if ( 'auto' === $value ) {
			return __( 'Auto-answer', 'acl-agent-rooms' );
		}

		if ( 'mention' === $value || 'slash' === $value ) {
			/* translators: %s: Legacy room reply mode. */
			return sprintf( __( 'Legacy: %s', 'acl-agent-rooms' ), ucfirst( $value ) );
		}

		return __( 'Click-to-answer/manual', 'acl-agent-rooms' );
	}
}
