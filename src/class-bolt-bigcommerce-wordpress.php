<?php

if ( !defined( 'ABSPATH' ) ) {
	exit;
}

class Bolt_Bigcommerce_Wordpress
{

	//Set up base actions 
	public function init()
	{
		add_action( 'bigcommerce/cart/proceed_to_checkout', array( $this, 'bolt_cart_button' ) );
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_scripts' ) );
		$this->init_bolt_api();
		$this->init_bigcommerce_api();

		require_once(dirname( __FILE__ ) . '/class-bolt-generate-order-token.php');
		new Bolt_Generate_Order_Token();

		require_once(dirname( __FILE__ ) . '/class-bolt-shipping-and-tax.php');
		new Bolt_Shipping_And_Tax();

		require_once(dirname( __FILE__ ) . '/class-bolt-save-order.php');
		new Bolt_Save_Order();

	}

	public function enqueue_scripts()
	{
		wp_enqueue_style( 'bolt-bigcommerce', plugins_url( 'css/bolt-bigcommerce.css', __FILE__ ) );
	}


	public function init_bolt_api()
	{
		$config = require(dirname( __FILE__ ) . '/../lib/config_bolt_php.php');
		\BoltPay\Bolt::$apiKey = $this->get_option( "api_key" );
		\BoltPay\Bolt::$signingSecret = $this->get_option( "signing_secret" );
		\BoltPay\Bolt::$apiPublishableKey = $this->get_option( "publishable_key" );
		\BoltPay\Bolt::$isSandboxMode = @$config['IS_SANDBOX'];
		\BoltPay\Bolt::$authCapture = @$config['AUTH_CAPTURE'];
		\BoltPay\Bolt::$connectSandboxBase = 'https://connect-sandbox.bolt.com';
		\BoltPay\Bolt::$connectProductionBase = 'https://connect.bolt.com';
		\BoltPay\Bolt::$apiSandboxUrl = 'https://api-sandbox.bolt.com';
		\BoltPay\Bolt::$apiProductionUrl = 'https://api.bolt.com';
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
		BoltLogger::write( "init_bigcommerce_api client_id ".get_option( "BIGCOMMERCE_CLIENT_ID" ).'auth_token'.get_option( "BIGCOMMERCE_ACCESS_TOKEN" ) );
		//TODO: get client_id auth_token from Bigcommerce. Now it works only for v2
		BCClient::configure( [
			'client_id' => '2ei6wibvhq71hkbl3vzwkukg8hfp7nz', //get_option( "BIGCOMMERCE_CLIENT_ID" ),
			'auth_token' => 'q08ula6ng01w2w29mgwnzb0iuyf6hdi', //get_option( "BIGCOMMERCE_ACCESS_TOKEN" ),
			'client_secret' => get_option( "BIGCOMMERCE_CLIENT_SECRET" ),
			'store_hash' => $store_hash,
		] );
	}

	//get option
	protected function get_option( $key )
	{
		return esc_attr( get_option( "bolt-bigcommerce_{$key}" ) );
	}


}