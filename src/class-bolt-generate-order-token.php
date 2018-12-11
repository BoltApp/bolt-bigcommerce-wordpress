<?php

namespace BoltBigcommerce;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Bolt_Generate_Order_Token {
	use Bolt_Order;

	private $on_single_product_page = false;

	private $cart;

	private function cart() {
		if ( ! isset( $this->cart ) ) {
			$this->cart = new Bolt_Cart();
		}

		return $this->cart;
	}

	public function __construct() {
		add_filter( 'bigcommerce/template=components/cart/cart-footer.php/data', array(
			$this,
			'change_bigcommerce_cart_footer_template'
		), 10, 1 );
		add_filter( 'bigcommerce/template/product/single', array( $this, 'on_single_product_page' ), 10, 1 );
		add_filter( 'bigcommerce/button/purchase', array( $this, 'change_bigcommerce_add_to_cart_button' ), 10, 2 );
	}

	public function change_bigcommerce_add_to_cart_button( $button, $post_id ) {
		if ( ! $this->on_single_product_page ) {
			//call from category page
			return $button;
		}
		$this->on_single_product_page = false;

		$result = \BoltPay\Helper::renderBoltTrackScriptTag();
		$result .= \BoltPay\Helper::renderBoltConnectScriptTag();

		$result .= <<<JAVASCRIPT
		<div class="bolt-product-buy" style=""></div>
		<script id="single-bolt-script-data" type="text/javascript">
		 jQuery( document ).ready(function() {
			singlePayProcess();
		});
		//so we need to refresh the bolt order when page loaded
		jQuery( 'form.bc-product-form' ).on('input', 'input[name=quantity]',
			function () {
				singlePayProcess();
			}
		);
        </script>
        </div>
JAVASCRIPT;

		return $button . $result;
	}

	public function on_single_product_page( $options ) {
		$this->on_single_product_page = true;

		//we don't want to change something
		return $options;
	}


	/**
	 * filter for bolt button adding
	 *
	 * @param array $data
	 *
	 * @return array
	 */
	public function change_bigcommerce_cart_footer_template( $data ) {
		$cart_code = $this->bolt_create_order_and_generate_button_code( $data['cart'] );
		if ( $cart_code ) {
			$data['actions'] .= '<div class="bc-cart-actions"><div class="bolt-checkout-button with-cards"></div></div>';
			$data['actions'] .= \BoltPay\Helper::renderBoltTrackScriptTag();
			$data['actions'] .= \BoltPay\Helper::renderBoltConnectScriptTag();
			$data['actions'] .= "<script>{$cart_code}</script>";

			$this->update_bolt_cart_id_option( $data['cart']["cart_id"] );

		} else {
			$data['actions'] .= '<div class="bc-cart-actions"><p>' . $this->error . '</div>';
		}

		return $data;
	}

	/**
	 * Format error text if the certain product isn't available
	 *
	 * @param $name product name
	 * @param $quantity product quanity on stock
	 */
	private function set_availability_error( $name, $quantity ) {
		if ( 0 == $quantity ) {
			$this->error = "Product '{$name}' is currently unavailable";
		} else if ( 1 == $quantity ) {
			$this->error = "We have only 1 item of '{$name}' on our stock";
		} else {
			$this->error = "We have only {$quantity} items of '{$name}' on our stock";
		}

	}

	function bolt_create_order_and_generate_button_code( $bigcommerce_cart ) {

		$cartData = $this->bolt_generate_cart_data( $bigcommerce_cart );
		if ( ! $cartData ) {
			return false;
		}

		$client = new \BoltPay\ApiClient( [
			'api_key'    => \BoltPay\Bolt::$apiKey,
			'is_sandbox' => \BoltPay\Bolt::$isSandboxMode
		] );

		$response   = $client->createOrder( $cartData );
		$orderToken = $response->isResponseSuccessful() ? @$response->getBody()->token : '';
		BoltLogger::write( "Create cart orderToken " . $orderToken );
		if ( ! $orderToken ) {
			echo "error Bolt order create";
			print_r( $response );
			print_r( $cartData );
			print_r( $bigcommerce_cart );
			BugsnagHelper::getBugsnag()->notifyException( new \Exception( "Bolt Order token doesn't create" ) );
			exit;
		}

		return $this->generate_button_code( $orderToken );
	}

	function generate_button_code( $orderToken ) {
		$hints       = $this->calculate_hints();
		$authcapture = get_option( 'bolt-bigcommerce_paymentaction' );

		$result = $this->render( 'main.js.php',
			array(
				'orderToken'  => $orderToken,
				'hints'       => $hints,
				'authcapture' => $authcapture,
			)
		);

		return $result;
	}

	/**
	 * @return mixed
	 */
	public function getError() {
		return $this->error;
	}

}
