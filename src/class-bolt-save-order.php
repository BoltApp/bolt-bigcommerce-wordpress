<?php

if ( !defined( 'ABSPATH' ) ) {
	exit;
}

//Save order in Bigcommerce
class Bolt_Save_Order
{
	private $confirmation_page;
	public function __construct()
	{
		add_action( 'rest_api_init', array( $this, 'register_endpoints' ) );
		$this->init_public_ajax();
		$this->confirmation_page = New Bolt_Confirmation_Page();
	}

	/**
	 * Register wordpress endpoints
	 */
	public function register_endpoints()
	{
		register_rest_route( 'bolt', '/response', array(
			'methods' => WP_REST_Server::CREATABLE,
			'callback' => array( $this, 'handler_response' ),
		) );
	}

	/**
	 * Set up public ajax action
	 */
	public function init_public_ajax()
	{
		add_action( 'wp_ajax_bolt_create_order', array( $this, 'ajax_bolt_create_order' ) );
		add_action( 'wp_ajax_nopriv_bolt_create_order', array( $this, 'ajax_bolt_create_order' ) );
		add_action( 'wp_ajax_bolt_clean_up_resources', array( $this, 'ajax_clean_up_archaic_resources' ) );
		add_action( 'wp_ajax_nopriv_bolt_clean_up_resources', array( $this, 'ajax_clean_up_archaic_resources' ) );
	}

	/**
	 *  Handle Bolt response
	 */
	function handler_response()
	{
		$hmacHeader = @$_SERVER['HTTP_X_BOLT_HMAC_SHA256'];
		BoltLogger::write( "bolt_endpoint_handler" );
		$signatureVerifier = new \BoltPay\SignatureVerifier(
			\BoltPay\Bolt::$signingSecret
		);
		$bolt_data_json = file_get_contents( 'php://input' );
		$bolt_data = json_decode( $bolt_data_json );
		BoltLogger::write( print_r( $bolt_data, true ) );

		if ( !$signatureVerifier->verifySignature( $bolt_data_json, $hmacHeader ) ) {
			BugsnagHelper::getBugsnag()->notifyException( new Exception( "Failed HMAC Authentication" ) );
		}

		//create new order
		$result = $this->bolt_create_order( $bolt_data->reference, $bolt_data->order, $bolt_data );

		$response = (object) array(
			'status' => $result["status"],
			'created_objects' => (object) array(
				'merchant_order_ref' => $result["order_id"]
			)
		);

		BoltLogger::write( "response: " . print_r( $response, true ) );

		wp_send_json( $response );
	}


	/**
	 * Set order status
	 *
	 * @param int $order_id order id
	 * @param string $bolt_type parameter from bolt: "rejected_reversible", "payment", "rejected_irreversible"
	 * @param string $bolt_status parameter from bolt: if $bolt_type=='payment', $bolt_status needs to be "completed"
	 */
	function order_set_status( $order_id, $bolt_type, $bolt_status = "" )
	{
		BoltLogger::write( "order_set_status( $order_id, $bolt_type, $bolt_status )" );
		//TODO (wait, asked) If shop owner changed default statuses
		$new_status_id = 0;
		if ( "rejected_reversible" == $bolt_type ) {
			$new_status_id = 12; // Manual Verification Required
			//$custom_status = "Recently Rejected";
		} else if ( ("payment" == $bolt_type) && ("completed" == $bolt_status) ) {
			$order = BCClient::getCollection( "/v2/orders/{$order_id}" );
			if (!$order) {
				BugsnagHelper::getBugsnag()->notifyException( new Exception( "Can't get order" ) );
			}
			BoltLogger::write( "order in order_set_status" . print_r( $order, true ) );
			if ( $order->order_is_digital ) {
				$new_status_id = 10; // Completed
			} else {
				$new_status_id = 11; // Awaiting Fulfillment
			}
		} else if ( "rejected_irreversible" == $bolt_type ) {
			$new_status_id = 6; // Declined
		}

		if ( $new_status_id && $order->id != $new_status_id ) {
			$body = array( "status_id" => $new_status_id );
			BoltLogger::write( "Order {$order_id} 
			Change status From {$order->status_id} ({$order->status}) TO {$new_status_id} " . json_encode( $body ) );
			$result = BCClient::updateResource( "/v2/orders/{$order_id}", $body );
			if (!$result) {
				BugsnagHelper::getBugsnag()->notifyException( new Exception( "Can't update order" ) );
			}

		} else {
			BoltLogger::write( "order status was actual" );
		}
	}

	/**
	 * Create order in Bigcommerce
	 *
	 * @param  string $bolt_reference  current transaction bolt id (like J7BK-JYZM-4RNF)
	 * @param  string $order_reference id which we sent to bolt when created order (like BLT5bdc8246d1a52)
	 * @param  array  $bolt_data all data from bolt
	 * @param  bool   $is_json true - called as JSON, false - called as hook
	 *
	 * @return array
	 */
	function bolt_create_order( $bolt_reference, $order_reference, $bolt_data, $is_json = false )
	{
		$bigcommerce_cart_id = get_option( "bolt_cart_id_" . $order_reference );
		$result = array(
			'status' => 'success',
			'order_id' => 0,
		);
		// prevent re-creation order
		BoltLogger::write( "bolt_create_order( {$bolt_reference}, {$order_reference}) bigcommerce_cart_id = {$bigcommerce_cart_id})" );
		$bc_order_id = get_option( "bolt_order_{$bolt_reference}" );
		if ( $bc_order_id ) {
			BoltLogger::write( "prevent re-creation order" );
			$this->order_set_status( $bc_order_id, $bolt_data->type, $bolt_data->status );
			$result["order_id"] = $bc_order_id;
			return $result;
		}

		if ( $is_json ) {
			$shipment = $bolt_data->shipping_option->value;
		} else {
			//get data from Bolt transaction
			$bolt_client = new \BoltPay\ApiClient( [
				'api_key' => \BoltPay\Bolt::$apiKey,
				'is_sandbox' => \BoltPay\Bolt::$isSandboxMode
			] );
			$bolt_transaction = $bolt_client->getTransactionDetails( $bolt_reference )->getBody();
			BoltLogger::write( "bolt_transaction " . print_r( $bolt_transaction, true ) );
			$bolt_billing_address = $bolt_transaction->order->cart->billing_address;
			$shipment = $bolt_transaction->order->cart->shipments[0];
		}
		$shipping_option_id = $shipment->reference;
		BoltLogger::write( "shipment" . print_r( $shipment, true ) );

		$checkout = BCClient::getCollection( "/v3/checkouts/{$bigcommerce_cart_id}" );

		BoltLogger::write( "checkout = BCClient::getCollection( \"/v3/checkouts/{$bigcommerce_cart_id}\" );" );
		BoltLogger::write( "get checkout " . print_r( $checkout, true ) );

		if ( !$checkout ) {
			BugsnagHelper::getBugsnag()->notifyException( new Exception( "cart already destroyed" ) );
			return $result;
		}

		//set selected shipping method
		if ( "no_shipping" <> $shipping_option_id ) {
			$consignment_id = $checkout->data->consignments[0]->id;
			$body = (object)array( "shipping_option_id" => $shipping_option_id );
			BoltLogger::write( "UPDATE Consignment /v3/checkouts/{$bigcommerce_cart_id}/consignments/$consignment_id?include=consignments.available_shipping_options" );
			BoltLogger::write( json_encode( $body ) );
			$checkout = BCClient::updateResource( "/v3/checkouts/{$bigcommerce_cart_id}/consignments/$consignment_id?include=consignments.available_shipping_options", $body );
			if (!$checkout) {
				BugsnagHelper::getBugsnag()->notifyException( new Exception( "Can't update consignment" ) );
			}

			BoltLogger::write( "New Consignment update answer " . print_r( $checkout, true ) );
		}

		BoltLogger::write( "CREATE ORDER /v3/checkouts/{$bigcommerce_cart_id}/orders" );
		$order = BCClient::createResource( "/v3/checkouts/{$bigcommerce_cart_id}/orders" );
		BoltLogger::write( print_r( $order, true ) );
		$order_id = $order->data->id;
		if (!$order_id) {
			BugsnagHelper::getBugsnag()->notifyException( new Exception( "Can't create order" ) );
		}


		//make order complete
		$pending_status_id = 1; //TODO Get status id from BC
		$body = array( "status_id" => $pending_status_id );
		BoltLogger::write( "Order {$order_id} UPDATE ORDER STATUS \"/v2/orders/{$order_id}\", " . json_encode( $body ) );
		BCClient::updateResource( "/v2/orders/{$order_id}", $body );

		//save Bigcommerce order id
		add_option( "bolt_order_{$bolt_reference}", $order_id );
		BoltLogger::write( "add_option( \"bolt_order_{$bolt_reference}\", $order_id )" );

		//delete cart (it doesn't delete itself altough according to the documentation it should
		BCClient::deleteResource( "/v3/carts/{$bigcommerce_cart_id}" );

		$result["order_id"] = $order_id;
		return $result;
	}

	//

	/**
	 * AJAX success callback
	 */
	function ajax_bolt_create_order()
	{
		BoltLogger::write( "save_order POST" . print_r( $_POST, true ) );
		$bolt_data = json_decode( stripslashes( $_POST["transaction_details"] ) );
		BoltLogger::write( "transaction_details" . print_r( $bolt_data, true ) );
		$result = $this->bolt_create_order( $bolt_data->reference, $bolt_data->cart->order_reference, $bolt_data, true );

		//Write order id to session. We'll read it on confirmation page
		$_SESSION["bolt_order_id"] = $result["order_id"];
		BoltLogger::write("\$_SESSION[\"bolt_order_id\"] = {$result["order_id"]};");
		BoltLogger::write( "result in save order" . print_r( $result, true ) );
		$this->clean_up_archaic_resources_async();
		wp_send_json( array(
			'result' => 'success',
			'redirect_url' => $this->confirmation_page->get_url(),
		) );
	}

	/**
	 * Makes non-blocking call to URL endpoint for cleaning up expired order creation resources
	 */
	protected function clean_up_archaic_resources_async()
	{
		BoltLogger::write( "start clean_up_archaic_resources_async" );
		if ( !function_exists( 'stream_socket_client' ) ) {
			BugsnagHelper::getBugsnag()->notifyException( new Exception( "This merchant does not appear to have streamed socket support enabled for resource cleanup.  Please enable this on their server." ) );
			return;
		}

		$url_parts = parse_url( get_site_url() );
		$host = $url_parts['host'];
		$protocol = '';
		$port = @$url_parts['port'] ?: 80;

		// verification disabled because ca_root file may not be configured correctly on host server
		// it is not needed because we can trust a call to our own server
		$context = stream_context_create( [
			'ssl' => [
				'verify_peer' => false,
				'verify_peer_name' => false
			]
		] );

		if ( $url_parts['scheme'] === 'https' ) {
			$protocol = 'ssl://';
			$port = @$url_parts['port'] ?: 443;
		}
		$errno = '';
		$errstr = '';
		BoltLogger::write( "$protocol$host:$port" );
		$fp = stream_socket_client( "$protocol$host:$port", $errno, $errstr, 30, STREAM_CLIENT_CONNECT, $context );
		BoltLogger::write( print_r( $fp, true ) );
		stream_set_blocking( $fp, false );

		if ( !$fp ) {
			BoltLogger::write( "Error connecting to clean Bolt resources. $errstr ($errno)\n" );
			BugsnagHelper::getBugsnag()->notifyException( new Exception( "Error connecting to clean Bolt resources. $errstr ($errno)\n" ) );
		} else {
			$out = "POST /wp-admin/admin-ajax.php?action=bolt_clean_up_resources HTTP/1.1\r\n";
			$out .= "Host: $host\r\n";
			$out .= "Content-Type: application/x-www-form-urlencoded\r\n";
			$out .= "Content-Length: 0\r\n";
			$out .= "Connection: Close\r\n\r\n";

			fwrite( $fp, $out );
			// Close socket immediately as we don't need to wait for execution completion
			fclose( $fp );
		}
	}

	/**
	 * Cleaning up expired order creation resources
	 */
	public function ajax_clean_up_archaic_resources()
	{
		global $wpdb;
		BoltLogger::write( "start ajax_clean_up_archaic_resources" );
		ignore_user_abort( true );
		set_time_limit( 300 );
		//////////////////////////////////////////////
		/// Clear historic bolt resources
		//////////////////////////////////////////////
		if ( !get_option( 'has_initiated_clearing_historic_bolt_resources' ) ) {
			# Disable option autoloading
			$wpdb->query( "UPDATE {$wpdb->options} SET autoload='no' WHERE option_name LIKE 'bolt_order_%'" );
			$wpdb->query( "UPDATE {$wpdb->options} SET autoload='no' WHERE option_name LIKE 'bolt_cart_id_%'" );
			$wpdb->query( "UPDATE {$wpdb->options} SET autoload='no' WHERE option_name LIKE 'bolt_shipping_and_tax_%'" );

			# Queue these rows to be deleted after 72 hours.
			# We leave them for that long in case they are current orders being processed, or pending review over the weekend.
			update_option( 'delete_bolt_resources_time', time() + 60 * 60 * 24 * 3 );
			update_option( 'delete_bolt_order_resources', implode( ",", $wpdb->get_col( "SELECT option_id FROM {$wpdb->options} WHERE option_name LIKE 'bolt_order_%'" ) ), false );
			BoltLogger::write( "update_option('delete_bolt_order_resources', " . implode( ",", $wpdb->get_col( "SELECT option_id FROM {$wpdb->options} WHERE option_name LIKE 'bolt_order_%'" ) ) );
			update_option( 'delete_bolt_cart_id_resources', implode( ",", $wpdb->get_col( "SELECT option_id FROM {$wpdb->options} WHERE option_name LIKE 'bolt_cart_id_%'" ) ), false );
			BoltLogger::write( "update_option('delete_bolt_cart_id_resources', " . implode( ",", $wpdb->get_col( "SELECT option_id FROM {$wpdb->options} WHERE option_name LIKE 'bolt_cart_id_%'" ) ) );
			update_option( 'delete_bolt_shipping_and_tax_resources', implode( ",", $wpdb->get_col( "SELECT option_id FROM {$wpdb->options} WHERE option_name LIKE 'bolt_shipping_and_tax_%'" ) ), false );

			update_option( 'has_initiated_clearing_historic_bolt_resources', true );
		}

		# Delete bolt resources if 72hr grace period has passed
		if ( ($deletion_time = get_option( 'delete_bolt_resources_time' )) && $deletion_time <= time() ) {
			# Remove expired data from abandoned carts
			$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE 'bolt_order_%' AND option_id IN (" . get_option( 'delete_bolt_order_resources' ) . ")" );
			$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE 'bolt_cart_id_%' AND option_id IN (" . get_option( 'delete_bolt_cart_id_resources' ) . ")" );
			$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE 'bolt_shipping_and_tax_%' AND option_id IN (" . get_option( 'delete_bolt_shipping_and_tax_resources' ) . ")" );

			delete_option( 'delete_bolt_resources_time' );

			# after the 72 hour period, we re-enable our 72 hour historic cleaning cycle to account for abandoned cart resources
			update_option( 'has_initiated_clearing_historic_bolt_resources', false );
		}
	}
}