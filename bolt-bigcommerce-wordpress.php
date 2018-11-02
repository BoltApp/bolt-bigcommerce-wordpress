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
 const BC_API="v2"; // use v3 server-to-server checkout APIs or not
 
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( in_array( 'bigcommerce-for-wordpress-master/bigcommerce.php', apply_filters( 'active_plugins', get_option( 'active_plugins' ) ) ) ) {
	// Check if there is admin user
	if ( is_admin() ) {
		require_once( dirname( __FILE__ ) . '/src/class-bolt-bigcommerce-wordpress-admin.php' );
		$bolt_bigcommerce = new Bolt_Bigcommerce_Wordpress_Admin();
	} else {
		require_once( dirname( __FILE__ ) . '/src/class-bolt-bigcommerce-wordpress.php' );
		$bolt_bigcommerce = new Bolt_Bigcommerce_Wordpress();
	}

 // include Bolt API
 // API_KEY and other parameters temporary stores /lib/config_bolt_php.php
 // TODO: Move settings to wordpress admin
 require(dirname(__FILE__) . '/lib/bolt-php/init.php');
 require(dirname(__FILE__) . '/src/class-bc-client.php');

	$bolt_bigcommerce->init();
 $bolt_bigcommerce->init_public_ajax();
}

//temporary solution while bigcommerce hook doesn't exist
function bolt_cart_button($bigcommerce_cart) {
 global $bolt_bigcommerce;
 return $bolt_bigcommerce->bolt_cart_button($bigcommerce_cart);
}

 
 



