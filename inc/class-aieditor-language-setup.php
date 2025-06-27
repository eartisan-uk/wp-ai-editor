<?php
if ( ! defined( 'ABSPATH' ) ) exit; // Exit if accessed directly

/**
 * Class AIEditor_Language_Setup
 * Manages the loading of the text domain for the plugin.
 */
class AIEditor_Language_Setup {

	/**
	 * Initialize the language setup.
	 */
	public static function init() {
		add_action( 'plugins_loaded', array( 'AIEditor_Language_Setup', 'load_textdomain' ) );
	}

	/**
	 * Load the plugin text domain for translation.
	 */
	public static function load_textdomain() {
		load_plugin_textdomain( 'ai-editor', false, plugin_basename( dirname( __DIR__ ) ) . '/languages' );
	}
}

AIEditor_Language_Setup::init();
