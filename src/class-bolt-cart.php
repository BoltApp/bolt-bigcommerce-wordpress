<?php
namespace BoltBigcommerce;

class Bolt_Cart extends \BigCommerce\Cart\Cart{
	public function __construct() {
		$config = new \BigCommerce\Api\v3\Configuration();

		$config->setHost( untrailingslashit( get_option( "bigcommerce_store_url" ) ) );
		$config->addDefaultHeader('X-Auth-Client', get_option( "BIGCOMMERCE_CLIENT_ID" ));
		$config->addDefaultHeader('X-Auth-Token', get_option( "BIGCOMMERCE_ACCESS_TOKEN" ));

		$apiclient = new \BigCommerce\Api\v3\ApiClient($config);
		$cart_api = new \BigCommerce\Api\v3\Api\CartAPI($apiclient);
		//$cart     = new \BigCommerce\Cart\Cart( $cart_api );
		parent::__construct($cart_api);
	}
}
