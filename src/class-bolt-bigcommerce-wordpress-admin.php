<?php
namespace BoltBigcommerce;

if ( !defined( 'ABSPATH' ) ) {
	exit;
}

require_once('class-bolt-bigcommerce-wordpress.php');

/*
* Bolt_Bigcommerce_Wordpress_Admin class for setting Bolt settings.
*/

class Bolt_Bigcommerce_Wordpress_Admin extends Bolt_Bigcommerce_Wordpress
{
	private $form_fields;
	/**
	 * Set up base actions
	 */
	public function init()
	{
		add_action( 'admin_menu', array( $this, 'admin_menu' ) );
		$this->init_form_fields();
		parent::init();
	}

	/**
	 * Add plugin to wordpress admin menu
	 */
	public function admin_menu()
	{
		add_submenu_page( 'edit.php?post_type=bigcommerce_product', 'Bolt', 'Bolt', 'manage_options', 'bolt-bigcommerce', array( $this, 'settings' ) );
		foreach ( $this->form_fields as $key => $form_field ) {
			register_setting( 'bolt-bigcommerce', "bolt-bigcommerce_{$key}" );
		}
	}

	/**
	 * Initialize $this->form_fields - array of settings page fields
	 */
	public function init_form_fields()
	{
		/**
		 * Settings for Bolt Payment Gateway.
		 */
		$this->form_fields = array(
			'api_key' => array(
				'title' => __( 'Api Key', 'bolt-bigcommerce-wordpress' ),
				'type' => 'text',
				'description' => __( 'Used when calling Bolt API from your server.', 'bolt-bigcommerce-wordpress' ),
				'default' => '',
				'desc_tip' => true,
				'placeholder' => __( 'Enter Api Key', 'bolt-bigcommerce-wordpress' ),
			),
			'signing_secret' => array(
				'title' => __( 'Signing Secret', 'bolt-bigcommerce-wordpress' ),
				'type' => 'text',
				'description' => __( 'Used to authenticate the signature of the payload from Bolt server.', 'bolt-bigcommerce-wordpress' ),
				'default' => '',
				'desc_tip' => true,
				'placeholder' => __( 'Enter Payment secret key', 'bolt-bigcommerce-wordpress' ),
			),
			'publishable_key' => array(
				'title' => __( 'Publishable Key', 'bolt-bigcommerce-wordpress' ),
				'type' => 'text',
				'description' => __( 'Embedded in your website and used to identify you as a merchant. Typically used on the checkout page.', 'bolt-bigcommerce-wordpress' ),
				'default' => '',
				'desc_tip' => true,
				'placeholder' => __( 'Enter Processing Key', 'bolt-bigcommerce-wordpress' ),
			),
			'testmode'           => array(
				'title'       => __( 'Bolt sandbox', 'bolt-bigcommerce-wordpress' ),
				'type'        => 'checkbox',
				'label'       => __( 'Enable Bolt sandbox', 'bolt-bigcommerce-wordpress' ),
				'default'     => 'yes',
				'description' => __( 'Bolt sandbox can be used to test payments.', 'bolt-bigcommerce-wordpress' ),
			),
			'paymentaction'   => array(
				'title'       => __( 'Payment action', 'bolt-bigcommerce-wordpress' ),
				'type'        => 'select',
				'class'       => 'wc-enhanced-select',
				'description' => __( 'Choose whether you wish to capture funds immediately when Bolt approves order or authorize payment only.', 'bolt-bigcommerce-wordpress' ),
				'default'     => 'true',
				'desc_tip'    => true,
				'options'     => array(
					'true'    => __( 'Capture', 'bolt-bigcommerce-wordpress' ),
					'false'   => __( 'Authorize', 'bolt-bigcommerce-wordpress' ),
				),
			),


		);
	}

	/**
	 * Output form with settings on admin page
	 */
	public function settings()
	{
		$admin_url = admin_url();
		echo "<h2>" .
			__( 'Bolt setup', 'bolt-bigcommerce-wordpress' ) .
			"</h2>";
		echo '<form action="' . admin_url() . 'options.php" method="POST">';
		settings_fields( 'bolt-bigcommerce' );
		echo '<table class="form-table">';
		$this->generate_settings_html();
		do_settings_sections( 'bolt-bigcommerce' );
		echo '</table>';
		submit_button();
		echo '</form>';
	}

	public function generate_settings_html() {
		foreach ( $this->form_fields as $key => $form_field ) {
			$name = "bolt-bigcommerce_{$key}";

			if ( method_exists( $this, 'generate_' . $form_field['type'] . '_html' ) ) {
				$this->{'generate_' . $form_field['type'] . '_html'}( $key, $name, $form_field );
			} else {
				$this->generate_text_html( $key, $name, $form_field );
			}
		}
	}

	public function generate_text_html( $key, $name, $form_field ) {
		echo '<tr>
    <th scope="row"><label for="' . $name . '">' . $form_field["title"] . '</label></th>
    <td><input name="' . $name . '" type="text" id="' . $name . '" aria-describedby="' . $name . '-description" value="' . $this->get_option( $key ) . '" class="regular-text" />
    <p class="description" id="' . $name . '-description">' . $form_field["description"] . '</p></td>
    </tr>';
	}

	public function generate_checkbox_html( $key, $name, $form_field ) {
		$value = $this->get_option( $key , $form_field['default'] );
		echo '<tr>
	<th scope="row">' . $form_field["title"] . '</th>
	<td> <fieldset><legend class="screen-reader-text"><span>' . $form_field["title"] . '</span></legend><label for="' . $name . '">
	<input name="' . $name . '" type="checkbox" id="' . $name . '" value="' . $form_field['default'] . '" ' . ( 'yes' == $value ? 'checked' : '') . ' />
	' . $form_field["label"] . '</label>
	<p class="description" id="' . $name . '-description">' . $form_field["description"] . '</p>
</fieldset></td>
</tr>';
	}

	public function generate_select_html( $key, $name, $form_field ) {
		$value = $this->get_option( $key );
		if ( false === $value ) $value = $form_field['default'];
		echo '<tr>
	<th scope="row"><label for="' . $name . '">' . $form_field["title"] . '</label></th>
	<td>
	<select name="' . $name . '" id="' . $name . '">';
		foreach ( $form_field["options"] as $option_name => $option_value ) {
			echo '<option ' . ($option_name == $value ? 'selected="selected" ' : '') . 'value="'. $option_name . '">' . $option_value . '</option>';
		}
		echo '</select><p class="description" id="' . $name . '-description">' . $form_field["description"] . '</p>';
	}


}