<?php
/** Agent room shortcode. @package ACL_Agent_Rooms */
namespace ACL\AgentRooms\Shortcodes;

use ACL\AgentRooms\Frontend\RoomApp;
use ACL\AgentRooms\Repositories\RoomRepository;
use ACL\AgentRooms\Services\AccessService;
use ACL\AgentRooms\Services\PollingMessageTransport;
use ACL\AgentRooms\Services\CommandRegistry;
use ACL\AgentRooms\Services\WhisperRecipientResolver;
use ACL\AgentRooms\Services\StorageBridge;
if ( ! defined( 'ABSPATH' ) ) {
	exit; }
class AgentRoomShortcode {
	private RoomRepository $rooms;
	private AccessService $access;
	public function __construct( ?RoomRepository $rooms = null, ?AccessService $access = null ) {
		$this->rooms  = $rooms ?: new RoomRepository();
		$this->access = $access ?: new AccessService( $this->rooms );}
	public function register(): void {
		add_shortcode( 'acl_agent_room', array( $this, 'render' ) );}
	public function render( array $atts = array() ): string {
		$atts = shortcode_atts( array( 'id' => 0 ), $atts, 'acl_agent_room' );
		if ( ! is_user_logged_in() ) {
			return '<div class="acl-ar-room-notice">' . esc_html__( 'Log in to view this agent room.', 'acl-agent-rooms' ) . '</div>';}
		$room_id = absint( $atts['id'] );
		$room    = $this->rooms->find( $room_id );
		if ( ! $room ) {
			return '<div class="acl-ar-room-notice">' . esc_html__( 'Agent room not found.', 'acl-agent-rooms' ) . '</div>';}
		if ( ! $this->access->can_access_room( $room_id ) ) {
			return '<div class="acl-ar-room-notice">' . esc_html__( 'You do not have access to this agent room.', 'acl-agent-rooms' ) . '</div>';}
		$agents    = $this->rooms->get_agents( $room_id );
		$transport = new PollingMessageTransport();
		$config    = $transport->config( $room );
		$user      = wp_get_current_user();
		$bootstrap = array(
			'v'                     => 1,
			'roomId'                => $room_id,
			'currentUserId'         => (int) $user->ID,
			'currentUserName'       => (string) $user->display_name,
			'restBase'              => untrailingslashit( rest_url( 'acl-agent-rooms/v1' ) ),
			'nonce'                 => wp_create_nonce( 'wp_rest' ),
			'eventEndpoint'         => '/rooms/' . $room_id . '/events',
			'legacyMessageEndpoint' => '/rooms/' . $room_id . '/messages',
			'pageSize'              => absint( $config['page_size'] ?? 50 ),
			'poll'                  => array(
				'active' => absint( $config['active_poll_delay'] ?? 3000 ),
				'idle'   => absint( $config['idle_poll_delay'] ?? 6000 ),
				'max'    => absint( $config['maximum_idle_delay'] ?? 24000 ),
			),
			'features'              => array(
				'events'              => true,
				'history'             => true,
				'legacy_send'         => true,
				'aol_ui'              => true,
				'presence'            => true,
				'agent_participation' => true,
				'search'              => true,
				'moderation'          => true,
				'clearChat'           => ! empty( $room['allow_chat_clear'] ),
				'roomFiles'           => ! empty( $room['room_files_enabled'] ) && ! empty( $room['room_files_agent_access'] ) && $this->access->can_write_room( $room_id, (int) $user->ID ) && ( new StorageBridge() )->available(),
			),
			'messageLimit'          => 12000,
			'room'                  => array(
				'id'                    => $room_id,
				'title'                 => (string) $room['title'],
				'description'           => wp_strip_all_tags( (string) ( $room['description'] ?: $room['top_context'] ) ),
				'type'                  => (string) $room['type'],
				'visibility'            => (string) $room['visibility'],
				'status'                => (string) $room['status'],
				'replyMode'             => (string) $room['agent_reply_mode'],
				'conversationMode'      => (string) $room['conversation_mode'],
				'allowChatClear'        => ! empty( $room['allow_chat_clear'] ),
				'roomFilesEnabled'      => ! empty( $room['room_files_enabled'] ),
				'fileContextMode'       => (string) $room['file_context_mode'],
				'fileContextMaxFiles'   => (int) $room['file_context_max_files'],
				'clearedThroughEventId' => max( 0, (int) ( $room['cleared_through_event_id'] ?? 0 ) ),
			),
			'agents'                => array_map(
				static fn( array $a ): array=>array(
					'id'                 => (int) $a['id'],
					'name'               => (string) $a['name'],
					'slug'               => (string) $a['slug'],
					'description'        => wp_strip_all_tags( (string) $a['description'] ),
					'avatarUrl'          => ! empty( $a['avatar_url'] ) ? esc_url_raw( (string) $a['avatar_url'] ) : null,
					'participationState' => (string) ( $a['participation_state'] ?? 'active' ),
					'autoMuted'          => ! empty( $a['auto_muted'] ),
					'activityState'      => 'ready',
				),
				$agents
			),
			'commands'              => ( new CommandRegistry() )->public_definitions(),
			'whisperRecipients'     => array_values( ( new WhisperRecipientResolver( $this->rooms, $this->access ) )->eligible( $room_id, (int) $user->ID ) ),
			'permissions'           => array(
				'canSend'               => $this->access->can_write_room( $room_id, (int) $user->ID ),
				'canManualReply'        => 'active' === (string) $room['status'] && 'auto' !== (string) $room['agent_reply_mode'],
				'canManageParticipants' => $this->access->can_manage_room( $room_id, (int) $user->ID ),
				'canModerate'           => $this->access->can_manage_room( $room_id, (int) $user->ID ),
				'canClearChat'          => ! empty( $room['allow_chat_clear'] ) && $this->access->can_manage_room( $room_id, (int) $user->ID ),
				'canSelectRoomFiles'    => ! empty( $room['room_files_enabled'] ) && ! empty( $room['room_files_agent_access'] ) && $this->access->can_write_room( $room_id, (int) $user->ID ) && ( new StorageBridge() )->available(),
			),
		);
		$this->enqueue_assets();
		return( new RoomApp() )->render( $room, $agents, $bootstrap );
	}
	private function enqueue_assets(): void {
		$asset_version = ACL_AR_VERSION . '-phase9';
		wp_enqueue_style( 'acl-agent-rooms-room', ACL_AR_URL . 'assets/css/room.css', array(), $asset_version );
		wp_enqueue_style( 'acl-agent-rooms-room-aol', ACL_AR_URL . 'assets/css/room-aol.css', array( 'acl-agent-rooms-room' ), $asset_version );
		$scripts  = array( 'utils', 'store', 'api', 'render-compat', 'sync', 'preferences', 'sound', 'dialogs', 'member-list', 'transcript', 'composer', 'toolbar', 'status-bar', 'app', 'interactions', 'commands', 'presence', 'agent-participation', 'search', 'moderation', 'room-files', 'clear-chat' );
		$previous = array();
		foreach ( $scripts as $script ) {
			$handle = 'acl-agent-rooms-room-' . $script;
			wp_enqueue_script( $handle, ACL_AR_URL . 'assets/js/room/' . $script . '.js', $previous, $asset_version, true );
			$previous = array( $handle );}
		wp_enqueue_script( 'acl-agent-rooms-room', ACL_AR_URL . 'assets/js/room.js', $previous, $asset_version, true );
	}
}
