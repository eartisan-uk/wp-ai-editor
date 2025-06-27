<?php
if ( ! defined( 'ABSPATH' ) ) exit; // Exit if accessed directly

/**
 * Class AIEditor_Settings_Endpoint
 * Handles custom REST API endpoints for the AI Editor plugin settings.
 */
class AIEditor_Settings_Endpoint extends WP_REST_Controller {

	/**
	 * Register all routes for the endpoint.
	 */
	public function register_routes() {
		register_rest_route(
			'ai-editor/v1',
			'/settings',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'get_plugin_settings' ),
				'permission_callback' => array( $this, 'permissions_check' ),
			)
		);
	}

	/**
	 * Handle the request to get plugin settings.
	 *
	 * @param WP_REST_Request $request Full data about the request.
	 * @return WP_Error|WP_REST_Response
	 */
	public function get_plugin_settings( $request ) {

		// Get the plugin settings.
		$options = get_option( 'ai_editor_settings' );

		// Check if the settings are retrieved successfully.
		if ( false === $options ) {
			return new WP_Error( 'settings_not_found', __( 'Please set up AI Editor in Settings -> AI Editor.', 'ai-editor' ), array( 'status' => 404 ) );
		}

		// Extract the settings.
		$settings = array(
			'ai_editor_model' => isset( $options['ai_editor_model'] ) ? $options['ai_editor_model'] : 'm4o',
		);

		// Return the settings in the response.
		return new WP_REST_Response( $settings, 200 );
	}

	/**
	 * Check if the user has permission to access the endpoint.
	 *
	 * @param WP_REST_Request $request Full data about the request.
	 * @return bool
	 */
	public function permissions_check( $request ) {
		return current_user_can( 'manage_options' );
	}
}

// Hook the class to the REST API.
add_action(
	'rest_api_init',
	function () {
		$ai_editor_settings_endpoint = new AIEditor_Settings_Endpoint();
		$ai_editor_settings_endpoint->register_routes();
	}
);
