<?php
/** Safe AOL-inspired room application renderer. @package ACL_Agent_Rooms */
namespace ACL\AgentRooms\Frontend;

if ( ! defined( 'ABSPATH' ) ) {
	exit; }
class RoomApp {
	public function render( array $room, array $agents, array $bootstrap ): string {
		$template = ACL_AR_DIR . 'templates/room-app.php';
		if ( ! is_readable( $template ) ) {
			return ''; }
		ob_start();
		require $template;
		return (string) ob_get_clean();
	}
}
