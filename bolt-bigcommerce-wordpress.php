<?php
/**
 * Bolt Checkout for BigCommerce for WooCommerce.
 *
 * @link              http://bolt.com
 * @since             1.0.0
 *
 * Plugin Name:       Bolt for BigCommerce Wordpress
 * Plugin URI:        http://bolt.com
 * Description:       It adds Bolt checkout to the BigCommerce Wordpress.
 * Version:           0.0.1
 * Author:            Bolt
 * Author URI:        https://bolt.com
 * License:           GPL-3.0
 * License URI:       http://www.gnu.org/licenses/gpl-3.0.txt
 */
 
 //Bigcommerce API v2
 use Bigcommerce\Api\Resource;
 use Bigcommerce\Api\Client;
 
 // include Bolt API
 // API_KEY and other parameters temporary stores /lib/config_bolt_php.php
 // TODO: Move settings to wordpress admin
 require(dirname(__FILE__) . '/lib/init_bolt_php.php');

 //temporary solution for using Bigcommerce API v3
 //necessary make changes in  bigcommerce-for-wordpress-master/vendor/bigcommerce/api/src/Bigcommerce/Api/Client.php
 //add first line in function mapCollection "return $object;". It's line 349
 function bigcommerce_api_v3( $method, $type="GET" ) {
  Client::$api_path = str_replace("/v2","/v3",Client::$api_path);
  if ($type=="GET") {
   $result = Client::getCollection($method);
  } else if ($type=="DELETE"){
   $result = Client::deleteResource($method);
  }
  Client::$api_path = str_replace("/v3","/v2",Client::$api_path);
  log_write("api v3 method={$method} type={$type} result ".print_r($result,true));
  return $result;
 }
 
 add_action( 'rest_api_init', 'wpc_register_bolt_api_endpoints' );
 function wpc_register_bolt_api_endpoints() {
    /**
     * Sync and handle bolt API.
     */
    register_rest_route( 'bolt', '/response', array(
        'methods'  => WP_REST_Server::CREATABLE,
        'callback' => 'bolt_endpoint_handler',
    ) );

    register_rest_route( 'bolt', '/shippingtax', array(
        'methods'  => WP_REST_Server::CREATABLE,
        'callback' => 'bolt_endpoint_handler_shipping_tax',
    ) );

 }
 function bolt_endpoint_handler() {
  $hmacHeader = @$_SERVER['HTTP_X_BOLT_HMAC_SHA256'];
  log_write("bolt_endpoint_handler");
  $signatureVerifier = new \BoltPay\SignatureVerifier(
     \BoltPay\Bolt::$signingSecret
  ); 
  $bolt_data_json = file_get_contents('php://input');
  $bolt_data = json_decode($bolt_data_json);
  log_write(print_r($bolt_data,true));
 
  if ( !$signatureVerifier->verifySignature( $bolt_data_json, $hmacHeader ) ) {
   throw new Exception("Failed HMAC Authentication");
  }
 
  //create new order
  $order_reference = $bolt_data->order;
  $bigcommerce_cart_id = get_option( "bolt_cart_id_".$order_reference );
  $bolt_reference = $bolt_data->reference;
  $response = bolt_create_order( $bolt_reference, $bigcommerce_cart_id, $bolt_data );
 
  //empty cart
  bigcommerce_api_v3("/carts/{$bigcommerce_cart_id}","DELETE");
 
  wp_send_json( $response );
  wp_die();
 }
 
 //return shipping methods
 function bolt_endpoint_handler_shipping_tax() {
  $hmacHeader = @$_SERVER['HTTP_X_BOLT_HMAC_SHA256'];
  $signatureVerifier = new \BoltPay\SignatureVerifier(
    \BoltPay\Bolt::$signingSecret
  );
 
  $bolt_order_json = file_get_contents('php://input');
  $bolt_order = json_decode($bolt_order_json);

  if (!$signatureVerifier->verifySignature($bolt_order_json, $hmacHeader)) {
   throw new Exception("Failed HMAC Authentication");
  }
  // TODO: add validation from wooplugin like $region = bolt_addr_helper()->get_region_code( $country_code, $bolt_order->shipping_address->region ? :'');
  $country_code = $bolt_order->shipping_address->country_code;
  $post_code = $bolt_order->shipping_address->postal_code;
  $region = $bolt_order->shipping_address->region;
 
  // TODO: work with states  
  // TODO: think about cache /shipping/zones
  $shipping_zones = Client::getCollection('/shipping/zones');
  
  // look for shipping zone contains specific country
  $shipping_zone_id = 0;
  foreach ($shipping_zones as $zone_id => $shipping_zone) {
   if ("global" == $shipping_zone->type) {
    //shipping zone for rest of word. it uses if we don't find zone contains specific country
    $rest_shipping_zone_id = $shipping_zone->id;
    continue;
   } 
   foreach ($shipping_zone->locations as $location) {
    if ($location->country_iso2 == $country_code) {
     $shipping_zone_id = $shipping_zone->id;
     break 2;
    }
   }
  }
  if (!$shipping_zone_id) {
   $shipping_zone_id = $rest_shipping_zone_id;
  }
  //get shipping methods for selected shipping zone
  $shipping_methods = Client::getCollection("/shipping/zones/{$shipping_zone_id}/methods");
  $bolt_shipping_options = array();
  // TODO: now code works only with 'flat rate - per order' and 'free shipping'. works with other delivery methods
  if ($shipping_zone->free_shipping && $shipping_zone->free_shipping->enabled) {
   $bolt_shipping_options[] = array(
    "service"    => "Free Shipping - Free",
    "reference"  => "freeshipping_freeshipping",
    "cost"       => 0,
    "tax_amount" => 0,
   );
  }
  foreach ($shipping_methods as $shipping_method) {
   if ($shipping_method->enabled) {
    switch ($shipping_method->type) {
     case "perorder":
      $bolt_shipping_options[] = array(
       "service"    => $shipping_method->name, //'Flat Rate - Fixed', 
       "reference"  => "flatrate_flatrate",
       "cost"       => $shipping_method->settings->rate * 100,
       "tax_amount" => 0,
      );
     break;
    }
   }
  }
  $response = array ( "shipping_options" => $bolt_shipping_options);
  
  wp_send_json( $response );
  wp_die();
 }
 //render html and js code for bolt checkout button
 //code "bolt_cart_button($cart);" added in bigcommerce-for-wordpress-master/public-views/cart.php
 //before "</footer>"
 function bolt_cart_button($bigcommerce_cart) {
  //create bolt order from bigcommerce cart
  $order_reference = uniqid('BLT',false);
  $cart = array(
   "order_reference" => $order_reference,
   "display_id"      => $order_reference,
   // TODO: work with different currency
   "currency"        => "USD", //!!!!,
   "total_amount"    => round( $bigcommerce_cart["cart_amount"]["raw"] * 100 ),
   "tax_amount"      => 0,
   // TODO: work with discounts
   "discounts"       => array(),
  );
  foreach ($bigcommerce_cart["items"] AS $item_id=>$item) {
   $cart["items"][] = array(
    "reference"    => $order_reference,
    "name"         => $item["name"],
    "sku"          => $item["sku"]["product"],
    "description"  => "",
    "total_amount" => round( $item["total_sale_price"]["raw"] * 100 ),
    "unit_price"   => round( $item["sale_price"]["raw"] * 100 ),
    "quantity"     => $item["quantity"],
    // TODO: work with different types of products
    "type"         => "physical", //!!!!
   );
  }
  $cartData = array("cart" => $cart);

  $client = new \BoltPay\ApiClient([
    'api_key' => \BoltPay\Bolt::$apiKey,
    'is_sandbox' => \BoltPay\Bolt::$isSandboxMode
  ]);
  
  $response = $client->createOrder($cartData);
  $orderToken = $response->isResponseSuccessful()  ? @$response->getBody()->token : '';
  //save link between order_reference and bolt_cart_id_
  //it uses when we create order in bigcommerce (function bolt_create_order)
  //todo: think about better storage
  $updated = update_option( "bolt_cart_id_".$order_reference, $bigcommerce_cart["cart_id"]);
 //Sample JS CODE
 // TODO: move it to the template
 ?>
<div class="bolt-checkout-button with-cards"></div>
<?= \BoltPay\Helper::renderBoltTrackScriptTag(); ?>
<?= \BoltPay\Helper::renderBoltConnectScriptTag(); ?>
<script>
    // Once the payment has been done on the bolt this method will be fired.
    save_checkout = function ( transaction, callback, type ) {

        var params = [
            'transaction_details=' + JSON.stringify( transaction ),
            //'_wpnonce=' + wc_bolt_checkout_config.nonce.checkout,
            type + '=1'
        ];
        //if(bolt_checkout_form && jQuery( bolt_checkout_form ).length>0){
        //    params.unshift(jQuery( bolt_checkout_form ).serialize());
        //}
        var cart_data = params.join("&");
        
        jQuery.ajax( {
            type: 'POST',
            url: '<?= admin_url('admin-ajax.php'); ?>?action=bolt_create_order',
            data: cart_data,
            success: function ( data ) {
                if ( data.result != 'success' ) {
                    //jQuery('#bolt-modal-background').remove();
                    //jQuery('html').removeClass('bolt_modal_active');
                    //jQuery('body').css('overflow', 'auto');
                    //display_notices(data);
                } else {
                    redirect_url = data.redirect_url;
                    callback();
                }
            }
        } );
     };

    var cart = {
        "orderToken": "<?= $orderToken;?>",
        "authcapture": true
    };
    var hints = {};
    var callbacks = {
        check: function () {
            // This function is called just before the checkout form loads.
            // This is a hook to determine whether Bolt can actually proceed
            // with checkout at this point. This function MUST return a boolean.
            return true;
        },

        onCheckoutStart: function () {
            // This function is called after the checkout form is presented to the user.
        },

        onShippingDetailsComplete: function () {
            // This function is called when the user proceeds to the shipping options page.
            // This is applicable only to multi-step checkout.
        },

        onShippingOptionsComplete: function () {
            // This function is called when the user proceeds to the payment details page.
            // This is applicable only to multi-step checkout.
        },

        onPaymentSubmit: function () {
            // This function is called after the user clicks the pay button.
        },

        success: function (transaction, callback) {
            save_checkout(transaction, callback, 'product_page');
        },

        close: function () {
            // This function is called when the Bolt checkout modal is closed.
            location.href = redirect_url;
        }
    };
    BoltCheckout.configure(cart, hints, callbacks);
</script>
<?php
 }
 function bolt_order_set_status ( $order_id, $bolt_type, $bolt_status="" ) {
  $new_status_id = 0;
  if ( "rejected_reversible" == $bolt_type ) {
   $new_status_id = 12; // Manual Verification Required
   //$custom_status = "Recently Rejected";
  } else if ( ( "payment" == $bolt_type ) && ( "completed" == $bolt_status) ) {
   $new_status_id = 11; // Awaiting Fulfillment
  } else if ( "rejected_irreversible" == $bolt_type ) {
   $new_status_id = 6; // Declined
  }
 
  //read the old status
  $order = Client::getCollection("/orders/{$order_id}");
  log_write("query '/order_status/{$order_id}'");
  log_write("answer".print_r($order,true));

  if ( $new_status_id && $order->id != $new_status_id) {
   log_write("Order {$order_id} Change status From {$order->status_id} ({$order->status}) TO {$new_status_id} {$custom_status} ");
   Client::updateResource( "/orders/{$order_id}", array( "status_id" => $new_status_id ) );
  }
 }
  
 //create order in bigcommerce
 function bolt_create_order( $bolt_reference, $bigcommerce_cart_id, $bolt_data) {
  // prevent re-creation order
  $bc_order_id = get_option( "bolt_order_{$bolt_reference}" );
  if ($bc_order_id) {
   log_write("prevent re-creation order");
   bolt_order_set_status( $bc_order_id, $bolt_data->type, $bolt_data->status );
   $response = new stdClass();
   $response -> status = "success";
   $response -> created_objects -> merchant_order_ref = $bc_order_id;   
   return $response;
  }
  // TODO: add information about selected shipment method
  // TODO: add information about payment and set order status
  
  //get data from bigcommerce cart ...
  $cart = bigcommerce_api_v3("/carts/{$bigcommerce_cart_id}");
  log_write("bigcommerce cart ".print_r($cart,true));
  
  //... and Bolt transaction
  $bolt_client = new \BoltPay\ApiClient([
    'api_key' => \BoltPay\Bolt::$apiKey,
    'is_sandbox' => \BoltPay\Bolt::$isSandboxMode
  ]);

  $bolt_transaction = $bolt_client->getTransactionDetails( $bolt_reference ) -> getBody();
  log_write("bolt_transaction ".print_r($bolt_transaction,true));

  $bolt_billing_address = $bolt_transaction->order->cart->billing_address;
 
  $order = new stdClass();
  
  $order->subtotal_ex_tax = $cart->data->base_amount;
  $order->subtotal_inc_tax = $cart->data->base_amount;
  
  $shipping_cost = $bolt_transaction->order->cart->shipments[0]->cost->amount / 100;
  
  $order->total_inc_tax = $cart->data->base_amount + $shipping_cost;
  $order->total_ex_tax = $cart->data->base_amount + $shipping_cost;
  
  $order->shipping_cost_ex_tax = $shipping_cost;
  $order->shipping_cost_inc_tax = $shipping_cost;
  $order->base_shipping_cost = $shipping_cost;
  $order->order_is_digital = false;
/*  
  <subtotal_ex_tax>34.9500</subtotal_ex_tax>
  <subtotal_inc_tax>34.9500</subtotal_inc_tax>
  
    <base_shipping_cost>10.0000</base_shipping_cost>
    <shipping_cost_ex_tax>10.0000</shipping_cost_ex_tax>
    <shipping_cost_inc_tax>10.0000</shipping_cost_inc_tax>

    <total_ex_tax>44.9500</total_ex_tax>
    <total_inc_tax>44.9500</total_inc_tax>
  */

  $order->billing_address = new stdClass();
  $order->billing_address->first_name   = $bolt_billing_address->first_name;
  $order->billing_address->last_name    = $bolt_billing_address->last_name;
  // TODO: find company in bolt form
  $order->billing_address->company      = "";
  $order->billing_address->street_1     = $bolt_billing_address->street_address1;
  $order->billing_address->street_2     = "";
  $order->billing_address->city         = $bolt_billing_address->locality;
  // TODO: find state in bolt form
  $order->billing_address->state        = "???";
  $order->billing_address->zip          = $bolt_billing_address->postal_code;
  $order->billing_address->country      = $bolt_billing_address->country;
  $order->billing_address->country_iso2 = $bolt_billing_address->country_code;
  $order->billing_address->phone        = $bolt_billing_address->phone_number;
  $order->billing_address->email        = $bolt_billing_address->email_address;
  
  // add shipping method (text)
  $order->shipping_addresses[] = clone $order->billing_address;
  $order->shipping_addresses[0]->shipping_method = $bolt_transaction->order->cart->shipments[0]->service;
  
  
  $order->products = array();

  foreach ($cart->data->line_items->physical_items as $item) {
   $product = new stdClass();
   $product->product_id = $item->product_id;
   $product->quantity = $item->quantity;
   //echo "!!!"; exit;
   $order->products[] = $product;
  }
  // TODO: the same about non physical items 
 
  Client::failOnError(true);
  log_write("<P>bc_order");
  try {
   $bc_order = Client::createResource('/orders', $order);
   log_write(print_r($bc_order,true)); 
  }  
  catch (Exception $ex) {
   log_write("!!");
   log_write($ex);
  }
  //save Bigcommerce order id
  add_option( "bolt_order_{$bolt_reference}", $bc_order->id);

  $response = new stdClass();
  $response -> status = "success";
  $response -> created_objects -> merchant_order_ref = $bc_order->id;
  return $response;
 }
 
 //AJAX success callback
 //for now save the order later, via webhook
 
 add_action( 'wp_ajax_bolt_create_order', 'save_order' );
 function save_order() {
  // TODO: move order creation from webhook to this function
  wp_send_json( array(
   'result'     => 'success',
   // TODO: create this order confirmation page
   'redirect_url'  => get_site_url()."/success",
  ) );
  wp_die();
 }
 
 //temporary log function
 function log_write($text) {
  //$logecho = true;
  $f = fopen( dirname(__FILE__) . "/log.txt","a" );
  fwrite( $f, date("Y-m-d H:i:s")." ".$text."\r\n" );
  if ($logecho) echo "<P>".date("Y-m-d H:i:s")."<pre>{$text}</pre>\r\n";
 }



