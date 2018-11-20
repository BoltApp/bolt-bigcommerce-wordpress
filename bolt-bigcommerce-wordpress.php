<?php
/**
 * Bolt Checkout for BigCommerce for Wordpress.
 *
 * @link              http://bolt.com
 *
 * Plugin Name:       Bolt for BigCommerce Wordpress
 * Plugin URI:        http://bolt.com
 * Description:       It adds Bolt checkout to the BigCommerce Wordpress.
 * Version:           0.0.1
 * Author:            Bolt
 * Author URI:        https://bolt.com
 * License:           GPL-3.0
 * License URI:       http://www.gnu.org/licenses/gpl-3.0.txt
 */


const BIGCOMMERCE_FOR_WORDPRESS_MAIN_PATH = 'bigcommerce-for-wordpress/bigcommerce.php';
if ( !defined( 'ABSPATH' ) ) {
	exit;
}
// Check if bigcommerce plugin installed and enables
if ( in_array( BIGCOMMERCE_FOR_WORDPRESS_MAIN_PATH, apply_filters( 'active_plugins', get_option( 'active_plugins' ) ) ) ) {

	if ( ! defined( 'BOLT_BIGCOMMERCE_VERSION' ) ) {
		$plugin_bolt_data = get_file_data(__FILE__, array('Version' => 'Version'), false);
		define( 'BOLT_BIGCOMMERCE_VERSION', $plugin_bolt_data['Version'] );
	}
	if ( ! defined( 'BIGCOMMERCE_VERSION' ) ) {
		$plugin_bigcommerce_data = get_file_data(WP_PLUGIN_DIR.'/'. BIGCOMMERCE_FOR_WORDPRESS_MAIN_PATH, array('Version' => 'Version'), false);
		define( 'BIGCOMMERCE_VERSION', $plugin_bigcommerce_data['Version'] );
	}

	// Check if there is admin user
	if ( is_admin() ) {
		require_once(dirname( __FILE__ ) . '/src/class-bolt-bigcommerce-wordpress-admin.php');
		$bolt_bigcommerce = new Bolt_Bigcommerce_Wordpress_Admin();
	} else {
		require_once(dirname( __FILE__ ) . '/src/class-bolt-bigcommerce-wordpress.php');
		$bolt_bigcommerce = new Bolt_Bigcommerce_Wordpress();
	}

	// include Bolt API
	require(dirname( __FILE__ ) . '/lib/bolt-php/init.php');

	// Include Bugsnag Class.
	require_once(dirname( __FILE__ ) . '/src/BugsnagHelper.php');
	BugsnagHelper::initBugsnag();

	require_once(dirname( __FILE__ ) . '/src/class-bolt-logger.php');

	//class for confirmation page
	require_once(dirname( __FILE__ ) . '/src/class-bolt-confirmation-page.php');

	require_once(dirname( __FILE__ ) . '/src/class-bolt-discounts-helper.php');

	require_once(dirname( __FILE__ ) . '/src/class-bolt-checkout.php');

	require_once(dirname( __FILE__ ) . '/src/class-bolt-cart.php');

	$bolt_bigcommerce->init();
}
 
 



