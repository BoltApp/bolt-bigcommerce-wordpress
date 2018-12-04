<?php
namespace BoltBigcommerce;

if ( !defined( 'ABSPATH' ) ) {
	exit;
}


class Bolt_Shipping_And_Tax
{
	public function __construct()
	{
		add_action( 'rest_api_init', array( $this, 'register_endpoints' ) );
	}

	private $cart;
	private $order_reference;
	private $bigcommerce_cart_id;
	private function cart() {
		if (!isset($this->cart)) {
			$this->cart = new Bolt_Cart();
		}
		return $this->cart;
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
		$bc_address = new \stdClass();
		$bc_address->first_name = $bolt_address->first_name;
		$bc_address->last_name = $bolt_address->last_name;
		//TODO ADD company from Bolt
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


	//return shipping methods
	protected function get_checkout_api($cart_id) {
		return new Bolt_Checkout($cart_id);
	}


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
		$bolt_order_json = file_get_contents('php://input');

		if (!$signatureVerifier->verifySignature($bolt_order_json, $hmacHeader)) {
			BugsnagHelper::getBugsnag()->notifyException(new \Exception("Failed HMAC Authentication"));
		}

		$bolt_order = json_decode($bolt_order_json);

		//try get data from cache
		$bolt_cart_md5 = md5( $bolt_order_json );
		if ( $cached_estimate = get_option( 'bolt_shipping_and_tax_' . $bolt_order->cart->order_reference . "_" . $bolt_cart_md5 ) ) {
			BoltLogger::write( "return shipping_and_tax value from cache" );
			wp_send_json( json_decode( $cached_estimate ) );
		}

		$shipping_and_tax_payload = $this->evaluate_shipping_tax( $bolt_order );

		BoltLogger::write( "response shipping options" . print_r( $shipping_and_tax_payload, true ) );

		wp_send_json( $shipping_and_tax_payload );
	}

	public function evaluate_shipping_tax( $bolt_order ) {
		BoltLogger::write( "evaluate_shipping_tax " . var_export( $bolt_order, true ) );

		$this->order_reference = $bolt_order->cart->order_reference;

		//get Bigcommerce checkout

		$bolt_cart_id_option = get_option( "bolt_cart_id_" . $bolt_order->cart->order_reference );
		BoltLogger::write( print_r($bolt_cart_id_option,true). " = get_option( \"bolt_cart_id_\" . {$bolt_order->cart->order_reference} )" );
		$this->bigcommerce_cart_id = $bolt_cart_id_option['cart_id'];
		if (!$this->bigcommerce_cart_id) {
			BugsnagHelper::getBugsnag()->notifyException(new \Exception("Can't read bigcommerce_cart_id for " .$bolt_order->cart->order_reference ) );
			return false;
		}

		if (isset($bolt_cart_id_option['product'])) {
			//need to add product to cart cos user in product page
			$this->add_product_to_cart($bolt_cart_id_option);
		}

		$checkout = $this->get_checkout_api( $this->bigcommerce_cart_id );

		//files names differ between BC v2 and v3 API. For example country_code <==> country_iso2
		$address = $this->convert_bolt_address_to_bc( $bolt_order->cart->billing_address );
		//TODO (after v3): If checkout bolt cart is different from BC cart use checkout bolt cart

		$checkout->update_address($address);

		//TODO test with delivery method (now tested only for digital products, not work if update_customer_id before update_address
		//TODO test mixed cart (added before+button on cart page)
		//TODO test for unregistered users

		if (isset($bolt_cart_id_option["product"]['customer_id'])) {
			//change customer_id only if necessary
			BoltLogger::write("change customer_id from '".$checkout->get()->data->cart->customer_id."' to '{$bolt_cart_id_option["product"]['customer_id']}'");
			if ($checkout->get()->data->cart->customer_id <> $bolt_cart_id_option["product"]['customer_id']) {
				BCCLIENT::updateResource("/v3/carts/{$this->bigcommerce_cart_id}",
					array(
						'customer_id'=> $bolt_cart_id_option["product"]['customer_id']
					));
			}
		}

		//Add or update consignment to Checkout
		$consignment = new \stdClass();
		//In Bolt  the shipping address is the same as the billing address
		$consignment->shipping_address = $address;
		//send all physical products to this address
		$bolt_shipping_options = array();
		$physical_items = $checkout->get()->data->cart->line_items->physical_items;
		BoltLogger::write("checkout->get()=".var_export($checkout->get(),true) );
		if ( !empty( $physical_items ) ) { //shipping is required
			foreach ( $physical_items as $physical_item ) {
				$consignment->line_items[] = array(
					"item_id" => $physical_item->id,
					"quantity" => $physical_item->quantity,
				);
			}
			$checkout->add_or_update_consignment($consignment);
			$bolt_shipping_options = $checkout->get_shipping_options();
		} else { //shipping isn't required
			$bolt_shipping_options = array(
				array(
					"service" => "no shipping required",
					"reference" => "no_shipping",
					"cost" => 0,
					"tax_amount" => 0,
				)
			);
		}
		$cart_tax = (int)round( ($checkout->get()->data->cart->cart_amount_inc_tax - $checkout->get()->data->cart->cart_amount_ex_tax) * 100 );

		$shipping_and_tax_payload = (object)array(
			'tax_result' => (object)array(
				'amount' => $cart_tax
			),
			'shipping_options' => $bolt_shipping_options,
		);
		if (empty($bolt_shipping_options)) {
			BugsnagHelper::getBugsnag()->notifyError("notifyError",
				"Bigcommerce doesn't have shipping options for this address",
				array ( 'address' => $address )
			);
		} else {
			// Cache the shipping and tax response
			update_option( 'bolt_shipping_and_tax_' . $bolt_order->cart->order_reference . "_" . $bolt_cart_md5, json_encode($shipping_and_tax_payload ), false );
  }
 return $shipping_and_tax_payload;
	}

	private function add_product_to_cart($bolt_cart_id_option) {
		$product = $bolt_cart_id_option['product'];
		$_COOKIE['bigcommerce_cart_id'] = $bolt_cart_id_option['cart_id'];
		$response = $this->cart()->add_line_item( $product['product_id'], $product['options'], $product['quantity'], $product['modifiers'] );
		if ($response->getId() <> $bolt_cart_id_option['cart_id']) {
			//new cart was created
			BoltLogger::write("customer_id {$product['customer_id']} set for cart ".$response->getId()." Old cart {$bolt_cart_id_option['cart_id']}");
			$bolt_cart_id_option['cart_id'] = $response->getId();
			/*
			if (($product['customer_id'])) {
				BCCLIENT::updateResource("/v3/carts/{$bolt_cart_id_option['cart_id']}",
					array(
						'customer_id'=> $product['customer_id']
					));
			}
			*/
			update_option( "bolt_cart_id_" . $this->order_reference, $bolt_cart_id_option);
			$this->bigcommerce_cart_id = $bolt_cart_id_option['cart_id'];
		}
	}

}