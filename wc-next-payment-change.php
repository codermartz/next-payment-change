<?php
/*
Plugin Name: WooCommerce Subscriptions Next Payment Change
Plugin URI: 
Description: Updates the next payment date when a customer buys any subscriptions and sends an email to the admin for notification.
Version: 1.0
Author: Ton
Author URI: https://www.guru.com/freelancers/coderprovw/portfolio
Text Domain: wc-next-payment-change
Requires at least: 5.4
Tested up to: 5.6
License: MIT

Copyright: 2021
*/

// This class should belong to this namespace to prevent any collision from other plugins.
namespace devton;

if ( ! defined( 'ABSPATH' ) ) die( 'Access denied.' );

define( 'WC_NEXT_PAYMENT_FILE', __FILE__ );
define( 'WC_NEXT_PAYMENT_PLUGIN', plugin_basename( WC_NEXT_PAYMENT_FILE ) );
define( 'WCS_PLUGIN_DIR', WP_CONTENT_DIR . '/plugins/woocommerce-subscriptions' );

// Some user defined settings/constants that can be change anytime.
define( 'WC_NEXT_PAYMENT_INITIAL_ORDERS_ONLY', true );
define( 'WC_NEXT_PAYMENT_DATE', '21/12/2021' );
define( 'WC_NEXT_PAYMENT_ADMIN_EMAIL_ADDRESS', '' );

/**
 * WC_Next_Payment_Change class
 *
 * Handles and renders all the necessary actions/features/behaviors of this plugin.
 *
 */
class WC_Next_Payment_Change {
	// Current version of this plugin
	const VERSION = '1.0';

	// Minimum PHP version required to run this plugin
	const PHP_REQUIRED = '5.3';

	// Minimum WP version required to run this plugin
	const WP_REQUIRED = '5.4';

	// Text domain
	const TEXT_DOMAIN = 'wc-next-payment-change';

	// Static property of this class that will hold the singleton instance of this class
	protected static $instance = null;

	/**
	 * Constructor
	 */
	public function __construct() {
		// Pre-requisites check.
		register_activation_hook( WC_NEXT_PAYMENT_FILE, array( $this , 'activate' ) );

		// Make sure that we load all necessary classes (dependencies) before we process anything.
		$this->maybe_load_dependencies();

		// This hook applies to initial orders and renewals. The same reason we introduced the WC_NEXT_PAYMENT_INITIAL_ORDERS_ONLY
		// constant so that the admin can choose whether we will process all payment types or for initial order payments only.
		add_action( 'woocommerce_subscription_payment_complete', array( $this, 'payment_complete' ), 10, 1 );
	}

	/**
	 * Processes the next payment date change when a user/customer successfully buys a subscription
	 *
	 * @param WC_Subscription $subscription Subscription object
	 * @return void
	 */
	public function payment_complete( $subscription ) {
		$order = $subscription->get_parent();
		if ( $order ) {

			// If the WC_NEXT_PAYMENT_INITIAL_ORDERS_ONLY constant is set then we will only apply the changing
			// of the next payment date if the newly bought subscription is for initial order only and not for renewals
			// or re-subscriptions (e.g. renew from cancelled or expired subscriptions).
			if ( WC_NEXT_PAYMENT_INITIAL_ORDERS_ONLY ) {
				$is_renewal = WC_Subscriptions_Renewal_Order::is_renewal( $order );

				if ( $is_renewal ) {
					// If the current payment was made for renewals or re-subscriptions then we bail. If you need
					// to cover and process all subscriptions payment then set the WC_NEXT_PAYMENT_INITIAL_ORDERS_ONLY
					// to false.
					return;
				}
			}

			$subscription_key = WC_Subscriptions_Manager::get_subscription_key( $order->get_id() );
			if ( ! empty( $subscription_key ) ) {
				// Here we will use "-" instead of "/" in order to accurately convert our
				// target next payment date into a UNIX timestamp.
				$next_payment_date = strtotime( str_replace( '/', '-', WC_NEXT_PAYMENT_DATE ) );

				$payment_date = WC_Subscriptions_Manager::set_next_payment_date( $subscription_key, '', $next_payment_date );
				if ( ! empty( $payment_date ) ) {
					$item = WC_Subscriptions_Order::get_item_by_subscription_key( $subscription_key );

					if ( ! empty( $item ) ) {
						$info = array(
							'order_number' => $subscription->get_order_number(),
							'product_name' => $item[ 'name' ],
							'next_payment_date' => $payment_date,
						);

						$this->send_email( $info );
					}	
				}
			}
		}
	}

	/**
	 * Changes the default email content-type to "text/html".
	 *
	 * @return string
	 */
	public function set_html_content_type() {
		return 'text/html';
	}

	/**
	 * Sends an email to the admin user to notify him or her of the next payment date change
	 *
	 * @param array $info The info/data to send (e.g. order number, product name and next payment date)
	 * @return void
	 */
	public function send_email( $info ) {
		// Get admin email address. This will fallback to getting the admin email addess set in WordPress general settings if the
		// WC_NEXT_PAYMENT_ADMIN_EMAIL_ADDRESS constant is not defined or is empty.
		$admin_email = ( defined( 'WC_NEXT_PAYMENT_ADMIN_EMAIL_ADDRESS' ) && ! empty( WC_NEXT_PAYMENT_ADMIN_EMAIL_ADDRESS ) ) ? WC_NEXT_PAYMENT_ADMIN_EMAIL_ADDRESS : get_option( 'admin_email' );

		if ( ! function_exists( 'is_email' ) ) {
			include_once( ABSPATH . WPINC . '/formatting.php' );
		}
		
		// Make sure that we actually have a valid email to use before sending out.
		//
		// N.B. The "is_email" method that we used here comes with the WordPress core. It may or may not cover
		// all of the advanced email address formatting out there. You can apply your own email validation logic here if you
		// find that the "is_email" method is not enough.
		if ( ! empty( $admin_email ) && is_email( $admin_email ) ) {
			$subject = __( 'Next Payment Change', self::TEXT_DOMAIN );

			// Build the email content based from the info provided.
			$content = '<label>' . __( 'Order Number', self::TEXT_DOMAIN ) . '</label>' . $info[ 'order_number' ] . '<br />';
			$content .= '<label>' . __( 'Product name', self::TEXT_DOMAIN ) . '</label>' . $info[ 'product_name' ] . '<br />';
			$content .= '<label>' . __( 'Next payment date', self::TEXT_DOMAIN ) . '</label>' . $info[ 'next_payment_date' ] . '<br />';

			// Here, we're trying to change the content-type so that the recipient will receive a well-formatted HTML content
			// instead of raw/plain text.
			add_filter( 'wp_mail_content_type', array( $this, 'set_html_content_type' ) );

			// The success of sending this email will entirely depend on your hosting and your SMTP settings.
			wp_mail( $admin_email, $subject, $content );

			// Reset content-type to avoid conflicts -- http://core.trac.wordpress.org/ticket/23578
			remove_filter( 'wp_mail_content_type', array( $this, 'set_html_content_type' ) );
		}
	}

	/**
	 * Creates an instance of this class. Singleton Pattern
	 *
	 * @return object Instance of this class
	 */
	public static function instance() {
		if ( empty( self::$instance ) ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Runs PHP, WP version and WC_Subscriptions checks on activation, more like pre-requisites check.
	 *
	 * @return void
	 */
	public function activate() {
		$data = get_plugin_data( WC_NEXT_PAYMENT_FILE );

		if ( version_compare( PHP_VERSION, self::PHP_REQUIRED, '<' ) ) {
			deactivate_plugins( WC_NEXT_PAYMENT_PLUGIN );
			wp_die( __( $data['Name'] . ' requires PHP version ' . self::PHP_REQUIRED . ' or greater.', self::TEXT_DOMAIN ) );
		}

		include ABSPATH . WPINC . '/version.php';
		if ( version_compare( $wp_version, self::WP_REQUIRED, '<' ) ) {
			deactivate_plugins( WC_NEXT_PAYMENT_PLUGIN );
			wp_die( __( $data['Name'] . ' requires WordPress version ' . self::WP_REQUIRED . ' or greater.', self::TEXT_DOMAIN ) );
		}

		if ( ! class_exists( 'WC_Subscriptions' ) ) {
			deactivate_plugins( WC_NEXT_PAYMENT_PLUGIN );
			wp_die( __( $data['Name'] . ' requires WooCommerce Subscriptions plugin.', self::TEXT_DOMAIN ) );
		}
	}

	/**
	 * Loads WC_Subscriptions classes if they currently don't exists.
	 *
	 * @return void
	 */
	private function maybe_load_dependencies() {
		if ( ! class_exists( 'WC_Subscriptions_Renewal_Order' ) ) {
			include_once( WCS_PLUGIN_DIR . '/includes/class-wc-subscriptions-renewal-order.php' );
		}

		if ( ! class_exists( 'WC_Subscriptions_Manager' ) ) {
			include_once( WCS_PLUGIN_DIR . '/includes/class-wc-subscriptions-manager.php' );
		}

		if ( ! class_exists( 'WC_Subscriptions_Order' ) ) {
			include_once( WCS_PLUGIN_DIR . '/includes/class-wc-subscriptions-order.php' );
		}
	}
}

/**
 * Creates or make use of the singleton instance of WC_Next_Payment_Change class
 *
 * @return object
 */
function WC_Next_Payment_Change() {
	// We are on the same file or namespace so there's no need to use '\devton\WC_Next_Payment_Change' to call the instance method.
	return WC_Next_Payment_Change::instance();
}

$GLOBALS[ 'wc-next-payment-change' ] = WC_Next_Payment_Change();