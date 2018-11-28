<?php
include_once("class-bolt-example-data.php");
class BoltShippingAndTaxTest extends WP_UnitTestCase
{

	public function test_EvaluateShippingTax_SimpleOrder_CheckCheckoutApiCalls()
	{
		$data = new BoltExampleData();
		$bolt_order = $data->get_shippingtax_request();
		$update_address_input = $data->get_update_address_input();
		$add_or_update_consignment_input = $data->get_add_or_update_consignment_input();
		$checkout_api_call_result = $data->get_checkout();

		update_option("bolt_cart_id_" . $bolt_order->cart->order_reference, array('cart_id' => true));

		$mock_checkout = $this->getMockBuilder('Bolt_Checkout')
			->disableOriginalConstructor()
			->setMethods(array('get', 'update_address', 'add_or_update_consignment'))
			->getMock();

		$mock_checkout->method('get')->willReturn($checkout_api_call_result);

		$mock_checkout->expects($this->once())
			->method('update_address')
			->with($this->equalTo($update_address_input));

		$mock_checkout->expects($this->once())
			->method('add_or_update_consignment')
			->with($this->equalTo($add_or_update_consignment_input));

		$bolt_shipping_and_tax = $this->getMockBuilder('Bolt_Shipping_And_Tax')
			->setMethods(array('get_checkout_api'))
			->getMock();

		$bolt_shipping_and_tax->expects($this->any())
			->method('get_checkout_api')
			->will($this->returnValue($mock_checkout));

		$bolt_shipping_and_tax->evaluate_shipping_tax($bolt_order);
	}
}

