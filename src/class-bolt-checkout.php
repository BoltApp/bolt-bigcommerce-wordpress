<?php

namespace BoltBigcommerce;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Represent Bigcommerce Checkout.
 *
 * @class   Bolt_Checkout
 * @author  Bolt
 */
class Bolt_Checkout {
	private $checkout_id;

	private $_data;

	public function __construct( $checkout_id ) {
		$this->checkout_id = $checkout_id;
	}

	/**
	 * Get checkout data by API request
	 *
	 */
	private function _get() {
		$data = BCClient::getCollection( "/v3/checkouts/{$this->checkout_id}" );
		if ( ! $data ) {
			BugsnagHelper::getBugsnag()->notifyException( new \Exception( "Can't get checkout" ) );
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
	public function get( $not_use_cache = false ) {
		if ( ! isset( $this->_data ) || $not_use_cache ) {
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
	public function update_address( $address ) {
		if ( $this->address_is_change( $this->get()->data->billing_address, $address ) ) {
			//add or update billing address
			$data = BCClient::createResource( "/v3/checkouts/{$this->checkout_id}/billing-address", $address );
			if ( ! $data ) {
				BugsnagHelper::getBugsnag()->notifyException( new \Exception( "Can't add address to checkout" ) );
			}
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
	private function address_is_change( $address1, $address2 ) {
		$properties = array(
			'first_name',
			'last_name',
			'company',
			'address1',
			'address2',
			'city',
			'state_or_province',
			'postal_code',
			'country',
			'country_code',
			'phone',
			'email'
		);
		foreach ( $properties as $property ) {
			if ( ! property_exists( $address1, $property ) || $address1->{$property} <> $address2->{$property} ) {
				ob_start();
				var_dump( $address1->{$property} );
				var_dump( $address2->{$property} );
				$dump = ob_get_clean();
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
	public function add_or_update_consignment( $consignment ) {
		if ( ! isset( $this->get()->data->consignments[0] ) ) { //consignment not created
			$params = array( $consignment );
			$data = BCClient::createResource( "/v3/checkouts/{$this->checkout_id}/consignments?include=consignments.available_shipping_options", $params );
			if ( ! $data ) {
				BugsnagHelper::getBugsnag()->notifyException( new \Exception( "Can't create consignment" ) );
			}
			$consignment_id = $data->data->consignments[0]->id;
		} else {
			$consignment_id = $this->get()->data->consignments[0]->id;
			$data = BCClient::updateResource( "/v3/checkouts/{$this->checkout_id}/consignments/$consignment_id?include=consignments.available_shipping_options", $consignment );
			if ( ! $data ) {
				BugsnagHelper::getBugsnag()->notifyException( new \Exception( "Can't update consignment" ) );
			}
		}
		$this->_data = $data;
	}

	/**
	 * Get all shipping options in Bolt format
	 *
	 * @return array
	 */
	public function get_shipping_options() {
		$bolt_shipping_options = array();
		$data                  = $this->get();
		if ( ! isset( $data->data->consignments[0] ) ) {
			BugsnagHelper::getBugsnag()->notifyException( new \Exception( "Can't get shipping options: consignment doesn't exist" ) );
		}
		$consignment_id = $data->data->consignments[0]->id;
		if ( isset( $data->data->consignments[0]->available_shipping_options ) ) {
			$shipping_options = $data->data->consignments[0]->available_shipping_options;
			foreach ( $shipping_options as $shipping_option ) {
				$cost = (int) round( $shipping_option->cost * 100 );
				//get tax amount for this shipping option
				$body = (object) array( "shipping_option_id" => $shipping_option->id );
				$data_cost = BCClient::updateResource( "/v3/checkouts/{$this->checkout_id}/consignments/$consignment_id?include=consignments.available_shipping_options", $body );
				if ( ! $data_cost ) {
					BugsnagHelper::getBugsnag()->notifyException( new \Exception( "Can't update consignment for calculate tax amount" ) );
				}
				$tax_amount = (int) round( ( $data_cost->data->consignments[0]->shipping_cost_inc_tax - $data_cost->data->consignments[0]->shipping_cost_ex_tax ) * 100 );
				//add handling_cost as shipping
				$cost                    += (int) round( $data_cost->data->consignments[0]->handling_cost_ex_tax * 100 );
				$tax_amount              += (int) round( ( $data_cost->data->consignments[0]->handling_cost_inc_tax - $data_cost->data->consignments[0]->handling_cost_ex_tax ) * 100 );
				$bolt_shipping_options[] = array(
					"service"    => $shipping_option->description,
					"reference"  => $shipping_option->id,
					"cost"       => $cost,
					"tax_amount" => $tax_amount,
				);
			}
		}

		return $bolt_shipping_options;
	}

	/**
	 * Set shipping option by id
	 *
	 * @param string $shipping_option_id
	 */
	public function set_shipping_option( $shipping_option_id ) {
		$data = $this->get();
		if ( ! isset( $data->data->consignments[0] ) ) {
			BugsnagHelper::getBugsnag()->notifyException( new \Exception( "Can't set shipping option: consignment doesn't exist" ) );
		}
		$consignment_id = $data->data->consignments[0]->id;
		$body           = (object) array( "shipping_option_id" => $shipping_option_id );
		$data = BCClient::updateResource( "/v3/checkouts/{$this->checkout_id}/consignments/$consignment_id?include=consignments.available_shipping_options", $body );
		if ( ! $data ) {
			BugsnagHelper::getBugsnag()->notifyException( new \Exception( "Can't update consignment" ) );
		}
		$this->_data = $data;
	}

	/**
	 * Create new order
	 *
	 * @return int order id
	 */
	public function create_order() {
		$order_data = BCClient::createResource( "/v3/checkouts/{$this->checkout_id}/orders" );
		$order_id = $order_data->data->id;
		if ( ! $order_data ) {
			BugsnagHelper::getBugsnag()->notifyException( new \Exception( "Can't create order" ) );
		}

		return $order_id;
	}

	/**
	 * delete checkout
	 */
	public function delete() {
		BCClient::deleteResource( "/v3/carts/{$this->checkout_id}" );
	}

	public function add_coupon( $coupon_code ) {
		$params = (object) array( "coupon_code" => $coupon_code );
		$data = BCClient::createResource( "/v3/checkouts/{$this->checkout_id}/coupons", $params );
		$this->_data = $data;
	}

	public function add_gift( $gift_code ) {
		$params = (object) array( "giftCertificateCode" => $gift_code );
		$data = BCClient::createResource( "/v3/checkouts/{$this->checkout_id}/gift-certificates", $params );
		$this->_data = $data;
	}


}