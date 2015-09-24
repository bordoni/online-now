<?php
/**
 * Plugin Name:       Online Now
 * Plugin URI:        http://bordoni.me/wordpress/display-online-users-wordpress/
 * Description:       An quick plugin that will allow you to show which registred users are online right now
 * Version:           0.2.0
 * Author:            Gustavo Bordoni
 * Author URI:        http://bordoni.me
 * Text Domain:       online-now
 * License:           GPL-2.0+
 * License URI:       http://www.gnu.org/licenses/gpl-2.0.txt
 * Domain Path:       /i18n
 * GitHub Plugin URI: https://github.com/bordoni/online-now
 */

// If this file is called directly, abort.
if ( ! defined( 'WPINC' ) ){
	die;
}

class OnlineNow {
	/**
	 * Holds the Static instance of this class
	 * @var OnlineNow
	 */
	static protected $_instance = null;

	/**
	 * Holds the Static instance of this class
	 * @var OnlineNow
	 */
	static protected $prefix = 'OnlineNow';

	/**
	 * [instance description]
	 * @return OnlineNow return a static instance of this class
	 */
	public static function instance() {
		null === self::$_instance && self::$_instance = new self;
		return self::$_instance;
	}

	/**
	 * Initalized the plugin main class
	 * @return bool Boolean on if the init process was successful
	 */
	public static function init() {
		// Lock this class to be initialized only once
		if ( null !== self::$_instance ){
			return false;
		}

		// Apply the needed actions
		add_action( 'wp_login', array( self::instance(), 'login' ), 10, 2 );
		add_action( 'clear_auth_cookie', array( self::instance(), 'logout' ), 10 );
		add_action( 'set_current_user', array( self::instance(), 'login' ), 15 );
		add_action( 'init', array( self::instance(), 'reset_everyone' ), 15 );

		// Register Shortcodes
		add_shortcode( 'online:list', array( self::instance(), 'shortcode_list' ) );
		add_shortcode( 'online:qty', array( self::instance(), 'shortcode_qty' ) );

		// To allow easier control this plugin will parse the shortcodes on Widgets
		add_filter( 'widget_text', 'do_shortcode' );

		return true;
	}

	/**
	 * Whenever a user logs in we register it to a option in the database
	 * @todo  Modify the plugin to allow reseting each user at a specific timeout
	 *
	 * @param  string $user_login Username
	 * @param  WP_User $user      The object that tells what user we are dealing with
	 */
	public function login( $user_login = null, $user = null ) {
		$users = get_option( self::$prefix . '-users', array() );

		// Make sure we have an user object
		if ( is_null( $user ) ){
			$user = wp_get_current_user();
		}

		// Check if the user is curretly set
		if ( ! $user->exists() ){
			return false;
		}

		// If the user is already in the list we leave
		if ( in_array( $user->ID, $users ) ){
			return false;
		}

		// Append the user ID to the List of online users
		$users[] = $user->ID;

		return update_option( self::$prefix . '-users', $users );
	}

	/**
	 * Whenever a User logs out we remove its ID from the Database option
	 *
	 * @return bool Wether the current user was removed
	 */
	public function logout( $user = null ) {
		$users = get_option( self::$prefix . '-users', array() );

		// Make sure we have an user object
		if ( is_null( $user ) ){
			$user = wp_get_current_user();
		}

		// Check if the user is curretly set
		if ( ! $user instanceof WP_User || ! $user->exists() ){
			return false;
		}

		// If the user is not in the list we leave
		if ( ! in_array( $user->ID, $users ) ){
			return false;
		}

		return update_option( self::$prefix . '-users', array_diff( $users, array( $user->ID ) ) );
	}

	/**
	 * Resets the users online every X amount of time
	 */
	public function reset_everyone( $force = false ) {
		$interval_timeout = apply_filters( 'onlinenow-interval_timeout', 5 * MINUTE_IN_SECONDS );
		$current_timeout = get_option( self::$prefix . '-timeout', 0 );

		if ( $current_timeout <= time() || true === $force ){
			return update_option( self::$prefix . '-users', array( get_current_user_id() ) ) && update_option( self::$prefix . '-timeout', time() + $interval_timeout );
		}

		return false;
	}

	/**
	 * Check if the user is Online
	 *
	 * @param  integer|WP_User $user The user ID or WP_User object
	 *
	 * @return boolean       Whether the user is online or not
	 */
	public function is_user_online( $user = 0 ) {
		$user = $this->user_exists( $user );
		if ( ! $user ){
			return false;
		}

		$users = $this->get_users();

		return in_array( $user->ID, $users );
	}

	/**
	 * A method to grab the users online from the database
	 * @param  array  $include [description]
	 * @param  array  $exclude [description]
	 * @return array           Users currently online, array of IDs
	 */
	public function get_users( $include = array(), $exclude = array() ) {
		// Retrieve the users from Database
		$users = get_option( self::$prefix . '-users', array() );

		// Parse Shortcode atts to exclude
		if ( is_string( $exclude ) ){
			$exclude = array_map( 'trim', explode( ',', $exclude ) );
		}

		// Exclude users based on shortcode attribute
		$users = array_diff( (array) $users, (array) $exclude );

		// Parse Shortcode atts to include
		if ( is_string( $include ) ){
			$include = array_map( 'trim', explode( ',', $include ) );
		}

		// Include users based on shortcode attribute
		$users = array_merge( (array) $users, (array) $include );

		// Garantee that the array is safe for usage
		$users = array_unique( array_filter( (array) $users ) );

		// Remove all non existent users
		$users = array_map( array( $this, 'user_exists' ), $users );

		// Garantee that the array is safe for usage
		$users = array_filter( (array) $users );

		return $users;
	}

	/**
	 * Check if the user ID exists
	 * @param  int $user_id    The user id
	 * @return bool/WP_User    Returns the WP_User object if valid ID
	 */
	public function user_exists( $user_id ) {
		$user = new WP_User( $user_id );

		// Check if the users exists
		if ( ! $user->exists() ){
			return false;
		}

		return $user;
	}

	/**
	 * Based on the database option that we created in the methods above we will allow the admin to show it on a shortcode
	 *
	 * @param  string $atts The atributes from the shortcode
	 * @return string       The HTML of which users are online
	 */
	public function shortcode_list( $atts ) {
		$atts = (object) shortcode_atts( array(
			'avatar' => false,

			'exclude' => '',
			'include' => '',
			'zero_text' => esc_attr__( 'There are no users online right now', 'online-now' ),
		), $atts );
		$html = '';

		$users = $this->get_users( $atts->include, $atts->exclude );

		if ( ! empty( $users ) ){
			$html .= '<ul class="users-online">';
			foreach ( (array) $users as $user ) {
				$html .= '<li>';
				// Allow the user to control the avatar size and if he wants to show
				if ( is_numeric( $atts->avatar ) ){
					$html .= get_avatar( $user, $atts->avatar );
				}
				$html .= '<span>' . $user->display_name . '</span>';
				$html .= '</li>';
			}
			$html .= '</ul>';
		} else {
			$html .= '<p>' . $atts->zero_text . '</p>';
		}
		return $html;
	}

	/**
	 * Shows the quantity of users online now
	 *
	 * @param  string $atts The atributes from the shortcode
	 * @return string       The HTML of how many users are online
	 */
	public function shortcode_qty( $atts ) {
		$atts = (object) shortcode_atts( array(
			'plural' => __( '%s users online', 'online-now' ),
			'singular' => __( 'One user online', 'online-now' ),
			'zero' => __( 'Zero users online', 'online-now' ),

			'numeric' => false,

			'exclude' => '',
			'include' => '',
		), $atts );

		$users = $this->get_users( $atts->include, $atts->exclude );

		if ( $atts->numeric ){
			return count( $users );
		}

		if ( count( $users ) === 0 ) {
			$text = $atts->zero;
		} elseif ( count( $users ) === 1 ) {
			$text = $atts->singular;
		} else {
			$text = $atts->plural;
		}

		return sprintf( $text, count( $users ) );
	}
}

// Actually Load the plugin
add_action( 'plugins_loaded', array( 'OnlineNow', 'init' ) );


/**
 * Creates a Globally Acessible version of OnlineNow->is_user_online()
 */
if ( ! function_exists( 'is_user_onlinenow' ) ){
	function is_user_onlinenow( $user = 0 ) {
		return OnlineNow::instance()->is_user_online( $user );
	}
}