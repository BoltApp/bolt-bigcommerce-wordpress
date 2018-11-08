<?php

if ( !defined( 'ABSPATH' ) ) {
	exit;
}

class Bolt_Generate_Order_Token
{

	public function __construct()
	{
		//echo "#1"; exit;
		BugsnagHelper::initBugsnag();
		add_action( 'bigcommerce/cart/proceed_to_checkout', array( $this, 'bolt_cart_button' ) );
	}

	//render html and js code for bolt checkout button
	//code "bolt_cart_button($cart);" added in bigcommerce-for-wordpress-master/public-views/cart.php
	//before "</footer>"
	function bolt_cart_button( $bigcommerce_cart )
	{
		//TODO: (later) If the cart changes without page reload handle then send to Bolt the new cart
		//In bolt-woocommerce we use page reload at event 'updated_cart_totals' but I don't see JS event on bigcommerce-wordpress
		BoltLogger::write( "bolt_cart_button " . print_r( $bigcommerce_cart, true ) );

		$currency_code = get_option( BigCommerce\Settings\Sections\Currency::CURRENCY_CODE, '' );

		$order_reference = uniqid( 'BLT', false );
		$cart = array(
			"order_reference" => $order_reference,
			"display_id" => $order_reference,
			"currency" => $currency_code,
			"total_amount" => (int)($bigcommerce_cart["cart_amount"]["raw"] * 100),
			"tax_amount" => (int)($bigcommerce_cart["tax_amount"]["raw"] * 100),
			"discounts" => array(),
		);

		//Discounts for product: show only discounted price as well as in bolt-woocommerce
		//Discounts for cart: show only total discount (Bigcommerce restrictions)
		//Coupon codes: customer can use it only after press "Bigcommerce process to checkout"
		if ( $bigcommerce_cart["discount_amount"] ) {
			$cart["discounts"][] = array(
				"amount" => (int)($bigcommerce_cart["discount_amount"]["raw"] * 100),
				"description" => "Discount",
			);
		}


		foreach ( $bigcommerce_cart["items"] AS $item_id => $item ) {
			if ( "digital" == $item["bigcommerce_product_type"][0]["label"] ) {
				$type = "digital";
			} else {
				$type = "physical";
			}
			$cart["items"][] = array(
				"reference" => (string)$item["product_id"],
				"name" => $item["name"],
				"sku" => $item["sku"]["product"],
				"description" => "",
				"total_amount" => (int)($item["total_sale_price"]["raw"] * 100),
				"unit_price" => (int)($item["sale_price"]["raw"] * 100),
				"quantity" => $item["quantity"],
				"type" => $type,
			);
		}
		$cartData = array( "cart" => $cart );
		BoltLogger::write( "Create cart " . print_r( $cartData, true ) );

		$client = new \BoltPay\ApiClient( [
			'api_key' => \BoltPay\Bolt::$apiKey,
			'is_sandbox' => \BoltPay\Bolt::$isSandboxMode
		] );

		$response = $client->createOrder( $cartData );
		$orderToken = $response->isResponseSuccessful() ? @$response->getBody()->token : '';
		BoltLogger::write( "Create cart orderToken " . $orderToken );
		if ( !$orderToken ) {
			echo "error Bolt order create";
			print_r( $response );
			print_r( $cartData );
			print_r( $bigcommerce_cart );
			exit;
		}
		//save link between order_reference and bolt_cart_id_
		//it uses when we create order in bigcommerce (function bolt_create_order)
		$updated = update_option( "bolt_cart_id_" . $order_reference, $bigcommerce_cart["cart_id"] );
		BoltLogger::write( "cart_id={$bigcommerce_cart["cart_id"]}" );

		$hints = $this->calculate_hints();

		echo '<div class="bolt-checkout-button with-cards"></div>';
		echo \BoltPay\Helper::renderBoltTrackScriptTag();
		echo \BoltPay\Helper::renderBoltConnectScriptTag();
		$this->render( "main.js.php", array( "orderToken" => $orderToken, "hints" => $hints ) );
	}

	private function calculate_hints()
	{
		$current_user = wp_get_current_user();
		if ( 0 == $current_user->ID ) {
			return "{}";
		} else {
			/*
			var hints = {
				"prefill": {
					"firstName": "Bolt",
    "lastName": "User",
    "email": "email@example.com",
    "phone": "1112223333",
    "addressLine1": "1235 Howard St",
    "addressLine2": "Unit D",
    "city": "San Francisco",
    "state": "California",
    "zip": "94103",
    "country": "US" // ISO Alpha-2 format expected
  }
};*/
			$prefill = array();
			if (isset($current_user->user_firstname)) {
				$prefill["firstName"] = $current_user->user_firstname;
			}
			if (isset($current_user->user_lastname)) {
				$prefill["lastName"] = $current_user->user_lastname;
			}
			if (isset($current_user->user_lastname)) {
				$prefill["email"] = $current_user->user_email;
			}
			if (empty($prefill)) {
				return "{}";
			} else {
				return json_encode((object)array("prefill"=>(object)$prefill));
			}
		}
	}

	//render template
	public function render( $template_name, array $parameters = array(), $render_output = true )
	{
		foreach ( $parameters as $name => $value ) {
			${$name} = $value;
		}
		ob_start();
		include __DIR__ . '/../view/' . $template_name;
		$output = ob_get_contents();
		ob_end_clean();

		if ( $render_output ) {
			echo $output;
		} else {
			return $output;
		}
	}
}
