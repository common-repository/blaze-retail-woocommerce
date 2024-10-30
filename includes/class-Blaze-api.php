<?php

/**
 * WooCommerce BLAZE Integration API
 *
 * @author      BLAZE
 * @category    API
 * @package     WooCommerce/API
 */
if (!defined('ABSPATH'))
    exit;

class Blaze_woo_API {

    const Blaze_API_PATH = '/api/v1/store/inventory/';

    private $dev_mode = false;

    /** @var String domain */
    private $domain;

    /** @var String apikey */
    private $apikey;

    /** @var Bool debug */
    private $debug;

    public function __construct() {
        $this->domain = get_option('Blaze_api_domain');
        $this->apikey = get_option('Blaze_api_key');
        $this->dev_mode = get_option('Blaze_api_dev_mode');
        $this->debug = get_option('Blaze_api_debug');
    }

    public function setDebug($value) {
        update_option('Blaze_api_debug', $value);
    }

    public function setDevMode($key) {
        if (strlen($key)) {
            update_option('Blaze_api_dev_mode', true);
            update_option('Blaze_api_dev_key', $key);
        } else {
            update_option('Blaze_api_dev_mode', false);
            update_option('Blaze_api_dev_key', '');
        }
    }

    public function setApiKey($apikey) {
        $this->apikey = $apikey;
    }

    public function setDomain($domain) {
        $this->domain = $domain;
    }

    public function getDomain() {
        return $this->domain;
    }

    /**
     * @param $method String
     * @param $params Array
     * @return Blaze_API_response
     */
    private function executeMethod($method, $params) {

        $timestamp = $params['timestamp'];
        $error_logger = new Blaze_error_logger();
        $params['api_key'] = $this->apikey;
        $use_new_api_to_sync = $params['use_new_api_to_sync'];
	    //$multi_shops = get_option("blaze_enable_multi_shop");
        //$result =  $multi_shops == "yes" ? "true" : "false";
	    $result = "false";
        $path = $this->domain . self::Blaze_API_PATH . $method . "?api_key=" . $params['api_key'] . "&multishop=" . $result;
        if($method == "products"){
            if($timestamp != 0 && $use_new_api_to_sync){
                $path .= '&modified=true&afterDate='.$timestamp;
            }
        }
        /*
        foreach($params as $key => $param) {
            $error_logger->log($key);
            if($key == "limit" ||  $key == "skip" || $key == "page") {
                $path = $path . "&" . $key . "=" . $param ;
            }
        } 
        */
        $error_logger->log($path);
        $response = new Blaze_API_response();
		$data = wp_remote_get(esc_url_raw($path), array(
            'timeout' => 600,
        ));
        //$response = json_decode($data['body']);
        if(is_wp_error($data)) {
            $error_logger->log("API Error when executing sync");
            $error_logger->log(print_r($data,true));
        }
        $response->setRawResponse(wp_remote_retrieve_body( $data ));
        $response->setStatusCode(wp_remote_retrieve_response_code($data));
        //curl_close($this->ch);
        return $response;
    }

    public function testConnection() {
        $response = $this->executeMethod('test', array('text' => 'carrot'));
        if ($response->getMessage() == 'Your text is "carrot"') {
            return true;
        } else {
            return false;
        }
    }

    public function getShippingSettings() {
        $response = $this->executeMethod('getShippingSettings', array());

        return $response->getData();
    }

    public function executeSync($syncId, $modelName, $page, $limit, $timestamp, $useNewApiToSync) {
        $response = $this->executeMethod($modelName, array(
            'sync_id' => $syncId,
            'model_name' => $modelName,
            'page' => $page,
            'limit' => $limit,
            'timestamp' => $timestamp,
            'use_new_api_to_sync' => $useNewApiToSync
        ));

        Blaze_connection::checkStatusCode($response->getStatusCode());
        return $response;
    }

}

class Blaze_API_response {

    public $status_code = 0;
    public $message = '';
    public $data = '';
    public $success = true;
    public $raw_response = '';

    function __construct() {
        
    }

    public function fromJson($json) {
        $tmp = json_decode($json, true);
        if (isset($tmp['message'])) {
            $this->setMessage($tmp['message']);
        }
    }

    /**
     * @param string $data
     */
    public function setData($data) {
        $this->data = $data;
    }

    /**
     * @return string
     */
    public function getData() {
        return $this->data;
    }

    /**
     * @param string $message
     */
    public function setMessage($message) {
        $this->message = $message;
    }

    /**
     * @return string
     */
    public function getMessage() {
        return $this->message;
    }

    /**
     * @param int $status_code
     */
    public function setStatusCode($status_code) {
        $this->status_code = $status_code;
    }

    /**
     * @return int
     */
    public function getStatusCode() {
        return $this->status_code;
    }

    /**
     * @param boolean $success
     */
    public function setSuccess($success) {
        $this->success = $success;
    }

    /**
     * @return boolean
     */
    public function getSuccess() {
        return $this->success;
    }

    /**
     * @param string $raw_response
     */
    public function setRawResponse($raw_response) {
        $this->raw_response = $raw_response;
        $this->fromJson($raw_response);
    }

    /**
     * @return string
     */
    public function getRawResponse() {
        return $this->raw_response;
    }

}
