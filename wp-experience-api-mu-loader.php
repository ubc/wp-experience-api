<?php
/**
 * This file will allow this plugin to work in mu-plugins folder.
 * Steps:
 * 1. copy the plugin "wp-experience-api" folder to wp-content/mu-plugins/ folder
 * 2. copy this file to just outside the wp-content/mu-plugins/wp-experience-api folder
 *
 * That should be it!
 */

//we add this constant so we know we are coming form MU
define( 'WP_XAPI_MU_MODE', true );
if ( defined( 'WPMU_PLUGIN_DIR' ) ) {
	define( 'WPXAPI_PLUGIN_DIR', WPMU_PLUGIN_DIR . '/wp-experience-api/' );
} else {
	$path = plugin_dir_path( __FILE__ );
	define( 'WPXAPI_PLUGIN_DIR', $path . 'wp-experience-api/' );
}
require_once WPXAPI_PLUGIN_DIR . '/wp-experience-api.php';