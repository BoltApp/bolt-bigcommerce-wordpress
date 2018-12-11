<?php

namespace BoltBigcommerce;

class BoltSaveOrderTest extends \WP_UnitTestCase {
	/**
	 * @dataProvider CreateOrderProvider
	 */
	public function test_CreateOrder_JsonCall_CallCheckoutMethodsSetShippingOptionAndCreateOrder( $bolt_reference, $order_reference, $bolt_data, $is_json ) {
		$mock_checkout = $this->getMockBuilder( 'BoltBigcommerce\Bolt_Checkout' )
		                      ->disableOriginalConstructor()
		                      ->setMethods( array( 'set_shipping_option', 'create_order', 'delete', 'get' ) )
		                      ->getMock();

		$shipping_option = $bolt_data->shipping_option->value->reference;
		$mock_checkout->expects( $this->once() )
		              ->method( 'set_shipping_option' )
		              ->with( $this->equalTo( $shipping_option ) );

		$mock_checkout->expects( $this->once() )
		              ->method( 'create_order' );

		update_option( "bolt_cart_id_" . $order_reference, array( 'cart_id' => true ) );

		$bolt_save_order = $this->getMockBuilder( 'BoltBigcommerce\Bolt_Save_Order' )
		                        ->setMethods( array( 'get_checkout_api', 'order_update' ) )
		                        ->getMock();
		$bolt_save_order->expects( $this->any() )
		                ->method( 'get_checkout_api' )
		                ->will( $this->returnValue( $mock_checkout ) );

		$bolt_save_order->bolt_create_order( $bolt_reference, $order_reference, $bolt_data, $is_json );

		delete_option( "bolt_cart_id_" . $order_reference );
	}

	public function CreateOrderProvider() {
		return array(
			//is_json=true
			array(
				'bolt_reference'  => 'FP2N-JWT7-9ZBR',
				'order_reference' => 'BLT5bf9a4f247214',
				'bolt_data'       =>
					(object) ( array(
						'id'                       => 'TAh4d5MVTUuSR',
						'type'                     => 'cc_payment',
						'date'                     => 1543087448176,
						'reference'                => 'FP2N-JWT7-9ZBR',
						'status'                   => 'pending',
						'from_consumer'            =>
							(object) ( array(
								'id'             => 'CAcF6ArounGCE',
								'first_name'     => 'John',
								'last_name'      => 'Smith',
								'avatar'         =>
									(object) ( array(
										'domain'   => 'img-sandbox.bolt.com',
										'resource' => 'default.png',
									) ),
								'authentication' =>
									(object) ( array(
										'methods' =>
											array(
												0 => 'code',
											),
										'actions' =>
											array(
												0 => 'set_password',
											),
									) ),
								'phones'         =>
									array(
										0 =>
											(object) ( array(
												'id'           => 'PA6ZpWqydRBo5',
												'number'       => ' * ******3254',
												'country_code' => '1',
												'status'       => 'pending',
												'priority'     => 'listed',
											) ),
									),
								'emails'         =>
									array(
										0 =>
											(object) ( array(
												'id'       => 'EA9sNXueN2zeu',
												'address'  => 'te***@te***.***',
												'status'   => 'pending',
												'priority' => 'primary',
											) ),
									),
							) ),
						'to_consumer'              =>
							(object) ( array(
								'id'         => 'CAjMjKd5wHAas',
								'first_name' => 'Vitaliy',
								'last_name'  => 'Reznikov',
								'avatar'     =>
									(object) ( array(
										'domain'   => 'img-sandbox.bolt.com',
										'resource' => 'default.png',
									) ),
							) ),
						'from_credit_card'         =>
							(object) ( array(
								'id'              => 'CA5UReRh7LS5g',
								'description'     => 'default card',
								'last4'           => '1111',
								'bin'             => '411111',
								'expiration'      => 1664582400000,
								'network'         => 'visa',
								'token_type'      => 'vantiv',
								'priority'        => 'listed',
								'display_network' => 'Visa',
								'icon_asset_path' => 'img/issuer-logos/visa.png',
								'status'          => 'active',
								'billing_address' =>
									(object) ( array(
										'id'              => 'AAgvwNGmfVa2B',
										'street_address1' => '8 Main Street',
										'locality'        => 'Buffalo',
										'region'          => 'New York',
										'postal_code'     => '10251',
										'country_code'    => 'US',
										'country'         => 'United States',
										'name'            => 'John Smith',
										'first_name'      => 'John',
										'last_name'       => 'Smith',
										'phone_number'    => '0584217904',
										'email_address'   => 'test@test.com',
									) ),
							) ),
						'amount'                   =>
							(object) ( array(
								'amount'          => 11770,
								'currency'        => 'USD',
								'currency_symbol' => '$',
							) ),
						'authorization'            =>
							(object) ( array(
								'status' => 'succeeded',
								'reason' => 'none',
							) ),
						'captures'                 =>
							array(),
						'merchant_division'        =>
							(object) ( array(
								'id'                   => 'MAaj6pRvDMpfV',
								'merchant_id'          => 'MA5nZHCUMVWNW',
								'public_id'            => 'Gnp9ViKZGOyx',
								'description'          => 'vitaliy BC Wordpress sandbox',
								'logo'                 =>
									(object) ( array(
										'domain'   => 'img-sandbox.bolt.com',
										'resource' => '',
									) ),
								'hook_url'             => 'https://shop2.reznikov.ru/wp-json/bolt/response',
								'hook_type'            => 'bolt',
								'shipping_and_tax_url' => 'https://shop2.reznikov.ru/wp-json/bolt/shippingtax',
							) ),
						'indemnification_decision' => 'indemnified',
						'indemnification_reason'   => 'checkout',
						'billing_address'          =>
							(object) ( array(
								'street_address1' => '8 Main Street',
								'street_address2' => '',
								'locality'        => 'Buffalo',
								'region'          => 'New York',
								'postal_code'     => '10251',
								'company'         => '',
								'country'         => 'United States',
								'country_code'    => 'US',
								'first_name'      => 'John',
								'last_name'       => 'Smith',
								'phone'           => '0584217904',
								'email'           => 'test@test.com',
							) ),
						'shipping_address'         =>
							(object) ( array(
								'street_address1' => '8 Main Street',
								'street_address2' => '',
								'locality'        => 'Buffalo',
								'region'          => 'New York',
								'postal_code'     => '10251',
								'company'         => '',
								'country'         => 'United States',
								'country_code'    => 'US',
								'first_name'      => 'Vitaliy',
								'last_name'       => 'Reznikov',
								'phone'           => '0584217904',
								'email'           => 'test@test.com',
							) ),
						'cart'                     =>
							(object) ( array(
								'order_reference' => 'BLT5bf9a4f247214',
							) ),
						'shipping_option'          =>
							(object) ( array(
								'valid' => true,
								'value' =>
									(object) ( array(
										'service'    => 'Flat Rate',
										'cost'       =>
											(object) ( array(
												'amount'          => 1000,
												'currency'        => 'USD',
												'currency_symbol' => '$',
											) ),
										'tax_amount' =>
											(object) ( array(
												'amount'          => 70,
												'currency'        => 'USD',
												'currency_symbol' => '$',
											) ),
										'reference'  => '1f9dc672-b31d-4889-8693-38ff2cb9ff86',
										'signature'  => 'uT5UaKO0GSH4k GRk7V 5AHaIixJk bDFAB0G3FRrSs=',
									) ),
							) ),
					) ),
				'is_json'         => true,
			)
		);
	}

	public function MockForSetOrderStatus( $order ) {
		$bolt_save_order = $this->getMockBuilder( 'BoltBigcommerce\Bolt_Save_Order' )
		                        ->setMethods( array(
			                        'getorder',
			                        'add_rejected_reversible_note',
			                        'delete_rejected_reversible_note',
			                        'order_update'
		                        ) )
		                        ->getMock();

		$bolt_save_order->expects( $this->any() )
		                ->method( 'getorder' )
		                ->will( $this->returnValue( $order ) );

		return $bolt_save_order;
	}

	/**
	 * @dataProvider OrderStatusDigitalProvider
	 */
	public function test_SetOrderStatus_DigitalOrder_CheckReturnValue( $bolt_data, $result ) {
		$bolt_save_order = $this->MockForSetOrderStatus( (object) array(
			'order_is_digital' => true,
			'total_inc_tax'    => 20,
			'refunded_amount'  => 0,
		) );

		$bolt_save_order->expects( $this->once() )
		                ->method( 'order_update' )
		                ->with( $this->equalTo( $result ) );

		$bolt_save_order->order_set_status( $bolt_data );
	}

	public function OrderStatusDigitalProvider() {
		return array(
			'payment'               => array(
				'input'  => (object) array( 'type' => 'payment', 'id' => 'id' ),
				'result' => array( 'status_id' => 10, 'payment_provider_id' => 'id' )
			),
			'capture'               => array(
				'input'  => (object) array( 'type' => 'capture', 'id' => 'id' ),
				'result' => array( 'status_id' => 10, 'payment_provider_id' => 'id' )
			),
			'credit_partially'      => array(
				'input'  => (object) array( 'type' => 'credit', 'amount' => 1000 ),
				'result' => array( 'status_id' => 14, 'refunded_amount' => 10 )
			),
			'credit_full'           => array(
				'input'  => (object) array( 'type' => 'credit', 'amount' => 2000 ),
				'result' => array( 'status_id' => 4, 'refunded_amount' => 20 )
			),
			'void'                  => array(
				'input'  => (object) array( 'type' => 'void' ),
				'result' => array( 'status_id' => 5 )
			),
			'auth'                  => array(
				'input'  => (object) array( 'type' => 'auth', 'id' => 'id' ),
				'result' => array( 'status_id' => 12, 'payment_provider_id' => 'id' )
			),
			'pending'               => array(
				'input'  => (object) array( 'type' => 'pending', 'id' => 'id' ),
				'result' => array( 'status_id' => 12, 'payment_provider_id' => 'id' )
			),
			'rejected_reversible'   => array(
				'input'  => (object) array( 'type' => 'rejected_reversible', 'id' => 'id' ),
				'result' => array( 'status_id' => 12, 'payment_provider_id' => 'id' )
			),
			'rejected_irreversible' => array(
				'input'  => (object) array( 'type' => 'rejected_irreversible' ),
				'result' => array( 'status_id' => 5, )
			),

		);
	}

	/**
	 * @dataProvider OrderStatusNonDigitalProvider
	 */
	public function test_SetOrderStatus_NonDigitalOrder_CheckReturnValue( $bolt_data, $result ) {
		$bolt_save_order = $this->MockForSetOrderStatus( (object) array(
			'order_is_digital' => false,
			'total_inc_tax'    => 20,
			'refunded_amount'  => 0,
		) );

		$bolt_save_order->expects( $this->once() )
		                ->method( 'order_update' )
		                ->with( $this->equalTo( $result ) );

		$bolt_save_order->order_set_status( $bolt_data );
	}

	public function OrderStatusNonDigitalProvider() {
		$result                                   = $this->OrderStatusDigitalProvider();
		$result['payment']['result']['status_id'] = 11;
		$result['capture']['result']['status_id'] = 11;

		return $result;
	}

}