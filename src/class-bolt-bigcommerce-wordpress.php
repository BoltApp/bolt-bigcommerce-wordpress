<?php

if ( !defined( 'ABSPATH' ) ) {
	exit;
}

class Bolt_Bigcommerce_Wordpress
{

	//Set up base actions 
	public function init()
	{
		add_action( 'rest_api_init', array( $this, 'register_endpoints' ) );
		add_action( 'bigcommerce/cart/proceed_to_checkout', array( $this, 'bolt_cart_button' ) );
		$this->init_bolt_api();
		$this->init_bigcommerce_api();
	}

	//Set up public ajax action.
	public function init_public_ajax()
	{
		add_action( 'wp_ajax_bolt_create_order', array( $this, 'save_order' ) );
		add_action( 'wp_ajax_nopriv_bolt_create_order', array( $this, 'save_order' ) );
		add_action( 'wp_ajax_bolt_clean_up_resources', array( $this, 'ajax_clean_up_archaic_resources' ) );
		add_action( 'wp_ajax_nopriv_bolt_clean_up_resources', array( $this, 'ajax_clean_up_archaic_resources' ) );

	}

	public function init_bolt_api()
	{
		$config = require(dirname( __FILE__ ) . '/../lib/config_bolt_php.php');
		\BoltPay\Bolt::$apiKey = $this->get_option( "merchant_key" );
		\BoltPay\Bolt::$signingSecret = $this->get_option( "payment_secret_key" );
		\BoltPay\Bolt::$apiPublishableKey = $this->get_option( "processing_key" );
		\BoltPay\Bolt::$isSandboxMode = @$config['IS_SANDBOX'];
		\BoltPay\Bolt::$authCapture = @$config['AUTH_CAPTURE'];
		\BoltPay\Bolt::$connectSandboxBase = !@$config['CONNECT_SANDBOX_BASE'] ?: $config['CONNECT_SANDBOX_BASE'];
		\BoltPay\Bolt::$connectProductionBase = !@$config['CONNECT_PRODUCTION_BASE'] ?: $config['CONNECT_PRODUCTION_BASE'];
		\BoltPay\Bolt::$apiSandboxUrl = !@$config['API_SANDBOX_URL'] ?: $config['API_SANDBOX_URL'];
		\BoltPay\Bolt::$apiProductionUrl = @$config['API_PRODUCTION_URL'] ?: $config['API_PRODUCTION_URL'];
	}

	public function init_bigcommerce_api()
	{
		require_once(dirname( __FILE__ ) . '/bigcommerce-api/Client.php');
		require_once(dirname( __FILE__ ) . '/bigcommerce-api/Connection.php');
		$v3_url = untrailingslashit( get_option( "bigcommerce_store_url" ) );
		preg_match( '#stores/([^\/]+)/#', $v3_url, $matches );
		if ( empty( $matches[1] ) ) {
			$store_hash = '';
		} else {
			$store_hash = $matches[1];
		}
		$this->log_write( "init_bigcommerce_api" );

		BCClient::configure( [
			'client_id' => get_option( "BIGCOMMERCE_CLIENT_ID" ),
			'auth_token' => get_option( "BIGCOMMERCE_ACCESS_TOKEN" ),
			'client_secret' => get_option( "BIGCOMMERCE_CLIENT_SECRET" ),
			'store_hash' => $store_hash,
		] );
	}


	public function register_endpoints()
	{
		/**
		 * Sync and handle bolt API.
		 */
		register_rest_route( 'bolt', '/response', array(
			'methods' => WP_REST_Server::CREATABLE,
			'callback' => array( $this, 'handler_response' ),
		) );

		register_rest_route( 'bolt', '/shippingtax', array(
			'methods' => WP_REST_Server::CREATABLE,
			'callback' => array( $this, 'handler_shipping_tax' ),
		) );
	}

	//temporary log function


	protected function log_write( $text )
	{
		$logecho = false;
		$f = fopen( dirname( __FILE__ ) . "/../log.txt", "a" );
		fwrite( $f, date( "Y-m-d H:i:s" ) . " " . $text . "\r\n" );
		if ( $logecho ) echo "<P>" . date( "Y-m-d H:i:s" ) . "<pre>{$text}</pre>\r\n";
	}

	//get option
	protected function get_option( $key )
	{
		return esc_attr( get_option( "bolt-bigcommerce_{$key}" ) );
	}

	function handler_response()
	{
		$hmacHeader = @$_SERVER['HTTP_X_BOLT_HMAC_SHA256'];
		$this->log_write( "bolt_endpoint_handler" );
		$signatureVerifier = new \BoltPay\SignatureVerifier(
			\BoltPay\Bolt::$signingSecret
		);
		$bolt_data_json = file_get_contents( 'php://input' );
		$bolt_data = json_decode( $bolt_data_json );
		$this->log_write( print_r( $bolt_data, true ) );

		if ( !$signatureVerifier->verifySignature( $bolt_data_json, $hmacHeader ) ) {
			throw new Exception( "Failed HMAC Authentication" );
		}

		//create new order
		//$order_reference = $bolt_data->order;
		//$bigcommerce_cart_id = get_option( "bolt_cart_id_".$order_reference );
		//$bolt_reference = $bolt_data->reference;

		$result = $this->bolt_create_order( $bolt_data->reference, $bolt_data->order, $bolt_data );

		$response = new stdClass();
		$response->status = $result["status"];
		$response->created_objects = new stdClass();
		$response->created_objects->merchant_order_ref = $result["order_id"];

		$this->log_write( "response: " . print_r( $response, true ) );

		wp_send_json( $response );
	}

	//return shipping methods
	function handler_shipping_tax()
	{
		//TODO (after v3) work with states maybe https://developer.bigcommerce.com/api/v3/#/reference/checkout/early-access-server-to-server-checkout/add-a-new-consignment-to-checkout
		//TODO (wait) error with product types: always unknown https://app.asana.com/0/0/895580293902646/f
		//TODO (after v3) deal with shipping discount https://store-5669f02hng.mybigcommerce.com/manage/marketing/discounts/create
		//TODO (after v3) return "no shipping required" as option if all products are digital
		//TODO (wait) work with taxes https://app.asana.com/0/0/895580293902645/f
		$hmacHeader = @$_SERVER['HTTP_X_BOLT_HMAC_SHA256'];
		$signatureVerifier = new \BoltPay\SignatureVerifier(
			\BoltPay\Bolt::$signingSecret
		);

		$bolt_order_json = file_get_contents( 'php://input' );
		$bolt_order = json_decode( $bolt_order_json );
		$this->log_write( "handler_shipping_tax " . print_r( $bolt_order, true ) );

		if ( !$signatureVerifier->verifySignature( $bolt_order_json, $hmacHeader ) ) {
			throw new Exception( "Failed HMAC Authentication" );
		}
		// TODO: (later) add validation from wooplugin like $region = bolt_addr_helper()->get_region_code( $country_code, $bolt_order->shipping_address->region ? :'');
		$country_code = $bolt_order->shipping_address->country_code;
		$post_code = $bolt_order->shipping_address->postal_code;
		$region = $bolt_order->shipping_address->region;


		// TODO: (after v3) think about cache /shipping/zones
		$shipping_zones = BCClient::getCollection( '/v2/shipping/zones' );

		// look for shipping zone contains specific country
		$shipping_zone_id = 0;
		foreach ( $shipping_zones as $zone_id => $shipping_zone ) {
			if ( "global" == $shipping_zone->type ) {
				//shipping zone for rest of word. it uses if we don't find zone contains specific country
				$rest_shipping_zone_id = $shipping_zone->id;
				continue;
			}
			foreach ( $shipping_zone->locations as $location ) {
				if ( $location->country_iso2 == $country_code ) {
					$shipping_zone_id = $shipping_zone->id;
					break 2;
				}
			}
		}
		if ( !$shipping_zone_id ) {
			$shipping_zone_id = $rest_shipping_zone_id;
		}
		//get shipping methods for selected shipping zone
		$shipping_methods = BCClient::getCollection( "/v2/shipping/zones/{$shipping_zone_id}/methods" );
		$this->log_write( "shipping_methods " . print_r( $shipping_methods, true ) );
		$bolt_shipping_options = array();
		// TODO: (after V3) now code works only with 'flat rate - per order' and 'free shipping'. works with other delivery methods
		if ( $shipping_zone->free_shipping && $shipping_zone->free_shipping->enabled ) {
			$bolt_shipping_options[] = array(
				"service" => "Free Shipping - Free",
				"reference" => "freeshipping_freeshipping",
				"cost" => 0,
				"tax_amount" => 0,
			);
		}
		foreach ( $shipping_methods as $shipping_method ) {
			if ( $shipping_method->enabled ) {
				switch ($shipping_method->type) {
					case "perorder":
						$bolt_shipping_options[] = array(
							"service" => $shipping_method->name, //'Flat Rate - Fixed',
							"reference" => "perorder",
							"cost" => $shipping_method->settings->rate * 100,
							"tax_amount" => 0,
						);
						break;
					case "peritem":
						$kolitem = 0;
						foreach ( $bolt_order->cart->items as $item ) {
							if ( $item->type <> "digital" ) {
								$kolitem += $item->quantity;
							}
						}
						$bolt_shipping_options[] = array(
							"service" => $shipping_method->name, //'Flat Rate - Fixed',
							"reference" => "peritem",
							"cost" => $shipping_method->settings->rate * $kolitem * 100,
							"tax_amount" => 0,
						);
						break;
				}
			}
		}
		$response = array( "shipping_options" => $bolt_shipping_options );

		wp_send_json( $response );
	}
	//render html and js code for bolt checkout button
	//code "bolt_cart_button($cart);" added in bigcommerce-for-wordpress-master/public-views/cart.php
	//before "</footer>"
	function bolt_cart_button( $bigcommerce_cart )
	{
		//TODO: (later) If the cart changes without page reload handle then send to Bolt the new cart
		//In bolt-woocommerce we use page reload at event 'updated_cart_totals' but I don't see JS event on bigcommerce-wordpress

		$currency_code = get_option( BigCommerce\Settings\Sections\Currency::CURRENCY_CODE, '' );

		$order_reference = uniqid( 'BLT', false );
		$cart = array(
			"order_reference" => $order_reference,
			"display_id" => $order_reference,
			"currency" => $currency_code,
			"total_amount" => round( $bigcommerce_cart["cart_amount"]["raw"] * 100 ),
			"tax_amount" => round( $bigcommerce_cart["tax_amount"]["raw"] * 100 ),
			"discounts" => array(),
		);

		//Discounts for product: show only discounted price as well as in bolt-woocommerce
		//Discounts for cart: show only total discount (Bigcommerce restrictions)
		//Coupon codes: customer can use it only after press "Bigcommerce process to checkout"
		if ( $bigcommerce_cart["discount_amount"] ) {
			$cart["discounts"][] = array(
				"amount" => (int)(round( $bigcommerce_cart["discount_amount"]["raw"] * 100 )),
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
				"reference" => $order_reference,
				"name" => $item["name"],
				"sku" => $item["sku"]["product"],
				"description" => "",
				"total_amount" => round( $item["total_sale_price"]["raw"] * 100 ),
				"unit_price" => round( $item["sale_price"]["raw"] * 100 ),
				"quantity" => $item["quantity"],
				"type" => $type,
			);
		}
		$cartData = array( "cart" => $cart );
		$this->log_write( "Create cart " . print_r( $cartData, true ) );

		$client = new \BoltPay\ApiClient( [
			'api_key' => \BoltPay\Bolt::$apiKey,
			'is_sandbox' => \BoltPay\Bolt::$isSandboxMode
		] );

		$response = $client->createOrder( $cartData );
		$orderToken = $response->isResponseSuccessful() ? @$response->getBody()->token : '';
		$this->log_write( "Create cart orderToken " . $orderToken );
		if ( !$orderToken ) {
			echo "error Bolt order create";
			print_r( $response );
			print_r( $bigcommerce_cart );
			exit;
		}
		//save link between order_reference and bolt_cart_id_
		//it uses when we create order in bigcommerce (function bolt_create_order)
		$updated = update_option( "bolt_cart_id_" . $order_reference, $bigcommerce_cart["cart_id"] );
		$this->log_write( "cart_id={$bigcommerce_cart["cart_id"]}" );

		echo '<div class="bolt-checkout-button with-cards"></div>';
		echo \BoltPay\Helper::renderBoltTrackScriptTag();
		echo \BoltPay\Helper::renderBoltConnectScriptTag();
		$this->render( "main.js.php", array( "orderToken" => $orderToken ) );
	}

	function order_set_status( $order_id, $bolt_type, $bolt_status = "" )
	{
		//TODO (wait, asked) If shop owner changed default statuses
		$new_status_id = 0;
		if ( "rejected_reversible" == $bolt_type ) {
			$new_status_id = 12; // Manual Verification Required
			//$custom_status = "Recently Rejected";
		} else if ( ("payment" == $bolt_type) && ("completed" == $bolt_status) ) {
			$new_status_id = 11; // Awaiting Fulfillment
		} else if ( "rejected_irreversible" == $bolt_type ) {
			$new_status_id = 6; // Declined
		}

		//read the old status
		$order = BCClient::getCollection( "/v2/orders/{$order_id}" );
		$this->log_write( "query '/order_status/{$order_id}'" );
		$this->log_write( "answer" . print_r( $order, true ) );

		if ( $new_status_id && $order->id != $new_status_id ) {
			$this->log_write( "Order {$order_id} Change status From {$order->status_id} ({$order->status}) TO {$new_status_id} {$custom_status} " );
			BCClient::updateResource( "/v2/orders/{$order_id}", array( "status_id" => $new_status_id ) );
		}
	}

	//create order in bigcommerce
	//$bolt_reference - current transaction bolt id (like J7BK-JYZM-4RNF)
	//$order_reference - id which we sent to bolt when creater order (like BLT5bdc8246d1a52)
	function bolt_create_order( $bolt_reference, $order_reference, $bolt_data, $is_json = false )
	{
		$bigcommerce_cart_id = get_option( "bolt_cart_id_" . $order_reference );
		$result = array(
			'status' => 'success',
			'order_id' => 0,
		);
		// prevent re-creation order
		$this->log_write( "bolt_create_order( {$bolt_reference}, {$order_reference}) bigcommerce_cart_id = {$bigcommerce_cart_id})" );
		$bc_order_id = get_option( "bolt_order_{$bolt_reference}" );
		if ( $bc_order_id ) {
			$this->log_write( "prevent re-creation order" );
			$this->order_set_status( $bc_order_id, $bolt_data->type, $bolt_data->status );
			$response = new stdClass();
			$response->status = "success";
			$result["order_id"] = $bc_order_id;
			return $result;
		}
		if ( $is_json ) {
			$bolt_billing_address = $bolt_data->shipping_address;
			$shipping_method = $bolt_data->shipping_option->value->service;
			$shipping_cost = $bolt_data->shipping_option->value->cost->amount / 100;
		} else {
			//get data from Bolt transaction
			$bolt_client = new \BoltPay\ApiClient( [
				'api_key' => \BoltPay\Bolt::$apiKey,
				'is_sandbox' => \BoltPay\Bolt::$isSandboxMode
			] );
			$bolt_transaction = $bolt_client->getTransactionDetails( $bolt_reference )->getBody();
			$this->log_write( "bolt_transaction " . print_r( $bolt_transaction, true ) );
			$bolt_billing_address = $bolt_transaction->order->cart->billing_address;
			//change names of the properties so they became the same with those obtained by ajax
			$bolt_billing_address->phone = $bolt_billing_address->phone_number;
			$bolt_billing_address->email = $bolt_billing_address->email_address;

			$shipping_method = $bolt_transaction->order->cart->shipments[0]->service;
			$shipping_cost = $bolt_transaction->order->cart->shipments[0]->cost->amount / 100;
		}
		$this->log_write( "shipping_method='$shipping_method' shipping_cost='$shipping_cost' billing address " . print_r( $bolt_billing_address, true ) );
		//get data from bigcommerce cart
		$cart = BCClient::getCollection( "/v3/carts/{$bigcommerce_cart_id}" );
		$this->log_write( "bigcommerce cart (/v3/carts/{$bigcommerce_cart_id})" . print_r( $cart, true ) );

		if ( !$cart ) {
			//cart already destroyed
			return $result;
		}

		if ( BC_API == "v3" ) {
			//files names differ between BC v2 and v3 API. For example country_code <==> country_iso2
			$billing_address = new stdClass();
			$billing_address->first_name = $bolt_billing_address->first_name;
			$billing_address->last_name = $bolt_billing_address->last_name;
			$billing_address->company = "";
			$billing_address->address1 = $bolt_billing_address->street_address1;
			$billing_address->address2 = $bolt_billing_address->street_address2;
			$billing_address->city = $bolt_billing_address->locality;
			$billing_address->state_or_province = $bolt_billing_address->region;
			$billing_address->postal_code = $bolt_billing_address->postal_code;
			$billing_address->country = $bolt_billing_address->country;
			$billing_address->country_code = $bolt_billing_address->country_code;
			$billing_address->phone = $bolt_billing_address->phone;
			$billing_address->email = $bolt_billing_address->email;
			//TODO (after v3): If checkout bolt cart is different from BC cart use checkout bolt cart
			//add billing address
			$checkout = BCClient::createResource( "/v3/checkouts/{$bigcommerce_cart_id}/billing-address", $billing_address );
			$this->log_write( "add billing address /v3/checkouts/{$bigcommerce_cart_id}/billing-address" );
			$this->log_write( json_encode( $billing_address ) );
			$this->log_write( "add billing address answer " . print_r( $checkout, true ) );


			//Add a New Consignment to Checkout
			$consignment = new stdClass();
			//In Bolt  the shipping address is the same as the billing address
			$consignment->shipping_address = $billing_address;
			//send all physical products to this address
			$physical_items = $checkout->data->cart->line_items->physical_items;
			foreach ( $physical_items as $physical_item ) {
				$consignment->line_items[] = array(
					"item_id" => $physical_item->id,
					"quantity" => $physical_item->quantity,
				);
			}
			$params = array( $consignment );
			$this->log_write( "Add a New Consignment /v3/checkouts/{$bigcommerce_cart_id}/consignments?include=availableShippingOptions" );
			$this->log_write( json_encode( $params ) );

			$checkout = BCClient::createResource( "/v3/checkouts/{$bigcommerce_cart_id}/consignments?include=availableShippingOptions", $params );
			$this->log_write( "Add a New Consignment answer " . print_r( $checkout, true ) );
			exit;


			$this->log_write( "checkout_to_order /v3/checkouts/{$bigcommerce_cart_id}/orders" );
			//exit;

			$order = BCClient::createResource( "/v3/checkouts/{$bigcommerce_cart_id}/orders" );

			$this->log_write( print_r( $order, true ) );
			exit;

		} else {


			$order = new stdClass();

			$order->products = array();

			foreach ( $cart->data->line_items->physical_items as $item ) {
				$product = new stdClass();
				$product->product_id = $item->product_id;
				$product->quantity = $item->quantity;

				$order->products[] = $product;
			}
			// TODO: (after v3) the same about non physical items
		}

		$order->subtotal_ex_tax = $cart->data->base_amount;
		$order->subtotal_inc_tax = $cart->data->base_amount;

		$order->total_inc_tax = $cart->data->base_amount + $shipping_cost;
		$order->total_ex_tax = $cart->data->base_amount + $shipping_cost;

		$order->shipping_cost_ex_tax = $shipping_cost;
		$order->shipping_cost_inc_tax = $shipping_cost;
		$order->base_shipping_cost = $shipping_cost;
		$order->order_is_digital = false;

		$order->billing_address = new stdClass();
		$order->billing_address->first_name = $bolt_billing_address->first_name;
		$order->billing_address->last_name = $bolt_billing_address->last_name;
		//Bolt can't add company name to address
		$order->billing_address->company = "";
		$order->billing_address->street_1 = $bolt_billing_address->street_address1;
		$order->billing_address->street_2 = $bolt_billing_address->street_address2;
		$order->billing_address->city = $bolt_billing_address->locality;
		$order->billing_address->state = $bolt_billing_address->region;
		$order->billing_address->zip = $bolt_billing_address->postal_code;
		$order->billing_address->country = $bolt_billing_address->country;
		$order->billing_address->country_iso2 = $bolt_billing_address->country_code;
		$order->billing_address->phone = $bolt_billing_address->phone;
		$order->billing_address->email = $bolt_billing_address->email;

		// add shipping method (text)
		$order->shipping_addresses[] = clone $order->billing_address;
		$order->shipping_addresses[0]->shipping_method = $shipping_method;


		BCClient::failOnError( true );
		$this->log_write( json_encode( $order ) );
		$this->log_write( "<P>bc_order" );
		try {
			$bc_order = BCClient::createResource( '/v2/orders', $order );
			$this->log_write( print_r( $bc_order, true ) );
		} catch (Exception $ex) {
			$this->log_write( "!!" );
			$this->log_write( $ex );
		}
		//save Bigcommerce order id
		add_option( "bolt_order_{$bolt_reference}", $bc_order->id );

		BCClient::deleteResource( "/v3/carts/{$bigcommerce_cart_id}" );

		$result["order_id"] = $bc_order->id;
		return $result;
	}

	//AJAX success callback
	function save_order()
	{
		$this->init_bigcommerce_api();
		$this->log_write( "save_order POST" . print_r( $_POST, true ) );
		$bolt_data = json_decode( stripslashes( $_POST["transaction_details"] ) );
		$this->log_write( "transaction_details" . print_r( $bolt_data, true ) );
		$result = $this->bolt_create_order( $bolt_data->reference, $bolt_data->cart->order_reference, $bolt_data, true );
		$this->log_write( "result in save order" . print_r( $result, true ) );
		$this->clean_up_archaic_resources_async();
		wp_send_json( array(
			'result' => 'success',
			// TODO: (after v3) create this order confirmation page
			'redirect_url' => get_site_url() . "/success",
		) );
	}

	/**
	 * Makes non-blocking call to URL endpoint for cleaning up expired order creation resources
	 */
	protected function clean_up_archaic_resources_async()
	{
		$this->log_write( "start clean_up_archaic_resources_async" );
		if ( !function_exists( 'stream_socket_client' ) ) {
			BugsnagHelper::getBugsnag()->notifyException( new Exception( "This merchant does not appear to have streamed socket support enabled for resource cleanup.  Please enable this on their server." ) );
			return;
		}

		$url_parts = parse_url( get_site_url() );
		$host = $url_parts['host'];
		$protocol = '';
		$port = @$url_parts['port'] ?: 80;
		$context = null;

		if ( $url_parts['scheme'] === 'https' ) {
			$protocol = 'ssl://';
			$port = @$url_parts['port'] ?: 443;

			# verification disabled because ca_root file may not be configured correctly on host server
			# it is not needed because we can trust a call to our own server
			$context = stream_context_create( [
				'ssl' => [
					'verify_peer' => false,
					'verify_peer_name' => false
				]
			] );
		}
		$errno = '';
		$errstr = '';
		$this->log_write( "$protocol$host:$port" );
		if ( $context ) {
			$fp = stream_socket_client( "$protocol$host:$port", $errno, $errstr, 30, STREAM_CLIENT_CONNECT, $context );
		} else {
			$fp = stream_socket_client( "$protocol$host:$port", $errno, $errstr, 30, STREAM_CLIENT_CONNECT );
		}
		$this->log_write( print_r( $fp, true ) );
		stream_set_blocking( $fp, false );

		if ( !$fp ) {
			$this->log_write( "Error connecting to clean Bolt resources. $errstr ($errno)\n" );
			BugsnagHelper::getBugsnag()->notifyException( new Exception( "Error connecting to clean Bolt resources. $errstr ($errno)\n" ) );
		} else {
			$out = "POST /wp-admin/admin-ajax.php?action=bolt_clean_up_resources HTTP/1.1\r\n";
			$out .= "Host: $host\r\n";
			$out .= "Content-Type: application/x-www-form-urlencoded\r\n";
			$out .= "Content-Length: 0\r\n";
			$out .= "Connection: Close\r\n\r\n";

			fwrite( $fp, $out );
			# Close socket immediately as we don't need to wait for execution completion
			fclose( $fp );
		}
	}

	public function ajax_clean_up_archaic_resources()
	{
		global $wpdb;
		$this->log_write( "start ajax_clean_up_archaic_resources" );
		ignore_user_abort( true );
		set_time_limit( 300 );
		BugsnagHelper::initBugsnag();
		//////////////////////////////////////////////
		/// Clear historic bolt resources
		//////////////////////////////////////////////
		if ( !get_option( 'has_initiated_clearing_historic_bolt_resources' ) ) {
			# Disable option autoloading
			$wpdb->query( "UPDATE {$wpdb->options} SET autoload='no' WHERE option_name LIKE 'bolt_order_%'" );
			$wpdb->query( "UPDATE {$wpdb->options} SET autoload='no' WHERE option_name LIKE 'bolt_cart_id_%'" );

			# Queue these rows to be deleted after 72 hours.
			# We leave them for that long in case they are current orders being processed, or pending review over the weekend.
			update_option( 'delete_bolt_resources_time', time() + 60 * 60 * 24 * 3 );
			update_option( 'delete_bolt_order_resources', implode( ",", $wpdb->get_col( "SELECT option_id FROM {$wpdb->options} WHERE option_name LIKE 'bolt_order_%'" ) ), false );
			$this->log_write( "update_option('delete_bolt_order_resources', " . implode( ",", $wpdb->get_col( "SELECT option_id FROM {$wpdb->options} WHERE option_name LIKE 'bolt_order_%'" ) ) );
			update_option( 'delete_bolt_cart_id_resources', implode( ",", $wpdb->get_col( "SELECT option_id FROM {$wpdb->options} WHERE option_name LIKE 'bolt_cart_id_%'" ) ), false );
			$this->log_write( "update_option('delete_bolt_cart_id_resources', " . implode( ",", $wpdb->get_col( "SELECT option_id FROM {$wpdb->options} WHERE option_name LIKE 'bolt_cart_id_%'" ) ) );

			update_option( 'has_initiated_clearing_historic_bolt_resources', true );
		}

		# Delete bolt resources if 72hr grace period has passed
		if ( ($deletion_time = get_option( 'delete_bolt_resources_time' )) && $deletion_time <= time() ) {
			# Remove expired data from abandoned carts
			$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE 'bolt_order_%' AND option_id IN (" . get_option( 'delete_bolt_order_resources' ) . ")" );
			$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE 'bolt_cart_id_%' AND option_id IN (" . get_option( 'delete_bolt_cart_id_resources' ) . ")" );

			delete_option( 'delete_bolt_resources_time' );

			# after the 72 hour period, we re-enable our 72 hour historic cleaning cycle to account for abandoned cart resources
			update_option( 'has_initiated_clearing_historic_bolt_resources', false );
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