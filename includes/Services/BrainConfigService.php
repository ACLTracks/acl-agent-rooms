<?php
/** Shared Brain provider/model validation and runtime resolution. @package ACL_Agent_Rooms */
namespace ACL\AgentRooms\Services;

use ACL\AgentRooms\Repositories\BrainRepository;

if ( ! defined( 'ABSPATH' ) ) {
	exit; }

class BrainConfigService {
	private BrainRepository $brains;
	private SwitchboardAdminService $switchboard;
	public function __construct( ?BrainRepository $brains = null, ?SwitchboardAdminService $switchboard = null ) {
		$this->brains      = $brains ?: new BrainRepository();
		$this->switchboard = $switchboard ?: new SwitchboardAdminService(); }
	public function validate_pair( string $provider, string $model ) {
		$provider = sanitize_text_field( $provider );
		$model    = sanitize_text_field( $model );
		if ( '' === $provider || '' === $model ) {
			return new \WP_Error( 'acl_ar_brain_model_required', __( 'Choose a Brain provider and model.', 'acl-agent-rooms' ), array( 'status' => 400 ) );}
		$override = apply_filters( 'acl_ar_brain_provider_model_valid', null, $provider, $model );
		if ( is_bool( $override ) ) {
			return $override ? true : new \WP_Error( 'acl_ar_brain_model_unavailable', __( 'That Brain provider/model is not available for chat.', 'acl-agent-rooms' ), array( 'status' => 400 ) );}
		$status = $this->switchboard->status();
		foreach ( (array) $status['models'] as $candidate ) {
			if ( (string) ( $candidate['provider'] ?? '' ) === $provider && (string) ( $candidate['model'] ?? '' ) === $model ) {
				return true;}
		}
		return new \WP_Error( 'acl_ar_brain_model_unavailable', __( 'That Brain provider/model is not currently available for chat.', 'acl-agent-rooms' ), array( 'status' => 400 ) );
	}
	public function runtime( int $brain_id ) {
		$brain = $this->brains->find( $brain_id );
		if ( ! $brain ) {
			return new \WP_Error( 'acl_ar_brain_missing', __( 'The assigned Shared Brain no longer exists.', 'acl-agent-rooms' ), array( 'status' => 422 ) );
		}if ( empty( $brain['enabled'] ) ) {
			return new \WP_Error( 'acl_ar_brain_disabled', __( 'The assigned Shared Brain is disabled.', 'acl-agent-rooms' ), array( 'status' => 423 ) );
		}$valid = $this->validate_pair( (string) $brain['provider'], (string) $brain['model'] );
		return is_wp_error( $valid ) ? $valid : $brain; }
	public function agent_availability( array $agent ) {
		if ( 'brain' !== (string) ( $agent['execution_mode'] ?? 'independent' ) ) {
			return true;
		}if ( empty( $agent['brain_id'] ) ) {
			return new \WP_Error( 'acl_ar_brain_required', __( 'This agent needs an enabled Shared Brain.', 'acl-agent-rooms' ) );
		}return $this->runtime( (int) $agent['brain_id'] ); }
}
