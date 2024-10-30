<?php

class WC_LOCAL_DELIVERY_SHIPPING_METHOD
{
	
	function __construct() {
	 	add_filter( 'woocommerce_shipping_methods',array($this,'add_wc_delivery_shipping_method'));
		 add_action( 'woocommerce_shipping_init',array($this,'wc_delivery_shipping_method_init') ); 
	}
		 
	function add_wc_delivery_shipping_method( $methods ) {
	   $methods['legacy_local_delivery'] = 'WC_Delivery_Shipping_Method';
	   return $methods;
	 }
	 
	 function wc_delivery_shipping_method_init(){
	   require_once 'class-delivery-shipping.php';
	 }
 
}
new WC_LOCAL_DELIVERY_SHIPPING_METHOD();

class WC_LOCAL_PICKUP_SHIPPING_METHOD
{
	
	function __construct() {
	    
	 	add_filter( 'woocommerce_shipping_methods',array($this,'add_wc_pickup_shipping_method'));
		 add_action( 'woocommerce_shipping_init',array($this,'wc_pickup_shipping_method_init') ); 
	}
		 
	function add_wc_pickup_shipping_method( $methods ) {
	   $methods['legacy_local_pickup_1'] = 'WC_Pickup_Shipping_Method';
	   return $methods;
	 }
	 
	 function wc_pickup_shipping_method_init(){
	   require_once 'class-pickup-shipping.php';
	 }
 
}
new WC_LOCAL_PICKUP_SHIPPING_METHOD();


?>