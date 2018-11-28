<?php
/*
class Bolt_Bigcommerce_Wordpress_test extends Bolt_Bigcommerce_Wordpress {
	public function get_option( $key , $default=false )  { return parent::get_option( $key , $default ); }
}
class BoltBigcommerceWordpressTest extends WP_UnitTestCase {
	public function test_GetOption_GetExistingAndNot() {
		update_option("bolt-bigcommerce_1","1");
		$bolt = new Bolt_Bigcommerce_Wordpress_test();
		$this->assertTrue( '1' == $bolt->get_option("1") );
		$this->assertTrue( false == $bolt->get_option("2") );
		$this->assertTrue( '3' == $bolt->get_option("2","3") );
	}
}
*/