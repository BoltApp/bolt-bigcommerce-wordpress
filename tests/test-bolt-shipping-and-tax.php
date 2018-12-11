<?php

namespace BoltBigcommerce;

include_once( "class-bolt-example-data.php" );

function file_get_contents( $var ) {
	global $bolt_order;

	return json_encode( $bolt_order );
}

function wp_send_json( $var ) {

}

class BoltShippingAndTaxTest extends \WP_UnitTestCase {


	public function test_EvaluateShippingTax_SimpleOrder_CheckCheckoutApiCalls() {
		$data                            = new BoltExampleData();
		$bolt_order                      = $data->get_shippingtax_request();
		$update_address_input            = $data->get_update_address_input();
		$add_or_update_consignment_input = $data->get_add_or_update_consignment_input();
		$checkout_api_call_result        = $data->get_checkout();

		update_option( "bolt_cart_id_" . $bolt_order->cart->order_reference, array( 'cart_id' => true ) );

		$mock_checkout = $this->getMockBuilder( 'BoltBigcommerce\Bolt_Checkout' )
		                      ->disableOriginalConstructor()
		                      ->setMethods( array( 'get', 'update_address', 'add_or_update_consignment' ) )
		                      ->getMock();

		$mock_checkout->method( 'get' )->willReturn( $checkout_api_call_result );

		$mock_checkout->expects( $this->once() )
		              ->method( 'update_address' )
		              ->with( $this->equalTo( $update_address_input ) );

		$mock_checkout->expects( $this->once() )
		              ->method( 'add_or_update_consignment' )
		              ->with( $this->equalTo( $add_or_update_consignment_input ) );

		$bolt_shipping_and_tax = $this->getMockBuilder( 'BoltBigcommerce\Bolt_Shipping_And_Tax' )
		                              ->setMethods( array( 'get_checkout_api' ) )
		                              ->getMock();

		$bolt_shipping_and_tax->expects( $this->any() )
		                      ->method( 'get_checkout_api' )
		                      ->will( $this->returnValue( $mock_checkout ) );

		$bolt_shipping_and_tax->evaluate_shipping_tax( $bolt_order );
	}

	public function test_HandlerShippingTax_SimpleOrder_CallEvaluateShippingTaxWithRightParameter() {
		global $bolt_order;
		$data       = new BoltExampleData();
		$bolt_order = $data->get_shippingtax_request();

		$bolt_shipping_and_tax = $this->getMockBuilder( 'BoltBigcommerce\Bolt_Shipping_And_Tax' )
		                              ->setMethods( array( 'evaluate_shipping_tax' ) )
		                              ->getMock();
		$bolt_shipping_and_tax->expects( $this->once() )
		                      ->method( 'evaluate_shipping_tax' )
		                      ->with( $this->equalTo( $bolt_order ) )
		                      ->will( $this->returnValue( '' ) );

		$computedHmac                       = trim( base64_encode( hash_hmac( 'sha256', json_encode( $bolt_order ), \BoltPay\Bolt::$signingSecret, true ) ) );
		$_SERVER['HTTP_X_BOLT_HMAC_SHA256'] = $computedHmac;

		$bolt_shipping_and_tax->handler_shipping_tax();
	}


}

