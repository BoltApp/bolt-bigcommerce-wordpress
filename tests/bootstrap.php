<?php
/**
 * PHPUnit bootstrap file
 *
 * @package Bolt_Bigcommerce_Wordpress
 */


$_tests_dir = getenv( 'WP_TESTS_DIR' );

if ( ! $_tests_dir ) {
	$_tests_dir = rtrim( sys_get_temp_dir(), '/\\' ) . '/wordpress-tests-lib';
}

if ( ! file_exists( $_tests_dir . '/includes/functions.php' ) ) {
	echo "Could not find $_tests_dir/includes/functions.php, have you run bin/install-wp-tests.sh ?" . PHP_EOL; // WPCS: XSS ok.
	exit( 1 );
}

// Give access to tests_add_filter() function.
require_once $_tests_dir . '/includes/functions.php';

//add bigcommerce to list of active plugin
tests_add_filter('active_plugins', function ( $option ) {
	$option[] = 'bigcommerce-for-wordpress/bigcommerce.php';
	return $option;
});

tests_add_filter( 'muplugins_loaded', '_manually_load_plugin' );
/**
 * Manually load the plugin being tested.
 */
function _manually_load_plugin() {
	echo "!".dirname( dirname( __FILE__ ) ) . '/bolt-bigcommerce-wordpress.php!';
	require dirname( dirname( __FILE__ ) ) . '/../bigcommerce-for-wordpress/bigcommerce.php';
	require dirname( dirname( __FILE__ ) ) . '/bolt-bigcommerce-wordpress.php';

}
tests_add_filter( 'muplugins_loaded', '_manually_load_plugin' );

// Start up the WP testing environment.
require $_tests_dir . '/includes/bootstrap.php';