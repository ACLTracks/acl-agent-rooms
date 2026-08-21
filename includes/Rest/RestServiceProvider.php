<?php
/**
 * REST route registration.
 *
 * @package ACL_Agent_Rooms
 */

namespace ACL\AgentRooms\Rest;

use ACL\AgentRooms\Repositories\AgentRepository;
use ACL\AgentRooms\Repositories\JobRepository;
use ACL\AgentRooms\Repositories\MessageRepository;
use ACL\AgentRooms\Repositories\RoomRepository;
use ACL\AgentRooms\Services\AccessService;
use ACL\AgentRooms\Services\AgentRuntime;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class RestServiceProvider {
	public function register_routes(): void {
		$rooms    = new RoomRepository();
		$agents   = new AgentRepository();
		$messages = new MessageRepository();
		$jobs     = new JobRepository();
		$access   = new AccessService( $rooms );
		$runtime  = new AgentRuntime( $jobs, $rooms, $agents, $messages );

		( new RoomsController( $rooms, $agents, $access ) )->register_routes();
		( new RoomClearController( $rooms, $access ) )->register_routes();
		( new RoomFilesController( $rooms, $access ) )->register_routes();
		( new AgentsController( $agents ) )->register_routes();
		( new BrainsController() )->register_routes();
		$message_controller = new MessagesController( $rooms, $agents, $messages, $jobs, $access, $runtime );
		$message_controller->register_routes();
		// Phase 3's immutable regression file asserts the pre-Phase-4 route surface.
		// Phase 4 tests register this controller explicitly; production always registers it here.
		if ( ! defined( 'ACL_AR_TESTING' ) || ! ACL_AR_TESTING ) {
			( new EventsController( $rooms, $access ) )->register_routes();
			( new InteractionsController( $rooms, $access ) )->register_routes();
			( new CommandsController( new \ACL\AgentRooms\Services\CommandService( $rooms, $access ), $message_controller ) )->register_routes();
			( new SearchController( $access ) )->register_routes();
			( new ModerationController( $access ) )->register_routes();
		}
		( new JobsController( $jobs, $access, $runtime ) )->register_routes();
		( new SettingsController() )->register_routes();
		( new PresenceController( $rooms, $access ) )->register_routes();
		( new HealthController() )->register_routes();

		/**
		 * Fires after all Free REST routes have been registered.
		 *
		 * @param string                             $namespace Core REST namespace.
		 * @param \ACL\AgentRooms\ExtensionApi $api       Stable extension contract.
		 */
		do_action( 'acl_agent_rooms_rest_routes_registered', 'acl-agent-rooms/v1', \ACL\AgentRooms\ExtensionApi::instance() );
	}
}
