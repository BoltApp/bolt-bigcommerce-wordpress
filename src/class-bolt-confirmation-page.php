<?php
namespace BoltBigcommerce;

if ( !defined( 'ABSPATH' ) ) {
	exit;
}

class Bolt_Confirmation_Page
{
	public function __construct()
	{
		add_shortcode( 'bolt-confirmation', array( $this, 'shortcode' ) );
	}

	/**
	 * Generate confirmation page
	 *
	 * @return string page HTML code
	 */
	//TODO Add test when Bigcommerce became open beta
	public function shortcode()
	{
		if ( $_SESSION["bolt_order_id"] ) {
			$order_id = $_SESSION["bolt_order_id"];
			$customer = new \BigCommerce\Accounts\Customer( get_current_user_id() );
			$order    = $customer->get_order_details( $order_id );
			if ( empty( $order ) ) {
				$controller = \BigCommerce\Templates\Order_Not_Found::factory([]);
			} else {
				$controller = \BigCommerce\Templates\Order_Details::factory( [ \BigCommerce\Templates\Order_Details::ORDER => $order ] );
			}
			$result = $controller->render();
		} else {
			$result = "Error";
		}
		return $result;
	}

	/**
	 * Retutn post_id of page with chortcode 'bolt-confirmation'
	 * Create page if it doesn't exit
	 *
	 * @return int post_id
	 */
	private function get_post_id()
	{
		$post_id = (int)get_option( "bolt-confirmation-post-id", 0 );
		if ( $post_id == 0 ) {
			$post_id = $this->get_post_candidate();
			if ( $post_id == 0 ) {
				$post_id = $this->create_post();
			}
			update_option( "bolt-confirmation-post-id", $post_id );
		}
		return $post_id;
	}

	/**
	 * Find all posts that contains chortcode 'bolt-confirmation'
	 * Return id of the first one
	 *
	 * @return int post_id
	 */
	private function get_post_candidate()
	{
		global $wpdb;
		$content = "[bolt-confirmation]";
		$content_like = '%' . $wpdb->esc_like( $content ) . '%';
		$post_ids = $wpdb->get_col( $wpdb->prepare(
			"SELECT ID FROM {$wpdb->posts} WHERE post_type=%s AND post_status='publish' AND post_content LIKE %s",
			'page',
			$content_like
		) );
		return isset($post_ids[0]) ? (int)$post_ids[0] : 0;
	}

	/**
	 * Create post and return it's id
	 *
	 * @return int post_id
	 */
	private function create_post()
	{
		$args = array(
			'post_type' => 'page',
			'post_status' => 'publish',
			'post_title' => 'Thanks for your order',
			'post_name' => 'order confirmation',
			'post_content' => "[bolt-confirmation]",
			'comment_status' => 'closed',
			'ping_status' => 'closed',
		);
		$post_id = wp_insert_post( $args );
		if (!$post_id) {
			BugsnagHelper::getBugsnag()->notifyException( new \Exception( "Can't create page" ) );
		}

		return $post_id;
	}


	/**
	 * Return Confirmation page full url
	 * Create page if it doesn't exit
	 *
	 * @return string url
	 */
	public function get_url()
	{
		return get_permalink( $this->get_post_id() );
	}
}