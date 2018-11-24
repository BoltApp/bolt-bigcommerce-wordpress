<?php
Class checkout_test{
	private $input;
	private $output;
	private $test_class;
	public $check_quantity = 0;
	public function __construct($test_class, $input, $output = array() ) {
		$this->test_class = $test_class;
		$this->input = $input;
		$this->output = $output;
	}
	public function __call ( $name , $arguments ) {
		//echo "**".$name."**";
		if ( isset( $this->input[$name] ) ) {
			$this->test_class->assertEquals( $this->input[$name], $arguments[0] );
			$this->check_quantity++;
		}
		if ( isset( $this->output[$name] ) ) {
			return $this->output[$name];
		}
	}
}

class BoltShippingAndTaxTest extends WP_UnitTestCase {


	/**
	 * @dataProvider ShipiingTaxProvider
	 */
	public function test_bolt_evaluate_shipping_tax($bolt_order,$input, $output)
	{
		$checkout_test = New checkout_test($this,$input,$output);

		update_option( "bolt_cart_id_" . $bolt_order->cart->order_reference, array('cart_id' => true ) );

		$bolt_shipping_and_tax = $this->getMockBuilder('Bolt_Shipping_And_Tax')
			->setMethods(array('get_checkout_api'))
			->getMock();
		$bolt_shipping_and_tax->expects($this->any())
			->method('get_checkout_api')
			->will($this->returnValue($checkout_test));
		$bolt_shipping_and_tax->evaluate_shipping_tax($bolt_order);
		$this->assertEquals( 2, $checkout_test->check_quantity );
		delete_option( "bolt_cart_id_" . $bolt_order->cart->order_reference );
	}

	public function ShipiingTaxProvider() {
		return array(
			//simple order
			array(
				//bolt_order
				(object)(array(
					'order_token' => 'baeec9ddfdc40ffcdea34ccc030165ab30b270f789d6a49da6076165cff81893',
					'cart' =>
						(object)(array(
							'total_amount' => 10000,
							'currency' => 'USD',
							'items' =>
								array (
									0 =>
										(object)(array(
											'reference' => '113',
											'name' => 'simple test product',
											'description' => NULL,
											'options' => NULL,
											'total_amount' => 10000,
											'unit_price' => 2000,
											'tax_amount' => 0,
											'quantity' => 5,
											'uom' => NULL,
											'upc' => NULL,
											'sku' => 'test2',
											'isbn' => NULL,
											'brand' => NULL,
											'manufacturer' => NULL,
											'category' => NULL,
											'tags' => NULL,
											'properties' => NULL,
											'color' => NULL,
											'size' => NULL,
											'weight' => NULL,
											'weight_unit' => NULL,
											'image_url' => NULL,
											'details_url' => NULL,
											'type' => 'unknown',
										)),
								),
							'tax_amount' => 0,
							'billing_address_id' => NULL,
							'billing_address' =>
								(object)(array(
									'street_address1' => '8 5th Avenue',
									'street_address2' => '',
									'street_address3' => NULL,
									'street_address4' => NULL,
									'locality' => 'New York',
									'region' => 'New York',
									'postal_code' => '10011',
									'country_code' => 'US',
									'country' => 'United States',
									'name' => NULL,
									'first_name' => 'John',
									'last_name' => 'Smit',
									'company' => '',
									'phone' => '0585143254',
									'email' => 'test@test.com',
								)),
							'shipments' => NULL,
							'discount_code' => '',
							'order_description' => NULL,
							'order_reference' => 'BLT5bf95e9c06585',
							'transaction_reference' => NULL,
							'cart_url' => NULL,
							'discounts' =>
								array (
								),
							'display_id' => 'BLT5bf95e9c06585',
							'is_shopify_hosted_checkout' => false,
						)),
					'shipping_address' =>
						(object)(array(
							'street_address1' => '8 5th Avenue',
							'street_address2' => '',
							'street_address3' => NULL,
							'street_address4' => NULL,
							'locality' => 'New York',
							'region' => 'New York',
							'postal_code' => '10011',
							'country_code' => 'US',
							'country' => 'United States',
							'name' => NULL,
							'first_name' => 'John',
							'last_name' => 'Smit',
							'company' => '',
							'phone' => '0585143254',
							'email' => 'test@test.com',
						)),
					'shipping_options' =>
						array (
						),
				)),
				//input
				array(
					'update_address' =>
						(object)(array(
							'first_name' => 'John',
							'last_name' => 'Smit',
							'company' => '',
							'address1' => '8 5th Avenue',
							'address2' => '',
							'city' => 'New York',
							'state_or_province' => 'New York',
							'postal_code' => '10011',
							'country' => 'United States',
							'country_code' => 'US',
							'phone' => '0585143254',
							'email' => 'test@test.com',
						)),
					'add_or_update_consignment' =>
						(object)(array(
							'shipping_address' =>
								(object)(array(
									'first_name' => 'John',
									'last_name' => 'Smit',
									'company' => '',
									'address1' => '8 5th Avenue',
									'address2' => '',
									'city' => 'New York',
									'state_or_province' => 'New York',
									'postal_code' => '10011',
									'country' => 'United States',
									'country_code' => 'US',
									'phone' => '0585143254',
									'email' => 'test@test.com',
								)),
							'line_items' =>
								array (
									0 =>
										array (
											'item_id' => 'fe4cca40-e807-4232-98e7-e581bba383a9',
											'quantity' => 5,
										),
								),
						)),
				), //input
				//output
				array(
					'get' => (object)(array(
						'data' =>
							(object)(array(
								'id' => '2e095089-3fd0-4f0e-aaa7-68fea86dcedf',
								'cart' =>
									(object)(array(
										'id' => '2e095089-3fd0-4f0e-aaa7-68fea86dcedf',
										'customer_id' => 1,
										'channel_id' => 62,
										'email' => 'vitaliy@reznikov.ru',
										'currency' =>
											(object)(array(
												'code' => 'USD',
											)),
										'base_amount' => 100,
										'discount_amount' => 0,
										'cart_amount_inc_tax' => 107,
										'cart_amount_ex_tax' => 100,
										'coupons' =>
											array (
											),
										'discounts' =>
											array (
												0 =>
													(object)(array(
														'id' => 'fe4cca40-e807-4232-98e7-e581bba383a9',
														'discounted_amount' => 0,
													)),
											),
										'line_items' =>
											(object)(array(
												'physical_items' =>
													array (
														0 =>
															(object)(array(
																'id' => 'fe4cca40-e807-4232-98e7-e581bba383a9',
																'parent_id' => NULL,
																'variant_id' => 123,
																'product_id' => 113,
																'sku' => 'test2',
																'name' => 'simple test product',
																'url' => 'http://shop149.mybigcommerce.com/simple-test-product/',
																'quantity' => 5,
																'is_taxable' => true,
																'image_url' => 'https://cdn11.bigcommerce.com/r-03b8fdf5d1037c0feebbcedfd701c709422a962e/themes/ClassicNext/images/ProductDefault.gif',
																'discounts' =>
																	array (
																	),
																'coupons' =>
																	array (
																	),
																'discount_amount' => 0,
																'coupon_amount' => 0,
																'list_price' => 20,
																'sale_price' => 20,
																'extended_list_price' => 100,
																'extended_sale_price' => 100,
																'is_require_shipping' => true,
																'gift_wrapping' => NULL,
															)),
													),
												'digital_items' =>
													array (
													),
												'gift_certificates' =>
													array (
													),
												'custom_items' =>
													array (
													),
											)),
										'created_time' => '2018-11-22T13:16:23+00:00',
										'updated_time' => '2018-11-24T14:50:38+00:00',
									)),
								'billing_address' =>
									(object)(array(
										'id' => '5bf6ac273a017',
										'first_name' => 'John',
										'last_name' => 'Smit',
										'email' => 'vitaliy@reznikov.ru',
										'company' => '',
										'address1' => '8 5th Avenue',
										'address2' => '',
										'city' => 'New York',
										'state_or_province' => 'New York',
										'state_or_province_code' => 'NY',
										'country' => 'United States',
										'country_code' => 'US',
										'postal_code' => '10011',
										'phone' => '0585143254',
										'custom_fields' =>
											array (
											),
									)),
								'consignments' =>
									array (
										0 =>
											(object)(array(
												'id' => '5bf95f6caccf4',
												'shipping_cost_inc_tax' => 0,
												'shipping_cost_ex_tax' => 0,
												'handling_cost_inc_tax' => 0,
												'handling_cost_ex_tax' => 0,
												'coupon_discounts' =>
													array (
													),
												'discounts' =>
													array (
													),
												'line_item_ids' =>
													array (
														0 => 'fe4cca40-e807-4232-98e7-e581bba383a9',
													),
												'selected_shipping_option' =>
													(object)(array(
														'id' => '103acce0-00e8-4dce-84d8-4db6f36b47e2',
														'type' => 'freeshipping',
														'description' => 'Free Shipping',
														'image_url' => '',
														'cost' => 0,
														'transit_time' => '',
													)),
												'shipping_address' =>
													(object)(array(
														'first_name' => 'John',
														'last_name' => 'Smit',
														'email' => 'test@test.com',
														'company' => '',
														'address1' => '8 5th Avenue',
														'address2' => '',
														'city' => 'New York',
														'state_or_province' => 'New York',
														'state_or_province_code' => 'NY',
														'country' => 'United States',
														'country_code' => 'US',
														'postal_code' => '10011',
														'phone' => '0585143254',
														'custom_fields' =>
															array (
															),
													)),
											)),
									),
								'taxes' =>
									array (
										0 =>
											(object)(array(
												'name' => 'Tax',
												'amount' => 7,
											)),
									),
								'coupons' =>
									array (
									),
								'order_id' => NULL,
								'shipping_cost_total_inc_tax' => 0,
								'shipping_cost_total_ex_tax' => 0,
								'handling_cost_total_inc_tax' => 0,
								'handling_cost_total_ex_tax' => 0,
								'tax_total' => 7,
								'subtotal_inc_tax' => 107,
								'subtotal_ex_tax' => 100,
								'grand_total' => 107,
								'created_time' => '2018-11-22T13:16:23+00:00',
								'updated_time' => '2018-11-24T14:50:38+00:00',
								'customer_message' => '',
							)),
						'meta' =>
							(object)(array(
							)),
					))
				)//output
			)
		);
	}
}
