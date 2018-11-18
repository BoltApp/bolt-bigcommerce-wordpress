<?php

if ( !defined( 'ABSPATH' ) ) {
	exit;
}

class Bolt_Generate_Order_Token
{
	private $error;

	public function __construct()
	{
		add_filter( 'bigcommerce/template=components/cart/cart-footer.php/data', array( $this, 'change_bigcommerce_cart_footer_template'), 10, 1 );
	}

	/**
	 * filter for bolt button adding
	 *
	 * @param array $data
	 * @return array
	 */
	public function change_bigcommerce_cart_footer_template($data)
	{
		if ($this->check_products_availability($data['cart'])) {
			$data['actions'] .= $this->bolt_cart_button($data['cart']);
		} else {
			$data['actions'] .= '<div class="bc-cart-actions"><p>'.$this->error.'</div>';
		}
		return $data;
	}

	/**
	 * Format error text if the certain product isn't available
	 *
	 * @param $name product name
	 * @param $quantity product quanity on stock
	 */
	private function set_availability_error($name, $quantity) {
		if (0 == $quantity) {
			$this->error = "Product '{$name}' is currently unavailable";
		} else if (1 == $quantity) {
			$this->error = "We have only 1 item of '{$name}' on our stock";
		} else {
			$this->error = "We have only {$quantity} items of '{$name}' on our stock";
		}

	}

	/**
	 * Check if products in cart are available now
	 *
	 * @param array $cart bigcommerce cart
	 *
	 * @return bool
	 */
	private function check_products_availability($cart) {
		$availability = true;
		foreach ($cart["items"] as $item) {
			$product = BCClient::getCollection("/v2/products/{$item["product_id"]}?include=@summary");
			if ("available" != $product->availability) {
				$this->set_availability_error($product->name,0);
				$availability = false;
				break;
			} else 	if  (("simple" == $product->inventory_tracking) && ($product->inventory_level<$item["quantity"])) {
				$this->set_availability_error($product->name,$product->inventory_level);
				$availability = false;
				break;
			} else if ("sku" == $product->inventory_tracking) {
				//need to do additional API call for product withy variant
				$variant_product = BCClient::getCollection("/v3/catalog/products/{$item["product_id"]}/variants/{$item["variant_id"]}");
				if ($variant_product->data->inventory_level<$item["quantity"]) {
					$options = "";
					foreach($variant_product->data->option_values as $option_id=>$option) {
						if (0 <> $option_id) $options.=", ";
						$options .= $option->option_display_name . ":" . $option->label;
					}
					$product_name = $product->name . ' (' . $options . ")";
					$this->set_availability_error($product_name,$variant_product->data->inventory_level);
					$availability = false;
					break;
				}
			}

		}
		return $availability;
	}

	/**
	 * Render html and js code for bolt checkout button
	 * @param array $bigcommerce_cart Bigcommerce cart content
	 */
	function bolt_cart_button( $bigcommerce_cart )
	{
		//TODO: (later) If the cart changes without page reload handle then send to Bolt the new cart
		//In bolt-woocommerce we use page reload at event 'updated_cart_totals' but I don't see JS event on bigcommerce-wordpress
		BoltLogger::write( "bolt_cart_button " . print_r( $bigcommerce_cart, true ) );

		$currency_code = get_option( BigCommerce\Settings\Sections\Currency::CURRENCY_CODE, '' );

		$tax_amount = isset($bigcommerce_cart["tax_amount"]["raw"]) ? $bigcommerce_cart["tax_amount"]["raw"] * 100 : 0;
		$discount_amount = isset($bigcommerce_cart["discount_amount"]["raw"]) ? $bigcommerce_cart["discount_amount"]["raw"] * 100 : 0;
		if ($tax_amount<0) {
			//coupon using result
			$discount_amount -= $tax_amount;
			$tax_amount = 0;
		}

		$order_reference = uniqid( 'BLT', false );
		$cart = array(
			"order_reference" => $order_reference,
			"display_id" => $order_reference,
			"currency" => $currency_code,
			"total_amount" => (int)round($bigcommerce_cart["cart_amount"]["raw"] * 100),
			"tax_amount" => (int)round($tax_amount),
			"discounts" => array(),
		);

		//Discounts for product: show only discounted price as well as in bolt-woocommerce
		//Discounts for cart: show only total discount (Bigcommerce restrictions)
		//Coupon codes: customer can use it only after press "Bigcommerce process to checkout"
		if ( $discount_amount > 0 ) {
			$cart["discounts"][] = array(
				"amount" => (int)round($discount_amount),
				"description" => "Discount",
			);
		}

		foreach ( $bigcommerce_cart["items"] AS $item_id => $item ) {
			$type = ( "digital" == $item["bigcommerce_product_type"][0]["label"] ) ? "digital" : "physical";
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
			BugsnagHelper::getBugsnag()->notifyException( new Exception( "Bolt Order token doesn't create" ) );
			exit;
		}
		//save link between order_reference and bolt_cart_id_
		//it uses when we create order in bigcommerce (function bolt_create_order)
		$updated = update_option( "bolt_cart_id_" . $order_reference, $bigcommerce_cart["cart_id"] );
		BoltLogger::write( "cart_id={$bigcommerce_cart["cart_id"]}" );

		$hints = $this->calculate_hints();

		$result = '<div class="bc-cart-actions"><div class="bolt-checkout-button with-cards"></div></div>';
		$result .= \BoltPay\Helper::renderBoltTrackScriptTag();
		$result .= \BoltPay\Helper::renderBoltConnectScriptTag();
		$result .= $this->render( "main.js.php", array( "orderToken" => $orderToken, "hints" => $hints ),false );
		return $result;
	}

	/**
	 * Create array from object. Map properties according to $parameters
	 *
	 * @param object $source     source objects
	 * @param array  $parameters array with couples "name_from" "name_to"
	 *
	 * @return array
	 */
	private function copy_object_to_array( $source, $parameters )
	{
		$result = array();
		foreach ( $parameters as $from => $to ) {
			if ( isset( $source->$from ) ) {
				$result[$to] = $source->$from;
			}
		}
		return $result;
	}

	/**
	 * If we know customers details calculates it for Bolt JS variable hints
	 *
	 * @return string json_encode string for Bolt JS hints
	 */
	private function calculate_hints()
	{
		$current_user = wp_get_current_user();
		if ( 0 == $current_user->ID ) {
			return "{}";
		} else {
			//get user address from Bigcommperce by API
			$bc_customer_id = get_user_option( 'bigcommerce_customer_id', $current_user->ID );
			if ( $bc_customer_id ) {
				$addresses = BCClient::getCollection( "/v2/customers/{$bc_customer_id}/addresses" );
				$prefill = $this->copy_object_to_array( $addresses[0], array(
					"first_name" => "firstName",
					"last_name" => "addressLine1",
					"street_1" => "addressLine1",
					"street_2" => "addressLine2",
					"city" => "city",
					"state" => "state",
					"zip" => "zip",
					"country_iso2" => "country",
					"phone" => "phone" ) );
			} else {
				$prefill = array();
			}
			//if BC doesn't have information get name from Wordpress
			if ( empty( $prefill ) ) {
				if ( isset( $current_user->user_firstname ) ) {
					$prefill["firstName"] = $current_user->user_firstname;
				}
				if ( isset( $current_user->user_lastname ) ) {
					$prefill["lastName"] = $current_user->user_lastname;
				}
			}

			if ( isset( $current_user->user_email ) ) {
				$prefill["email"] = $current_user->user_email;
			}
			if ( empty( $prefill ) ) {
				return "{}";
			} else {
				return json_encode( (object)array( "prefill" => (object)$prefill ) );
			}
		}
	}

	//render template

	/**
	 * Render template
	 *
	 * @param string $template_name
	 * @param array  $parameters
	 * @param bool   $render_output true - output, false - return
	 * @return string rendering template
	 */
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
