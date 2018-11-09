<?php

if ( !defined( 'ABSPATH' ) ) {
	exit;
}

class Bolt_Confirmation_Page
{
	public function __construct()
	{
		add_shortcode( 'bolt-confirmation', array( $this, 'shortcode' ) );
	}

	public function shortcode( $atts )
	{
		if ( $_SESSION["bolt_order_id"] ) {
			$result = "<h1>Thank you!</h1><p>Your order number is {$_SESSION["bolt_order_id"]}";
		} else {
			$result = "Error";
		}
		return $result;
	}

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
		return (int)$post_ids[0];
	}

	private function create_post()
	{
		$args = array(
			'post_type' => 'page',
			'post_status' => 'publish',
			'post_title' => 'Thank you for your order',
			'post_name' => 'order confitmation',
			'post_content' => "[bolt-confirmation]",
			'comment_status' => 'closed',
			'ping_status' => 'closed',
		);
		$post_id = wp_insert_post( $args );
		return $post_id;
	}


	public function get_url()
	{
		return get_permalink( $this->get_post_id() );
	}
}