<?php
/**
 * Plugin Name: AI Block Editor
 * Description: AI assistant that inserts blocks and content into the Gutenberg editor based on your prompts. Powered by OpenAI's GPT technology.
 * Version: 1.0.3
 * Author: Virgiliu Diaconu
 * License: GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: ai-editor
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Require files for the plugin.
require_once plugin_dir_path( __FILE__ ) . 'inc/class-aieditor-language-setup.php';
require_once plugin_dir_path( __FILE__ ) . 'inc/class-aieditor-settings-endpoint.php';
require_once plugin_dir_path( __FILE__ ) . 'inc/class-aieditor-openai-endpoint.php';
require_once plugin_dir_path( __FILE__ ) . 'inc/class-aieditor-settings-page.php';
require_once plugin_dir_path( __FILE__ ) . 'inc/class-aieditor-enqueues.php';
