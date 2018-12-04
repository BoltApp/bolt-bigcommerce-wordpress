<?php
namespace BoltBigcommerce;

if ( !defined( 'ABSPATH' ) ) {
	exit;
}

class Bolt_Generate_Order_Token
{
	private $error;

	private $order_reference;

	private $on_product_archive = false;

	private $cart;
	private function cart() {
		if (!isset($this->cart)) {
			$this->cart = new Bolt_Cart();
		}
		return $this->cart;
	}

	public function __construct()
	{
		add_filter( 'bigcommerce/template=components/cart/cart-footer.php/data', array( $this, 'change_bigcommerce_cart_footer_template'), 10, 1 );
		add_filter( 'bigcommerce/template=components/catalog/product-archive.php/options', array( $this, 'check_if_we_on_product_archive'), 10, 1 );
		add_filter( 'bigcommerce/button/purchase', array( $this, 'change_bigcommerce_add_to_cart_button'), 10, 2);
		$this->init_public_ajax();
	}

	/**
	 * Set up public ajax action
	 */
	public function init_public_ajax()
	{
		add_action( 'wp_ajax_bolt_create_single_order', array( $this, 'ajax_bolt_create_single_order' ) );
		add_action( 'wp_ajax_nopriv_bolt_create_single_order', array( $this, 'ajax_bolt_create_single_order' ) );
	}

	public function ajax_bolt_create_single_order() {
		BoltLogger::write( "create_single_order POST" . print_r( $_POST, true ) );

		//we have post_id. Calculate product_id on Bigcommerce
		$product    = new \BigCommerce\Post_Types\Product\Product( $_POST['post_id'] );
		$product_id = $product->bc_id();
		//$variant_id = $this->get_variant_id( $product, $_POST );

		BoltLogger::write("cart_id before start".$_COOKIE['bigcommerce_cart_id']);
		//we want to create a new bigcommerce cart and save old

        $old_bigcommerce_cart_id = $_COOKIE['bigcommerce_cart_id'];
        $_COOKIE['bigcommerce_cart_id'] = '';

		//add product to cart
		//TODO as well as woocommerce if product was added to cart AFTER product page generation it doesn't appear in Bolt cart
		$quantity = array_key_exists( 'quantity', $_POST ) ? absint( $_POST[ 'quantity' ] ) : 1;
		$options = array();
		$modifiers = array();
		$cart = $this->cart()->add_line_item( $product_id, $options, $quantity, $modifiers );
		//var_dump($cart); exit;
		BoltLogger::write("cart_id before after_line_item".$_COOKIE['bigcommerce_cart_id']);
		BoltLogger::write("cart_id before add_line_item".print_r($cart,true));

		//TODO IF error and products didn't add

		//map API ANSWER TO usual format
		$mapper   = new \BigCommerce\Cart\Cart_Mapper( $cart );
		$response = $mapper->map();

		//create bolt cart and prepare JS script
		$js_script = $this->generate_single_order_button_code($response);

		//restore old cart
		$_COOKIE['bigcommerce_cart_id'] = $old_bigcommerce_cart_id;
		$cookie_life = apply_filters( 'bigcommerce/cart/cookie_lifetime', 30 * DAY_IN_SECONDS );
		$secure      = ( 'https' === parse_url( home_url(), PHP_URL_SCHEME ) );
		$cookie_result = setcookie( 'bigcommerce_cart_id', $old_bigcommerce_cart_id, time() + $cookie_life, COOKIEPATH, COOKIE_DOMAIN, $secure );
		BoltLogger::write("{$cookie_result} = setcookie( bigcommerce_cart_id, {$old_bigcommerce_cart_id}, time() + ".$cookie_life.", ".COOKIEPATH.", ".COOKIE_DOMAIN.", $secure );");


		//remove product from cart
		/*
		//search necessary row
		$item_for_delete = null;
		foreach ($response["items"] as $item_id => $item) {
			if (($item["product_id"]==$product_id)
			//&& ($item["variant_id"]==$variant_id)
			) {
				$item_for_delete = $item_id;
				$old_quantity = $item["quantity"] - $quantity;
			}
		}
		if (!$item_for_delete) {
			BugsnagHelper::getBugsnag()->notifyException(new \Exception("Can't find item after adding"));
		}
		//$this->cart()->get_cart_id() can return old value https://github.com/bigcommerce/bigcommerce-for-wordpress/issues/112
		$cart_id = $response["cart_id"];
		BoltLogger::write("cart_id before delete".$_COOKIE['bigcommerce_cart_id']);
		//if cart is destroyed then $result==""
		$result = BCClient::deleteResource("/v3/carts/{$cart_id}/items/{$item_id}");
		if (!$result) {
			//cart is destroyed
			$cart_id = null;
		}
		BoltLogger::write("old_quantity $old_quantity");
		if ($old_quantity>0) {
			$response = $this->cart()->add_line_item( $product_id, $options, $old_quantity, $modifiers );
		}

		echo $js_script;

		$this->update_bolt_cart_id_option($cart_id,array(
			'product_id' => $product_id,
			'options' => $options,
			'quantity' => $quantity,
			'modifiers' => $modifiers,
			'customer_id' => $cart->getCustomerId()
		));
*/

		exit;
	}

	public function generate_single_order_button_code($response) {
		$data = $this->bolt_generate_cart_data($response);
		$cart = $data['cart'];
?>
console.log(BoltCheckout.configureProductCheckout(
	{
		currency: "<?= $cart['currency']?>",
		total: <?= $cart['total_amount']/100;?>,
		items: [{
			reference: "<?= $cart['items'][0]['reference']?>",
			price: <?= $cart['items'][0]['unit_price']/100;?>,
			quantity: <?= $cart['items'][0]['quantity']?>,
			name: "<?= $cart['items'][0]['name']?>"
		}],
	},
    {},
    {},
    { checkoutButtonClassName: "bolt-checkout-button" }));
<?php
}

	public function change_bigcommerce_add_to_cart_button($button,$post_id) {
		if ($this->on_product_archive) {
			//call from category page
			return $button;
		}
		return $button;
		//TODO rename
		$this->on_product_archive = true;

		$result = \BoltPay\Helper::renderBoltTrackScriptTag();
		$result .= \BoltPay\Helper::renderBoltConnectScriptTag();

        $result .= <<<JAVASCRIPT
		<div class="bolt-checkout-button bolt-multi-step-checkout with-cards" style=""></div>
		<script id="single-bolt-script-data" type="text/javascript">
		 jQuery( document ).ready(function() {
			singlePayProcess();
			//so we need to refresh the bolt order when page loaded
			jQuery('form.cart').on('input', 'input[name=quantity]',
				function () {
					singlePayProcess();
				}
			);
		});
        </script>
        </div>
JAVASCRIPT;
		return $button.$result;


	}

	public function check_if_we_on_product_archive( $options ) {
		$this->on_product_archive = true;
		//we don't want to change something
		return $options;
	}


	/**
	 * filter for bolt button adding
	 *
	 * @param array $data
	 * @return array
	 */
	public function change_bigcommerce_cart_footer_template($data)
	{
		$cart_code = $this->bolt_create_order_and_generate_button_code($data['cart']);
		if ( $cart_code ) {
			$data['actions'] .= '<div class="bc-cart-actions"><div class="bolt-checkout-button with-cards"></div></div>';
			$data['actions'] .= \BoltPay\Helper::renderBoltTrackScriptTag();
			$data['actions'] .= \BoltPay\Helper::renderBoltConnectScriptTag();
			$data['actions'] .= "<script>{$cart_code}</script>";

			$this->update_bolt_cart_id_option($data['cart']["cart_id"]);

		} else {
			$data['actions'] .= '<div class="bc-cart-actions"><p>'.$this->error.'</div>';
		}
		return $data;
	}
	private function update_bolt_cart_id_option($cart_id,$product="") {
		//save link between order_reference and bolt_cart_id_
		//it uses when we create order in bigcommerce (function bolt_create_order)
		update_option( "bolt_cart_id_" . $this->order_reference, array('cart_id'=>$cart_id,'product'=>$product));
		BoltLogger::write( "update_bolt_cart_id_option bolt_cart_id_{$this->order_reference}, array('cart_id'=>{$cart_id},'product'=>".print_r($product,true) );
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

	protected function api_call_get_product( $product_id ) {
		$url = "/v2/products/{$product_id}?include=@summary";
		return BCClient::getCollection( $url );
	}

	protected function api_call_get_product_variant($product_id, $variant_id) {
		$url = "/v3/catalog/products/{$product_id}/variants/{$variant_id}";
		return BCClient::getCollection( $url );
	}

	/**
	 * Check if products in cart are available now
	 *
	 * @param array $cart bigcommerce cart
	 *
	 * @return bool
	 */

	protected function check_products_availability($cart) {
		$availability = true;
		foreach ($cart["items"] as $item) {
			$product = $this->api_call_get_product( $item["product_id"] );
			BoltLogger::write( 'call availability result' . print_r($product,true) );
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
				$variant_product = $this->api_call_get_product_variant( $item["product_id"], $item["variant_id"]);
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

	function bolt_create_order_and_generate_button_code( $bigcommerce_cart ) {

		$cartData = $this->bolt_generate_cart_data( $bigcommerce_cart );
		if ( !$cartData ) {
			return false;
		}

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
			BugsnagHelper::getBugsnag()->notifyException( new \Exception( "Bolt Order token doesn't create" ) );
			exit;
		}

		return $this->generate_button_code( $orderToken );
	}


	/**
	 * Render html and js code for bolt checkout button
	 * @param array $bigcommerce_cart Bigcommerce cart content
	 *
	 * @return cartdata or false if can't create cart
	 */
	function bolt_generate_cart_data( $bigcommerce_cart )
	{
		if ( !$this->check_products_availability( $bigcommerce_cart ) ) {
			return false;
		}

		//TODO: (later) If the cart changes without page reload handle then send to Bolt the new cart
		//In bolt-woocommerce we use page reload at event 'updated_cart_totals' but I don't see JS event on bigcommerce-wordpress
		BoltLogger::write( "bolt_generate_cart_data from " . print_r( $bigcommerce_cart, true ) );
		BoltLogger::write( "bolt_generate_cart_data from " . json_encode( $bigcommerce_cart) );

		$currency_code = get_option( \BigCommerce\Settings\Sections\Currency::CURRENCY_CODE, '' );

		$tax_amount = isset($bigcommerce_cart["tax_amount"]["raw"]) ? $bigcommerce_cart["tax_amount"]["raw"] * 100 : 0;
		$discount_amount = isset($bigcommerce_cart["discount_amount"]["raw"]) ? $bigcommerce_cart["discount_amount"]["raw"] * 100 : 0;
		if ($tax_amount<0) {
			//coupon using result
			$discount_amount -= $tax_amount;
			$tax_amount = 0;
		}

		$this->order_reference = uniqid( 'BLT', false );
		$cart = array(
			"order_reference" => $this->order_reference,
			"display_id" => $this->order_reference,
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
				"total_amount" => (int)round($item["total_sale_price"]["raw"] * 100),
				"unit_price" => (int)round($item["sale_price"]["raw"] * 100),
				"quantity" => $item["quantity"],
				"type" => $type,
			);
		}
		$cartData = array( "cart" => $cart );
		BoltLogger::write( "Create cart " . print_r( $cartData, true ) );
		return $cartData;
	}

	function generate_button_code( $orderToken ) {
		$hints = $this->calculate_hints();
		$authcapture = get_option( 'bolt-bigcommerce_paymentaction' );

		$result = $this->render( 'main.js.php',
			array(
				'orderToken' => $orderToken,
				'hints' => $hints,
				'authcapture' => $authcapture,
			),false );
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

	/**
	 * @return mixed
	 */
	public function getError()
	{
		return $this->error;
	}

}
