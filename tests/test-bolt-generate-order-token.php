<?php
namespace BoltBigcommerce;

class BoltGenerateOrderTokenTest extends \WP_UnitTestCase {
	//TODO: move to different files
	public function test_constants_defined() {
		$this->assertTrue( defined( 'BIGCOMMERCE_FOR_WORDPRESS_MAIN_PATH' ) );
		$this->assertTrue( defined( 'BOLT_BIGCOMMERCE_VERSION' ) );
		$this->assertTrue( defined( 'BIGCOMMERCE_VERSION' ) );
		$this->assertTrue( defined( 'BoltBigcommerce\Bolt_Discounts_Helper::E_BOLT_INSUFFICIENT_INFORMATION' ) );
		$this->assertTrue( defined( 'BoltBigcommerce\Bolt_Discounts_Helper::E_BOLT_CODE_INVALID' ) );
		$this->assertTrue( defined( 'BoltBigcommerce\Bolt_Discounts_Helper::E_BOLT_CODE_EXPIRED' ) );
		$this->assertTrue( defined( 'BoltBigcommerce\Bolt_Discounts_Helper::E_BOLT_CODE_NOT_AVAILABLE' ) );
		$this->assertTrue( defined( 'BoltBigcommerce\Bolt_Discounts_Helper::E_BOLT_CODE_LIMIT_REACHED' ) );
		$this->assertTrue( defined( 'BoltBigcommerce\Bolt_Discounts_Helper::E_BOLT_MINIMUM_CART_AMOUNT_REQUIRED' ) );
		$this->assertTrue( defined( 'BoltBigcommerce\Bolt_Discounts_Helper::E_BOLT_UNIQUE_EMAIL_REQUIRED' ) );
		$this->assertTrue( defined( 'BoltBigcommerce\Bolt_Discounts_Helper::E_BOLT_ITEMS_NOT_ELIGIBLE' ) );
		$this->assertTrue( defined( 'BoltBigcommerce\Bolt_Discounts_Helper::E_BOLT_SERVICE' ) );
	}


	/**
	 * @dataProvider BigcommerceCartProvider
	 */
	public function test_GenerateCartData_CheckTotalAmountAndDiscount($result, $bigcommerce_cart) {
		//don't want to check products availability in this test
		$bolt_generate_order_token = $this->getMockBuilder('BoltBigcommerce\Bolt_Generate_Order_Token')
			->setMethods( array('check_products_availability') )
			->getMock();
		$bolt_generate_order_token -> expects( $this->any() )
			->method('check_products_availability')
			->will($this->returnValue(true));

		$bolt_data = $bolt_generate_order_token->bolt_generate_cart_data( $bigcommerce_cart );
		$bolt_cart = $bolt_data['cart'];

		//sum of products needs to be equal total_amount+discount-tax
		$total_amount = isset($bolt_cart['total_amount']) ? $bolt_cart['total_amount'] : 0;
		$tax_amount = isset($bolt_cart['tax_amount']) ? $bolt_cart['tax_amount'] : 0;
		$discount = isset($bolt_cart['discounts'][0]['amount']) ? $bolt_cart['discounts'][0]['amount'] : 0;

		$total_sum = $total_amount - $tax_amount + $discount;
		$products_sum = 0;
		foreach ( $bolt_cart["items"] as $item ) {
			$products_sum += $item['total_amount'];
		}
		$this->assertEquals( $total_sum, $products_sum );

		if ('total_amount' == $result["type"] ) {
			$this->assertEquals( $result['value'], $bolt_cart['total_amount']  );
		} elseif ( 'discount' == $result["type"] ) {
			$this->assertEquals( $result['value'], $bolt_cart['discounts'][0]['amount'] );
		}

	}

	public function BigcommerceCartProvider() {
		return array(
			'simple product' => array( array('type'=>'total_amount','value'=>'10000'), $this->CartsData(0)),
			'one product + discount' => array( array('type'=>'discount','value'=>'100'), $this->CartsData(1) ),
			'two product + discount' => array( array('type'=>'total_amount','value'=>'4745'), $this->CartsData(2) ),
			//TODO: add an example with tax
		);
	}

	public function test_GenerateCatData_ProductIsnotAvailable_ReturnFalse () {
		$bolt_generate_order_token = $this->getMockBuilder('BoltBigcommerce\Bolt_Generate_Order_Token')
			->setMethods( array('api_call_get_product') )
			->getMock();
		$bolt_generate_order_token -> expects( $this->any() )
			->method('api_call_get_product')
			->will($this->returnValue(
				(object) array
				(
					'id' => 113,
					'name' => 'simple test product',
					'sku' => 'test2',
					'calculated_price' => 20.0000,
					'is_visible' => 1,
					'is_featured' => '',
					'inventory_level' => 0,
					'inventory_warning_level' => 0,
					'inventory_tracking' => 'none',
					'availability' => 'disabled',
				)
			));
		$this->assertFalse( $bolt_generate_order_token->bolt_generate_cart_data( $this->CartsData(0 ) ) );
		$this->assertEquals( $bolt_generate_order_token->getError(), 'Product \'simple test product\' is currently unavailable' );
	}

	public function test_GenerateCatData_ProductIsnotEnough_ReturnFalse () {
		$bolt_generate_order_token = $this->getMockBuilder('BoltBigcommerce\Bolt_Generate_Order_Token')
			->setMethods( array('api_call_get_product') )
			->getMock();
		$bolt_generate_order_token -> expects( $this->any() )
			->method('api_call_get_product')
			->will($this->returnValue( (object) array
			(
				'id' => 113,
				'name' => 'simple test product',
				'sku' => 'test2',
				'calculated_price' => 20.0000,
				'is_visible' => 1,
				'is_featured' => '',
				'inventory_level' => 4,
				'inventory_warning_level' => 0,
				'inventory_tracking' => 'simple',
				'availability' => 'available',
			)));
		$this->assertFalse( $bolt_generate_order_token->bolt_generate_cart_data( $this->CartsData(0 ) ) );
		$this->assertEquals( $bolt_generate_order_token->getError(), 'We have only 4 items of \'simple test product\' on our stock' );
	}

	public function test_GenerateCatData_ProductInStock_ReturnArray () {
		$bolt_generate_order_token = $this->getMockBuilder('BoltBigcommerce\Bolt_Generate_Order_Token')
			->setMethods( array('api_call_get_product') )
			->getMock();
		$bolt_generate_order_token -> expects( $this->any() )
			->method('api_call_get_product')
			->will($this->returnValue( (object) array
			(
				'id' => 113,
				'name' => 'simple test product',
				'sku' => 'test2',
				'calculated_price' => 20.0000,
				'is_visible' => 1,
				'is_featured' => '',
				'inventory_level' => 5,
				'inventory_warning_level' => 0,
				'inventory_tracking' => 'simple',
				'availability' => 'available',
			)));
		$this->assertInternalType('array', $bolt_generate_order_token->bolt_generate_cart_data( $this->CartsData(0 ) ) );
	}

	public function CartsData($id) {
		switch ($id) {
			case 0:
				//one simple product 5 * $20
				return json_decode( '{
  "cart_id": "2e095089-3fd0-4f0e-aaa7-68fea86dcedf",
  "base_amount": {
    "raw": 100,
    "formatted": "$100.00"
  },
  "discount_amount": {
    "raw": 0,
    "formatted": "Free"
  },
  "cart_amount": {
    "raw": 100,
    "formatted": "$100.00"
  },
  "tax_included": false,
  "items": {
    "fe4cca40-e807-4232-98e7-e581bba383a9": {
      "id": "fe4cca40-e807-4232-98e7-e581bba383a9",
      "variant_id": 123,
      "product_id": 113,
      "name": "simple test product",
      "quantity": 5,
      "list_price": {
        "raw": 20,
        "formatted": "$20.00"
      },
      "sale_price": {
        "raw": 20,
        "formatted": "$20.00"
      },
      "total_list_price": {
        "raw": 100,
        "formatted": "$100.00"
      },
      "total_sale_price": {
        "raw": 100,
        "formatted": "$100.00"
      },
      "post_id": 90,
      "thumbnail_id": "0",
      "is_featured": false,
      "on_sale": false,
      "sku": {
        "product": "test2",
        "variant": "test2"
      },
      "options": [],
      "minimum_quantity": 0,
      "maximum_quantity": 0,
      "inventory_level": -1,
      "bigcommerce_availability": [
        {
          "id": 21,
          "label": "available",
          "slug": "available"
        }
      ],
      "bigcommerce_condition": [
        {
          "id": 22,
          "label": "New",
          "slug": "new"
        }
      ],
      "bigcommerce_product_type": [
        {
          "id": 23,
          "label": "physical",
          "slug": "physical"
        }
      ],
      "bigcommerce_brand": [],
      "bigcommerce_category": [
        {
          "id": 19,
          "label": "Shop All",
          "slug": "shop-all"
        }
      ]
    }
  },
  "tax_amount": {
    "raw": 0,
    "formatted": "Free"
  },
  "subtotal": {
    "raw": 100,
    "formatted": "$100.00"
  }
}', true);
				break;
			case 1:
				//one product + discount
				return json_decode( '{
  "cart_id": "2e095089-3fd0-4f0e-aaa7-68fea86dcedf",
  "base_amount": {
    "raw": 20,
    "formatted": "$20.00"
  },
  "discount_amount": {
    "raw": 1,
    "formatted": "$1.00"
  },
  "cart_amount": {
    "raw": 19,
    "formatted": "$19.00"
  },
  "tax_included": false,
  "items": {
    "92cf936d-1639-45ad-a7f7-9020772a3955": {
      "id": "92cf936d-1639-45ad-a7f7-9020772a3955",
      "variant_id": 78,
      "product_id": 112,
      "name": "test product",
      "quantity": 2,
      "list_price": {
        "raw": 10,
        "formatted": "$10.00"
      },
      "sale_price": {
        "raw": 10,
        "formatted": "$10.00"
      },
      "total_list_price": {
        "raw": 20,
        "formatted": "$20.00"
      },
      "total_sale_price": {
        "raw": 20,
        "formatted": "$20.00"
      },
      "post_id": 85,
      "thumbnail_id": "0",
      "is_featured": false,
      "on_sale": false,
      "sku": {
        "product": "test1",
        "variant": "SKU-E1148EF3"
      },
      "options": [
        {
          "label": "Size",
          "key": null,
          "value": "XS",
          "value_id": null
        },
        {
          "label": "Color",
          "key": null,
          "value": "Silver",
          "value_id": null
        }
      ],
      "minimum_quantity": 0,
      "maximum_quantity": 2,
      "inventory_level": 2,
      "bigcommerce_availability": [
        {
          "id": 21,
          "label": "available",
          "slug": "available"
        }
      ],
      "bigcommerce_condition": [
        {
          "id": 22,
          "label": "New",
          "slug": "new"
        }
      ],
      "bigcommerce_product_type": [
        {
          "id": 23,
          "label": "physical",
          "slug": "physical"
        }
      ],
      "bigcommerce_brand": [],
      "bigcommerce_category": [
        {
          "id": 19,
          "label": "Shop All",
          "slug": "shop-all"
        }
      ]
    }
  },
  "tax_amount": {
    "raw": 0,
    "formatted": "Free"
  },
  "subtotal": {
    "raw": 19,
    "formatted": "$19.00"
  }
}', true);
				break;
			case 2:
				//two product + discount
				return json_decode( '{
  "cart_id": "2e095089-3fd0-4f0e-aaa7-68fea86dcedf",
  "base_amount": {
    "raw": 49.95,
    "formatted": "$49.95"
  },
  "discount_amount": {
    "raw": 2.5,
    "formatted": "$2.50"
  },
  "cart_amount": {
    "raw": 47.45,
    "formatted": "$47.45"
  },
  "tax_included": false,
  "items": {
    "92cf936d-1639-45ad-a7f7-9020772a3955": {
      "id": "92cf936d-1639-45ad-a7f7-9020772a3955",
      "variant_id": 78,
      "product_id": 112,
      "name": "test product",
      "quantity": 2,
      "list_price": {
        "raw": 10,
        "formatted": "$10.00"
      },
      "sale_price": {
        "raw": 10,
        "formatted": "$10.00"
      },
      "total_list_price": {
        "raw": 20,
        "formatted": "$20.00"
      },
      "total_sale_price": {
        "raw": 20,
        "formatted": "$20.00"
      },
      "post_id": 85,
      "thumbnail_id": "0",
      "is_featured": false,
      "on_sale": false,
      "sku": {
        "product": "test1",
        "variant": "SKU-E1148EF3"
      },
      "options": [
        {
          "label": "Size",
          "key": null,
          "value": "XS",
          "value_id": null
        },
        {
          "label": "Color",
          "key": null,
          "value": "Silver",
          "value_id": null
        }
      ],
      "minimum_quantity": 0,
      "maximum_quantity": 2,
      "inventory_level": 2,
      "bigcommerce_availability": [
        {
          "id": 21,
          "label": "available",
          "slug": "available"
        }
      ],
      "bigcommerce_condition": [
        {
          "id": 22,
          "label": "New",
          "slug": "new"
        }
      ],
      "bigcommerce_product_type": [
        {
          "id": 23,
          "label": "physical",
          "slug": "physical"
        }
      ],
      "bigcommerce_brand": [],
      "bigcommerce_category": [
        {
          "id": 19,
          "label": "Shop All",
          "slug": "shop-all"
        }
      ]
    },
    "46994fed-a70f-48fd-997e-8ac6e2b12efe": {
      "id": "46994fed-a70f-48fd-997e-8ac6e2b12efe",
      "variant_id": 70,
      "product_id": 98,
      "name": "[Sample] Laundry Detergent",
      "quantity": 1,
      "list_price": {
        "raw": 29.95,
        "formatted": "$29.95"
      },
      "sale_price": {
        "raw": 29.95,
        "formatted": "$29.95"
      },
      "total_list_price": {
        "raw": 29.95,
        "formatted": "$29.95"
      },
      "total_sale_price": {
        "raw": 29.95,
        "formatted": "$29.95"
      },
      "post_id": 74,
      "thumbnail_id": "0",
      "is_featured": false,
      "on_sale": false,
      "sku": {
        "product": "CGLD",
        "variant": "CGLD"
      },
      "options": [],
      "minimum_quantity": 0,
      "maximum_quantity": 2,
      "inventory_level": 2,
      "bigcommerce_availability": [
        {
          "id": 21,
          "label": "available",
          "slug": "available"
        }
      ],
      "bigcommerce_condition": [
        {
          "id": 22,
          "label": "New",
          "slug": "new"
        }
      ],
      "bigcommerce_product_type": [
        {
          "id": 23,
          "label": "physical",
          "slug": "physical"
        }
      ],
      "bigcommerce_brand": [
        {
          "id": 32,
          "label": "Common Good",
          "slug": "common-good"
        }
      ],
      "bigcommerce_category": [
        {
          "id": 19,
          "label": "Shop All",
          "slug": "shop-all"
        },
        {
          "id": 31,
          "label": "Utility",
          "slug": "utility"
        }
      ]
    }
  },
  "tax_amount": {
    "raw": 0,
    "formatted": "Free"
  },
  "subtotal": {
    "raw": 47.45,
    "formatted": "$47.45"
  }
}', true);
				break;
		}

	}




}
