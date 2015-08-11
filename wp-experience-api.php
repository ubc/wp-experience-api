<?php
/**
 * Plugin Name: WP Experience API
 * Plugin URI: http://ctlt.ubc.ca
 * Description: This plugin generates and sends various xAPI statements to the specified LRS
 * Tags: xAPI
 * Author: CTLT, Devindra Payment, loongchan
 * Version: 1.0
 * Author URI: https://github.com/ubc
 * License: GNU AGPLv3
 * License URI: http://www.gnu.org/licenses/agpl-3.0.html
 */

/* Helpful documentation at
 * http://tincanapi.com/tech-overview/
 * NOTE:
 * - can run without network activation on a per site basis as well as network wide. will give warning if network activated but no saved network options
 * - need to double check later the class's method/property visiblity for better security... just in case!
 */
//basic configuration constants used throughout the plugin
use TinCan\Activity;

if ( ! defined( 'ABSPATH' ) ) {
	die( '-1' );
}

require_once( 'wp-experience-api-configs.php' );
require_once( 'includes/TinCanPHP/autoload.php' );
require_once( 'wp-experience-api-queue-obj.php' );

class WP_Experience_API {

	private static $lrs1;
	private static $triggers = array();
	private static $register_locked = false;
	private static $options;
	private static $site_options;   //to be used if multisite and network activated
	public static $dependencies = array(
		'BadgeOS' => 'http://wordpress.org/plugins/badgeos/',
		'JSON_API' => 'http://wordpress.org/plugins/json-api/',
		'BadgeOS_Open_Badges_Issuer_AddOn' => 'https://github.com/ubc/open-badges-issuer-addon',
	);
	//constants for managing creation of LRSes, cause we can't store password/username in DB.
	const WPXAPI_NETWORK_LRS = 1;
	const WPXAPI_SITE_LRS = 2;

	/**
	 * creates TinCan\RemoteLRS instance based which one wanted
	 *
	 * @param Integer $lrs
	 * @return Mixed <boolean, \TinCan\RemoteLRS>
	 */
	private static function setup_lrs( $lrs ) {
		$return_lrs = false;

		//let's make sure we have options setup
		self::setup_options();

		//let's do it!
		switch ( $lrs ) {
			case WP_Experience_API::WPXAPI_NETWORK_LRS:
				$return_lrs = new TinCan\RemoteLRS(
					self::$site_options['wpxapi_network_lrs_url'],
					WP_XAPI_DEFAULT_XAPI_VERSION,
					self::$site_options['wpxapi_network_lrs_username'],
					self::$site_options['wpxapi_network_lrs_password']
				);
				break;
			case WP_Experience_API::WPXAPI_SITE_LRS:
				$return_lrs = new TinCan\RemoteLRS(
					self::$options['wpxapi_lrs_url'],
					WP_XAPI_DEFAULT_XAPI_VERSION,
					self::$options['wpxapi_lrs_username'],
					self::$options['wpxapi_lrs_password']
				);
				break;
		}

		return $return_lrs;
	}

	/**
	 * Initialization function
	 *
	 * @return void
	 */
	public static function init() {
		// need to check for php version!  min 5.4
		if ( ! WP_Experience_API::check_php_version() ) {
			deactivate_plugins( plugin_basename( __FILE__ ) );
			add_action( 'admin_notices', array( 'WP_Experience_API', 'php_disable_notice' ) );
			if ( isset( $_GET['activate'] ) ) {
				unset( $_GET['activate'] );
			}
		}

		//get options
		self::setup_options();

		//create LRS
		if ( is_multisite() && ! function_exists( 'is_plugin_active_for_network' ) ) {
			require_once( ABSPATH . '/wp-admin/includes/plugin.php' );
		}
		if ( ( is_multisite() && is_plugin_active_for_network( 'wp-experience-api/wp-experience-api.php' ) ) || defined( 'WP_XAPI_MU_MODE' ) ) {
			if ( ! empty( WP_Experience_API::$site_options ) || ! empty( WP_Experience_API::$site_options['wpxapi_network_lrs_password'] ) && ! empty( WP_Experience_API::$site_options['wpxapi_network_lrs_username'] ) && ! empty( WP_Experience_API::$site_options['wpxapi_network_lrs_url'] ) ) {

				self::$lrs1 = self::setup_lrs( WP_Experience_API::WPXAPI_NETWORK_LRS );
			} else {
				add_action( 'admin_notices', array( 'WP_Experience_API', 'config_unset_notice' ) );
				error_log( 'Please tell Network Administrator to set the default username/password/URL of the LRS (for plugin: WP ExperienceA API)' );
			}
		} else {
			if ( ! is_multisite() &&
					 ( empty( WP_Experience_API::$options['wpxapi_lrs_url'] ) ||
					   empty( WP_Experience_API::$options['wpxapi_lrs_username'] ) ||
					   empty( WP_Experience_API::$options['wpxapi_lrs_password'] ) ) ) {
				add_action( 'admin_notices', array( 'WP_Experience_API', 'config_unset_local_notice' ) );
				error_log( 'Please tell the Site Administrator to set the username/password/URL of the LRS (for plugin: WP ExperienceA API)' );
			}
		}

		if ( 'Yes' === WP_Experience_API::$site_options['wpxapi_network_lrs_stop_all'] ) {
			add_action( 'admin_notices', array( 'WP_Experience_API', 'stop_all_statements_notice' ) );
			error_log( 'The Network Administrator chose to stop allowing statements to be sent to the Network level LRS.' );
		}

		if ( is_admin() || is_network_admin() ) {
			require_once( plugin_dir_path( __FILE__ ).'wp-experience-api-admin.php' );
		}

		require_once( plugin_dir_path( __FILE__ ).'includes/triggers.php' );

		//this is where you can register more stuff (aka link filters to statements) programmatically via filters
		do_action( 'wpxapi_add_registers' );

		add_action( 'init', array( __CLASS__, 'load' ) );

		//needs to check if queue is empty or not and display admin notices as appropriate
		add_action( 'admin_notices', array( __CLASS__, 'wpxapi_queue_is_not_emtpy_notice' ) );
		add_action( 'network_admin_notices', array( __CLASS__, 'wpxapi_queue_is_not_emtpy_notice' ) );

	}

	/**
	 * does basic activation stuff such as... create table!
	 *
	 * @props http://shibashake.com/wordpress-theme/write-a-plugin-for-wordpress-multi-site
	 * @return void
	 */
	public static function wpxapi_on_activate() {
		global $wpdb;

		//use base_prefix so it will be on global regardless of mu or single site
		$table_name = WP_Experience_Queue_Object::get_queue_table_name();
		if ( $wpdb->get_var( "SHOW TABLES LIKE '{$table_name}'" ) != $table_name ) {
			if ( ! empty( $wpdb->charset ) ) {
				$charset_collate = "DEFAULT CHARACTER SET {$wpdb->charset}";
			}
			if ( ! empty( $wpdb->collate ) ) {
				$charset_collate .= " COLLATE {$wpdb->collate}";
			}
			$sql = "CREATE TABLE IF NOT EXISTS {$table_name} (
				id bigint NOT NULL AUTO_INCREMENT,
				tries tinyint UNSIGNED NOT NULL DEFAULT '1',
				last_try_time datetime,
				statement text NOT NULL,
				lrs_info tinyint NOT NULL,
				created timestamp DEFAULT CURRENT_TIMESTAMP,
				PRIMARY KEY (id)
			) {$charset_collate};";

			require_once( ABSPATH . 'wp-admin/includes/upgrade.php' );
			dbDelta( $sql );
		}

		/**
		 * DEPRECATED!!!!  Now we use a button to run the queue!  much more control...  we also added admin notices when queue is not empty.
		 *
		 * We need to create a wp_cron, but we only want it to run from one spot.
		 */
		/*
		if ( is_main_site() ) {
			if ( false === wp_get_schedule( 'wpxapi_run_the_queue' ) ) {
				//we check first BEFORE we schedule. otherwise, we get multiple copies!we
				$delay = 1 * 60 * 60;	//number of seconds before we initially run the cron
				wp_schedule_event( time() + $delay, WP_XAPI_QUEUE_RECURRANCE, 'wpxapi_run_the_queue' );
			}
			//we check if main site first so that ONLY the main site will be able to do the run queue thing
			add_action( 'wpxapi_run_the_queue', array( __CLASS__, 'wpxapi_run_the_queue' ) );
		}
		*/

		/**
		 * We need to remove legacy wp_cron 'wpxapi_run_queue'.
		 *
		 * Issue was that in 1.0.4, the plugin scheduled EVERY VISIT on EVERY SITE instead of
		 * simulating cron where it is run only on one site and checked whether it's set or not (see above!)
		 */
		if ( false !== wp_get_schedule( 'wpxapi_run_queue' ) ) {
			wp_clear_scheduled_hook( 'wpxapi_run_queue' );
		}
	}

	/**
	 * Converts the triggers created by the trigger.php and starts sending the statements to the LRS
	 *
	 * @return void
	 */
	public static function load() {
		$register_locked = true;

		foreach ( WP_Experience_API::$triggers as $slug => $trigger ) {
			foreach ( $trigger['hooks'] as $hook ) {
				$args = 1;
				$priority = 10;
				if ( isset( $trigger['num_args'] ) && isset( $trigger['num_args'][ $hook ] ) ) {
					$args = $trigger['num_args'][ $hook ];
				}
				if ( isset( $trigger['num_priority'] ) && isset( $trigger['num_priority'][ $hook ] ) ) {
					$priority = $trigger['num_priority'][ $hook ];
				}
				add_action(
					$hook,
					function() use ( $trigger ) {
						WP_Experience_API::send( $trigger, func_get_args() );
					},
					$priority,
					$args
				);
			}
		}
	}

	/**
	 * registers triggers which select what filters to use and what statement to send
	 *
	 * @param  string $slug name of trigger
	 * @param  array $data fancy array holding a bunch of info. see trigger.php
	 * @return void
	 */
	public static function register( $slug, $data ) {
		if ( ! WP_Experience_API::$register_locked ) {
			WP_Experience_API::$triggers[ $slug ] = $data;
		}
	}

	/**
	 * deregisters triggers
	 *
	 * @param  string $slug name of triggger
	 * @param  array $data not that important, optional.
	 * @return void
	 */
	public static function deregister( $slug, $data ) {
		if ( ! WP_Experience_API::$register_locked ) {
			unset( WP_Experience_API::$triggers[ $slug ] );
		}
	}

	/**
	 * generates the xAPI statement and initializes sending of the statement to the LRS
	 *
	 * @param  array $trigger fancy array holding all info related to filter/statement
	 * @param  array $args    arguments sent by the filter specified in fancy array
	 * @return mixed  false on failure, void otherwise
	 */
	public static function send( $trigger, $args ) {
		if ( 'Yes' === WP_Experience_API::$site_options['wpxapi_network_lrs_stop_all'] ) {
			error_log( 'The Network Administrator chose to stop allowing statements to be sent to the Network level LRS.' );
			return;
		}
		//error_log("checkin '".__FUNCTION__."', filter: '".current_filter()."'; args: ".print_r(func_get_args(), true));
		$data = $trigger['process']( current_filter(), $args );

		if ( false == $data ) {
			return false;
		}

		//to make more flexible, can allow trigger['process']() to return entire arrays if they want to
		$actor = $verb = $object = $result = $context = $attachments = null;

		//ok, setup to get each statement property
		$actor = WP_Experience_API::create_actor( $data );
		$verb = WP_Experience_API::create_verb( $data );
		$object = WP_Experience_API::create_object( $data );
		$context = WP_Experience_API::create_context( $data );
		$result = WP_Experience_API::create_result( $data );
		$attachments = WP_Experience_API::create_attachments( $data );
		$timestamp = WP_Experience_API::create_timestamp( $data );

		//sanity check as actor/verb/object is required.  the rest os recommended.
		if ( empty( $actor ) || empty( $verb ) || empty( $object ) ) {
			return false;
		}

		$statement = array(
			'actor' => $actor,
			'verb' => $verb,
			'object' => $object,
		);

		//now for the extras like context, results, etc
		if ( ! empty( $context ) ) {
			$statement['context'] = $context;
		}
		if ( ! empty( $result ) ) {
			$statement['result'] = $result;
		}
		if ( ! empty( $attachments ) ) {
			$statement['attachments'] = $attachments;
		}

		if ( ! empty( $timestamp ) ) {
			$statement['timestamp'] = $timestamp;
		}

		WP_Experience_API::post( new TinCan\Statement( apply_filters( 'wpxapi_statement', $statement ) ) );
	}

	/**
	 * does the actual sending of the statement to the LRS
	 *
	 * @param  TinCan\Statement $data TinCan statement object
	 * @return void
	 */
	public static function post( $data ) {
		if ( is_multisite() && ! function_exists( 'is_plugin_active_for_network' ) ) {
			require_once( ABSPATH . '/wp-admin/includes/plugin.php' );
		}

		if ( ( is_multisite() && is_plugin_active_for_network( 'wp-experience-api/wp-experience-api.php' ) ) || defined( 'WP_XAPI_MU_MODE' ) ) {
			if ( ! empty( WP_Experience_API::$lrs1 ) && 'No' === WP_Experience_API::$site_options['wpxapi_network_lrs_stop_network_level_only'] ) {
				$response = WP_Experience_API::$lrs1->saveStatement( $data );

				if ( false === (bool) $response->success ) {
					//since it fails, we add to queue!
					WP_Experience_API::wpxapi_queue_enqueue( $data, WP_Experience_API::WPXAPI_NETWORK_LRS );
				}
			}
		}

		if ( ! empty( WP_Experience_API::$options['wpxapi_lrs_url'] ) && ! empty( WP_Experience_API::$options['wpxapi_lrs_username'] ) && ! empty( WP_Experience_API::$options['wpxapi_lrs_password'] ) ) {
			$lrs2 = self::setup_lrs( WP_Experience_API::WPXAPI_SITE_LRS );
			$response2 = $lrs2->saveStatement( $data );

			if ( false === (bool) $response2->success ) {
				//failed, so enqueue!
				WP_Experience_API::wpxapi_queue_enqueue( $data, WP_Experience_API::WPXAPI_SITE_LRS );
			}
		}

		return;
	}

/**===========================================================**/
/**=== the following section is more like helper functions ===**/
/**===========================================================**/

	/**
	 * creates actor
	 *
	 * @param  array $data structured array of actor info
	 * @return TinCan\Agent object
	 */
	public static function create_actor( $data ) {
		$actor = null;
		if ( isset( $data['actor_raw'] ) ) {
			$actor = new TinCan\Agent( $data['actor_raw'] );
		} else if ( isset( $data['user'] ) && is_int( $data['user'] ) && $data['user'] > 0 ) {
			$user_data = get_userdata( $data['user'] );

			if ( false !== $user_data ) {
				//now we pull the account/email option!
				if ( ! empty( WP_Experience_API::$site_options['wpxapi_network_lrs_user_setting'] ) && 2 == WP_Experience_API::$site_options['wpxapi_network_lrs_user_setting'] ) {
					//if email
					$actor = new TinCan\Agent(
						[
							'objectType' => 'Agent',
							'name' => $user_data->display_name,
							'mbox' => 'mailto:' . $user_data->user_email,
						]
					);
				} else {
					//if account
					$unique_id = apply_filters( 'wpxapi_actor_account_name', get_user_meta( $user_data->ID, WP_XAPI_DEFAULT_ACTOR_ACCOUNT_NAME, true ) );

					if ( empty( $unique_id ) ) {
						//we give this error message BECAUSE I was caught debugging this > 3 times!
						error_log( 'Please ensure that the constants in wp-experience-api-configs.php file is set properly!' );
						return;
					}

					$actor = new TinCan\Agent(
						[
							'objectType' => 'Agent',
							'name' => $user_data->display_name,
							'account' => [
								'homePage' => apply_filters( 'wpxapi_actor_account_homepage', WP_XAPI_DEFAULT_ACTOR_ACCOUNT_HOMEPAGE ),
								'name' => $unique_id,
							],
						]
					);
				}
			}
		}

		return apply_filters( 'wpxapi_actor', $actor, $data );
	}

	/**
	 * creates verb
	 *
	 * @param  array $data array of verb info
	 * @return TinCan\Verb object
	 */
	public static function create_verb( $data ) {
		$verb = null;
		if ( isset( $data['verb_raw'] ) ) {
			$verb = new TinCan\Verb( $data['verb_raw'] );
		} else if ( isset( $data['verb'] ) && is_array( $data['verb'] ) && isset( $data['verb']['id'] ) ) {
			$verb = new TinCan\Verb( [ 'id' => $data['verb']['id'] ] );

			if ( isset( $data['verb']['display'] ) ) {
				$verb->setDisplay( $data['verb']['display'] );
			}
		} else if ( isset( $data['verb'] ) && is_array( $data['verb'] ) && isset( $data['verb']['verb'] ) ) {
			$verb = new TinCan\Verb(
				[
					'id' => 'http://activitystrea.ms/schema/1.0/' . $verb,
					'display' => [ 'en-US' => $verb ],
				]
			);
		}

		return apply_filters( 'wpxapi_verb', $verb, $data );
	}

	/**
	 * creates object
	 *
	 * @param  array $data array of object info
	 * @return TinCan\Activity object
	 */
	public static function create_object( $data ) {
		$object = null;
		if ( isset( $data['object_raw'] ) ) {
			$object = new TinCan\Activity( $data['object_raw'] );
		} else if ( isset( $data['object'] ) && is_array( $data['object'] ) && isset( $data['object']['id'] ) ) {
			//required stuff
			$object = new TinCan\Activity(
				[
				'id' => $data['object']['id'],
				'objectType' => 'Activity',
				]
			);

			//optional stuff - definition
			if ( isset( $data['object']['definition'] ) && is_array( $data['object']['definition'] ) ) {
				$activity_definition = new TinCan\ActivityDefinition( $data['object']['definition'] );
				$object->setDefinition( $activity_definition );
			} else if ( isset( $data['object']['name'] ) && isset( $data['object']['description'] ) ) {
				$activity_definition = new TinCan\ActivityDefinition(
					[
						'name' => array( 'en-US' => $data['object']['name'] ),
						'description' => array( 'en-US' => $data['object']['description'] )
					]
				);
				$object->setDefinition( $activity_definition );
			}
		}

		return apply_filters( 'wpxapi_object', $object, $data );
	}

	/**
	 * creates context
	 *
	 * @param  array $data array of context info
	 * @return TinCan\Context object
	 */
	public static function create_context( $data ) {
		$context = null;
		if ( isset( $data['context_raw'] ) ) {
			$context = new TinCan\Context( $data['context_raw'] );
		}
		return apply_filters( 'wpxapi_context', $context, $data );
	}

	/**
	 * creates timestamp
	 *
	 * @param unknown $data array of context info
	 * @return TinCan\Timestamp object
	 */
	public static function create_timestamp( $data ) {
		$timestamp = null;
		if ( isset( $data['timestamp_raw'] ) ) {
			$timestamp = $data['timestamp_raw'];
		}
		return apply_filters( 'wpxapi_timestamp', $timestamp, $data );
	}
	/**
	 * creates result
	 *
	 * @param  array $data array of result info
	 * @return TinCan\Result object
	 */
	public static function create_result( $data ) {
		$result = null;
		if ( isset( $data['result_raw'] ) ) {
			$result = new TinCan\Result( $data['result_raw'] );
		}
		return apply_filters( 'wpxapi_result', $result, $data );
	}

	/**
	 * creates attachment
	 * @param  array $data array of attachment info
	 * @return TinCan\Attachment
	 */
	public static function create_attachments( $data ) {
		$result = null;
		if ( isset( $data['attachments_raw'] ) ) {
			$result = new TinCan\Attachment( $data['attachments_raw'] );
		}
		return apply_filters( 'wpxapi_attachments', $result, $data );
	}

	/**
	 * Helper function to check if BadgeOS plugin is installed and activated
	 *
	 * @return boolean
	 */
	public static function is_badgeos_activated() {
		return class_exists( 'BadgeOS' ) && function_exists( 'badgeos_get_user_earned_achievement_types' );
	}

	/**
	 * Helper function that checks if PulsePress theme is the current theme
	 *
	 * @return B doolean
	 */
	public static function is_using_pulsepress_theme() {
		$current_theme = wp_get_theme();
		return $current_theme->get( 'Name' ) == 'PulsePress';
	}

	/**
	 * Helper function to see if it can meet OpenBadges dependencies
	 *
	 * This is so that we can assume that the WP installation can handle OpenBadges protocol
	 *
	 * @return Boolean
	 */
	public static function meets_badgeos_dependencies() {
		$return = true;
		foreach ( WP_Experience_API::$dependencies as $class => $url ) {
			if ( ! class_exists( $class ) ) {
				$return = false;
				break;
			}
		}
		return $return;
	}

	/**
	 * Checks if the required plugin is installed.
	 *
	 * @return Boolean
	 *
	 * PROPS: https://github.com/ubc/open-badges-issuer-addon/blob/master/badgeos-open-badges.php
	 * NOTE: not currently used as it will force users to have other plugins installed.
	 */
	public static function meets_requirements() {
		$return = WP_Experience_API::meets_badgeOS_dependencies();

		//we only want to show this in admin panels and only for folks that can do something about it, aka admins and above
		if ( false === $return && is_admin() && current_user_can( 'activate_plugins' ) ) {
			?>
			<div id='message' class='error'>
				<?php
				echo  wp_kses( WP_Experience_API::dependencies_disable_notice(), array( 'div' => array( 'class' => array() ), 'strong' => array() ) );
				?>
			</div>
			<?php

			// Deactivate our plugin, unless of course it's in the mu_plugins folder!
			deactivate_plugins( plugin_basename( __FILE__ ) );
		}
	}

	/**
	 * Displays message saying installation doesn't meet minimum PHP requirement
	 *
	 * @return void
	 */
	public static function php_disable_notice() {
		echo "<div class='error'><strong>" . esc_html__( 'WP Experience API Plugin requires PHP ' . WP_XAPI_MINIMUM_PHP_VERSION . ' or higher.', 'wpxapi' ) . '</strong></div>';
	}

	/**
	 * puts up a error notice when the queue is NOT empty. ONLY superadmins
	 *
	 * @return void
	 */
	public static function wpxapi_queue_is_not_emtpy_notice() {
		$count = WP_Experience_API::wpxapi_queue_is_not_empty( true );
		if ( is_super_admin() && $count > 0 ) {
			$message = __( 'The WP Experience API Queue is now not empty.  Current number of items: ', 'wpxapi' );
			echo '<div class="error notice is-dismissible"><p>' . esc_html( $message . $count ) .'</p></div>';
		}
	}
	/**
	 * Displays message saying that related required plugins for Mozilla Open Badges Protocol not installed
	 * @return [type] [description]
	 */
	public static function dependencies_disable_notice() {
		echo "<div class='error'>";
		foreach ( WP_Experience_API::$dependencies as $class => $url ) {
			if ( ! class_exists( $class ) ) {
				$dependency = sprintf( '<a href="%s">%s</a>', esc_url( $url ), esc_html( $class ) );
				?>
				<p>
					<?php printf( __( 'Open Badges Issuer requires %s and has been <a href="%s">deactivated</a>. Please install and activate %s and then reactivate this plugin.', 'bosobi' ),  $dependency, admin_url( 'plugins.php' ), $dependency ); ?>
				</p>
				<?php
			}
		}
		echo '</div>';
	}

	/**
	 * Displays message saying that LRS settings at the network level is NOT set
	 */
	public static function config_unset_notice() {
		?>
		<div class="error">
			<p><?php esc_html_e( 'Please tell Network Administrator to set the default username/password/URL of the LRS (for plugin: WP ExperienceA API)', 'wpxapi' ); ?></p>
		</div>
		<?php
	}

	/**
	 * Displays admin notice showing that the network level LRS is not stopped by the admin
	 */
	public static function stop_all_statements_notice() {
		?>
		<div class="update-nag">
			<p><?php esc_html_e( 'The Network Administrator chose to stop allowing statements to be sent to the Network level LRS.', 'wpxapi' ); ?></p>
		</div>
		<?php
	}
	/**
	* Displays message saying that LRS settings at the network level is NOT set
	*/
	public static function config_unset_local_notice() {
		?>
		<div class="error">
			<p><?php esc_html_e( 'Please tell the Site Administrator to set the username/password/URL of the LRS (for plugin: WP ExperienceA API)' ); ?></p>
		</div>
		<?php
	}

	/**
	* checks that the php installed on the server meets the minimum required by this plugin (currently tincanPHP requires PHP5.4)
	*/
	public static function check_php_version() {
		return version_compare( phpversion(), WP_XAPI_MINIMUM_PHP_VERSION, '>=' );
	}

	/**
	 * funcky function within function so that I can get page url
	 *
	 * Props to http://stackoverflow.com/questions/5216172/getting-current-url
	 */
	public static function current_page_url() {
		$page_url = 'http';
		if ( isset( $_SERVER['HTTPS'] ) && 'on' == $_SERVER['HTTPS'] ) {
			$page_url .= 's';
		}

		$page_url .= '://';

		if ( '80' != $_SERVER['SERVER_PORT'] ) {
			$page_url .= $_SERVER['SERVER_NAME'] . ':' . $_SERVER['SERVER_PORT'] . $_SERVER['REQUEST_URI'];
		} else {
			$page_url .= $_SERVER['SERVER_NAME'] . $_SERVER['REQUEST_URI'];
		}
		return $page_url;
	}

	/**
	 * singleton wrapper for getting options
	 *
	 * @param $site boolean if true, then get site option.  If false, then get network options.
	 */
	public static function wpxapi_get_class_option( $network = true ) {
		if ( true === $network ) {
			//get network level options
			if ( null === WP_Experience_API::$site_options ) {
				static::$site_options = get_site_option( 'wpxapi_network_settings' );
			}
			return WP_Experience_API::$site_options;
		} else {
			// get site level options
			if ( null === WP_Experience_API::$options ) {
				static::$options = self::wpxapi_get_blog_option( 'wpxapi_settings' );
			}

			return WP_Experience_API::$options;
		}
	}

	/**
	 * Basically a wrapper for a wrapper so that getting options work for both
	 * multisite and stand alone sites
	 *
	 * @param $option_name String name of option wanted at single blog level
	 *
	 */
	private static function wpxapi_get_blog_option( $option_name ) {
		if ( function_exists( 'get_blog_option' ) ) {
			return get_blog_option( null, $option_name );
		} else {
			return get_option( $option_name );
		}
	}

	/**
	 * setup options for the class
	 */
	private static function setup_options() {
		self::$options = WP_Experience_API::wpxapi_get_class_option( false );
		self::$site_options = WP_Experience_API::wpxapi_get_class_option( true );
	}

	/**
	 * Pulls from the queue and sends tries to resend xAPI statements
	 *
	 * NOTES:
	 * - it goes through the entire queue
	 * - checks if past max tries
	 * - checks if past next retry time (based on last try time and # of attempts)
	 * - tries sending, if fails, adds back to queue
	 *
	 * @return boolean
	 */
	public static function wpxapi_run_the_queue() {
		if ( WP_Experience_API::wpxapi_queue_is_not_empty() ) {

			$count = WP_Experience_API::wpxapi_queue_is_not_empty( true );
			$i = 0; //counter

			//sanity check JUST to make sure it can/will end the loop
			if ( 0 >= $count ) {
				return false;
			}

			while ( $i < $count ) {
				$i++;

				//get queue object
				$queue_obj = WP_Experience_API::wpxapi_queue_dequeue();

				//sanity check that queue_obj is NOT empty
				if ( empty( $queue_obj ) ) {
					error_log( 'The queue is empty but is not supposed to be.' );
					return false;
				}

				//if past max tries, then throw error message into log.
				if ( $queue_obj->tries > WP_XAPI_MAX_SENDING_TRIES ) {
					error_log( 'Max number of tries exceeded for the following statement: '.print_r( $queue_obj->statement, true ) );
					return false;
				}

				//check retry time to make sure it's good still.
				$last_try_time = intval( strtotime( $queue_obj->last_try_time ) );
				$next_retry_time = $last_try_time + pow( 2, intval( $queue_obj->tries ) );
				if ( time() < $next_retry_time ) {
					//not time to try yet, so skip trying.  next time cron runs, it should check this again.
					continue;
				}

				//try sending the statement!
				$lrs = self::setup_lrs( $queue_obj->lrs_info );
				$response = $lrs->saveStatement( $queue_obj->statement );
				if ( false === (bool) $response->success ) {
					//failed, so enqueue!
					WP_Experience_API::wpxapi_queue_enqueue( $queue_obj, $lrs_info );
				}
			}
		}
		return true;
	}

	/**
	 * checks if global statement queue is empty or not
	 *
	 * @param boolean if true, then returns number, else returns boolean
	 * @return mixed if $count == true, return count, else return boolean
	 */
	public static function wpxapi_queue_is_not_empty( $count = false ) {
		global $wpdb;
		$return_value = null;
		$table_name = WP_Experience_Queue_Object::get_queue_table_name();

		$sql = "SELECT COUNT(*) FROM $table_name";
		$return_value = $wpdb->get_var( $sql );

		if ( ! $count ) {
			$return_value = (bool) $return_value;
		}

		return $return_value;
	}

	/**
	 * adds statement to queue
	 *
	 * This is triggered when statement fails to send
	 *
	 * @param mixed $statement either TinCan\Statement or WP_Experience_Queue_Object
	 * @param Array $lrs_info eg. array('endpoing' => 'xx', 'version' => 'yy', 'username' => 'zz', 'password')
	 * @return Boolean true if it worked, false otherwise
	 */
	public static function wpxapi_queue_enqueue( $statement, $lrs_info ) {
		$queue = null;
		$table_name = WP_Experience_Queue_Object::get_queue_table_name();

		//create queue instance based on what's passed in
		if ( $statement instanceof TinCan\Statement ) {
			//sanity check, for in this case, we need both parameters NOT empty!
			if ( empty( $lrs_info ) ) {
				return false;
			}
			$queue = WP_Experience_Queue_Object::with_statement_lrs_info( $statement, $lrs_info );
		} else if ( $statement instanceof WP_Experience_Queue_Object ){
			 $statement->tried_sending_again();
			 $queue = $statement;
		}

		//save queue!
		return (bool) $queue->save_row();
	}

	/**
	 * removes item from queue
	 *
	 * @return TinCan\Statement
	 */
	public static function wpxapi_queue_dequeue() {
		$queue_obj = WP_Experience_Queue_Object::get_row();

		return $queue_obj;
	}
}

WP_Experience_API::init();

//OK, so depending on single installation or multisite install, we do different

//single stand alone site install... register_activation_hook should work.
//the "queue" is global, so after the first activation where table is created,
//it is just checked to see the table is there on activation again.
register_activation_hook( __FILE__, array( 'WP_Experience_API', 'wpxapi_on_activate' ) );

//this is for special case of plugin working with mu_plugins folder
//@props https://wordpress.org/support/topic/register_activation_hook-on-multisite
if ( is_multisite() ) {
	//check if in mu_plugins folder.  We go up 2 directories because we assume
	//user installed it properly following instructions!
	if ( WPMU_PLUGIN_DIR == dirname( dirname( __FILE__ ) ) ) {
		WP_Experience_API::wpxapi_on_activate();
	}
}
