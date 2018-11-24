<?php

if (!defined('ABSPATH')) {
	exit;
}

/**
 * Represent Bigcommerce Checkout.
 *
 * @class   Bolt_Discounts_Helper
 * @author  Bolt
 */

/**
 * Bolt_Discounts_Helper.
 */
class Bolt_Checkout
{
	private $checkout_id;

	private $_data;

	public function __construct($checkout_id)
	{
		$this->checkout_id = $checkout_id;
	}

	/**
	 * Get checkout data by API request
	 *
	 */
	private function _get()
	{
		$data = BCClient::getCollection( "/v3/checkouts/{$this->checkout_id}" );
		BoltLogger::write( "checkout = BCClient::getCollection( \"/v3/checkouts/{$this->checkout_id}\" );" );
		BoltLogger::write( "get checkout " . print_r( $data, true ) );
		if (!$data) {
			BugsnagHelper::getBugsnag()->notifyException( new Exception( "Can't get checkout" ) );
		}
		$this->_data = $data;
	}

	/**
	 * Return checkout data : from cache or not
	 *
	 * @param bool $not_use_cache if true doesn't use cache
	 *
	 * @return object
	 */
	public function get($not_use_cache = false)
	{
		if (!isset($this->_data) || $not_use_cache) {
			$this->_get();
		}
		return $this->_data;
	}

	/**
	 * Add or Update checkout billing address
	 *
	 * @param object $address
	 *
	 * @return bool true if address was changed
	 */
	public function update_address($address)
	{
		BoltLogger::write("update_address input parametrs ".var_export($address,true));
		if ( $this->address_is_change( $this->get()->data->billing_address, $address ) ) {
			//add or update billing address
			$data = BCClient::createResource( "/v3/checkouts/{$this->checkout_id}/billing-address", $address );
			if (!$data) {
				BugsnagHelper::getBugsnag()->notifyException( new Exception( "Can't add address to checkout" ) );
			}
			BoltLogger::write( "add billing address /v3/checkouts/{$this->checkout_id}/billing-address " . json_encode( $address ) );
			BoltLogger::write( "add billing address answer " . print_r( $data, true ) );
			$this->_data = $data;
			return true;
		} else {
			return false;
		}
	}

	/**
	 * Compare two addresses in Bigcommerce format
	 *
	 * @param $address1
	 * @param $address2
	 *
	 * @return bool true if address different, false if  the same
	 */
	private function address_is_change( $address1, $address2 )
	{
		$properties = array( 'first_name', 'last_name', 'company', 'address1', 'address2', 'city', 'state_or_province', 'postal_code', 'country', 'country_code', 'phone', 'email' );
		foreach ( $properties as $property ) {
			if ( !property_exists( $address1, $property ) || $address1->{$property} <> $address2->{$property} ) {
				ob_start();
				var_dump( $address1->{$property} );
				var_dump( $address2->{$property} );
				$dump = ob_get_clean();
				BoltLogger::write( "address_is_change {$property} {$dump}" . print_r( $address1, true ) . " " . print_r( $address2, true ) );
				return true;
			}
		}
		return false;
	}

	/**
	 * Add or update checkout consignment
	 *
	 * @param object $consignment
	 */
	public function add_or_update_consignment($consignment) {
		BoltLogger::write("add_or_update_consignment input parameters ".var_export($consignment,true));
		if ( !isset( $this->get()->data->consignments[0] ) ) { //consignment not created
			$params = array( $consignment );
			BoltLogger::write( "Add a New Consignment /v3/checkouts/{$this->checkout_id}/consignments?include=consignments.available_shipping_options" );
			BoltLogger::write( json_encode( $params ) );
			$data = BCClient::createResource( "/v3/checkouts/{$this->checkout_id}/consignments?include=consignments.available_shipping_options", $params );
			if (!$data) {
				BugsnagHelper::getBugsnag()->notifyException( new Exception( "Can't create consignment" ) );
			}
			BoltLogger::write( "Add a New Consignment answer " . print_r( $data, true ) );
			$consignment_id = $data->data->consignments[0]->id;
			BoltLogger::write( "New consigment ID {$consignment_id}" );
		} else {
			$consignment_id = $this->get()->data->consignments[0]->id;
			BoltLogger::write( "UPDATE Consignment /v3/checkouts/{$this->checkout_id}/consignments/$consignment_id?include=consignments.available_shipping_options" );
			BoltLogger::write( json_encode( $consignment ) );
			$data = BCClient::updateResource( "/v3/checkouts/{$this->checkout_id}/consignments/$consignment_id?include=consignments.available_shipping_options", $consignment );
			if (!$data) {
				BugsnagHelper::getBugsnag()->notifyException( new Exception( "Can't update consignment" ) );
			}
			BoltLogger::write( "New Consignment update answer " . print_r( $data, true ) );
		}
		$this->_data = $data;
	}

	/**
	 * Get all shipping options in Bolt format
	 *
	 * @return array
	 */
	public function get_shipping_options()
	{
		$bolt_shipping_options = array();
		$data = $this->get();
		if (!isset($data->data->consignments[0])) {
			BugsnagHelper::getBugsnag()->notifyException( new Exception( "Can't get shipping options: consignment doesn't exist" ) );
		}
		$shipping_options = $data->data->consignments[0]->available_shipping_options;
		$consignment_id = $data->data->consignments[0]->id;
		foreach ( $shipping_options as $shipping_option ) {
			$cost = (int)round( $shipping_option->cost * 100 );
			//get tax amount for this shipping option
			$body = (object)array( "shipping_option_id" => $shipping_option->id );
			BoltLogger::write( "UPDATE Consignment for calculate tax amount /v3/checkouts/{$this->checkout_id}/consignments/$consignment_id" );
			BoltLogger::write( json_encode( $body ) );
			$data_cost = BCClient::updateResource( "/v3/checkouts/{$this->checkout_id}/consignments/$consignment_id?include=consignments.available_shipping_options", $body );
			if (!$data_cost) {
				BugsnagHelper::getBugsnag()->notifyException( new Exception( "Can't update consignment for calculate tax amount" ) );
			}
			BoltLogger::write( "UPDATE Consignment for calculate tax amount answer " . print_r( $data_cost, true ) );
			$tax_amount = (int)round( ($data_cost->data->consignments[0]->shipping_cost_inc_tax - $data_cost->data->consignments[0]->shipping_cost_ex_tax) * 100 );
			//add handling_cost as shipping
			$cost += (int)round( $data_cost->data->consignments[0]->handling_cost_ex_tax * 100 );
			$tax_amount += (int)round( ($data_cost->data->consignments[0]->handling_cost_inc_tax - $data_cost->data->consignments[0]->handling_cost_ex_tax) * 100 );
			$bolt_shipping_options[] = array(
				"service" => $shipping_option->description,
				"reference" => $shipping_option->id,
				"cost" => $cost,
				"tax_amount" => $tax_amount,
			);
		}
		return $bolt_shipping_options;
	}

	/**
	 * Set shipping option by id
	 *
	 * @param string $shipping_option_id
	 */
	public function set_shipping_option($shipping_option_id)
	{
		$data = $this->get();
		if (!isset($data->data->consignments[0])) {
			BugsnagHelper::getBugsnag()->notifyException( new Exception( "Can't set shipping option: consignment doesn't exist" ) );
		}
		$consignment_id = $data->data->consignments[0]->id;
		$body = (object)array( "shipping_option_id" => $shipping_option_id );
		BoltLogger::write( "UPDATE Consignment /v3/checkouts/{$this->checkout_id}/consignments/$consignment_id?include=consignments.available_shipping_options" );
		BoltLogger::write( json_encode( $body ) );
		$data = BCClient::updateResource( "/v3/checkouts/{$this->checkout_id}/consignments/$consignment_id?include=consignments.available_shipping_options", $body );
		if (!$data) {
			BugsnagHelper::getBugsnag()->notifyException( new Exception( "Can't update consignment" ) );
		}
		BoltLogger::write( "set_shipping_option answer " . print_r( $data, true ) );
		$this->_data = $data;
	}

	/**
	 * Create new order
	 *
	 * @return int order id
	 */
	public function create_order()
	{
		BoltLogger::write( "CREATE ORDER /v3/checkouts/{$this->checkout_id}/orders" );
		$order_data = BCClient::createResource( "/v3/checkouts/{$this->checkout_id}/orders" );
		BoltLogger::write( print_r( $order_data, true ) );
		$order_id = $order_data->data->id;
		if (!$order_data) {
			BugsnagHelper::getBugsnag()->notifyException( new Exception( "Can't create order" ) );
		}
		return $order_id;
	}

	/**
	 * delete checkout
	 */
	public function delete()
	{
		BCClient::deleteResource( "/v3/carts/{$this->checkout_id}" );
	}

	public function add_coupon($coupon_code) {
		$params = (object) array( "coupon_code" => $coupon_code);
		BoltLogger::write("/v3/checkouts/{$this->checkout_id}/coupons ".json_encode($params));
		$data = BCClient::createResource( "/v3/checkouts/{$this->checkout_id}/coupons", $params );
		BoltLogger::write("COUPON CHECKOUT AFTER" . print_r($data,true));
		$this->_data = $data;
	}

	public function add_gift($gift_code) {
		$params = (object) array( "giftCertificateCode" => $gift_code);
		BoltLogger::write("/v3/checkouts/{$this->checkout_id}/gift-certificates ".json_encode($params));
		$data = BCClient::createResource( "/v3/checkouts/{$this->checkout_id}/gift-certificates", $params );
		BoltLogger::write("GIFT CHECKOUT AFTER" . print_r($data,true));
		$this->_data = $data;
	}



}