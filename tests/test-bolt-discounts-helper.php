<?php
include_once("class-bolt-example-data.php");

class BoltDiscountsHelperTest extends WP_UnitTestCase
{
	const E_BOLT_INSUFFICIENT_INFORMATION = 6200;
	const E_BOLT_CODE_INVALID = 6201;
	const E_BOLT_CODE_EXPIRED = 6202;
	const E_BOLT_CODE_NOT_AVAILABLE = 6203;
	const E_BOLT_CODE_LIMIT_REACHED = 6204;
	const E_BOLT_MINIMUM_CART_AMOUNT_REQUIRED = 6205;
	const E_BOLT_UNIQUE_EMAIL_REQUIRED = 6206;
	const E_BOLT_ITEMS_NOT_ELIGIBLE = 6207;
	const E_BOLT_SERVICE = 6001;


	public function test_BigcommerceCartIdIsntSet_returnErrorCartNotFound()
	{
		$data = New BoltExampleData();
		$discount_helper = New Bolt_Discounts_Helper($data->get_coupon_request());
		$result = $discount_helper->evaluate_answer_for_discount_hook();
		$this->assertEquals('Cart not found', $result['error']['message']);
	}

	public function test_CouponIsntExist_ReturnErrorBoltCodeInvalid() {
		$data = New BoltExampleData();
		$order_reference = $data->get_coupon_request()->cart->order_reference;
		update_option( "bolt_cart_id_" . $order_reference, array('cart_id' => true ) );
			$discount_helper = $this->getMockBuilder('Bolt_Discounts_Helper')
			->setConstructorArgs(array($data->get_coupon_request()))
			->setMethods(array('coupon_info'))
			->getMock();
		$discount_helper->method('coupon_info')->willReturn('');

		$result = $discount_helper->evaluate_answer_for_discount_hook();
		delete_option( "bolt_cart_id_" . $order_reference);

		$this->assertEquals(SELF::E_BOLT_CODE_INVALID, $result['error']['code']);
	}

	public function test_CouponAlreadyAppliedInBigcommerce_ReturnSuccessAndDiscoint1000()
	{
		$data = New BoltExampleData();
		$order_reference = $data->get_coupon_request()->cart->order_reference;
		update_option("bolt_cart_id_" . $order_reference, array('cart_id' => true));

		$stub_checkout_api = $this->getMockBuilder('Bolt_Checkout')
			->setMethods(array('get'))
			->getMock();
		$stub_checkout_api->method('get')->willReturn($data->get_checkout_with_coupon_applied());

		$discount_helper = $this->getMockBuilder('Bolt_Discounts_Helper')
			->setConstructorArgs(array($data->get_coupon_request()))
			->setMethods(array('get_coupon_info', 'get_checkout_api'))
			->getMock();
		$discount_helper->method('get_checkout_api')->willReturn($stub_checkout_api);
		$discount_helper->method('get_coupon_info')->willReturn($data->get_bigccommerce_coupon_success_answer());

		$result = $discount_helper->evaluate_answer_for_discount_hook();
		delete_option("bolt_cart_id_" . $order_reference);

		$this->assertEquals('success', $result['status']);
		$this->assertEquals(1000, $result['discount_amount']);

	}


	public function test_CouponIsCorrect_ReturnSuccessAndDiscoint1000()
	{
		$data = New BoltExampleData();
		$order_reference = $data->get_coupon_request()->cart->order_reference;
		update_option("bolt_cart_id_" . $order_reference, array('cart_id' => true));

		$stub_checkout_api = $this->getMockBuilder('Bolt_Checkout')
			->setMethods(array('get'))
			->getMock();

		$stub_checkout_api->expects($this->any())->method('get')->will(
			//first time return checkout without coupon
			//than when checkout need without cache return with coupon
			$this->returnCallback(function($not_use_cache=false) {
				$data = New BoltExampleData();
				if (!$not_use_cache) {
					return $data->get_checkout();
				} else {
					return $data->get_checkout_with_coupon_applied();
				}
			}));

		$discount_helper = $this->getMockBuilder('Bolt_Discounts_Helper')
			->setConstructorArgs(array($data->get_coupon_request()))
			->setMethods(array('get_coupon_info', 'get_checkout_api'))
			->getMock();
		$discount_helper->method('get_checkout_api')->willReturn($stub_checkout_api);
		$discount_helper->method('get_coupon_info')->willReturn($data->get_bigccommerce_coupon_success_answer());

		$result = $discount_helper->evaluate_answer_for_discount_hook();

		delete_option("bolt_cart_id_" . $order_reference);

		$this->assertEquals('success', $result['status']);
		$this->assertEquals(1000, $result['discount_amount']);

	}

}