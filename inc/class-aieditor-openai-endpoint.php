<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly
}

/**
 * Class AIEditor_OpenAI_Endpoint
 * Handles custom REST API endpoints for the OpenAI API.
 */
class AIEditor_OpenAI_Endpoint extends WP_REST_Controller {

	/**
	 * Register all routes for the endpoint.
	 */
	public function register_routes() {
		register_rest_route(
			'ai-editor/v1',
			'/completions',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'openai_api' ),
				'permission_callback' => array( $this, 'openai_api_permissions_check' ),
				'args'                => array(
					'messages'  => array(
						'required' => true,
						'type'     => 'array',
					),
					'functions' => array(
						'required' => false,
						'type'     => 'array',
					),
				),
			)
		);
	}

	/**
	 * Handle the OpenAI API request.
	 *
	 * @param WP_REST_Request $request Full data about the request.
	 * @return WP_Error|WP_REST_Response
	 */
	public function openai_api( $request ) {
		// Get the parameters from the request.
		$messages  = $request->get_param( 'messages' );
		$functions = $request->get_param( 'functions' );

		// Get the plugin settings.
		$options = get_option( 'ai_editor_settings' );
		$api_key = $options['ai_editor_api_key'];
		$model   = $options['ai_editor_model'];

		// Determine the system message based on the model.
		$system_message = $this->get_system_message( $model );

		// Prepend the system message.
		array_unshift(
			$messages,
			array(
				'role'    => 'system',
				'content' => $system_message,
			)
		);

		// Set the GPT model.
		$model = $this->get_gpt_model( $model );

		// Check if API key is empty.
		if ( empty( $api_key ) ) {
			return new WP_Error( 'api_key_missing', __( 'Please enter your OpenAI API key in Settings -> AI Editor.', 'ai-editor' ), array( 'status' => 400 ) );
		}

		// Prepare the data for the OpenAI API.
		$data = array(
			'model'       => $model,
			'messages'    => $messages,
			'tools'       => $functions,
			'tool_choice' => 'auto',
		);

		// Make POST request to the OpenAI API.
		$response = wp_remote_post(
			'https://api.openai.com/v1/chat/completions',
			array(
				'headers' => array(
					'Content-Type'  => 'application/json',
					'Authorization' => 'Bearer ' . $api_key,
				),
				'body'    => wp_json_encode( $data ),
				'timeout' => 120, // Set a timeout for the request.
			)
		);

		// Check if the response is an error and handle it.
		if ( is_wp_error( $response ) ) {
			return new WP_Error( 'request_failed', $response->get_error_message(), array( 'status' => 500 ) );
		}

		// Decode the response body from JSON format.
		$body          = wp_remote_retrieve_body( $response );
		$response_data = json_decode( $body, true );

		// Check for JSON parsing errors.
		if ( JSON_ERROR_NONE !== json_last_error() ) {
			$json_error_msg = json_last_error_msg();
			return new WP_Error( 'json_error', __( 'JSON parsing error: ', 'ai-editor' ) . $json_error_msg, array( 'status' => 500 ) );
		}

		// Retrieve the HTTP status code.
		$http_status = wp_remote_retrieve_response_code( $response );

		// Handle the error if the status is not a success (200 OK).
		if ( 200 !== $http_status ) {
			if ( isset( $response_data['error']['code'] ) && 'model_not_found' === $response_data['error']['code'] ) {
				$error_message = __( 'You do not have access to this GPT model. Please select GPT-4-o mini (recommended) or GPT-3.5 from Settings -> AI Editor.', 'ai-editor' );
			} else {
				$error_message = isset( $response_data['error']['message'] ) ? $response_data['error']['message'] : __( 'Unknown error.', 'ai-editor' );
			}

			return new WP_REST_Response(
				array(
					'error'   => true,
					'message' => $error_message,
					'status'  => $http_status,
				),
				$http_status
			);
		}

		// Initialize the default response array.
		$response_array = array(
			'data' => $response_data,
		);

		// Combine all Gutenberg blocks.
		$combined_blocks = $this->combine_blocks( $response_data );

		// Initialize the unsupported blocks array.
		$not_supported = array();

		// Check for unsupported blocks if model is gpt-3.5-turbo.
		if ( 'gpt-3.5-turbo' === $model ) {
			$has_unsupported_block = false;

			if ( ! empty( $combined_blocks ) ) {
				$original_count  = count( $combined_blocks );
				$combined_blocks = $this->remove_unsupported_blocks( $combined_blocks, $not_supported, $has_unsupported_block );

				// If blocks are empty or count is less than original, unsupported blocks were removed.
				if ( empty( $combined_blocks ) || count( $combined_blocks ) < $original_count ) {
					$has_unsupported_block = true;
				}
			}

			if ( $has_unsupported_block ) {

				// Ensure unique values for not supported blocks.
				$not_supported = array_unique( $not_supported );

				// Check if all tool calls have been removed.
				if ( empty( $combined_blocks ) ) {
					// Prepare the response array for empty blocks cases.
					$response_array['error']   = true;
					$response_array['name']    = 'notSupported';
					$response_array['message'] = sprintf( __( 'The %s blocks are not supported by the current model. Switch to a GPT-4 model for this feature.', 'ai-editor' ), implode( ', ', $not_supported ) );
					return new WP_REST_Response( $response_array, 400 ); // Returning error response
				} else {
					// Prepare the response array for unsupported block cases.
					$response_array['warning'] = true;
					$response_array['message'] = sprintf( __( 'The %s block is not supported. Switch to a GPT-4 model for this feature.', 'ai-editor' ), implode( ', ', $not_supported ) );
				}
			}
		}

		// Update the response data, remove tool_calls, and add combined blocks if not empty.
		if ( isset( $response_data['choices'] ) ) {
			foreach ( $response_data['choices'] as &$choice ) {
				if ( isset( $choice['message']['tool_calls'] ) ) {
					unset( $choice['message']['tool_calls'] );
				}
				if ( ! empty( $combined_blocks ) ) {
					$choice['message']['blocks'] = $combined_blocks;
				}
			}
		}

		// Update the response array with the modified response data.
		$response_array['data'] = $response_data;

		// Return the successful response.
		return new WP_REST_Response( $response_array, 200 );
	}

	/**
	 * Get the GPT model based on the model identifier.
	 *
	 * @param string $model The model identifier.
	 * @return string The GPT model name.
	 */
	public function get_gpt_model( $model ) {
		switch ( $model ) {
			case 'm3':
				return 'gpt-3.5-turbo';
			case 'm4':
				return 'gpt-4-turbo';
			case 'm4om':
				return 'gpt-4o-mini';
			case 'm4o':
				return 'gpt-4o';
			default:
				return 'gpt-4o'; // Default model
		}
	}

	/**
	 * Get the system message based on the model.
	 *
	 * @param string $model The model identifier.
	 * @return string The system message.
	 */
	public function get_system_message( $model ) {
		$system_message = '';

		if ( 'm3' === $model ) {
			$system_message = __(
				'You are a helpful assistant tasked with inserting content as blocks in the WordPress Gutenberg editor when instructed by the user. Ask for clarification if a request is ambiguous. Use your available Gutenberg blocks (columns, headings, paragraphs, lists, images, buttons, quotes, pullquotes) to create organized layouts. Do not use Markdown markup language. Once a block is added, it cannot be edited.',
				'ai-editor'
			);
		} else {
			$system_message = __(
				'You are a helpful assistant tasked with inserting content as blocks in the WordPress Gutenberg editor. If the user does not provide specific content, use filler text. Create visually appealing and interactive layouts with your available Gutenberg blocks, using columns where appropriate. Once a block is added, it cannot be edited.',
				'ai-editor'
			);
		}

		return $system_message;
	}

	/**
	 * Combine all blocks from functions named 'create_gutenberg_blocks'.
	 *
	 * @param array $response_data The response data from the OpenAI API.
	 * @return array Combined Gutenberg blocks.
	 */
	public function combine_blocks( $response_data ) {
		$combined_blocks = array();

		if ( isset( $response_data['choices'] ) ) {
			foreach ( $response_data['choices'] as $choice ) {
				if ( isset( $choice['message']['tool_calls'] ) ) {
					foreach ( $choice['message']['tool_calls'] as $tool_call ) {
						if ( $tool_call['function']['name'] === 'create_gutenberg_blocks' ) {
							$arguments = json_decode( $tool_call['function']['arguments'], true );
							if ( isset( $arguments['blocks'] ) ) {
								$combined_blocks = array_merge( $combined_blocks, $arguments['blocks'] );
							}
						}
					}
				}
			}
		}

		return $combined_blocks;
	}

	/**
	 * Remove unsupported blocks recursively.
	 *
	 * @param array $blocks The blocks to filter.
	 * @param array &$not_supported The list of not supported blocks.
	 * @param bool  &$has_unsupported_block Flag indicating if there's an unsupported block.
	 * @return array The filtered blocks.
	 */
	public function remove_unsupported_blocks( $blocks, &$not_supported, &$has_unsupported_block ) {
		$filtered_blocks = array();

		foreach ( $blocks as $block ) {
			if ( isset( $block['blockType'] ) && $block['blockType'] === 'core/table' ) {
				$not_supported[]       = 'table';
				$has_unsupported_block = true;
			} else {
				if ( isset( $block['columnContent'] ) ) {
					$block['columnContent'] = array_map(
						function ( $column ) use ( &$not_supported, &$has_unsupported_block ) {
							return $this->remove_unsupported_blocks( $column, $not_supported, $has_unsupported_block );
						},
						$block['columnContent']
					);
				}
				$filtered_blocks[] = $block;
			}
		}

		return $filtered_blocks;
	}

	/**
	 * Check if the user has permission to access the endpoint.
	 *
	 * @param WP_REST_Request $request Full data about the request.
	 * @return bool
	 */
	public function openai_api_permissions_check( $request ) {
		return current_user_can( 'manage_options' );
	}
}

// Hook the class to the REST API.
add_action(
	'rest_api_init',
	function () {
		$ai_editor_endpoint = new AIEditor_OpenAI_Endpoint();
		$ai_editor_endpoint->register_routes();
	}
);
