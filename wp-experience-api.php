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
 * - to make work:
 * - - need to set correct username and pasword for LRS in wp-experience-api-config.php
 * - - should also add a slug and hooks in trigers.php to make it trigger on whatever action.  add hooks there.
 * - can run without network activation on a per site basis as well as network wide. will give warning if network activated but no saved network options
 * - to make work in MU folder, just copy the plugin directory to the wp-content/mu-plugins folder, then copy wp-experience-api-mu-loader.php to the outside of hte folder directly under the mu-plugins folder.
 */
//basic configuration constants used throughout the plugin
use TinCan\Activity;

if ( ! defined( 'ABSPATH' ) ) {
	die( '-1' );
}

require_once( 'wp-experience-api-configs.php' );
require_once( 'includes/TinCanPHP/autoload.php' );

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

	/**
	 * Initialization function
	 *
	 * @return void
	 */
	public static function init() {

		// need to check for php version!  min 5.4
		if ( ! self::check_php_version() ) {
			deactivate_plugins( plugin_basename( __FILE__ ) );
			add_action( 'admin_notices', array( 'WP_Experience_API', 'php_disable_notice' ) );
			if ( isset( $_GET['activate'] ) ) {
				unset( $_GET['activate'] );
			}
		}

		if ( is_multisite() && ! function_exists( 'is_plugin_active_for_network' ) ) {
			require_once( ABSPATH . '/wp-admin/includes/plugin.php' );
		}

		if ( ( is_multisite() && is_plugin_active_for_network( 'wp-experience-api/wp-experience-api.php' ) ) || defined( 'WP_XAPI_MU_MODE' ) ) {
			self::$site_options = get_site_option( 'wpxapi_network_settings' );
			if ( ! empty( self::$site_options ) || ! empty( self::$site_options['wpxapi_network_lrs_password'] ) && ! empty( self::$site_options['wpxapi_network_lrs_username'] ) && ! empty( self::$site_options['wpxapi_network_lrs_url'] ) ) {

				self::$lrs1 = new TinCan\RemoteLRS(
					self::$site_options['wpxapi_network_lrs_url'],
					WP_XAPI_DEFAULT_XAPI_VERSION,
					self::$site_options['wpxapi_network_lrs_username'],
					self::$site_options['wpxapi_network_lrs_password']
				);
			} else {
				//use defaults from wp-experience-api-configs.php
				self::$lrs1 = new TinCan\RemoteLRS(
					WP_XAPI_DEFAULT_PRIMARY_LRS_URL,
					WP_XAPI_DEFAULT_XAPI_VERSION,
					WP_XAPI_DEFAULT_PRIMARY_LRS_USERNAME,
					WP_XAPI_DEFAULT_PRIMARY_LRS_PASSWORD
				);
				add_action( 'admin_notices', array( 'WP_Experience_API', 'config_unset_notice' ) );

				error_log( 'Please tell Network Administrator to set the default username/password/URL of the LRS (for plugin: WP ExperienceA API)' );
			}
		}

		if ( is_admin() || is_network_admin() ) {
			require_once( plugin_dir_path( __FILE__ ).'wp-experience-api-admin.php' );
		}

		require_once( plugin_dir_path( __FILE__ ).'includes/triggers.php' );
		add_action( 'init', array( __CLASS__, 'load' ) );

		WP_Experience_API::$options = get_option( 'wpxapi_settings' );
	}

	/**
	 * Converts the triggers created by the trigger.php and starts sending the statements to the LRS
	 *
	 * @return void
	 */
	public static function load() {
		$register_locked = true;

		foreach ( self::$triggers as $slug => $trigger ) {
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
		if ( ! self::$register_locked ) {
			self::$triggers[ $slug ] = $data;
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
		if ( ! self::$register_locked ) {
			unset( self::$triggers[ $slug ] );
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

		self::post( new TinCan\Statement( apply_filters( 'wpxapi_statement', $statement ) ) );
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
			$response = self::$lrs1->saveStatement( $data );
			//error_log('response:');error_log( print_r( $response, true ) );
		}

		if ( ! empty( WP_Experience_API::$options['wpxapi_lrs_url'] ) && ! empty( WP_Experience_API::$options['wpxapi_lrs_username'] ) && ! empty( WP_Experience_API::$options['wpxapi_lrs_password'] ) ) {
			$lrs2 = new TinCan\RemoteLRS(
				WP_Experience_API::$options['wpxapi_lrs_url'],
				WP_XAPI_DEFAULT_XAPI_VERSION,
				WP_Experience_API::$options['wpxapi_lrs_username'],
				WP_Experience_API::$options['wpxapi_lrs_password']
			);
			$response2 = $lrs2->saveStatement( $data );
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
				$unique_id = apply_filters( 'wpxapi_actor_account_name', get_user_meta( $user_data->ID, WP_XAPI_DEFAULT_ACTOR_ACCOUNT_NAME, true ) );

				if ( empty( $unique_id ) ) {
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
			if ( isset($data['object']['definition'] ) && is_array( $data['object']['definition'] ) ) {
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
		foreach ( self::$dependencies as $class => $url ) {
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
		$return = self::meets_badgeOS_dependencies();

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
	 * Displays message saying that related required plugins for Mozilla Open Badges Protocol not installed
	 * @return [type] [description]
	 */
	public static function dependencies_disable_notice() {
		echo "<div class='error'>";
		foreach ( self::$dependencies as $class => $url ) {
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
}

WP_Experience_API::init();
