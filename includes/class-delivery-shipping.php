<?php

class WC_Delivery_Shipping_Method extends WC_Shipping_Method{
 
  	public function __construct( $instance_id = 0 ) {
  	   
		$this->id                    = 'legacy_local_delivery';
		$this->instance_id           = absint( $instance_id );
		$this->method_title          = __( 'Local Delivery' );
		$this->method_description    = __( 'Delivery Shipping method for demonstration purposes.' );
		$this->supports              = array(
			'shipping-zones',
			'instance-settings',
		);
		$this->instance_form_fields = array(
			'enabled' => array(
				'title' 		=> __( 'Enable/Disable' ),
				'type' 			=> 'checkbox',
				'label' 		=> __( 'Enable this shipping method' ),
				'default' 		=> 'yes',
			),
			'title' => array(
				'title' 		=> __( 'Method Title' ),
				'type' 			=> 'text',
				'description' 	=> __( 'This controls the title which the user sees during checkout.' ),
				'default'		=> __( 'Delivery' ),
				'desc_tip'		=> true
			)
		);
		$this->enabled              = $this->get_option( 'enabled' );
		$this->title                = $this->get_option( 'title' );

		add_action( 'woocommerce_update_options_shipping_' . $this->id, array( $this, 'process_admin_options' ) );
		
		
	}
	public function calculate_shipping( $package = array() ) {
	$this->add_rate( array(
		'id'    => $this->id,
		'label' => $this->title,
		'cost'  => 0,
	) );
}
 
 
}