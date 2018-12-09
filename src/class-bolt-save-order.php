<?php
namespace BoltBigcommerce;

if ( !defined( 'ABSPATH' ) ) {
	exit;
}

//Save order in Bigcommerce
class Bolt_Save_Order
{
	private $confirmation_page;
	private $order_id;
	private $order;
	private $transaction_reference;
	public function __construct()
	{
		add_action( 'rest_api_init', array( $this, 'register_endpoints' ) );
		$this->init_public_ajax();
		$this->confirmation_page = New Bolt_Confirmation_Page();
	}

	protected function getorder() {
		if ( !isset( $this->order ) ) {
			$this->order = BCClient::getCollection( "/v2/orders/{$this->order_id}" );
			if (!$this->order) {
				BugsnagHelper::getBugsnag()->notifyException( new \Exception( "Can't get order" ) );
			}
		}
		return $this->order;
	}

	/**
	 * Register wordpress endpoints
	 */
	public function register_endpoints()
	{
		register_rest_route( 'bolt', '/response', array(
			'methods' => \WP_REST_Server::CREATABLE,
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
		BugsnagHelper::addBreadCrumbs( array( 'WEBHOOK CALL' => array( 'body' => $bolt_data ) ) );
		BoltLogger::write( "webhook call".print_r( $bolt_data, true ) );

		if ( !$signatureVerifier->verifySignature( $bolt_data_json, $hmacHeader ) ) {
			BugsnagHelper::getBugsnag()->notifyException( new \Exception( "Failed HMAC Authentication" ) );
		}


		// Implement discount hook
		if ('discounts.code.apply' === $bolt_data->type) {
			BoltLogger::write("into discount if");
			BoltLogger::write($bolt_data_json);
			$bolt_discounts  = new Bolt_Discounts_Helper($bolt_data);
			return $bolt_discounts->apply_coupon_from_discount_hook();
		}


		if (!isset($bolt_data->reference) && isset($bolt_data->items)) {
			// Create Cart for Product Page Checkout
			BoltLogger::write("Create Cart for Product Page Checkout");
			$bolt_page_checkout = new Bolt_Page_Checkout;
			return $bolt_page_checkout->create_cart_from_api_call_and_send_it( $bolt_data );
		}

		//create new order
		$result = $this->bolt_create_order( $bolt_data->reference, $bolt_data->order, $bolt_data );

		$response = (object) array(
			'status' => $result["status"],
			'message' => $result["message"],
			'display_id' => $result["order_id"],
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
	 * @param array $bolt_data parameters from bolt
	 */
	function order_set_status( $bolt_data )
	{
		$message = '';
		BoltLogger::write( "order_set_status( {$this->order_id}, {$bolt_data->type} ):".print_r($bolt_data,true) );
		$query = array();
		//TODO If shop owner changed default statuses https://store-5669f02hng.mybigcommerce.com/manage/orders/order-statuses

		//payment a sale occurred (auth+capture)
		if ( ( 'payment' === $bolt_data->type ) || ( 'capture' === $bolt_data->type ) ) {
			if ( $this->getorder()->order_is_digital ) {
				$query['status_id'] = 10; // Completed
			} else {
				$query['status_id'] = 11; // Awaiting Fulfillment
			}
			$query['payment_provider_id'] = $bolt_data->id;
			$message = $bolt_data->type;
			if (isset($bolt_data->reference)) {
				$this->transaction_reference = $bolt_data->reference;
			}
			$this->delete_rejected_reversible_note();
		// credit a credit/refund was issued
		} elseif ( 'credit' === $bolt_data->type ) {
			$order_data = $this->getorder();
			$rest_before_credit = $order_data->total_inc_tax - $order_data->refunded_amount;
			$rest_after_credit = $rest_before_credit - $bolt_data->amount / 100;
			if ( $rest_after_credit>0 ) {
				$query['status_id'] = 14; //Partially Refunded
			} else if ( $rest_after_credit==0 ) {
				$query['status_id'] = 4; //Refunded
			} else {
				BugsnagHelper::getBugsnag()->notifyException(new \Exception("Try to refund more then rest"));
			}
			BoltLogger::write("order data". print_r($order_data,true));
			$query['refunded_amount'] = $bolt_data->amount / 100 + $order_data->refunded_amount;
			$message = 'refunded';
		// void a void occurred
		} elseif ( 'void' === $bolt_data->type ) {
			$query['status_id'] = 5; //Cancelled
			$message = 'cancelled';
		// auth an authorization was issued
		} elseif ( 'auth' === $bolt_data->type ) {
			$query['status_id'] = 12; // Manual Verification Required
			$query['payment_provider_id'] = $bolt_data->id;
			$message = 'auth';
		} elseif ( 'pending' === $bolt_data->type ) {
			$query['status_id'] = 12; // Manual Verification Required
			$query['payment_provider_id'] = $bolt_data->id;
			$message = 'pending';
		// rejected_reversible a transaction was rejected but decision can be overridden.
		} elseif ( 'rejected_reversible' === $bolt_data->type ) {
			$query['status_id'] = 12; // Manual Verification Required
			$query['payment_provider_id'] = $bolt_data->id;
			$message = 'rejected_reversible';
			if (isset($bolt_data->reference)) {
				$this->transaction_reference = $bolt_data->reference;
			}
			$this->add_rejected_reversible_note();
			// rejected_irreversible a transaction was rejected and decision can not be overridden.
		} elseif ( 'rejected_irreversible' === $bolt_data->type ) {
			$query['status_id'] = 5; // Cancelled
			$message = 'rejected_irreversible';
			if (isset($bolt_data->reference)) {
				$this->transaction_reference = $bolt_data->reference;
			}
			$this->delete_rejected_reversible_note();
		} else {
			BugsnagHelper::getBugsnag()->notifyException( new \Exception("Unknown transaction type {$bolt_data->type}".print_r($bolt_data,true)));
		}
		if ( $query  ) {
			$this->order_update($query);
		}
		return $message;
	}
	protected function order_update($query) {
		BoltLogger::write( "Order {$this->order_id} query " . print_r($query,true) );
		$result = BCClient::updateResource( "/v2/orders/{$this->order_id}", $query );
		if (!$result) {
			BugsnagHelper::getBugsnag()->notifyException( new \Exception( "Can't update order" ) );
		}
	}

	protected function get_bolt_client() {
		return new \BoltPay\ApiClient( [
		'api_key' => \BoltPay\Bolt::$apiKey,
		'is_sandbox' => \BoltPay\Bolt::$isSandboxMode
	] );
	}

	protected function get_checkout_api($bigcommerce_cart_id) {
		return new Bolt_Checkout($bigcommerce_cart_id);
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
		BoltLogger::write("### params =".var_export(array(
				"bolt_reference"=>$bolt_reference,
				"order_reference"=>$order_reference,
				"bolt_data"=>$bolt_data,
				"is_json"=>$is_json,
			),true));
		$result = array(
			'status' => 'success',
			'order_id' => 0,
		);
		// prevent re-creation order
		BoltLogger::write( "bolt_create_order( {$bolt_reference}, {$order_reference}) )" );
		$bc_order_id = get_option( "bolt_order_{$order_reference}" );
		if ( $bc_order_id ) {
			BoltLogger::write( "prevent re-creation order" );
			$this->order_id =  $bc_order_id;
			$result["message"] = $this->order_set_status( $bolt_data );
			$result["order_id"] = $bc_order_id;
			return $result;
		}

		$bolt_cart_id_option = get_option( "bolt_cart_id_{$order_reference}" );

		$bigcommerce_cart_id = $bolt_cart_id_option['cart_id'];
		if (!$bigcommerce_cart_id) {
			BugsnagHelper::getBugsnag()->notifyException(new \Exception("Can't read bigcommerce_cart_id for " .$order_reference ) );
			return false;
		}

		if ( $is_json ) {
			$shipment = $bolt_data->shipping_option->value;
		} else {
			if ($_COOKIE['bigcommerce_cart_id']<>$bigcommerce_cart_id) {
				//user is on product page
				//bigcommerce cart created when Bolt asked about shipping and task
				//and bigcommerce_cart_id isn't yet bound to user session
				$_COOKIE['bigcommerce_cart_id'] = $bigcommerce_cart_id;
				$cookie_life = apply_filters( 'bigcommerce/cart/cookie_lifetime', 30 * DAY_IN_SECONDS );
				$secure      = ( 'https' === parse_url( home_url(), PHP_URL_SCHEME ) );
				$cookie_result = setcookie( 'bigcommerce_cart_id', $bigcommerce_cart_id, time() + $cookie_life, COOKIEPATH, COOKIE_DOMAIN, $secure );
				BoltLogger::write("{$cookie_result} = setcookie( bigcommerce_cart_id, {$bigcommerce_cart_id}, time() + ".$cookie_life.", ".COOKIEPATH.", ".COOKIE_DOMAIN.", $secure );");
			}
			//get data from Bolt transaction
			$bolt_client = $this->get_bolt_client();
			$bolt_transaction = $bolt_client->getTransactionDetails( $bolt_reference )->getBody();
			BoltLogger::write("### bolt_input[getTransactionDetails] ". var_export($bolt_reference, true) );
			BoltLogger::write("### bolt_output[getBody] ". var_export($bolt_transaction, true) );
			BoltLogger::write( "bolt_transaction " . print_r( $bolt_transaction, true ) );
			$bolt_billing_address = $bolt_transaction->order->cart->billing_address;
			$shipment = $bolt_transaction->order->cart->shipments[0];
		}
		$shipping_option_id = $shipment->reference;
		BoltLogger::write( "shipment" . print_r( $shipment, true ) );

		$checkout = $this->get_checkout_api($bigcommerce_cart_id);

		//set selected shipping method
		if ( "no_shipping" <> $shipping_option_id ) {
			$checkout->set_shipping_option($shipping_option_id);
			BoltLogger::write("### bigcommerce_input[set_shipping_option] ". var_export($shipping_option_id, true ) );
		}
		$customer_id = $checkout->get()->data->cart->customer_id;
		BoltLogger::write("customer_id in cart is '{$customer_id}'");

		$this->order_id = $checkout->create_order();

		$body = array('payment_method' => 'Credit Card (Bolt)');

		//set customer_id if it isn't set before
		if (!$customer_id) {
			$current_user = wp_get_current_user();
			if ($current_user->ID ) {
				$bc_customer_id = get_user_option('bigcommerce_customer_id', $current_user->ID);
				if ($bc_customer_id) {
					$body['customer_id'] = $bc_customer_id;
				}
			}
		}

		//make order complete
		$pending_status_id = 1; //TODO Get status id from BC
		$body['status_id'] = $pending_status_id;
		$this->order_update( $body );

		//save Bigcommerce order id
		add_option( "bolt_order_{$order_reference}", $this->order_id );
		BoltLogger::write( "add_option( \"bolt_order_{$order_reference}\", $this->order_id )" );

		//delete cart (it doesn't delete itself altough according to the documentation it should
		$checkout->delete();

		$result["order_id"] = $this->order_id;
		return $result;
	}

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
		if (headers_sent($filename, $linenum)) {
			BugsnagHelper::getBugsnag()->notifyException( new \Exception( "Can't save session cos headers already sent in {$filename} {$linenum}" ) );
		}
		$_SESSION["bolt_order_id"] = $result["order_id"];
		BoltLogger::write("\$_SESSION[\"bolt_order_id\"] = {$result["order_id"]};");
		BoltLogger::write( "result in save order" . print_r( $result, true ) );
		$this->clean_up_archaic_resources_async();
		wp_send_json( array(
			'result' => 'success',
			'redirect_url' => $this->confirmation_page->get_url(),
		) );
	}

	private function set_staff_note($note) {
		$body = array( "staff_notes" => $note );
		$this->order = BCClient::updateResource( "/v2/orders/{$this->order_id}" , $body);
	}

	private function rejection_note_text() {
		if (\BoltPay\Bolt::$isSandboxMode) {
			$domen = 'merchant-sandbox.bolt.com';
		} else {
			$domen = 'merchant.bolt.com';
		}
		return "Order is rejected by Bolt. You can either force approve or confirm rejection from https://{$domen}/transaction/{$this->transaction_reference}";
	}

	private function get_staff_note() {
		return $this->getorder()->staff_notes;
	}

	protected function delete_rejected_reversible_note() {
		if ( $this->rejection_note_text() == $this->get_staff_note()) {
			$this->set_staff_note( '' );
			BoltLogger::write("STAFF_NOTE DELETE");
		} else {
			BoltLogger::write("STAFF_NOTE not delete cos already set '".$this->get_staff_note()."' not '".$this->rejection_note_text()."''");
		}

	}

	protected function add_rejected_reversible_note() {
		if ( '' == $this->get_staff_note()) {
			$this->set_staff_note( $this->rejection_note_text() );
			BoltLogger::write("STAFF_NOTE SET");
		} else {
			BoltLogger::write("STAFF_NOTE not set cos already set '".$this->get_staff_note()."''");
		}
	}

	/**
	 * Makes non-blocking call to URL endpoint for cleaning up expired order creation resources
	 */
	protected function clean_up_archaic_resources_async()
	{
		BoltLogger::write( "start clean_up_archaic_resources_async" );
		if ( !function_exists( 'stream_socket_client' ) ) {
			BugsnagHelper::getBugsnag()->notifyException( new \Exception( "This merchant does not appear to have streamed socket support enabled for resource cleanup.  Please enable this on their server." ) );
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
			BugsnagHelper::getBugsnag()->notifyException( new \Exception( "Error connecting to clean Bolt resources. $errstr ($errno)\n" ) );
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