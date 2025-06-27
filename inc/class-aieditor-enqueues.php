<?php
if ( ! defined( 'ABSPATH' ) ) exit; // Exit if accessed directly

/**
 * Class AIEditor_Enqueue
 * Handles the enqueuing of scripts and styles for the plugin in the admin area.
 */
class AIEditor_Enqueues {

	/**
	 * Initialize the class.
	 * Action to enqueue scripts and styles.
	 */
	public static function init() {
		add_action( 'admin_enqueue_scripts', array( 'AIEditor_Enqueues', 'enqueue_scripts' ) );
	}

	/**
	 * Enqueue scripts and styles for the admin page of the plugin. TODO: ONLY FOR ADMIN!!!
	 */
	public static function enqueue_scripts() {
		// Get URL and path of the plugin's root directory.
		$plugin_root_url  = plugin_dir_url( __DIR__ );
		$plugin_root_path = plugin_dir_path( __DIR__ );

		// Get version from asset file.
		$script_asset_path = $plugin_root_path . 'build/sidebar.asset.php';
		$script_asset      = include $script_asset_path;
		$version           = $script_asset['version'];

		// Enqueue JavaScript.
		wp_enqueue_script(
			'aieditor-sidebar-script',
			$plugin_root_url . 'build/sidebar.js',
			array( 'wp-plugins', 'wp-edit-post', 'wp-element' ),
			$version,
			true
		);

		// Enqueue CSS.
		wp_enqueue_style(
			'aieditor-styles',
			$plugin_root_url . 'build/sidebar.css',
			array( 'wp-edit-blocks' ),
			$version
		);
	}
}

AIEditor_Enqueues::init();
