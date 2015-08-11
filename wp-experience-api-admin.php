<?php
/**
 * Section to do settings page for the wp-experience-api
 */

class WP_Experience_API_Admin {

	//site level options
	private $options;

	//network level options
	private $site_options;

	//fields that are saved. need to be smarter though
	private static $fields = array(
		'wpxapi_network_lrs_stop_all' => 'No',
		'wpxapi_network_lrs_stop_network_level_only' => 'No',
		'wpxapi_network_lrs_url' => '',
		'wpxapi_network_lrs_username' => '',
		'wpxapi_network_lrs_password' => '',
		'wpxapi_network_lrs_admin' => '',
		'wpxapi_network_lrs_admin_level' => '2',
		'wpxapi_network_lrs_guest' => '',
		'wpxapi_network_lrs_whitelist' => '',
		'wpxapi_network_lrs_user_setting' => '2',
		//***** site level defaults ****//
		'wpxapi_network_lrs_site_page_views' => 3,
		'wpxapi_network_lrs_site_posts' => 5,
		'wpxapi_network_lrs_site_guest' => 0,
		'wpxapi_network_lrs_site_comments' => 0,
		'wpxapi_network_lrs_site_earn_badges' => 0,
		'wpxapi_network_lrs_site_pulsepress' => 4,
	);

	//need this for sanitization, cause default is to not save checkbox value if not checked!  I want it to be 0 of not checked.
	private static $checkbox_options = array(
		'wpxapi_comments',
		'wpxapi_badges',
		'wpxapi_guest',
	);

	//options for what kind of pages to track
	public static $page_options = array(
		'1' => 'All Pages',
		'2' => 'Singular Pages Only',
		'3' => 'No Pages',
	);

	//options for what kind of post status changes to track
	public static $publish_options = array(
		'1' => 'Track posts being published, updated, retracted, and deleted',
		'2' => 'Track posts being published, updated, and deleted',
		'3' => 'Track posts being published and deleted',
		'4' => 'Only track posts being published',
		'5' => 'Do not track any posts status changes',
	);

	//options for what kind of pulsepress things to track
	public static $pulsepress_options = array(
		'1' => 'track all votes and favoriting',
		'2' => 'track only votes',
		'3' => 'track only favoriting',
		'4' => 'do not track votes or favoriting',
	);

	/**
	 * Constructor
	 *
	 * @param void
	 * @return void;
	 */
	public function __construct() {
		$this->setup_options();

		add_action( 'admin_menu', array( $this, 'wp_xapi_add_admin_menu' ) );
		add_action( 'admin_init', array( $this, 'wp_xapi_settings_init' ) );

		if ( is_network_admin() ) {
			new WP_Experience_API_Network_Admin();
		}

		add_action( 'wp_ajax_run_queue', array( __CLASS__, 'wp_xapi_check_and_run_queue' ) );
		add_action( 'admin_footer',  array( __CLASS__, 'wp_xapi_add_js' ) );
	}

	/**
	 * Helper function to view stuff
	 *
	 * @return boolean tells whether we should show the page or not
	 */
	private function wp_xapi_check_if_can_view() {
		//setup site admin level stuff whether to show site level lrs settings page or not
		$show_admin_page = false;
		if ( $this->site_options['wpxapi_network_lrs_admin_level'] == '1' ) {
			$usernames = array_map( 'trim', explode( ',', $this->site_options['wpxapi_network_lrs_admin'] ) );
			$user = wp_get_current_user();
			if ( in_array( $user->user_login, $usernames ) ) {
				$show_admin_page = true;
			}
		} else if ( $this->site_options['wpxapi_network_lrs_admin_level'] == '2' ) {
			$show_admin_page = true;
		}

		return ( $show_admin_page || is_super_admin() );
	}
	/**
	 * adds new wp xapi plugin page
	 */
	public function wp_xapi_add_admin_menu() {
		// adding new page
		if ( $this->wp_xapi_check_if_can_view() ) {
			add_menu_page( 'WP xAPI', 'WP xAPI', 'manage_options', 'wpxapi', array( $this, 'wp_xapi_options_page' ), 'dashicons-format-status' );
		}
	}

	/**
	 * WP Settings API setting up fields
	 *
	 * @return void
	 */
	public function wp_xapi_settings_init() {
		//simple check to see if can show anything based on network level admin stuff
		if ( ! $this->wp_xapi_check_if_can_view() ) {
			return;
		}
		register_setting(
			'wpxapi',
			'wpxapi_settings',
			array( __CLASS__, 'wp_xapi_sanitize_options' )
		);

		add_settings_section(
			'wpxapi_settings_section',
			__( 'WP xAPI Settings', 'wpxapi' ),
			array( $this, 'wp_xapi_section_callback' ),
			'wpxapi'
		);

		//since this is triggering running of the queue, ONLY admins/Superadmins should see this option.
		if ( is_super_admin() ) {
			add_settings_field(
				'wpxapi_run_queue',
				__( 'Run xAPI Queue', 'wpxapi' ),
				array( $this, 'wp_xapi_run_xapi_queue_render' ),
				'wpxapi',
				'wpxapi_settings_section'
			);
		}

		add_settings_field(
			'wpxapi_pages',
			__( 'Record page views?', 'wpxapi' ),
			array( $this, 'wp_xapi_pages_render' ),
			'wpxapi',
			'wpxapi_settings_section'
		);

		add_settings_field(
			'wpxapi_publish',
			__( 'Record anything being published?', 'wpxapi' ),
			array( $this, 'wp_xapi_publish_render' ),
			'wpxapi',
			'wpxapi_settings_section'
		);

		if ( isset( $this->site_options['wpxapi_network_lrs_guest'] ) ) {
			$ids = array_map( 'trim', explode( ',', $this->site_options['wpxapi_network_lrs_guest'] ) );
			$site_id = get_current_blog_id();
			if ( in_array( $site_id, $ids ) ) {
				add_settings_field(
					'wpxapi_guest',
					__( 'Record Guest Page Views?', 'wpxapi' ),
					array( $this, 'wp_xapi_guest_render' ),
					'wpxapi',
					'wpxapi_settings_section'
				);
			} else {
				//ok, since we DON'T want to allow the site to record guests, we will turn it off
				$this->options['wpxapi_guest'] = 0;
				update_option( 'wpxapi_settings', $this->options );
			}
		}

		add_settings_field(
			'wpxapi_comments',
			__( 'Record comments?', 'wpxapi' ),
			array( $this, 'wp_xapi_comments_render' ),
			'wpxapi',
			'wpxapi_settings_section'
		);

		if ( WP_Experience_API::meets_badgeOS_dependencies() ) {
			add_settings_field(
				'wpxapi_badges',
				__( 'Record Earning Badges?', 'wpxapi' ),
				array( $this, 'wp_xapi_badges_render' ),
				'wpxapi',
				'wpxapi_settings_section'
			);
		}

		if ( WP_Experience_API::is_using_pulsepress_theme() ) {
			add_settings_field(
				'wpxapi_voting',
				__( 'Record PulsePress Theme voting?', 'wpxapi' ),
				array( $this, 'wp_xapi_voting_render' ),
				'wpxapi',
				'wpxapi_settings_section'
			);
		}

		if ( isset( $this->site_options['wpxapi_network_lrs_admin'] ) ) {
			$usernames = array_map( 'trim', explode( ',', $this->site_options['wpxapi_network_lrs_admin'] ) );
			$user = wp_get_current_user();
			if ( in_array( $user->user_login, $usernames ) || is_super_admin() ) {
				add_settings_section(
					'wpxapi_settings_section_lrs',
					__( 'WP xAPI LRS Settings', 'wpxapi' ),
					array( $this, 'wp_xapi_section_callback' ),
					'wpxapi'
				);

				add_settings_field(
					'wpxapi_lrs_url',
					__( 'LRS url', 'wpxapi' ),
					array( $this, 'wp_xapi_lrs_url_render' ),
					'wpxapi',
					'wpxapi_settings_section_lrs'
				);

				add_settings_field(
					'wpxapi_lrs_username',
					__( 'LRS Username', 'wpxapi' ),
					array( $this, 'wp_xapi_lrs_username_render' ),
					'wpxapi',
					'wpxapi_settings_section_lrs'
				);

				add_settings_field(
					'wpxapi_lrs_password',
					__( 'LRS Password', 'wpxapi' ),
					array( $this, 'wp_xapi_lrs_password_render' ),
					'wpxapi',
					'wpxapi_settings_section_lrs'
				);
			}
		}
	}

	/**
	 * WP Settings API structuring page layout
	 *
	 * @return void
	 */
	public function wp_xapi_options_page() {

		//output check for network admin settings is set
		if ( ! function_exists( 'is_plugin_active_for_network' ) ) {
			require_once( ABSPATH . '/wp-admin/includes/plugin.php' );
		}
		if ( is_plugin_active_for_network( 'wp-experience-api' ) ) {
			$site_options = get_site_option( 'wpxapi_network_settings' );
			if ( ! empty( $site_options ) && ! empty( $site_options['wpxapi_network_lrs_password'] ) && ! empty( $site_options['wpxapi_network_lrs_username'] ) && ! empty( $site_options['wpxapi_network_lrs_url'] ) ) {
				//do nothing since network administrator set up stuff!
			} else {
				?>
				<div id='message' class='error'>
					<?php echo esc_html__( 'Please ask the Network Administrator to set the network level options', 'wpxapi' ); ?>
				</div>
				<?php
			}
		}
		//hack mentioned on https://codex.wordpress.org/Function_Reference/add_settings_error to display saving errors
		settings_errors();
		?>
			<form action='options.php' method='post'>
			<h2>WP xAPI Options</h2>

				<?php
				settings_fields( 'wpxapi' );
				do_settings_sections( 'wpxapi' );
				submit_button();
				?>

			</form>
		<?php
	}

	public function wp_xapi_run_xapi_queue_render() {
		$count = WP_Experience_API::wpxapi_queue_is_not_empty( true );
		?>
			<input type='button' class='button button-primary' id='wpxapi_run_queue' value="<?php echo esc_html__( 'Run Queue' );?>"><br>
			<div class="help-div"><?php echo esc_html__( 'Count of xAPI queue entries: ' , 'wpxapi' ) .'<span id="wpxapi_run_queue_count">' . esc_html( $count ) . '</span>'; ?></div>
		<?php
	}

	/**
	 * Outputs page view tracking options
	 *
	 * @return void
	 */
	public function wp_xapi_pages_render() {
		?>
		<select name='wpxapi_settings[wpxapi_pages]'>
		<?php
		foreach ( WP_Experience_API_Admin::$page_options as $key => $value ) {
			echo "<option value='" . esc_attr( $key ) . "' " . selected( $this->options['wpxapi_pages'], $key ) . '>' . esc_html( $value ) . '</option>';
		}
		?>
		</select>
		<?php
	}

	/**
	 * Outputs comment  tracking
	 *
	 * @return void
	 */
	public function wp_xapi_comments_render() {
		?>
			<input type='checkbox' name='wpxapi_settings[wpxapi_comments]' <?php checked( $this->options['wpxapi_comments'], 1 ); ?> value='1'>
		<?php
	}

	/**
	 * Outputs post/page status changes
	 *
	 * @return void
	 */
	public function wp_xapi_publish_render() {
		?>
			<select name='wpxapi_settings[wpxapi_publish]'>
		<?php
		foreach ( WP_Experience_API_Admin::$publish_options as $key => $value ) {
			echo "<option value='" . esc_attr( $key ) . "' " . selected( $this->options['wpxapi_publish'], $key ) . '>' . esc_html( $value ) . '</option>';
		}
		?>
			</select>
		<?php
	}

	/**
	 * Outputs PulsePress theme specific tracking options
	 *
	 * @return void
	 */
	public function wp_xapi_voting_render() {
		?>
			<select name='wpxapi_settings[wpxapi_voting]'>
		<?php
		foreach ( WP_Experience_API_Admin::$pulsepress_options as $key => $value ) {
			echo "<option value='" . esc_attr( $key ) . "' " . selected( $this->options['wpxapi_voting'], $key ) . '>' . esc_html( $value ) . '</option>';
		}
		?>
			</select>
		<?php
	}

	/**
	 * Outputs earning badge tracking options
	 *
	 * @return void
	 */
	public function wp_xapi_badges_render() {
		?>
			<input type='checkbox' name='wpxapi_settings[wpxapi_badges]' <?php checked( $this->options['wpxapi_badges'], 1 ); ?> value='1'>
		<?php
	}

	/**
	 * Outputs anonymous page views options
	 *
	 * @return void
	 */
	public function wp_xapi_guest_render() {
		?>
			<input type='checkbox' name='wpxapi_settings[wpxapi_guest]' <?php checked( $this->options['wpxapi_guest'], 1 ); ?> value='1'>
		<?php
	}

	/**
	 * Outputs setting local LRS url field
	 *
	 * @return void
	 */
	public function wp_xapi_lrs_url_render() {
		?>
			<input type='text' name='wpxapi_settings[wpxapi_lrs_url]' value='<?php echo esc_html( $this->options['wpxapi_lrs_url'] ); ?>'>
		<?php
	}

	/**
	 * Outputs setting local LRS username field
	 *
	 * @return void
	 */
	public function wp_xapi_lrs_username_render() {
		?>
		<input type='text' name='wpxapi_settings[wpxapi_lrs_username]' value='<?php echo esc_html( $this->options['wpxapi_lrs_username'] ); ?>'>
		<?php
	}

	/**
	 * Outputs lrs password field
	 */
	public function wp_xapi_lrs_password_render() {
		?>
		<input type='text' name='wpxapi_settings[wpxapi_lrs_password]' value='<?php echo esc_html( $this->options['wpxapi_lrs_password'] ); ?>'>
		<?php
	}

	/**
	 * placeholder for callback (in case we want to use it)
	 */
	public function wp_xapi_section_callback() {
		//echo __('WP xAPI Settings Section Description', 'wpxapi');
	}

	/**
	 * function to setup opitons, initialize and save / or pull from options table
	 */
	public function setup_options() {
		//setup for site wide options
		$this->site_options = get_site_option( 'wpxapi_network_settings' );
		if ( false === $this->site_options ) {
			$this->site_options = self::$fields;
			add_site_option( 'wpxapi_network_settings', $this->site_options );
		}

		$this->options = WP_Experience_API::wpxapi_get_class_option( false );
		if ( false === $this->options ) {
			//we want to use network level set stuff if it is set, else use defaults.
			$pages = isset( $this->site_options['wpxapi_network_lrs_site_page_views'] ) ? $this->site_options['wpxapi_network_lrs_site_page_views'] : 3;
			$comments = isset( $this->site_options['wpxapi_network_lrs_site_comments'] ) ? $this->site_options['wpxapi_network_lrs_site_comments'] : 0;
			$badges = isset( $this->site_options['wpxapi_network_lrs_site_earn_badges'] ) ? $this->site_options['wpxapi_network_lrs_site_earn_badges'] : 0;
			$guest = isset( $this->site_options['wpxapi_network_lrs_site_guest'] ) ? $this->site_options['wpxapi_network_lrs_site_guest'] : 0;
			$publish = isset( $this->site_options['wpxapi_network_lrs_site_posts'] ) ? $this->site_options['wpxapi_network_lrs_site_posts'] : 5;
			$voting = isset( $this->site_options['wpxapi_network_lrs_site_pulsepress'] ) ? $this->site_options['wpxapi_network_lrs_site_pulsepress'] : 4;

			$this->options = array(
				'wpxapi_pages' => $pages,
				'wpxapi_comments' => $comments,
				'wpxapi_badges' => $badges,
				'wpxapi_guest' => $guest,
				'wpxapi_publish' => $publish,
				'wpxapi_voting' => $voting,
				'wpxapi_lrs_url' => '',
				'wpxapi_lrs_username' => '',
				'wpxapi_lrs_password' => '',
			);

			add_option( 'wpxapi_settings', $this->options );
		}
	}

	/**
	 * Sanitizes user input.
	 *
	 * - sets value of checkbox to 0 if unselected.  (Default WP behaviour is to not save the key even in options table.)
	 * - also checks if URL of local LRS is acceptable based on values set by network level settings
	 *
	 * @param array $input
	 * @return array
	 */
	public static function wp_xapi_sanitize_options( $input ) {
		//checks to see if lrs endpoint is whitelisted
		$site_options = get_site_option( 'wpxapi_network_settings' );
		$lrs_url = $input['wpxapi_lrs_url'];
		$lrs_whitelist = preg_split( '/\r\n|\r|\n/', $site_options['wpxapi_network_lrs_whitelist'] );
		if ( ! empty( $lrs_whitelist ) ) {
			$white_list_pass = false;
			if ( ! empty( $lrs_url ) ) {
				foreach ( $lrs_whitelist as $check_url ) {
					if ( substr( $lrs_url, 0, strlen( $check_url ) ) == $check_url ) {
						$white_list_pass = true;
						break;
					}
				}
				if ( ! $white_list_pass ) {
					add_settings_error(
						'wpxapiEndpoinInvalid',
						'wpxapi-invalid-lrs-endpoint',
						__( 'You have entered an invalid LRS Endpoint', 'wpxapi' ),
						'error'
					);
					$input['wpxapi_lrs_url'] = '';
				}
			}
		}

		//fixes to make checkbox to 0 instead of default unset
		foreach ( WP_Experience_API_Admin::$checkbox_options as $checkboxKeys ) {
			if ( ! isset( $input[ $checkboxKeys ] ) ) {
				$input[ $checkboxKeys ] = 0;
			}
		}

		return $input;
	}

	/**
	 * runs the queue and returns json response with count and message.
	 */
	public static function wp_xapi_check_and_run_queue() {
		check_ajax_referer( 'wp_ajax_run_queue', 'security' );
		$finished = WP_Experience_API::wpxapi_run_the_queue();
		$count = array( 'count' => 0, 'message' => 'The queue had issues and could not run.' );
		if ( $finished ) {
			$count['count'] = WP_Experience_API::wpxapi_queue_is_not_empty( true );
			$count['message'] = __( 'The queue ran successfully!' );
		}
		header( 'Content-Type: application/json' );
		echo wp_json_encode( $count );

		wp_die();
	}

	/**
	 * function to output simple javascript.
	 */
	public static function wp_xapi_add_js() {
		$nonce = wp_create_nonce( 'wp_ajax_run_queue' );
		$url = admin_url( 'admin-ajax.php' );
		if ( is_network_admin() || is_admin() ) {
			?>
				<script type='text/javascript' id='holdemhat'>
					jQuery(document).ready(function() {
						var data = {
							'action': 'run_queue',
							'security': '<?php echo esc_html( $nonce ); ?>'
						};
						var network_ajax_url = '<?php echo esc_html( $url ); ?>';

						jQuery( '#wpxapi_run_queue' ).on('click', function() {
							jQuery.post(network_ajax_url, data, function(response) {
								jQuery( '#wpxapi_run_queue_count' ).text( response.count );
								alert( response.message );
							});
							return false;
						});
					});
				</script>
			<?php
		}
	}

	/**
	 * Currently unused helper function to convert between int and page options
	 *
	 * @param  int $page_option_int
	 * @return string
	 */
	public static function wp_xapi_page_option_to_string( $page_option_int ) {
		return WP_Experience_API_Admin::$page_options[ $page_option_int ];
	}
}

$experience_api_admin = new WP_Experience_API_Admin();

//==========***********----------------*************===========//
								//===== New Class ======//
////==========***********----------------*************===========//
class WP_Experience_API_Network_Admin {

	//options so that we only call it once instead of each time we need it
	private $options;

	//fields that are saved. need to be smarter though
	private $fields = array(
		'wpxapi_network_lrs_stop_all' => 'No',
		'wpxapi_network_lrs_stop_network_level_only' => 'No',
		'wpxapi_network_lrs_url' => '',
		'wpxapi_network_lrs_username' => '',
		'wpxapi_network_lrs_password' => '',
		'wpxapi_network_lrs_admin' => '',
		'wpxapi_network_lrs_admin_level' => '2',
		'wpxapi_network_lrs_guest' => '',
		'wpxapi_network_lrs_whitelist' => '',
		'wpxapi_network_lrs_user_setting' => '2',
		//***** site level defaults ****//
		'wpxapi_network_lrs_site_page_views' => 3,
		'wpxapi_network_lrs_site_posts' => 5,
		'wpxapi_network_lrs_site_guest' => 0,
		'wpxapi_network_lrs_site_comments' => 0,
		'wpxapi_network_lrs_site_earn_badges' => 0,
		'wpxapi_network_lrs_site_pulsepress' => 4,
	);

	private static $checkbox_fields = array(
		'wpxapi_network_lrs_site_guest',
		'wpxapi_network_lrs_site_comments',
		'wpxapi_network_lrs_site_earn_badges',
	);

	private static $admin_level_options = array(
		'1' => 'Manage all site level settings',
		'2' => 'Manage only site level LRS settings',
	);

	//options to determine how to id a user in xAPI statement
	private static $user_setting_options = array(
		'1' => 'Account',
		'2' => 'Email',
	);

	/**
	 * Constructor
	 */
	public function __construct() {
		//don't setup network activate if not activated
		if ( is_multisite() && ! function_exists( 'is_plugin_active_for_network' ) ) {
			require_once( ABSPATH . '/wp-admin/includes/plugin.php' );
		}
		if ( ( is_multisite() && is_plugin_active_for_network( 'wp-experience-api/wp-experience-api.php' ) ) || defined( 'WP_XAPI_MU_MODE' ) ) {
			add_action( 'network_admin_menu', array( $this, 'wp_xapi_add_network_admin_menu' ) );
			add_action( 'admin_init', array( $this, 'wp_xapi_network_settings_init' ) );

			$this->setup_options();
		}
		add_action( 'wp_ajax_run_queue', array( 'WP_Experience_API_Admin', 'wp_xapi_check_and_run_queue' ) );
		add_action( 'admin_footer',  array( 'WP_Experience_API_Admin', 'wp_xapi_add_js' ) );
	}

	/**
	 * Adds network level admin page
	 */
	public function wp_xapi_add_network_admin_menu() {
		// adding new page
		add_menu_page( 'WP xAPI Network', 'WP xAPI Network', 'manage_network_plugins', 'wpxapi_network', array( $this, 'wp_xapi_network_options_page' ), 'dashicons-format-status' );
	}

	/**
	 * Creates structure of network level settings page
	 *
	 * @return void
	 */
	public function wp_xapi_network_options_page() {
		//some logic to save the page!
		if ( is_network_admin() && ! empty( $_POST ) ) {
			$valid_fields = array_keys( $this->fields );
			$save_array = $this->fields;
			foreach ( $_POST['wpxapi_network_settings'] as $key => $value ) {
				if ( in_array( $key, $valid_fields ) ) {
					$save_array[ $key ] = $value;
				}
			}

			update_site_option( 'wpxapi_network_settings', $save_array );
			$this->options = $save_array;
		}

		?>
			<form method='post'>
			<h2>WP xAPI Options</h2>

				<?php
				settings_fields( 'wpxapi_network' );
				do_settings_sections( 'wpxapi_network' );
				submit_button();
				?>

			</form>
		<?php
	}

	/**
	 * Setting up network level settings page fields
	 *
	 * @return void
	 */
	public function wp_xapi_network_settings_init() {
		register_setting(
			'wpxapi_network',
			'wpxapi_network_settings',
			array( __CLASS__, 'wp_xapi_sanitize_network_options' )
		);

		add_settings_section(
			'wpxapi_settings_section_lrs',
			__( 'WP xAPI Network Settings', 'wpxapi' ),
			array( $this, 'wp_xapi_network_section_callback' ),
			'wpxapi_network'
		);
		//yes, I know. I stuffed the logic and render inside the regular admin class....
		add_settings_field(
			'wpxapi_run_queue',
			__( 'Run xAPI Queue', 'wpxapi' ),
			array( 'WP_Experience_API_Admin', 'wp_xapi_run_xapi_queue_render' ),
			'wpxapi_network',
			'wpxapi_settings_section_lrs'
		);
		add_settings_field(
			'wpxapi_network_lrs_stop_all',
			__( 'Stop ALL statements', 'wpxapi' ),
			array( $this, 'wp_xapi_network_lrs_stop_all_render' ),
			'wpxapi_network',
			'wpxapi_settings_section_lrs'
		);
		add_settings_field(
			'wpxapi_network_lrs_stop_network_level_only',
			__( 'Stop sending statements to Network LRS ONLY', 'wpxapi' ),
			array( $this, 'wp_xapi_network_lrs_stop_network_level_only_render' ),
			'wpxapi_network',
			'wpxapi_settings_section_lrs'
		);
		add_settings_field(
			'wpxapi_network_lrs_url',
			__( 'LRS URL', 'wpxapi' ),
			array( $this, 'wp_xapi_network_lrs_url_render' ),
			'wpxapi_network',
			'wpxapi_settings_section_lrs'
		);
		add_settings_field(
			'wpxapi_network_lrs_username',
			__( 'LRS Username', 'wpxapi' ),
			array( $this, 'wp_xapi_network_lrs_username_render' ),
			'wpxapi_network',
			'wpxapi_settings_section_lrs'
		);
		add_settings_field(
			'wpxapi_network_lrs_password',
			__( 'LRS Password', 'wpxapi' ),
			array( $this, 'wp_xapi_network_lrs_password_render' ),
			'wpxapi_network',
			'wpxapi_settings_section_lrs'
		);
		add_settings_field(
			'wpxapi_network_lrs_user_setting',
			__( 'LRS Actor Setting', 'wpxapi' ),
			array( $this, 'wp_xapi_network_lrs_user_setting_render' ),
			'wpxapi_network',
			'wpxapi_settings_section_lrs'
		);
		add_settings_field(
			'wpxapi_network_lrs_admin',
			__( 'Whitelisted Users', 'wpxapi' ),
			array( $this, 'wp_xapi_network_lrs_admin_render' ),
			'wpxapi_network',
			'wpxapi_settings_section_lrs'
		);
		add_settings_field(
			'wpxapi_network_lrs_admin_level',
			__( 'Whitelisted Users Management Level', 'wpxapi' ),
			array( $this, 'wp_xapi_network_lrs_admin_level_render' ),
			'wpxapi_network',
			'wpxapi_settings_section_lrs'
		);
		add_settings_field(
			'wpxapi_network_lrs_guest',
			__( 'Sites that can Log Anonymous Guests', 'wpxapi' ),
			array( $this, 'wp_xapi_network_lrs_guest_render' ),
			'wpxapi_network',
			'wpxapi_settings_section_lrs'
		);
		add_settings_field(
			'wpxapi_network_lrs_whitelist',
			__( 'Whitelisted LRS domains', 'wpxapi' ),
			array( $this, 'wp_xapi_network_lrs_whitelist_render' ),
			'wpxapi_network',
			'wpxapi_settings_section_lrs'
		);

		//site default settings
		add_settings_section(
			'wpxapi_settings_site_section_lrs',
			__( 'WP xAPI Site Level Default Settings', 'wpxapi' ),
			array( $this, 'wp_xapi_network_section_callback' ),
			'wpxapi_network'
		);
		add_settings_field(
			'wpxapi_network_lrs_site_page_views',
			__( 'Record page views?', 'wpxapi' ),
			array( $this, 'wp_xapi_network_lrs_site_page_views_render' ),
			'wpxapi_network',
			'wpxapi_settings_site_section_lrs'
		);
		add_settings_field(
			'wpxapi_network_lrs_site_posts',
			__( 'Record anything being published?', 'wpxapi' ),
			array( $this, 'wp_xapi_network_lrs_site_posts_render' ),
			'wpxapi_network',
			'wpxapi_settings_site_section_lrs'
		);
		add_settings_field(
			'wpxapi_network_lrs_site_guest',
			__( 'Record Guest Page Views?', 'wpxapi' ),
			array( $this, 'wp_xapi_network_lrs_site_guest_render' ),
			'wpxapi_network',
			'wpxapi_settings_site_section_lrs'
		);
		add_settings_field(
			'wpxapi_network_lrs_site_comments',
			__( 'Record comments?', 'wpxapi' ),
			array( $this, 'wp_xapi_network_lrs_site_comments_render' ),
			'wpxapi_network',
			'wpxapi_settings_site_section_lrs'
		);
		add_settings_field(
			'wpxapi_network_lrs_site_earn_badges',
			__( 'Record Earning Badges?', 'wpxapi' ),
			array( $this, 'wp_xapi_network_lrs_site_earn_badges_render' ),
			'wpxapi_network',
			'wpxapi_settings_site_section_lrs'
		);
		add_settings_field(
			'wpxapi_network_lrs_site_pulsepress',
			__( 'Record PulsePress Theme voting?', 'wpxapi' ),
			array( $this, 'wp_xapi_network_lrs_site_pulsepress_render' ),
			'wpxapi_network',
			'wpxapi_settings_site_section_lrs'
		);
	}

	/**
	 * Outputs radio kill switch to stop all statements being sent to LRS
	 *
	 * @return void
	 */
	public function wp_xapi_network_lrs_stop_all_render() {
		?>
		<input type='radio' name='wpxapi_network_settings[wpxapi_network_lrs_stop_all]' value='No' <?php checked( $this->options['wpxapi_network_lrs_stop_all'], 'No' ) ?>>No
		<input type='radio' name='wpxapi_network_settings[wpxapi_network_lrs_stop_all]' value='Yes' <?php checked( $this->options['wpxapi_network_lrs_stop_all'], 'Yes' )?>>Yes
		<?php
	}

	/**
	* Outputs radio kill switch ONLY at the network level to stop all statements being sent to LRS
	*
	* @return void
	*/
	public function wp_xapi_network_lrs_stop_network_level_only_render() {
		?>
		<input type='radio' name='wpxapi_network_settings[wpxapi_network_lrs_stop_network_level_only]' value='No' <?php checked( $this->options['wpxapi_network_lrs_stop_network_level_only'], 'No' ) ?>>No
		<input type='radio' name='wpxapi_network_settings[wpxapi_network_lrs_stop_network_level_only]' value='Yes' <?php checked( $this->options['wpxapi_network_lrs_stop_network_level_only'], 'Yes' )?>>Yes
		<?php
	}

	/**
	 * Outputs site level default settings
	 *
	 * @return void
	 */
	public function wp_xapi_network_lrs_site_page_views_render() {
		?>
		<select name='wpxapi_network_settings[wpxapi_network_lrs_site_page_views]'>
		<?php
		foreach ( WP_Experience_API_Admin::$page_options as $key => $value ) {
			echo "<option value='" . esc_attr( $key ) . "' " . selected( $this->options['wpxapi_network_lrs_site_page_views'], $key ) . '>' . esc_html( $value ) . '</option>';
		}
		?>
		</select>
		<?php
	}

	/**
	 * Outputs default site posts options
	 *
	 * @return void
	 */
	public function wp_xapi_network_lrs_site_posts_render() {
		?>
			<select name='wpxapi_network_settings[wpxapi_network_lrs_site_posts]'>
		<?php
		foreach ( WP_Experience_API_Admin::$publish_options as $key => $value ) {
			echo "<option value='" . esc_attr( $key ) . "' " . selected( $this->options['wpxapi_network_lrs_site_posts'], $key ) . '>' . esc_html( $value ) . '</option>';
		}
		?>
			</select>
		<?php
	}

	/**
	* Outputs default site anonymous page views options
	*
	* @return void
	*/
	public function wp_xapi_network_lrs_site_guest_render() {
		?>
			<input type='checkbox' name='wpxapi_network_settings[wpxapi_network_lrs_site_guest]' <?php checked( $this->options['wpxapi_network_lrs_site_guest'], 1 ); ?> value='1'>
		<?php
	}

	/**
	 * Outputs default site level comment options
	 *
	 * @return void
	 */
	public function wp_xapi_network_lrs_site_comments_render() {
		?>
			<input type='checkbox' name='wpxapi_network_settings[wpxapi_network_lrs_site_comments]' <?php checked( $this->options['wpxapi_network_lrs_site_comments'], 1 ); ?> value='1'>
		<?php
	}
	/**
	* Outputs site level default earning badge tracking options
	*
	* @return void
	*/
	public function wp_xapi_network_lrs_site_earn_badges_render() {
		?>
			<input type='checkbox' name='wpxapi_network_settings[wpxapi_network_lrs_site_earn_badges]' <?php checked( $this->options['wpxapi_network_lrs_site_earn_badges'], 1 ); ?> value='1'>
		<?php
	}

	/**
	 * Outputs site level default pulsepress theme voting options
	 *
	 * @return void
	 */
	public function wp_xapi_network_lrs_site_pulsepress_render() {
		?>
			<select name='wpxapi_network_settings[wpxapi_network_lrs_site_pulsepress]'>
		<?php
		foreach ( WP_Experience_API_Admin::$pulsepress_options as $key => $value ) {
			echo "<option value='" . esc_attr( $key ) . "' " . selected( $this->options['wpxapi_network_lrs_site_pulsepress'], $key ) . '>' . esc_html( $value ) . '</option>';
		}
		?>
			</select>
		<?php
	}
	/**
	 * Outputs the network level LRS URL field
	 *
	 * @return void
	 */
	public function wp_xapi_network_lrs_url_render() {
		?>
			<input type='text' name='wpxapi_network_settings[wpxapi_network_lrs_url]' value='<?php echo esc_url( $this->options['wpxapi_network_lrs_url'] ); ?>'>
		<?php
	}

	/**
	 * Outputs the network level LRS username field
	 *
	 * @return void
	 */
	public function wp_xapi_network_lrs_username_render() {
		?>
			<input type='text' name='wpxapi_network_settings[wpxapi_network_lrs_username]' value='<?php echo esc_attr( $this->options['wpxapi_network_lrs_username'] ); ?>'>
		<?php
	}

	/**
	 * Outputs the network level LRS password field
	 *
	 * @return void
	 */
	public function wp_xapi_network_lrs_password_render() {
		?>
			<input type='text' name='wpxapi_network_settings[wpxapi_network_lrs_password]' value='<?php echo esc_attr( $this->options['wpxapi_network_lrs_password'] ); ?>'>
		<?php
	}

	/**
	 * Creates the method by which users are identified.
	 *
	 * @return void
	 */
	public function wp_xapi_network_lrs_user_setting_render() {
		?>
		<select name='wpxapi_network_settings[wpxapi_network_lrs_user_setting]'>
		<?php
		foreach ( WP_Experience_API_Network_Admin::$user_setting_options as $key => $value ) {
			echo "<option value='" . esc_attr( $key ) . "' " . selected( $this->options['wpxapi_network_lrs_user_setting'], $key ) . '>' . esc_html( $value ) . '</option>';
		}
		?>
		</select>
		<?php
	}

	/**
	 * Outputs the list of users who can see and set local LRS on a per website basis
	 *
	 * Done by comma separated wordpress usernames. When going to local site, if you are in this list, you can set the site level LRS info
	 *
	 * @return void
	 */
	public function wp_xapi_network_lrs_admin_render() {

		?>
			<input type='text' name='wpxapi_network_settings[wpxapi_network_lrs_admin]' value='<?php echo esc_attr( $this->options['wpxapi_network_lrs_admin'] ); ?>'>
			<div class="help-div">Please enter a comma separated list of wordpress usernames.</div>
		<?php
	}


	public function wp_xapi_network_lrs_admin_level_render() {
		?>
			<select name='wpxapi_network_settings[wpxapi_network_lrs_admin_level]'>
			<?php
			foreach ( WP_Experience_API_Network_Admin::$admin_level_options as $key => $value ) {
				echo "<option value='" . esc_attr( $key ) . "' " . selected( $this->options['wpxapi_network_lrs_admin_level'], $key ) . '>' . esc_html( $value ) . '</option>';
			}
			?>
			</select>
		<?php
	}

	/**
	 * Outputs the field which allows sites to log anonymous page views
	 *
	 * Done via comma separated list of siteIDs
	 *
	 * @return void
	 */
	public function wp_xapi_network_lrs_guest_render() {
		?>
			<input type='text' name='wpxapi_network_settings[wpxapi_network_lrs_guest]' value='<?php echo esc_attr( $this->options['wpxapi_network_lrs_guest'] ); ?>'>
			<div class="help-div">Please enter a comma separated list of site ids of sites that should be able to log anonymous users.</div>
		<?php
	}

	/**
	 * Outputs a list of URLs that the site level LRSs will be compared to.
	 *
	 * - one URL on each line
	 * - it will check by seeing if the start of the URL will match one of these
	 *
	 * NOTE: special thanks to https://github.com/ubc/wiki-embed for the idea of doing it this way.
	 */
	public function wp_xapi_network_lrs_whitelist_render() {
		?>
		<textarea name='wpxapi_network_settings[wpxapi_network_lrs_whitelist]'  rows="10" cols="50"><?php echo esc_textarea( $this->options['wpxapi_network_lrs_whitelist'] ); ?></textarea>
		<div class="help-div">We are checking only if the beginning of the url starts with the url that you provided.  So for example: <em>http://lrs.example.org/</em> would work but <em>http://statements.lrs.example.org/</em> will not work</div>
		<p><strong>Currently allowed urls:</strong><br />
		<?php
		if ( ! isset( $this->options['wpxapi_network_lrs_whitelist'] ) || empty( $this->options['wpxapi_network_lrs_whitelist'] ) ) {
			echo '<em>' . esc_html__( 'No currently whitelisted URLs', 'wpxapi' ) . '</em>';
		} else {
			foreach ( preg_split( '/\r\n|\r|\n/', $this->options['wpxapi_network_lrs_whitelist'] ) as $link ) {
				echo esc_url( $link ) . '<br />';
			}
		}
	}

	/**
	 * Placeholder for title of section.
	 *
	 * @return string
	 */
	public function wp_xapi_network_section_callback() {
		return '';
	}

	/**
	 * Placeholder for sanitization callback for network level settings page.
	 *
	 * @param  array $input
	 * @return array
	 */
	public static function wp_xapi_sanitize_network_options($input) {
		//fixes to make checkbox to 0 instead of default unset
		foreach ( WP_Experience_API_Network_Admin::$checkbox_fields as $checkboxKeys ) {
			if ( ! isset( $input[ $checkboxKeys ] ) ) {
				$input[ $checkboxKeys ] = 0;
			}
		}
		return $input;
	}

	/**
	 * function to setup opitons, initialize and save / or pull from options table
	 */
	public function setup_options() {
		$this->options = get_site_option( 'wpxapi_network_settings' );
		if ( false === $this->options ) {
			$this->options = $this->fields;
			add_site_option( 'wpxapi_network_settings', $this->options );
		}
	}
}

//$experienceAPINetwork = new ExperienceAPINetworkAdmin();
