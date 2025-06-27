<?php
// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class AIEditor_Settings_Page
 * The settings page for the AI Editor plugin.
 */
class AIEditor_Settings_Page {

	/**
	 * Intialize the class.
	 * Set up actions for admin menu and settings initialization.
	 */
	public static function init() {
		add_action( 'admin_menu', array( 'AIEditor_Settings_Page', 'add_settings_menu' ) );
		add_action( 'admin_init', array( 'AIEditor_Settings_Page', 'initialize_settings' ) );
	}

	/**
	 * Adds the settings menu to the WordPress admin.
	 */
	public static function add_settings_menu() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		add_options_page(
			__( 'AI Editor Settings', 'ai-editor' ),
			__( 'AI Editor', 'ai-editor' ),
			'manage_options',
			'ai-editor',
			array( 'AIEditor_Settings_Page', 'render_settings_page' )
		);
	}

	/**
	 * Initializes the plugin settings.
	 */
	public static function initialize_settings() {
		register_setting( 'aie_openai_page', 'ai_editor_settings', array( 'AIEditor_Settings_Page', 'sanitize' ) );

		add_settings_section(
			'ai_editor_aie_openai_page_section',
			__( 'OpenAI Configuration', 'ai-editor' ),
			array( 'AIEditor_Settings_Page', 'settings_section_callback' ),
			'aie_openai_page'
		);

		$options = get_option( 'ai_editor_settings' );
		self::add_settings_field( 'ai_editor_api_key', __( 'OpenAI API Key', 'ai-editor' ), $options );
		self::add_settings_field( 'ai_editor_model', __( 'GPT Model', 'ai-editor' ), $options );
	}

	/**
	 * Sanitizes the input received from the settings form.
	 *
	 * @param array $input The input received from the settings form.
	 *
	 * @return array The sanitized input.
	 */
	public static function sanitize( $input ) {
		$sanitized_input = array();

		if ( isset( $input['ai_editor_api_key'] ) ) {
			$sanitized_input['ai_editor_api_key'] = sanitize_text_field( $input['ai_editor_api_key'] );
		}

		if ( isset( $input['ai_editor_model'] ) ) {
			$sanitized_input['ai_editor_model'] = sanitize_text_field( $input['ai_editor_model'] );
		}

		return $sanitized_input;
	}

	/**
	 * Adds a settings field.
	 *
	 * @param string $id      The ID of the settings field.
	 * @param string $title   The title of the settings field.
	 * @param array  $options The options for the settings field.
	 */
	private static function add_settings_field( $id, $title, $options ) {
		add_settings_field(
			$id,
			$title,
			array( 'AIEditor_Settings_Page', 'render_settings_field' ),
			'aie_openai_page',
			'ai_editor_aie_openai_page_section',
			array(
				'id'      => $id,
				'options' => $options,
			)
		);
	}

	/**
	 * Renders a settings field.
	 *
	 * @param array $args Arguments for the settings field.
	 */
	public static function render_settings_field( $args ) {
		$options = $args['options'];
		$value   = isset( $options[ $args['id'] ] ) ? esc_attr( $options[ $args['id'] ] ) : '';
		$html    = '';

		switch ( $args['id'] ) {
			case 'ai_editor_api_key':
				$html .= '<input type="password" id="' . esc_attr( $args['id'] ) . '" name="ai_editor_settings[' . esc_attr( $args['id'] ) . ']" value="' . $value . '" class="regular-text">';
				$html .= '<p class="description">' . esc_html__( 'Enter your OpenAI API key.', 'ai-editor' ) . '</p>';
				break;
			case 'ai_editor_model':
				// Set default model to gpt-4-turbo if no value is set.
				$value = $value !== '' ? $value : 'm4o';
				$html .= '<select id="' . esc_attr( $args['id'] ) . '" name="ai_editor_settings[' . esc_attr( $args['id'] ) . ']" class="regular-text">';
				$html .= '<option value="m4o"' . selected( $value, 'm4o', false ) . '>GPT-4o (Most advanced model)</option>';
				$html .= '<option value="m4om"' . selected( $value, 'm4om', false ) . '>GPT-4o mini</option>';
				$html .= '<option value="m4"' . selected( $value, 'm4', false ) . '>GPT-4 Turbo</option>';
				$html .= '<option value="m3"' . selected( $value, 'm3', false ) . '>GPT-3.5 Turbo</option>';
				$html .= '</select>';
				$html .= '<p class="description">' . esc_html__( 'For best results, use the GPT-4 models if you have access to them.', 'ai-editor' ) . '</p>';
				break;
		}

		$allowed_html = array(
			'input' => array(
				'type' => array(),
				'id' => array(),
				'name' => array(),
				'value' => array(),
				'class' => array(),
			),
			'select' => array(
				'id' => array(),
				'name' => array(),
				'class' => array(),
			),
			'option' => array(
				'value' => array(),
				'selected' => array(),
			),
			'p' => array(
				'class' => array(),
			),
		);

		echo wp_kses($html, $allowed_html);

	}

	/**
	 * Callback for the settings section description.
	 */
	public static function settings_section_callback() {
		echo esc_html__( 'Please enter your OpenAI API key and select a GPT model.', 'ai-editor' );
	}

	/**
	 * Renders the settings page in the WordPress admin.
	 */
	public static function render_settings_page() {
		?>
		<div class="wrap ai_editor-settings-page">
			<form action='options.php' method='post'>
				<h1><?php echo esc_html__( 'AI Editor Settings', 'ai-editor' ); ?></h1>
				<?php
				settings_fields( 'aie_openai_page' );
				do_settings_sections( 'aie_openai_page' );
				submit_button();
				?>
			</form>

			<hr />

			<div>
				<h2><?php echo esc_html__( 'Reminder', 'ai-editor' ); ?></h2>
				<ul>
					<li><?php echo esc_html__( 'Your API key is stored in your database. You are responsible for your own API key and any associated charges or usage limits incurred with OpenAI. Find your API key', 'ai-editor' ); ?> <a href="https://platform.openai.com/api-keys" target="_blank" rel="noopener noreferrer"><?php echo esc_html__( 'here', 'ai-editor' ); ?></a>.</li>
				</ul>
			</div>
		</div>
		<?php
	}
}

AIEditor_Settings_Page::init();
