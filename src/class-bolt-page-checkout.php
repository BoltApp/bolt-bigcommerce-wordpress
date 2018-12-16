<?php

namespace BoltBigcommerce;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Implement Product Page Checkout
 *
 * @class   Bolt_Page_Checkout
 * @author  Bolt
 */
class Bolt_Page_Checkout {

	use Bolt_Order;

	const E_BOLT_OUT_OF_STOCK = 6301;
	const E_BOLT_INVALID_SIZE = 6302;
	const E_BOLT_INVALID_QUANTITY = 6303;
	const E_BOLT_INVALID_REFERENCE = 6304;
	const E_BOLT_INVALID_AMOUNT = 6305;

	private $bigcommerce_cart_id;

	private $cart;

	private function cart() {
		if ( ! isset( $this->cart ) ) {
			$this->cart = new Bolt_Cart();
		}

		return $this->cart;
	}

	public function __construct() {
		add_action( 'wp_ajax_bolt_create_single_order', array( $this, 'ajax_bolt_create_single_order' ) );
		add_action( 'wp_ajax_nopriv_bolt_create_single_order', array( $this, 'ajax_bolt_create_single_order' ) );
	}


	public function ajax_bolt_create_single_order() {
		$product    = new \BigCommerce\Post_Types\Product\Product( $_POST['post_id'] );
		$product_id = $product->bc_id();
		if ( ! $product_id ) {
			return false;
		}
		$quantity       = array_key_exists( 'quantity', $_POST ) ? absint( $_POST['quantity'] ) : 1;
		$bolt_cart_data = $this->create_bolt_cart_by_product( $product_id, $quantity );
		$cart_data      = $bolt_cart_data['cart'];
		//we no need cart, delete it
		$checkout = new Bolt_Checkout( $this->bigcommerce_cart_id );
		$checkout->delete();

		$hints       = $this->calculate_hints();
		$authcapture = get_option( 'bolt-bigcommerce_paymentaction' );
		//callbacks parameter
		echo $this->render( 'main.js.php',
			array(
				'only_hints_and_callbacks' => true,
				'hints'                    => $hints,
				'authcapture'              => $authcapture,
			)
		);
		?>
        console.log(BoltCheckout.configureProductCheckout(
        {
        currency: "<?= $cart_data['currency'] ?>",
        total: <?= $cart_data['total_amount'] / 100; ?>,
        items: [{
        reference: "<?= $cart_data['items'][0]['reference'] ?>",
        price: <?= $cart_data['items'][0]['unit_price'] / 100; ?>,
        quantity: <?= $cart_data['items'][0]['quantity'] ?>,
        name: "<?= $cart_data['items'][0]['name'] ?>",
        }],
        },
        hints,
        callbacks,
        { checkoutButtonClassName: "bolt-product-buy" }));
		<?php
		exit;
	}

	private function restore_bigcommerce_cart_cookie( $cart_id ) {
		if ( $cart_id ) {
			$_COOKIE['bigcommerce_cart_id'] = $cart_id;
			$cookie_life                    = apply_filters( 'bigcommerce/cart/cookie_lifetime', 30 * DAY_IN_SECONDS );
			$secure                         = ( 'https' === parse_url( home_url(), PHP_URL_SCHEME ) );
			setcookie( 'bigcommerce_cart_id', $cart_id, time() + $cookie_life, COOKIEPATH, COOKIE_DOMAIN, $secure );
		}
	}

	public function create_bolt_cart_by_product( $product_id, $quantity ) {
		$old_bigcommerce_cart_id = "";
		if ( isset( $_COOKIE['bigcommerce_cart_id'] ) && $_COOKIE['bigcommerce_cart_id'] <> "" ) {
			$old_bigcommerce_cart_id        = $_COOKIE['bigcommerce_cart_id'];
			$_COOKIE['bigcommerce_cart_id'] = "";
		}
		$options   = array();
		$modifiers = array();
		$cart      = $this->cart()->add_line_item( $product_id, $options, $quantity, $modifiers );
		if ( is_null( $cart ) ) {
			$this->restore_bigcommerce_cart_cookie( $old_bigcommerce_cart_id );
			return false;
		}

		//map API ANSWER TO usual format
		$mapper                    = new \BigCommerce\Cart\Cart_Mapper( $cart );
		$response                  = $mapper->map();
		$this->bigcommerce_cart_id = $response["cart_id"];

		//create bolt cart
		$bolt_cart_data = $this->bolt_generate_cart_data( $response );

		//we don't need tax in this stage
		$bolt_cart_data['cart']['total_amount'] = $bolt_cart_data['cart']['total_amount'] - $bolt_cart_data['cart']['tax_amount'];
		$bolt_cart_data['cart']['tax_amount']   = 0;

		//instead discount reduce price of item
		if ($bolt_cart_data['cart']['discounts']) {
			$discount = $bolt_cart_data['cart']['discounts'][0]['amount'];
			$bolt_cart_data['cart']['items'][0]['total_amount'] -= $discount;
			$bolt_cart_data['cart']['items'][0]['unit_price'] -=
			round($discount / $bolt_cart_data['cart']['items'][0]['quantity']);
			$bolt_cart_data['cart']['discounts'] = array();
		}

		$this->restore_bigcommerce_cart_cookie( $old_bigcommerce_cart_id );

		return $bolt_cart_data;
	}

	private function send_error( $code, $message ) {
		$result = (object) array(
			'status' => 'failure',
			'error'  => (object) array(
				'code'    => $code,
				'message' => $message
			)
		);
		wp_send_json( $result );
	}

	public function create_cart_from_api_call_and_send_it( $bolt_data ) {
		if ( ! ( $bolt_data->items[0] ) ) {
			return $this->send_error( SELF::E_BOLT_INVALID_REFERENCE, "Not find product data" );
		}
		if ( count( $bolt_data->items ) > 1 ) {
			BugsnagHelper::getBugsnag()->notifyException( new \Exception( "More than one product in page checkout" ) );
			$this->send_error( SELF::E_BOLT_INVALID_REFERENCE, "More than one product in page checkout" );
			exit;
		}
		$item = $bolt_data->items[0];

		$bolt_cart_data = $this->create_bolt_cart_by_product( $item->reference, $item->quantity );

		if ( ! $bolt_cart_data ) {
			$this->send_error( SELF::E_BOLT_OUT_OF_STOCK, "Out of stock" );
			exit;
		}

		//add product to cart
		//TODO as well as woocommerce if product was added to cart AFTER product page generation it doesn't appear in Bolt cart

		$this->update_bolt_cart_id_option( $this->bigcommerce_cart_id );

		$response         = $bolt_cart_data;
		$response->status = 'success';

		wp_send_json( $response );
		exit;
	}
}