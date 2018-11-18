<?php

if (!defined('ABSPATH')) {
	exit;
}

/**
 * Helper class around interacting with coupons.
 *
 * @class   Bolt_Discounts_Helper
 * @author  Bolt
 */

/**
 * Bolt_Discounts_Helper.
 */
class Bolt_Discounts_Helper
{
	const E_BOLT_INSUFFICIENT_INFORMATION = 6200;
	const E_BOLT_CODE_INVALID = 6201;
	const E_BOLT_CODE_EXPIRED = 6202;
	const E_BOLT_CODE_NOT_AVAILABLE = 6203;
	const E_BOLT_CODE_LIMIT_REACHED = 6204;
	const E_BOLT_MINIMUM_CART_AMOUNT_REQUIRED = 6205;
	const E_BOLT_UNIQUE_EMAIL_REQUIRED = 6206;
	const E_BOLT_ITEMS_NOT_ELIGIBLE = 6207;
	const E_BOLT_SERVICE = 6001;

	/**
	 * Reference to cart or order object.
	 *
	 * @since 1.2.7
	 * @var mixed
	 */
	protected $object;

	/**
	 * Reference to the transaction object retrieved from the Bolt API endpoint.
	 *
	 * @since 1.2.7
	 * @var   WC_Cart
	 */
	private $api_request;

	/**
	 * Constructor Function.
	 *
	 * @since  1.2.7
	 * @access public
	 *
	 * @param  mixed $object Cart or order object.
	 *
	 */
	public function __construct($object = array())
	{
		$this->api_request = $object;
	}

	/**
	 * Apply coupon code from discount hook
	 *
	 * @return WP_REST_Response   Well-formed response sent to the Bolt Server
	 */
	public function apply_coupon_from_discount_hook()
	{
		try {
			$bigcommerce_cart_id = get_option("bolt_cart_id_{$this->api_request->cart->order_reference}");
			BoltLogger::write("$bigcommerce_cart_id = get_option( \"bolt_cart_id_{$this->api_request->cart->order_reference}\" )" . print_r($this->api_request, true));
			if (!$bigcommerce_cart_id) {
				throw new Exception(__('Cart not found', 'bolt-bigcommerce-wordpress'), self::E_BOLT_INSUFFICIENT_INFORMATION);
			}
			$checkout = new Bolt_Checkout($bigcommerce_cart_id);
			$discount_code = $this->api_request->discount_code;
			BoltLogger::write("BEFORE ADD COUPON");
			//try use $discount_code as coupon_code
			$type = "";
			$coupon_info = BCClient::getCollection("/v2/coupons?code=" . urlencode($discount_code));
			BoltLogger::write("coupon_info " . print_r($coupon_info, true));
			if (isset($coupon_info[0]->name)) {
				//it's coupon
				$coupon_name = $coupon_info[0]->name;
				$type = "coupon";
			}

			//memorize how many coupons already been applied
			$old_coupons_qty = 0;
			while (isset($checkout->get()->data->cart->coupons[$old_coupons_qty])) {
				//if coupon already applied return "ok"
				$coupon = $checkout->get()->data->cart->coupons[$old_coupons_qty];
				if ($coupon->code == $discount_code) {
					BoltLogger::write("already apllied");
					return set_version_headers(new WP_REST_Response(
						(object)array(
							"status" => "success",
							"discount_code" => $discount_code,
							"description" => $coupon_name,
							"discount_type" => "fixed_amount",
							"discount_amount" => (int)round($coupon->discounted_amount * 100)),
						200,
						array('X-Bolt-Cached-Value' => false)
					));

				}

				$old_coupons_qty++;
			}

			if ("coupon" == $type) {
				//if coupon has type 'shipping_discount' or it works with specific delivery method
				//and delivery method doesn't select the return error
				//if (("shipping_discount" == $coupon_info->type) or isset($coupon_info->shipping_methods[0])) {

				try {
					$checkout->add_coupon($discount_code);
				} catch (Exception $e) {
					BoltLogger::write("BC API EXEPTION " . $e->getCode() . ") " . $e->getMessage());
					if (400 == $e->getCode()) {
						throw new Exception($e->getMessage(), SELF::E_BOLT_ITEMS_NOT_ELIGIBLE);
					}
				}
				$coupon = $checkout->get()->data->cart->coupons[$old_coupons_qty];
				BoltLogger::write("old_coupons_qty=$old_coupons_qty");
				BoltLogger::write("coupon" . print_r($coupon, true));
				$discounted_amount = isset($coupon->discounted_amount) ? (int)round($coupon->discounted_amount * 100) : 0;
				if (0 == $discounted_amount) {
					throw new Exception("error", SELF::E_BOLT_ITEMS_NOT_ELIGIBLE);
				}
				return set_version_headers(new WP_REST_Response(
					(object)array(
						"status" => "success",
						"discount_code" => $discount_code,
						"description" => $coupon_name,
						"discount_type" => "fixed_amount",
						"discount_amount" => (int)round($coupon->discounted_amount * 100)),
					200,
					array('X-Bolt-Cached-Value' => false)
				));
			}
			//try use $discount_code as gift_code
			$gift_info = BCClient::getCollection("/v2/gift_certificates?code=" . urlencode($discount_code));
			BoltLogger::write("gift_info " . print_r($gift_info, true));
			if (isset($gift_info[0]->id)) {
				if ("active" <> $gift_info[0]->status) {
					throw new Exception("Gift sertificate isn't active", SELF::E_BOLT_CODE_NOT_AVAILABLE);
				}
				if (0 == $gift_info[0]->balance) {
					throw new Exception("Gift sertificate limit reached", SELF::E_BOLT_CODE_LIMIT_REACHED);
				}

				try {
					$checkout->add_gift($discount_code);
					exit;
				} catch (Exception $e) {
					BoltLogger::write("BC API EXCEPTION " . $e->getCode() . ") " . $e->getMessage());
					if (400 == $e->getCode()) {
						throw new Exception($e->getMessage(), SELF::E_BOLT_ITEMS_NOT_ELIGIBLE);
					}
				}

			}
			exit;
		} catch (Exception $e) {
			BoltLogger::write("error(" . $e->getCode() . ") " . $e->getMessage());
			return set_version_headers(new WP_REST_Response(
				array(
					'status' => 'error',
					'error' => array(
						'code' => (int)$e->getCode(),
						'message' => $e->getMessage(),
					),
				),
				422,
				array('X-Bolt-Cached-Value' => false)
			));
		}
	}
}