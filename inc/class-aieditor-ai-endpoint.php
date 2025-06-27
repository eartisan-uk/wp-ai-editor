<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly
}

/**
 * Class AIEditor_AI_Endpoint
 * Handles custom REST API endpoints for various AI APIs.
 */
class AIEditor_AI_Endpoint extends WP_REST_Controller {

	/**
	 * Register all routes for the endpoint.
	 */
	public function register_routes() {
		register_rest_route(
			'ai-editor/v1',
			'/completions',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'handle_ai_request' ),
				'permission_callback' => array( $this, 'permission_check' ),
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
	 * Handle the AI API request.
	 *
	 * @param WP_REST_Request $request Full data about the request.
	 * @return WP_Error|WP_REST_Response
	 */
	public function handle_ai_request( $request ) {
		// Get the parameters from the request.
		$messages  = $request->get_param( 'messages' );
		$functions = $request->get_param( 'functions' );

		// Get the plugin settings.
		$options = get_option( 'ai_editor_settings' );
		$selected_model_identifier = $options['ai_editor_model'];

		// Determine the system message based on the model.
		$system_message = $this->get_system_message( $selected_model_identifier );

		// Prepend the system message.
		array_unshift(
			$messages,
			array(
				'role'    => 'system',
				'content' => $system_message,
			)
		);

		$api_key = '';
		$api_url = '';
		$request_body = array();
		$headers = array( 'Content-Type' => 'application/json' );

		$actual_model_name = $this->get_ai_model_name( $selected_model_identifier );

		// Prepare request based on AI provider
		if ( strpos( $selected_model_identifier, 'm' ) === 0 ) { // OpenAI models (m3, m4, m4o, m4om)
			$api_key = isset( $options['ai_editor_openai_api_key'] ) ? $options['ai_editor_openai_api_key'] : '';
			if ( empty( $api_key ) ) {
				return new WP_Error( 'api_key_missing', __( 'Please enter your OpenAI API key in Settings -> AI Editor.', 'ai-editor' ), array( 'status' => 400 ) );
			}
			$api_url = 'https://api.openai.com/v1/chat/completions';
			$headers['Authorization'] = 'Bearer ' . $api_key;
			$request_body = array(
				'model'       => $actual_model_name,
				'messages'    => $messages,
				'tools'       => $functions,
				'tool_choice' => 'auto',
			);
		} elseif ( strpos( $selected_model_identifier, 'claude' ) === 0 ) { // Anthropic models
			$api_key = isset( $options['ai_editor_anthropic_api_key'] ) ? $options['ai_editor_anthropic_api_key'] : '';
			if ( empty( $api_key ) ) {
				return new WP_Error( 'api_key_missing', __( 'Please enter your Anthropic API key in Settings -> AI Editor.', 'ai-editor' ), array( 'status' => 400 ) );
			}
			$api_url = 'https://api.anthropic.com/v1/messages';
			$headers['x-api-key'] = $api_key;
			$headers['anthropic-version'] = '2023-06-01';

			// Convert messages to Anthropic format if necessary (user/assistant roles)
			$anthropic_messages = array();
			foreach ($messages as $msg) {
				if ($msg['role'] === 'system') continue; // Skip system message for Anthropic, add it separately
				$anthropic_messages[] = array('role' => $msg['role'], 'content' => $msg['content']);
			}

			$request_body = array(
				'model' => $actual_model_name,
				'system' => $system_message, // System message for Anthropic
				'messages' => $anthropic_messages,
				'max_tokens' => 4096, // Max output tokens
				// TODO: Adapt 'tools' for Anthropic if its function calling is different
			);
			if (!empty($functions)) {
				// Anthropic tool usage is different, needs specific adaptation
				// For now, let's assume a similar structure or skip if too complex for initial pass
				// $request_body['tools'] = $this->format_tools_for_anthropic($functions);
			}

		} elseif ( strpos( $selected_model_identifier, 'gemini' ) === 0 ) { // Google Gemini models
			$api_key = isset( $options['ai_editor_gemini_api_key'] ) ? $options['ai_editor_gemini_api_key'] : '';
			if ( empty( $api_key ) ) {
				return new WP_Error( 'api_key_missing', __( 'Please enter your Google Gemini API key in Settings -> AI Editor.', 'ai-editor' ), array( 'status' => 400 ) );
			}
			// Note: Gemini API structure might be different, e.g. v1beta
			$api_url = 'https://generativelanguage.googleapis.com/v1beta/models/' . $actual_model_name . ':generateContent?key=' . $api_key;

			// Gemini expects a different message format ('parts' and 'role')
			// System message handling might also differ.
			$gemini_contents = array();
			foreach ($messages as $msg) {
				// Gemini uses 'user' and 'model' roles. System prompt is handled differently or as first user message.
				if ($msg['role'] === 'system') {
					// Option 1: Prepend as a user message (if no dedicated system prompt)
					// $gemini_contents[] = array('role' => 'user', 'parts' => array(array('text' => $msg['content'])));
					// Option 2: Some models have a dedicated system_instruction field (check Gemini docs for specific model)
					// For now, we'll rely on the general system message variable, which might need to be part of the first user message for Gemini.
					// Let's assume for now the $system_message is prepended to the first user message or handled by a specific parameter if available.
					continue;
				}
				$role = ($msg['role'] === 'assistant') ? 'model' : 'user';
				$gemini_contents[] = array('role' => $role, 'parts' => array(array('text' => $msg['content'])));
			}
			// Prepend system message to the first user message if not handled otherwise
			if (!empty($system_message) && !empty($gemini_contents) && $gemini_contents[0]['role'] === 'user') {
			    $gemini_contents[0]['parts'][0]['text'] = $system_message . "\n\n" . $gemini_contents[0]['parts'][0]['text'];
			} elseif (!empty($system_message) && empty($gemini_contents)) {
				// If only system message exists, send it as user message
				$gemini_contents[] = array('role' => 'user', 'parts' => array(array('text' => $system_message)));
			}


			$request_body = array(
				'contents' => $gemini_contents,
				// TODO: Adapt 'tools' for Gemini (Vertex AI functions)
				// 'tools' => $this->format_tools_for_gemini($functions),
			);
			// Gemini function calling ('tools') is structured differently.
			// It involves 'functionDeclarations' and 'functionCalls'.
			// This needs a more detailed mapping.
		} else {
			return new WP_Error( 'unknown_model_provider', __( 'Selected AI model provider is not recognized.', 'ai-editor' ), array( 'status' => 400 ) );
		}

		$response = wp_remote_post(
			$api_url,
			array(
				'headers' => $headers,
				'body'    => wp_json_encode( $request_body ),
				'timeout' => 120,
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
			// Generic error message
			$error_message = __( 'An unknown error occurred with the AI API.', 'ai-editor' );

			// Attempt to parse provider-specific error messages
			if ( ! empty( $response_data['error'] ) ) { // OpenAI, and potentially others if they use 'error'
				if ( is_array( $response_data['error'] ) && isset( $response_data['error']['message'] ) ) {
					$error_message = $response_data['error']['message'];
					if ( isset( $response_data['error']['code'] ) && 'model_not_found' === $response_data['error']['code'] ) {
						$error_message = __( 'The selected model was not found or you may not have access to it. Please check your model selection and API key.', 'ai-editor' );
					}
				} elseif ( is_string( $response_data['error'] ) ) {
					$error_message = $response_data['error'];
				}
			} elseif (isset($response_data['message'])) { // Gemini uses 'message' for errors
				$error_message = $response_data['message'];
			}


			return new WP_REST_Response(
				array(
					'error'   => true,
					'message' => $error_message,
					'status'  => $http_status,
					'provider_response' => $response_data // Include raw provider response for debugging if needed
				),
				$http_status
			);
		}

		// Initialize the default response array.
		// Normalize response structure here if necessary, for now, assume OpenAI structure and adapt others to it.
		$normalized_response_data = $this->normalize_response( $response_data, $selected_model_identifier );

		$response_array = array(
			'data' => $normalized_response_data,
		);

		// Combine all Gutenberg blocks - this function expects OpenAI's 'tool_calls' structure.
		// May need adjustment if Anthropic/Gemini tool/function structures are very different and normalization is complex.
		$combined_blocks = $this->combine_blocks( $normalized_response_data );


		// Initialize the unsupported blocks array.
		$not_supported = array();

		// Check for unsupported blocks (example: gpt-3.5-turbo, now actual_model_name)
		if ( 'gpt-3.5-turbo' === $actual_model_name ) { // Check against the actual model name
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
	 * Normalize responses from different AI providers to a common format (similar to OpenAI's).
	 *
	 * @param array  $response_data The raw response data from the AI provider.
	 * @param string $model_identifier The identifier of the model used (e.g., 'm4o', 'claude-3-opus').
	 * @return array The normalized response data.
	 */
	public function normalize_response( $response_data, $model_identifier ) {
		if ( strpos( $model_identifier, 'claude' ) === 0 ) {
			// Normalize Anthropic Claude response
			$normalized = array(
				'choices' => array(),
			);
			if ( isset( $response_data['content'] ) && is_array( $response_data['content'] ) ) {
				$message_content = '';
				$tool_calls = array();
				foreach ($response_data['content'] as $content_block) {
					if ($content_block['type'] === 'text') {
						$message_content .= $content_block['text'];
					} elseif ($content_block['type'] === 'tool_use') {
						// Adapt Anthropic tool_use to OpenAI's tool_calls structure
						$tool_calls[] = array(
							'id' => $content_block['id'], // Anthropic tool use ID
							'type' => 'function', // OpenAI uses 'function'
							'function' => array(
								'name' => $content_block['name'],
								'arguments' => json_encode($content_block['input']), // Anthropic 'input' is the arguments
							)
						);
					}
				}
				$normalized['choices'][] = array(
					'message' => array(
						'role' => 'assistant',
						'content' => $message_content, // Text content
						'tool_calls' => !empty($tool_calls) ? $tool_calls : null,
					),
					'finish_reason' => $response_data['stop_reason'] ?? 'stop', // e.g. tool_use, end_turn
				);
			}
			// Add other fields if necessary, like 'id', 'model', 'usage'
			$normalized['id'] = $response_data['id'] ?? 'claude-' . uniqid();
			$normalized['model'] = $response_data['model'] ?? $model_identifier;
			// Anthropic usage data is in $response_data['usage']['input_tokens'] and $response_data['usage']['output_tokens']
			if (isset($response_data['usage'])) {
				$normalized['usage'] = array(
					'prompt_tokens' => $response_data['usage']['input_tokens'] ?? 0,
					'completion_tokens' => $response_data['usage']['output_tokens'] ?? 0,
					'total_tokens' => ($response_data['usage']['input_tokens'] ?? 0) + ($response_data['usage']['output_tokens'] ?? 0),
				);
			}
			return $normalized;

		} elseif ( strpos( $model_identifier, 'gemini' ) === 0 ) {
			// Normalize Google Gemini response
			$normalized = array(
				'choices' => array(),
			);
			if (isset($response_data['candidates']) && is_array($response_data['candidates'])) {
				foreach ($response_data['candidates'] as $candidate) {
					$message_content = '';
					$tool_calls = null; // Initialize as null

					if (isset($candidate['content']['parts']) && is_array($candidate['content']['parts'])) {
						$text_parts = array();
						foreach ($candidate['content']['parts'] as $part) {
							if (isset($part['text'])) {
								$text_parts[] = $part['text'];
							}
							// Adapt Gemini function calls to OpenAI's tool_calls structure
							if (isset($part['functionCall'])) {
								if ($tool_calls === null) $tool_calls = array(); // Initialize if a functionCall is found
								$tool_calls[] = array(
									// Gemini's functionCall doesn't have an 'id' directly in the part,
									// it's more about the sequence. For now, generate one or see if client needs it.
									// 'id' => 'gemini-tool-' . uniqid(),
									'type' => 'function',
									'function' => array(
										'name' => $part['functionCall']['name'],
										'arguments' => json_encode($part['functionCall']['args']),
									)
								);
							}
						}
						$message_content = implode("\n", $text_parts);
					}

					$normalized['choices'][] = array(
						'message' => array(
							'role' => 'assistant', // Gemini uses 'model' for assistant
							'content' => $message_content,
							'tool_calls' => $tool_calls,
						),
						'finish_reason' => $candidate['finishReason'] ?? 'stop',
						// Other details like 'index', 'safetyRatings' can be added if needed
					);
				}
			}
			// Add other fields if necessary, like 'id', 'model', 'usage'
			// Gemini doesn't provide a top-level 'id' or 'model' in the same way.
			// Usage statistics might be in `promptFeedback.tokenCount` or similar, if enabled/available.
			return $normalized;
		}
		// If not Claude or Gemini, assume it's already in OpenAI format (or doesn't need normalization here)
		return $response_data;
	}

	/**
	 * Get the actual AI model name based on the internal identifier.
	 *
	 * @param string $model_identifier The internal model identifier.
	 * @return string The actual AI model name for the API.
	 */
	public function get_ai_model_name( $model_identifier ) {
		switch ( $model_identifier ) {
			// OpenAI
			case 'm3':
				return 'gpt-3.5-turbo';
			case 'm4':
				return 'gpt-4-turbo';
			case 'm4om':
				return 'gpt-4o-mini';
			case 'm4o':
			default: // Default to gpt-4o for OpenAI if unspecified or new 'm' prefix
				return 'gpt-4o';
			// Anthropic
			case 'claude-3-opus':
				return 'claude-3-opus-20240229';
			case 'claude-3-sonnet':
				return 'claude-3-sonnet-20240229';
			case 'claude-3-haiku':
				return 'claude-3-haiku-20240307';
			// Google Gemini
			case 'gemini-1.5-pro':
				return 'gemini-1.5-pro-latest'; // Or specific version like gemini-1.5-pro-001
			case 'gemini-1.5-flash':
				return 'gemini-1.5-flash-latest';
		}
		return $model_identifier; // Should not happen if settings are correct
	}

	/**
	 * Get the system message based on the model identifier.
	 *
	 * @param string $model_identifier The model identifier.
	 * @return string The system message.
	 */
	public function get_system_message( $model_identifier ) {
		$system_message = '';
		// Generic message suitable for most models, emphasizing Gutenberg block creation.
		$base_message = __(
			'You are a helpful assistant tasked with inserting content as blocks in the WordPress Gutenberg editor. If the user does not provide specific content, use filler text. Create visually appealing and interactive layouts with your available Gutenberg blocks, using columns where appropriate. Once a block is added, it cannot be edited.',
			'ai-editor'
		);

		// Specific adjustments or different messages based on model family or specific model
		if ( strpos( $model_identifier, 'm3' ) === 0 ) { // Older OpenAI GPT-3.5
			$system_message = __(
				'You are a helpful assistant tasked with inserting content as blocks in the WordPress Gutenberg editor when instructed by the user. Ask for clarification if a request is ambiguous. Use your available Gutenberg blocks (columns, headings, paragraphs, lists, images, buttons, quotes, pullquotes) to create organized layouts. Do not use Markdown markup language. Once a block is added, it cannot be edited.',
				'ai-editor'
			);
		} else {
			// For other models (GPT-4, Claude, Gemini), use the base message.
			// This can be further customized if specific models have different needs or capabilities for system prompts.
			$system_message = $base_message;
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
	public function permission_check( $request ) {
		return current_user_can( 'manage_options' );
	}
}

// Hook the class to the REST API.
add_action(
	'rest_api_init',
	function () {
		$ai_editor_endpoint = new AIEditor_AI_Endpoint();
		$ai_editor_endpoint->register_routes();
	}
);
