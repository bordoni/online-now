<?php
/**
 * Plugin Name:       Online Now
 * Plugin URI:        http://bordoni.me/wordpress/display-online-users-wordpress/
 * Description:       An quick plugin that will allow you to show which registred users are online right now
 * Version:           0.3.0
 * Author:            Gustavo Bordoni
 * Author URI:        http://bordoni.me
 * Text Domain:       online-now
 * License:           GPL-2.0+
 * License URI:       http://www.gnu.org/licenses/gpl-2.0.txt
 * Domain Path:       /i18n
 * GitHub Plugin URI: https://github.com/bordoni/online-now
 */

// If this file is called directly, abort.
if ( ! defined( 'WPINC' ) ) {
	die;
}

class OnlineNow {
	/**
	 * Holds the Static instance of this class
	 * @var OnlineNow
	 */
	private static $instance;

	/**
	 * Holds the Static instance of this class
	 * @var string
	 */
	private static $prefix = 'OnlineNow';

	/**
	 * Holds the Static instance of this class
	 * @var int
	 */
	private static $purge_threshold = 0;

	/**
	 * [instance description]
	 * @return OnlineNow return a static instance of this class
	 */
	public static function instance() {
		return self::$instance ? self::$instance : self::$instance = new self;
	}

	/**
	 * Initalized the plugin main class
	 * @return bool Boolean on if the init process was successful
	 */
	private function __construct() {
		// Apply the needed actions
		add_action( 'wp_login', array( $this, 'login' ), 10, 2 );
		add_action( 'clear_auth_cookie', array( $this, 'logout' ), 10 );
		add_action( 'set_current_user', array( $this, 'login' ), 15 );
		add_action( 'init', array( $this, 'reset_everyone' ), 15 );

		// Register Shortcodes
		add_shortcode( 'online:list', array( $this, 'shortcode_list' ) );
		add_shortcode( 'online:qty', array( $this, 'shortcode_qty' ) );

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
		if ( is_null( $user ) ) {
			$user = wp_get_current_user();
		}

		// Check if the user is curretly set
		if ( ! $user instanceof WP_User || ! $user->exists() ) {
			return false;
		}

		// Append this User to the List
		$users[ $user->ID ] = date( 'Y-m-d H:i:s' );

		// Re-order after adding the user
		asort( $users );

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
		if ( is_null( $user ) ) {
			$user = wp_get_current_user();
		}

		// Check if the user is curretly set
		if ( ! $user instanceof WP_User || ! $user->exists() ) {
			return false;
		}

		// If the user is not in the list we leave
		if ( isset( $users[ $user->ID ] ) ) {
			return false;
		}

		// Check if we can Remove from the List
		if ( $this->get_purge_threshold() >= count( $users ) ) {
			return false;
		}

		unset( $users[ $user->ID ] );

		// Re-order after removing the user
		asort( $users );

		return update_option( self::$prefix . '-users', $users );
	}

	/**
	 * Resets the users online every X amount of time
	 */
	public function reset_everyone( $force = false ) {
		$interval_timeout = apply_filters( 'onlinenow-interval_timeout', 5 * MINUTE_IN_SECONDS );
		$current_timeout = get_option( self::$prefix . '-timeout', 0 );
		$users = get_option( self::$prefix . '-users', array() );

		if ( $current_timeout <= time() || true === $force ) {
			$i = 0;
			foreach ( $users as $user_id => $time ) {
				$i++;
				// Only remove on the Purge Threshold
				if ( $i < $this->get_purge_threshold() ) {
					continue;
				}

				unset( $users[ $user_id ] );
			}

			return update_option( self::$prefix . '-users', array( get_current_user_id() => date( 'Y-m-d H:i:s' ) ) ) && update_option( self::$prefix . '-timeout', time() + $interval_timeout );
		}

		return false;
	}

	/**
	 * Fetches and allow filtering of the threshold for the amount of users that always should be shown online
	 * Basically it won't allow de number of users Online to be below the number defined, by default 0;
	 *
	 * @return int
	 */
	public function get_purge_threshold() {
		return (int) apply_filters( 'onlinenow-purge_threshold', self::$purge_threshold );
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
		if ( ! $user ) {
			return false;
		}

		$users = $this->get_users();

		return isset( $users[ $user->ID ] );
	}

	/**
	 * A method to grab the users online from the database
	 *
	 * @param  array  $include Ids of the users to include
	 * @param  array  $exclude Ids of the users to exclude
	 *
	 * @return array           Users currently online, array of IDs
	 */
	public function get_users( $include = array(), $exclude = array() ) {
		// Retrieve the users from Database
		$users = get_option( self::$prefix . '-users', array() );

		// Parse Shortcode atts to exclude
		if ( is_string( $exclude ) ) {
			$exclude = array_map( 'trim', explode( ',', $exclude ) );
		}

		// Exclude users based on shortcode attribute
		foreach ( (array) $exclude as $id ) {
			// Prevent Fatals
			if ( ! isset( $users[ $id ] ) ) {
				continue;
			}

			// Actually Remove
			unset( $users[ $id ] );
		}

		// Parse Shortcode atts to include
		if ( is_string( $include ) ) {
			$include = array_map( 'trim', explode( ',', $include ) );
		}

		// Include users based on shortcode attribute
		foreach ( (array) $include as $id ) {
			// Prevent Fatals
			if ( isset( $users[ $id ] ) ) {
				continue;
			}

			// Actually include the user
			$users[ $id ] = date( 'Y-m-d H:i:s' );
		}

		// Garantee that the array is safe for usage
		$users = array_filter( (array) $users );

		// Loop and Unset non-existent
		foreach ( (array) $users as $user_id => $time ) {
			// Prevent Fatals
			if ( $this->user_exists( $user_id ) ) {
				continue;
			}

			// Actually Remove
			unset( $users[ $user_id ] );
		}

		return $users;
	}

	/**
	 * Check if the user ID exists
	 *
	 * @param  int $user_id    The user id
	 *
	 * @return bool/WP_User    Returns the WP_User object if valid ID
	 */
	public function user_exists( $user_id ) {
		$user = new WP_User( $user_id );

		// Check if the users exists
		if ( ! $user->exists() ) {
			return false;
		}

		return true;
	}

	/**
	 * Based on the database option that we created in the methods above we will allow the admin to show it on a shortcode
	 *
	 * @param  string $atts The atributes from the shortcode
	 *
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
		$objects = array();
		foreach ( $users as $id => $time ) {
			$objects[] = new WP_User( $id );
		}

		if ( ! empty( $users ) ) {
			$html .= '<ul class="users-online">';
			foreach ( (array) $objects as $user ) {
				$html .= '<li>';
				// Allow the user to control the avatar size and if he wants to show
				if ( is_numeric( $atts->avatar ) ) {
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

		if ( $atts->numeric ) {
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
add_action( 'plugins_loaded', array( 'OnlineNow', 'instance' ) );

/**
 * Creates a Globally Acessible version of OnlineNow->is_user_online()
 */
if ( ! function_exists( 'is_user_onlinenow' ) ) {
	function is_user_onlinenow( $user = 0 ) {
		return OnlineNow::instance()->is_user_online( $user );
	}
}