<?php
/** Operational health admin page. @package ACL_Agent_Rooms */
namespace ACL\AgentRooms\Admin;

use ACL\AgentRooms\Capabilities;
use ACL\AgentRooms\Services\HealthService;
if ( ! defined( 'ABSPATH' ) ) {
	exit;}
class HealthPage {
	public function render(): void {
		if ( ! Capabilities::current_user_can( Capabilities::MANAGE_SETTINGS ) ) {
			wp_die( esc_html__( 'You cannot view Agent Rooms health.', 'acl-agent-rooms' ) );
		}$health = ( new HealthService() )->snapshot();
		echo '<div class="wrap acl-ar-admin"><h1>' . esc_html__( 'Agent Rooms Health', 'acl-agent-rooms' ) . '</h1><p>' . esc_html__( 'Private operational metrics only; scheduled response content, file content, and credentials are never shown.', 'acl-agent-rooms' ) . '</p><table class="widefat striped"><tbody>';
		foreach ( array(
			'status'              => 'Status',
			'version'             => 'Plugin version',
			'db_version'          => 'Database version',
			'active_restrictions' => 'Active restrictions',
			'retention_days'      => 'Retention days',
		) as $key => $label ) {
			echo '<tr><th>' . esc_html( $label ) . '</th><td>' . esc_html( (string) $health[ $key ] ) . '</td></tr>';
		}foreach ( $health['counts'] as $key => $count ) {
			echo '<tr><th>' . esc_html( ucwords( str_replace( '_', ' ', $key ) ) ) . '</th><td>' . esc_html( (string) $count ) . '</td></tr>';
		}foreach ( $health['conversation_turns'] as $key => $count ) {
			echo '<tr><th>' . esc_html__( 'Conversation turns: ', 'acl-agent-rooms' ) . esc_html( ucwords( str_replace( '_', ' ', $key ) ) ) . '</th><td>' . esc_html( (string) $count ) . '</td></tr>';
		}foreach ( $health['room_files'] as $key => $value ) {
			if ( is_array( $value ) ) {
				$value = wp_json_encode( $value );
			}echo '<tr><th>' . esc_html__( 'Room files: ', 'acl-agent-rooms' ) . esc_html( ucwords( str_replace( '_', ' ', $key ) ) ) . '</th><td>' . esc_html( (string) $value ) . '</td></tr>';
		}echo '</tbody></table></div>';}
}
