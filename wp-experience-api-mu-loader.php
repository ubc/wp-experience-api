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
require_once WPMU_PLUGIN_DIR . '/wp-experience-api/wp-experience-api.php';
