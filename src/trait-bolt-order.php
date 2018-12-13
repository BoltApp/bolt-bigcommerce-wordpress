<?php

namespace BoltBigcommerce;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

trait Bolt_Order {
	private $error;

	private $order_reference;

	/**
	 * Generate cart data
	 *
	 * @param array $bigcommerce_cart Bigcommerce cart content
	 *
	 * @return cartdata or false if can't create cart
	 */
	function bolt_generate_cart_data( $bigcommerce_cart ) {
		if ( ! $this->check_products_availability( $bigcommerce_cart ) ) {
			return false;
		}

		$currency_code = get_option( \BigCommerce\Settings\Sections\Currency::CURRENCY_CODE, '' );

		$tax_amount      = isset( $bigcommerce_cart["tax_amount"]["raw"] ) ? $bigcommerce_cart["tax_amount"]["raw"] * 100 : 0;
		$discount_amount = isset( $bigcommerce_cart["discount_amount"]["raw"] ) ? $bigcommerce_cart["discount_amount"]["raw"] * 100 : 0;
		if ( $tax_amount < 0 ) {
			//coupon using result
			$discount_amount -= $tax_amount;
			$tax_amount      = 0;
		}

		$this->order_reference = uniqid( 'BLT', false );
		$cart                  = array(
			"order_reference" => $this->order_reference,
			"display_id"      => $this->order_reference,
			"currency"        => $currency_code,
			"total_amount"    => (int) round( $bigcommerce_cart["cart_amount"]["raw"] * 100 ),
			"tax_amount"      => (int) round( $tax_amount ),
			"discounts"       => array(),
		);

		//Discounts for product: show only discounted price as well as in bolt-woocommerce
		//Discounts for cart: show only total discount (Bigcommerce restrictions)
		//Coupon codes: customer can use it only after press "Bigcommerce process to checkout"
		if ( $discount_amount > 0 ) {
			$cart["discounts"][] = array(
				"amount"      => (int) round( $discount_amount ),
				"description" => "Discount",
			);
		}

		foreach ( $bigcommerce_cart["items"] AS $item_id => $item ) {
			$type            = ( "digital" == $item["bigcommerce_product_type"][0]["label"] ) ? "digital" : "physical";
			$cart["items"][] = array(
				"reference"    => (string) $item["product_id"],
				"name"         => $item["name"],
				"sku"          => $item["sku"]["product"],
				"description"  => "",
				"total_amount" => (int) round( $item["total_sale_price"]["raw"] * 100 ),
				"unit_price"   => (int) round( $item["sale_price"]["raw"] * 100 ),
				"quantity"     => $item["quantity"],
				"type"         => $type,
			);
		}
		$cartData = array( "cart" => $cart );
		BugsnagHelper::addBreadCrumbs( $cartData );

		return $cartData;
	}

	/**
	 * Check if products in cart are available now
	 *
	 * @param array $cart bigcommerce cart
	 *
	 * @return bool
	 */

	protected function check_products_availability( $cart ) {
		$availability = true;
		foreach ( $cart["items"] as $item ) {
			if ( 0 == $item["product_id"] ) {
				//gift sertificate
				continue;
			}
			$product = $this->api_call_get_product( $item["product_id"] );
			if ( "available" != $product->availability ) {
				$this->set_availability_error( $product->name, 0 );
				$availability = false;
				break;
			} else if ( ( "simple" == $product->inventory_tracking ) && ( $product->inventory_level < $item["quantity"] ) ) {
				$this->set_availability_error( $product->name, $product->inventory_level );
				$availability = false;
				break;
			} else if ( "sku" == $product->inventory_tracking ) {
				//need to do additional API call for product with variant
				$variant_product = $this->api_call_get_product_variant( $item["product_id"], $item["variant_id"] );
				if ( $variant_product->data->inventory_level < $item["quantity"] ) {
					$options = "";
					foreach ( $variant_product->data->option_values as $option_id => $option ) {
						if ( 0 <> $option_id ) {
							$options .= ", ";
						}
						$options .= $option->option_display_name . ":" . $option->label;
					}
					$product_name = $product->name . ' (' . $options . ")";
					$this->set_availability_error( $product_name, $variant_product->data->inventory_level );
					$availability = false;
					break;
				}
			}

		}

		return $availability;
	}

	private function update_bolt_cart_id_option( $cart_id ) {
		//save link between order_reference and bolt_cart_id_
		//it uses when we create order in bigcommerce (function bolt_create_order)
		update_option( "bolt_cart_id_" . $this->order_reference, array( 'cart_id' => $cart_id ) );
	}


	protected function api_call_get_product( $product_id ) {
		$url = "/v2/products/{$product_id}?include=@summary";

		return BCClient::getCollection( $url );
	}

	protected function api_call_get_product_variant( $product_id, $variant_id ) {
		$url = "/v3/catalog/products/{$product_id}/variants/{$variant_id}";

		return BCClient::getCollection( $url );
	}

	/**
	 * Create array from object. Map properties according to $parameters
	 *
	 * @param object $source source objects
	 * @param array $parameters array with couples "name_from" "name_to"
	 *
	 * @return array
	 */
	private function copy_object_to_array( $source, $parameters ) {
		$result = array();
		foreach ( $parameters as $from => $to ) {
			if ( isset( $source->$from ) ) {
				$result[ $to ] = $source->$from;
			}
		}

		return $result;
	}

	/**
	 * If we know customers details calculates it for Bolt JS variable hints
	 *
	 * @return string json_encode string for Bolt JS hints
	 */
	private function calculate_hints() {
		$current_user = wp_get_current_user();
		if ( 0 == $current_user->ID ) {
			return "{}";
		} else {
			$prefill = array();
			//get user address from Bigcommperce by API
			$bc_customer_id = get_user_option( 'bigcommerce_customer_id', $current_user->ID );
			if ( $bc_customer_id ) {
				try {
					$addresses = BCClient::getCollection( "/v2/customers/{$bc_customer_id}/addresses" );
					$prefill   = $this->copy_object_to_array( $addresses[0], array(
						"first_name"   => "firstName",
						"last_name"    => "addressLine1",
						"street_1"     => "addressLine1",
						"street_2"     => "addressLine2",
						"city"         => "city",
						"state"        => "state",
						"zip"          => "zip",
						"country_iso2" => "country",
						"phone"        => "phone"
					) );
				} catch ( Exception $ex ) {
					//if addresses call returns 404 error
					$prefill = array();
				}
			}
			//if BC doesn't have information get name from Wordpress
			if ( empty( $prefill ) ) {
				if ( isset( $current_user->user_firstname ) ) {
					$prefill["firstName"] = $current_user->user_firstname;
				}
				if ( isset( $current_user->user_lastname ) ) {
					$prefill["lastName"] = $current_user->user_lastname;
				}
			}

			if ( isset( $current_user->user_email ) ) {
				$prefill["email"] = $current_user->user_email;
			}
			if ( empty( $prefill ) ) {
				return "{}";
			} else {
				return json_encode( (object) array( "prefill" => (object) $prefill ) );
			}
		}
	}


	//render template

	/**
	 * Render template
	 *
	 * @param string $template_name
	 * @param array $parameters
	 * @param bool $render_output true - output, false - return
	 *
	 * @return string rendering template
	 */
	public function render( $template_name, array $parameters = array() ) {
		foreach ( $parameters as $name => $value ) {
			${$name} = $value;
		}
		ob_start();
		include __DIR__ . '/../view/' . $template_name;
		$output = ob_get_contents();
		ob_end_clean();

		return $output;
	}


}
