<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once( 'class-bolt-bigcommerce-wordpress.php' );

/*
* Bolt_Bigcommerce_Wordpress_Admin class for setting Bolt settings.
*/
class Bolt_Bigcommerce_Wordpress_Admin extends Bolt_Bigcommerce_Wordpress
{
	//Set up base actions    
	public function init() {
		add_action( 'admin_menu', array( $this, 'admin_menu' ) );
  $this->init_form_fields();
	}

	//Add plugin to wordpress admin menu.
	public function admin_menu() {
  //TODO: move to Bigcommerce as submenu page
  add_menu_page( 'Bolt', 'Bolt', 'manage_options', 'bolt-bigcommerce', array( $this, 'settings' ) );	
  foreach ($this->form_fields as $key=>$form_field) {
   register_setting( 'bolt-bigcommerce', "bolt-bigcommerce_{$key}" );
  }  
	}
 
 public function init_form_fields() {
       /**
         * Settings for Bolt Payment Gateway.
         */
  $this->form_fields = array(
   'merchant_key'       => array(
    'title'       => __( 'Api Key', 'bolt-bigcommerce-wordpress' ),
    'type'        => 'text',
    'description' => __( 'Used when calling Bolt API from your server.', 'bolt-bigcommerce-wordpress' ),
    'default'     => '',
    'desc_tip'    => true,
    'placeholder' => __( 'Enter Api Key', 'bolt-bigcommerce-wordpress' ),
   ),
   'payment_secret_key' => array(
    'title'       => __( 'Signing Secret', 'bolt-bigcommerce-wordpress' ),
    'type'        => 'text',
    'description' => __( 'Used to authenticate the signature of the payload from Bolt server.', 'bolt-bigcommerce-wordpress' ),
    'default'     => '',
    'desc_tip'    => true,
    'placeholder' => __( 'Enter Payment secret key', 'bolt-bigcommerce-wordpress' ),
   ),
   'processing_key'     => array(
    'title'       => __( 'Publishable Key (Payment Only)', 'bolt-bigcommerce-wordpress' ),
    'type'        => 'text',
    'description' => __( 'Embedded in your website and used to identify you as a merchant. Typically used on the checkout page.', 'bolt-bigcommerce-wordpress' ),
    'default'     => '',
    'desc_tip'    => true,
    'placeholder' => __( 'Enter Processing Key', 'bolt-bigcommerce-wordpress' ),
   ),
  );
 }

 
	//Output form with settings.
	public function settings() {
  $admin_url = admin_url();
  echo "<h2>" .
  __( 'Bolt setup', 'bolt-bigcommerce-wordpress' ) .
  "</h2>";
  echo '<form action="'.admin_url().'options.php" method="POST">';  
  settings_fields( 'bolt-bigcommerce' );
  echo '<table class="form-table">';
  foreach ($this->form_fields as $key=>$form_field) {
   $name = "bolt-bigcommerce_{$key}";
   echo '<tr>
    <th scope="row"><label for="'.$name.'">'.$form_field["title"].'</label></th>
    <td><input name="'.$name.'" type="text" id="'.$name.'" aria-describedby="'.$name.'-description" value="'.$this->get_option($key).'" class="regular-text" />
    <p class="description" id="'.$name.'-description">'.$form_field["description"].'</p></td>
    </tr>';
  }
  do_settings_sections( 'bolt-bigcommerce' );  
  echo '</table>';
  submit_button();  
  echo '</form>';
	} 
 
}