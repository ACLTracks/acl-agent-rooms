<?php
/**
 * Dashboard admin page.
 *
 * @package ACL_Agent_Rooms
 */

namespace ACL\AgentRooms\Admin;

use ACL\AgentRooms\Capabilities;
use ACL\AgentRooms\Repositories\AgentRepository;
use ACL\AgentRooms\Repositories\RoomRepository;
use ACL\AgentRooms\Services\SwitchboardAdminService;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class DashboardPage {
	private AgentRepository $agents;
	private RoomRepository $rooms;
	private SwitchboardAdminService $switchboard;

	public function __construct( ?AgentRepository $agents = null, ?RoomRepository $rooms = null, ?SwitchboardAdminService $switchboard = null ) {
		$this->agents      = $agents ?: new AgentRepository();
		$this->rooms       = $rooms ?: new RoomRepository();
		$this->switchboard = $switchboard ?: new SwitchboardAdminService();
	}

	public function render(): void {
		if ( ! Capabilities::current_user_can( Capabilities::MANAGE_ALL_ROOMS ) ) {
			wp_die( esc_html__( 'You cannot manage agent rooms.', 'acl-agent-rooms' ) );
		}

		$status      = $this->switchboard->status();
		$agent_count = count( $this->agents->all() );
		$room_count  = count( $this->rooms->all() );

		echo '<div class="wrap acl-ar-admin">';
		echo '<h1>' . esc_html__( 'ACL Agent Rooms', 'acl-agent-rooms' ) . '</h1>';
		echo '<div class="acl-ar-dashboard-actions">';
		echo '<a class="button button-primary" href="' . esc_url( admin_url( 'admin.php?page=acl-agent-rooms-agents&action=add' ) ) . '">' . esc_html__( 'Create Agent', 'acl-agent-rooms' ) . '</a> ';
		echo '<a class="button" href="' . esc_url( admin_url( 'admin.php?page=acl-agent-rooms-rooms&action=add' ) ) . '">' . esc_html__( 'Create Room', 'acl-agent-rooms' ) . '</a> ';
		echo '<a class="button" href="' . esc_url( admin_url( 'admin.php?page=acl-agent-rooms-settings' ) ) . '">' . esc_html__( 'Open Settings', 'acl-agent-rooms' ) . '</a>';
		echo '</div>';
		echo '<div class="acl-ar-admin-grid">';
		$this->render_status_panel( $status, $agent_count, $room_count );
		$this->render_steps_panel();
		echo '</div></div>';
	}

	private function render_status_panel( array $status, int $agent_count, int $room_count ): void {
		echo '<section class="acl-ar-panel">';
		echo '<h2>' . esc_html__( 'Status', 'acl-agent-rooms' ) . '</h2>';

		if ( empty( $status['available'] ) || empty( $status['chat_callable'] ) ) {
			echo '<div class="notice notice-warning inline acl-ar-action-notice"><p><strong>' . esc_html__( 'Switchboard is not ready.', 'acl-agent-rooms' ) . '</strong> ';
			echo esc_html__( 'Activate and configure ACL Switchboard before expecting agents to answer. You can still create rooms and enter provider/model values manually.', 'acl-agent-rooms' ) . '</p></div>';
		}

		echo '<table class="widefat striped"><tbody>';
		$this->status_row( __( 'Switchboard available', 'acl-agent-rooms' ), ! empty( $status['available'] ) && ! empty( $status['chat_callable'] ) );
		$this->status_row( __( 'Provider discovery', 'acl-agent-rooms' ), ! empty( $status['provider_discovery'] ), sprintf( '%d', count( $status['providers'] ) ) );
		$this->status_row( __( 'Model discovery', 'acl-agent-rooms' ), ! empty( $status['model_discovery'] ), sprintf( '%d', count( $status['models'] ) ) );
		$this->status_row( __( 'Agents', 'acl-agent-rooms' ), $agent_count > 0, (string) $agent_count );
		$this->status_row( __( 'Rooms', 'acl-agent-rooms' ), $room_count > 0, (string) $room_count );
		echo '</tbody></table>';
		echo '<p class="description">' . esc_html__( 'Shortcode format:', 'acl-agent-rooms' ) . ' <code>[acl_agent_room id="123"]</code></p>';
		echo '</section>';
	}

	private function render_steps_panel(): void {
		echo '<section class="acl-ar-panel">';
		echo '<h2>' . esc_html__( 'Next Setup Steps', 'acl-agent-rooms' ) . '</h2>';
		echo '<ol class="acl-ar-steps">';
		echo '<li>' . esc_html__( 'Confirm ACL Switchboard is active and provider discovery works.', 'acl-agent-rooms' ) . '</li>';
		echo '<li>' . esc_html__( 'Create a Shared Brain for one-call orchestration, or create an agent that uses independent or legacy shared runtime settings.', 'acl-agent-rooms' ) . '</li>';
		echo '<li>' . esc_html__( 'Create a solo, private, or public room, add top chat context, choose manual or auto-answer mode, and assign enabled agents.', 'acl-agent-rooms' ) . '</li>';
		echo '<li>' . esc_html__( 'Place the room shortcode on a page available to logged-in users with room access.', 'acl-agent-rooms' ) . '</li>';
		echo '<li>' . esc_html__( 'Send a room message, then generate manual agent replies or let auto-answer mode run assigned agents.', 'acl-agent-rooms' ) . '</li>';
		echo '</ol>';
		echo '</section>';
	}

	private function status_row( string $label, bool $ok, string $detail = '' ): void {
		$value = $ok ? __( 'Yes', 'acl-agent-rooms' ) : __( 'No', 'acl-agent-rooms' );
		if ( '' !== $detail ) {
			$value .= ' - ' . $detail;
		}

		echo '<tr><th>' . esc_html( $label ) . '</th><td>' . esc_html( $value ) . '</td></tr>';
	}
}
