<?php

/**
 * WooCommerce BLAZE Connection
 *
 * @author      BLAZE
 * @category    API
 * @package     WooCommerce/API
 */
if (!defined('ABSPATH'))
    exit;

class Blaze_connection {

    public static function isConnected() {
        return get_option('Blaze_is_connected', 'no') === 'yes';
    }

    public static function testConnection($domain = false, $api_key = false) {
        $api = new Blaze_API();

        if ($domain || $api_key) {
            $api->setDomain($domain);
            $api->setApiKey($api_key);
        }

        self::setIsConnected($api->testConnection());
    }

    public static function checkStatusCode($code) {
        $is_connected = $code == 200;
        self::setIsConnected($is_connected);
    }

    private static function setIsConnected($is_connected) {
        $option = $is_connected ? 'yes' : 'no';

        if (!get_option('Blaze_is_connected')) {
            add_option('Blaze_is_connected', $option);
        } else {
            update_option('Blaze_is_connected', $option);
        }
    }

}
