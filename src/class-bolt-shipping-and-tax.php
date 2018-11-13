<?php

if ( !defined( 'ABSPATH' ) ) {
	exit;
}

class Bolt_Shipping_And_Tax
{
	public function __construct()
	{
		add_action( 'rest_api_init', array( $this, 'register_endpoints' ) );
	}

	/**
	 * Register wordpress endpoints
	 */
	public function register_endpoints()
	{
		register_rest_route( 'bolt', '/shippingtax', array(
			'methods' => WP_REST_Server::CREATABLE,
			'callback' => array( $this, 'handler_shipping_tax' ),
		) );
	}

	/**
	 * Convert address from bolt format to Bigcommerce
	 *
	 * @param  stdClass $bolt_address
	 *
	 * @return stdClass Address in Bigcommerce format
	 */
	private function convert_bolt_address_to_bc( $bolt_address )
	{
		$bc_address = new stdClass();
		$bc_address->first_name = $bolt_address->first_name;
		$bc_address->last_name = $bolt_address->last_name;
		$bc_address->company = "";
		$bc_address->address1 = $bolt_address->street_address1;
		$bc_address->address2 = $bolt_address->street_address2;
		$bc_address->city = $bolt_address->locality;
		$bc_address->state_or_province = $bolt_address->region;
		$bc_address->postal_code = $bolt_address->postal_code;
		$bc_address->country = $bolt_address->country;
		$bc_address->country_code = $bolt_address->country_code;
		$bc_address->phone = $bolt_address->phone;
		$bc_address->email = $bolt_address->email;
		return $bc_address;
	}

	/**
	 * Compare two addresses in Bigcommerce format
	 *
	 * @param $address1
	 * @param $address2
	 * @return bool true if address different, false if  the same
	 */
	private function address_is_change( $address1, $address2 )
	{
		$properties = array( 'first_name', 'last_name', 'company', 'address1', 'address2', 'city', 'state_or_province', 'postal_code', 'country', 'country_code', 'phone', 'email' );
		foreach ( $properties as $property ) {
			if ( !property_exists( $address1, $property ) || $address1->{$property} <> $address2->{$property} ) {
				ob_start();
				var_dump( $address1->{$property} );
				var_dump( $address2->{$property} );
				$dump = ob_get_clean();
				BoltLogger::write( "address_is_change {$property} {$dump}" . print_r( $address1, true ) . " " . print_r( $address2, true ) );
				return true;
			}
		}
		return false;
	}

	//return shipping methods

	/**
	 * Return object with all accessable shipping methods & tax
	 * GET $bolt_order from php://input
	 */
	function handler_shipping_tax()
	{
		//TODO deal with shipping discount https://store-5669f02hng.mybigcommerce.com/manage/marketing/discounts/create
		$hmacHeader = @$_SERVER['HTTP_X_BOLT_HMAC_SHA256'];
		$signatureVerifier = new \BoltPay\SignatureVerifier(
			\BoltPay\Bolt::$signingSecret
		);
		$bolt_order_json = file_get_contents( 'php://input' );
		$bolt_order = json_decode( $bolt_order_json );

		BoltLogger::write( "handler_shipping_tax " . print_r( $bolt_order, true ) );

		if ( !$signatureVerifier->verifySignature( $bolt_order_json, $hmacHeader ) ) {
			BugsnagHelper::getBugsnag()->notifyException( new Exception( "Failed HMAC Authentication" ) );
		}

		//try get data from cache
		$bolt_cart_md5 = md5( $bolt_order_json );
		if ( $cached_estimate = get_option( 'bolt_shipping_and_tax_' . $bolt_order->cart->order_reference . "_" . $bolt_cart_md5 ) ) {
			BoltLogger::write( "return shipping_and_tax value from cache" );
			wp_send_json( json_decode( $cached_estimate ) );
		}

		//get Bigcommerce checkout
		$bigcommerce_cart_id = get_option( "bolt_cart_id_" . $bolt_order->cart->order_reference );
		BoltLogger::write( "{$bigcommerce_cart_id} = get_option( \"bolt_cart_id_\" . {$bolt_order->cart->order_reference} )" );

		$checkout = BCClient::getCollection( "/v3/checkouts/{$bigcommerce_cart_id}" );
		if (!$checkout) {
			BugsnagHelper::getBugsnag()->notifyException( new Exception( "Can't get checkout" ) );
		}
		BoltLogger::write( "checkout = BCClient::getCollection( \"/v3/checkouts/{$bigcommerce_cart_id}\" );" );
		BoltLogger::write( "get checkout " . print_r( $checkout, true ) );

		//files names differ between BC v2 and v3 API. For example country_code <==> country_iso2
		$address = $this->convert_bolt_address_to_bc( $bolt_order->cart->billing_address );
		//TODO (after v3): If checkout bolt cart is different from BC cart use checkout bolt cart

		if ( $this->address_is_change( $checkout->data->billing_address, $address ) ) {
			//add or update billing address
			$checkout = BCClient::createResource( "/v3/checkouts/{$bigcommerce_cart_id}/billing-address", $address );
			if (!$checkout) {
				BugsnagHelper::getBugsnag()->notifyException( new Exception( "Can't add address to checkout" ) );
			}
			BoltLogger::write( "add billing address /v3/checkouts/{$bigcommerce_cart_id}/billing-address " . json_encode( $address ) );
			BoltLogger::write( "add billing address answer " . print_r( $checkout, true ) );
		}
		//Add or update consignment to Checkout
		$consignment = new stdClass();
		//In Bolt  the shipping address is the same as the billing address
		$consignment->shipping_address = $address;
		//send all physical products to this address
		$bolt_shipping_options = array();
		$physical_items = $checkout->data->cart->line_items->physical_items;
		if ( !empty( $physical_items ) ) { //shipping is required
			foreach ( $physical_items as $physical_item ) {
				$consignment->line_items[] = array(
					"item_id" => $physical_item->id,
					"quantity" => $physical_item->quantity,
				);
			}
			if ( !isset( $checkout->data->consignments[0] ) ) { //consignment not created
				$params = array( $consignment );
				BoltLogger::write( "Add a New Consignment /v3/checkouts/{$bigcommerce_cart_id}/consignments?include=consignments.available_shipping_options" );
				BoltLogger::write( json_encode( $params ) );
				$checkout = BCClient::createResource( "/v3/checkouts/{$bigcommerce_cart_id}/consignments?include=consignments.available_shipping_options", $params );
				if (!$checkout) {
					BugsnagHelper::getBugsnag()->notifyException( new Exception( "Can't create consignment" ) );
				}
				BoltLogger::write( "Add a New Consignment answer " . print_r( $checkout, true ) );
				$consignment_id = $checkout->data->consignments[0]->id;
				BoltLogger::write( "New consigment ID {$consignment_id}" );
			} else {
				$consignment_id = $checkout->data->consignments[0]->id;
				BoltLogger::write( "UPDATE Consignment /v3/checkouts/{$bigcommerce_cart_id}/consignments/$consignment_id?include=consignments.available_shipping_options" );
				BoltLogger::write( json_encode( $consignment ) );
				$checkout = BCClient::updateResource( "/v3/checkouts/{$bigcommerce_cart_id}/consignments/$consignment_id?include=consignments.available_shipping_options", $consignment );
				if (!$checkout) {
					BugsnagHelper::getBugsnag()->notifyException( new Exception( "Can't update consignment" ) );
				}

				BoltLogger::write( "New Consignment update answer " . print_r( $checkout, true ) );
			}
			$shipping_options = $checkout->data->consignments[0]->available_shipping_options;
			foreach ( $shipping_options as $shipping_option ) {
				$cost = (int)round( $shipping_option->cost * 100 );
				//get tax amount for this shipping option
				$body = (object)array( "shipping_option_id" => $shipping_option->id );
				BoltLogger::write( "UPDATE Consignment for calculate tax amount /v3/checkouts/{$bigcommerce_cart_id}/consignments/$consignment_id" );
				BoltLogger::write( json_encode( $body ) );
				$checkout_cost = BCClient::updateResource( "/v3/checkouts/{$bigcommerce_cart_id}/consignments/$consignment_id?include=consignments.available_shipping_options", $body );
				if (!$checkout_cost) {
					BugsnagHelper::getBugsnag()->notifyException( new Exception( "Can't update consignment for calculate tax amount" ) );
				}
				BoltLogger::write( "UPDATE Consignment for calculate tax amount answer " . print_r( $checkout_cost, true ) );
				$tax_amount = (int)round( ($checkout_cost->data->consignments[0]->shipping_cost_inc_tax - $checkout_cost->data->consignments[0]->shipping_cost_ex_tax) * 100 );
				//add handling_cost as shipping
				$cost += (int)round( $checkout_cost->data->consignments[0]->handling_cost_ex_tax * 100 );
				$tax_amount += (int)round( ($checkout_cost->data->consignments[0]->handling_cost_inc_tax - $checkout_cost->data->consignments[0]->handling_cost_ex_tax) * 100 );
				$bolt_shipping_options[] = array(
					"service" => $shipping_option->description,
					"reference" => $shipping_option->id,
					"cost" => $cost,
					"tax_amount" => $tax_amount,
				);
			}
		} else { //shipping isn't required
			$bolt_shipping_options[] = array(
				"service" => "no shipping required",
				"reference" => "no_shipping",
				"cost" => 0,
				"tax_amount" => 0,
			);
		}
		$cart_tax = (int)round( ($checkout->data->cart->cart_amount_inc_tax - $checkout->data->cart->cart_amount_ex_tax) * 100 );

		$shipping_and_tax_payload = (object)array(
			'tax_result' => (object)array(
				'amount' => $cart_tax
			),
			'shipping_options' => $bolt_shipping_options,
		);

		// Cache the shipping and tax response
		update_option( 'bolt_shipping_and_tax_' . $bolt_order->cart->order_reference . "_" . $bolt_cart_md5, json_encode( $shipping_and_tax_payload ), false );

		BoltLogger::write( "response shipping options" . print_r( $shipping_and_tax_payload, true ) );
		wp_send_json( $shipping_and_tax_payload );
	}

}