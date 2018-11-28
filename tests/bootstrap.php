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

//TODO When Bigcommerce is open add it
tests_add_filter('active_plugins', function ( $option ) {
	$option[] = 'bigcommerce-for-wordpress/bigcommerce.php';
	return $option;
});


tests_add_filter( 'muplugins_loaded', '_manually_load_plugin' );
/**
 * Manually load the plugin being tested.
 */


function _manually_load_plugin() {
	//require dirname( dirname( __FILE__ ) ) . '/../bigcommerce-for-wordpress/bigcommerce.php';
	require dirname( dirname( __FILE__ ) ) . '/tests/bigcommerce-stub.php';
	require dirname( dirname( __FILE__ ) ) . '/tests/bigcommerce-stub-currency.php';
	require dirname( dirname( __FILE__ ) ) . '/bolt-bigcommerce-wordpress.php';

}
tests_add_filter( 'muplugins_loaded', '_manually_load_plugin' );

echo "*".$_tests_dir,"*";
if (!file_exists($_tests_dir.'/plugins')) mkdir($_tests_dir.'/plugins');
if (!file_exists($_tests_dir.'/plugins/bigcommerce-for-wordpress'))mkdir($_tests_dir.'/plugins/bigcommerce-for-wordpress' );
$file = fopen($_tests_dir.'/plugins/bigcommerce-for-wordpress/bigcommerce.php','w');
fwrite ($file,"<?php>");
fclose ($file);

// Start up the WP testing environment.
require $_tests_dir . '/includes/bootstrap.php';
