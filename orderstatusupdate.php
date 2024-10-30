<?php

/**
 * WooCommerce BLAZE Webhook Response Handler
 *
 * @author      BLAZE
 * @category    API
 * @package     WooCommerce/API
 */
require_once dirname(dirname(dirname(dirname(__FILE__)))) . '/wp-config.php';

global $wpdb;
global $woocommerce;

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $json_params = file_get_contents("php://input");
    $data = json_decode($json_params);
    $orderstatus = $data->orderStatus;
    if ($orderstatus == "InProgress") {
        $orderstatus = "processing";
    } elseif ($orderstatus == "Placed") {
        $orderstatus = "placed";
    } elseif ($orderstatus == "Accepted") {
        $orderstatus = "accepted";
    } elseif ($orderstatus == "Completed") {
        $orderstatus = "completed";
    } elseif ($orderstatus == "Declined") {
        $orderstatus = "declined";
    } elseif ($orderstatus == "CanceledByConsumer") {
        $orderstatus = "cancelconsumer";
    } elseif ($orderstatus == "CanceledByDispensary") {
        $orderstatus = "canceldispensary";
    }

    $Blazewooorderid = $data->consumerOrderNo;
    $args = array(
        'post_type' => 'shop_order',
        'meta_key' => 'Blaze_woo_order_id',
        'meta_value' => $Blazewooorderid,
        'compare' => '=',
        'post_status' => array('wc-pending', 'wc-processing', 'wc-on-hold', 'wc-completed', 'wc-cancelled', 'wc-refunded', 'wc-failed', 'wc-placed', 'wc-accepted', 'wc-declined', 'wc-cancelconsumer', 'wc-canceldispensary'),
        'post_per_page' => -1,
        'nopaging' => true,
    );

    $query = new WP_Query($args);
    if ($query->have_posts()): while ($query->have_posts()): $query->the_post();
            echo $orderId = get_the_ID();
            $order = new WC_Order($orderId);
            $order->update_status($orderstatus, 'order_note');
        endwhile;
    endif;
    // reset $post
    wp_reset_postdata();
}
