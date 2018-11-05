<?php

if ( !defined( 'ABSPATH' ) ) {
	exit;
}

class Bolt_Shipping_And_Tax
{
	public function __construct() {
		add_action( 'rest_api_init', array( $this, 'register_endpoints' ) );
	}

	public function register_endpoints()
	{
		register_rest_route( 'bolt', '/shippingtax', array(
			'methods' => WP_REST_Server::CREATABLE,
			'callback' => array( $this, 'handler_shipping_tax' ),
		) );
	}

	//return shipping methods
	function handler_shipping_tax()	{
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
		BoltLogger::write( "handler_shipping_tax " . print_r( $bolt_order, true ) );

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
		BoltLogger::write( "shipping_methods " . print_r( $shipping_methods, true ) );
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

}