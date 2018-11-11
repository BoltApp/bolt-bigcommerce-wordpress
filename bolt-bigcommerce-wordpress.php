<?php
/**
 * Bolt Checkout for BigCommerce for Wordpress.
 *
 * @link              http://bolt.com
 * @since             1.0.0
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

if ( !defined( 'ABSPATH' ) ) {
	exit;
}
// Check if bigcommerce plugin installed and enables
if ( in_array( 'bigcommerce-for-wordpress/bigcommerce.php', apply_filters( 'active_plugins', get_option( 'active_plugins' ) ) ) ) {
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
	require_once(dirname( __FILE__ ) . '/src/class-bolt-logger.php');

	//class for confirmation page
	require_once(dirname( __FILE__ ) . '/src/class-bolt-confirmation-page.php');

	$bolt_bigcommerce->init();
}
 
 



