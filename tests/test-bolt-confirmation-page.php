<?php
namespace BoltBigcommerce;

class BoltConfirmationPageTest extends \WP_UnitTestCase
{
	public function test_GetURL_PageNotExistsBefore_URLnonEmpty() {
		$confirmation_page = new Bolt_Confirmation_Page();
		$confirmation_page_url = $confirmation_page->get_url();
		$this->assertNotEmpty($confirmation_page_url);
	}
}