<?php

/**
 * WooCommerce BLAZE Webhook Response to update membership status
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
    $consumerId = $data->consumerId;
    $membershipAccepted = $data->membershipAccepted;
    if ($consumerId) {
        $user = reset(get_users(
                        array(
                            'meta_key' => 'Blaze_woo_user_id',
                            'meta_value' => $consumerId,
                            'number' => 1,
                            'count_total' => false
                        )
        ));
        $user_id = $user->ID;
        update_usermeta($user_id, 'membershipAccepted', $membershipAccepted);
    }
}