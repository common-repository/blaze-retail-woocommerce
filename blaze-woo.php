<?php
/*
  Plugin Name: Blaze Retail WooCommerce
  Plugin URI: http://support.blaze.me
  Version: 2.5.2
  Description: This plugin allows you to integrate your Blaze store with WooCommerce store.
  Author: BLAZE
  Author URI: http://blaze.me
  Text Domain: blaze-woo-integration
  Domain Path: /languages/
  License: GNU General Public License v3.0
  License URI: http://www.gnu.org/licenses/gpl-3.0.html
 */
load_plugin_textdomain('blaze-woo-integration', false, dirname(plugin_basename(__FILE__)) . '/languages/');

/**
 * WooBlaze_Retail class
 *
 */
class WooBlaze_Retail {

    /**
     * @var null|Blaze_API
     */
    public $api = null;

    /**
     * @var null|Blaze_user_sync
     */
    public $userSync = null;
    public static $_instance = null;

    public static function instance() {
        if (is_null(self::$_instance)) {
            self::$_instance = new self();
        }
        return self::$_instance;
    }

    public function __construct() {
        // Define constants
        $this->define_constants();

        if (!$this->checkWC()) {
            add_action('admin_notices', array($this, 'woocommerce_error_notice'));
            return;
        }

        // Include required files
        $this->includes();

        $this->api = new Blaze_woo_API();
        $this->sync = new Blaze_woo_sync();
        $this->userSync = new Blaze_woo_user_sync(true);
        $this->cart = new Blaze_woo_cart();
        $Blaze_sync_id = get_option('Blaze_sync_id', 0);

        if ($Blaze_sync_id > 0) {
            $lasr_sync_desc = ' (Last Sync on ' . date('m.d.Y', $Blaze_sync_id) . ')';
        } else {
            $lasr_sync_desc = '';
        }

        // Init settings
        $this->settings = array(
            array(
                'name' => __('BLAZE Integration', 'blaze-woo-integration'),
                'desc' => '<p>' . __('This section lets you customise the Blaze Integration.', 'blaze-woo-integration') . ' <a href="' . admin_url('admin.php?page=wc-settings&tab=general&Blaze_resync=1') . '">Click here to redownload store data</a>' . $lasr_sync_desc . '</p>',
                'id' => 'Blaze_options',
                'type' => 'title',
            ),
            array(
                'name' => __('BLAZE API Key', 'blaze-woo-integration'),
                'desc' => '<p>' . __('API key found in BLAZE > Global Settings > Online Store.', 'blaze-woo-integration') . '<br/> <a id="verifyBlazeApiKey" href="#">Click here to verify API key.</a></p>',
                'id' => 'Blaze_api_key',
                'type' => 'text',
            ),
	        array(
                'name' => __('Agreement Document Link ', 'blaze-woo-integration'),
                'desc' => __('When users register, there is a field to accept the store\'s agreement. This sets the link to the agreement.', 'blaze-woo-integration'),
                'id' => 'Blaze_agreement_link',
                'type' => 'text',
            ),
            array(
                'name' => __('Convenience Fee (Handling Fee, Baggings, etc..)', 'blaze-woo-integration'),
                'desc' => __('Applies to Pickup Orders only. (Insert Product ID)', 'blaze-woo-integration'),
                'id' => 'Blaze_required_pickup_product',
                'type' => 'text',
            ),
            array(
                'name' => __('Refresh Products', 'blaze-woo-product-import'),
                'desc' => '<p>' . __('You can import new products.', 'blaze-woo-product-import') . ' <a id="importProducts" href="#">Click here to import products.</a></p>',
                'id' => 'product-import',
                'type' => 'title',
            ),
            array(
                'name' => __('Nearby API'),
                'desc' => __('', 'blaze-woo-integration'),
                'id' => 'blaze_enable_nearby',
                'type' => 'checkbox',
            ),
	        /*array(
		        'name' => __('Nearby API for Multiple Shops.'),
		        'desc' => __('', 'blaze-woo-integration'),
		        'id' => 'blaze_enable_multi_shop',
		        'type' => 'checkbox',
	        ),*/
            array(
                'name' => __('No Products if theres no address ( nearby only )'),
                'desc' => __('Dont show products if there is no address in nearby (delivery only).', 'blaze-woo-integration'),
                'id' => 'blaze_enable_nearby_delivery_only',
                'type' => 'checkbox',
            ),
            array(
                'name' => __('Geocode Key ( nearby only )', 'blaze-woo-integration'),
                'desc' => __('Google Geocode API key needed for nearby..', 'blaze-woo-integration'),
                'id' => 'blaze_geocode_key',
                'type' => 'text',
            ),
            array(
                'name' => __('Import Users', 'blaze-woo-user-import'),
                'desc' => '<p>' . __('You can also import your existing customers to BLAZE.', 'blaze-woo-user-import') . ' <a id="exportUser" href="#">Click here to import</a></p>',
                'id' => 'user-import',
                'type' => 'title',
            ),
            array(
                'name' => __('Settings for unit type products', 'blaze-woo-integration'),
                'desc' => __(''),
                'id' => 'Blaze_product_option',
                'type' => 'select',
                'options' => array('Quantity' => __('Quantity'), 'Drop Down' => __('Dropdown'),'Both' => __('Both')),
                'required' => true,
            ),
            array(
                'name' => __('Show delivery date and time on checkout page'),
                'desc' => __('', 'blaze-woo-integration'),
                'id' => 'Blaze_delivery_date',
                'type' => 'checkbox',
            ),
            array(
                'name' => __('Enable Blaze Debug Log'),
                'desc' => __('', 'blaze-woo-integration'),
                'id' => 'blaze_debug_log',
                'type' => 'checkbox',
            ),
            array(
                'name' => __('Want to receive user registration data on admin email?'),
                'desc' => __('', 'blaze-woo-integration'),
                'id' => 'Blaze_email_registration',
                'type' => 'checkbox',
            ),
            array(
                'name' => __('Display Reward field on cart page?'),
                'desc' => __('', 'blaze-woo-integration'),
                'id' => 'Blaze_reward_name',
                'type' => 'checkbox',
            ),
            array(
                'name' => __('Display Payments option on cart page?'),
                'desc' => __('', 'blaze-woo-integration'),
                'id' => 'Blaze_payment_options',
                'type' => 'checkbox',
            ),
            array(
                'name' => __('Display address fields on registration page?'),
                'desc' => __('', 'blaze-woo-integration'),
                'id' => 'Blaze_address_registration',
                'type' => 'checkbox',
            ),
            array('type' => 'sectionend', 'id' => 'Blaze_options'),
        );

        // Admin hooks
        add_action('admin_init', array($this, 'admin_init'), 21);
        add_action('woocommerce_settings_general_options_after', array($this, 'admin_settings'), 21);
        add_action('woocommerce_update_options_general', array($this, 'save_admin_settings'));
        add_action('wp_loaded', array($this, 'wp_loaded'));
        add_action('admin_notices', array($this, 'connection_error_notice'));
        add_action('wp_enqueue_scripts', array(&$this, 'add_java_scripts'), 1000);
        add_action('admin_enqueue_scripts', array($this, 'admin_java_scripts'), 1);
        add_action('update_option_Blaze_show_pp_gram', array(&$this, 'Blaze_show_pp_gram_update'), 10, 2);
        add_action('update_option_Blaze_strain_as_category', array(&$this, 'Blaze_strain_as_category_update'), 10, 2);
        add_action('woocommerce_before_my_account', array(&$this, 'Blaze_registration_success'), 10);
        add_filter('woocommerce_product_tabs', array(&$this, 'woocommerce_product_tabs'), 1000);
        add_filter('woocommerce_checkout_fields', array(&$this, 'custom_remove_woo_checkout_fields'), 1000, 1);
        add_action('woocommerce_after_checkout_billing_form', array(&$this, 'woocommerce_add_text_cart'), 1000, 2);
        add_filter('woocommerce_checkout_fields', array(&$this, 'cloudways_custom_checkout_fields'), 1000, 2);
        add_action('woocommerce_checkout_after_customer_details', array(&$this, 'cloudways_extra_checkout_fields'), 1000, 4);
        add_filter('manage_shop_order_posts_columns', array(&$this, 'set_custom_edit_post_columns'), 99, 1);
        add_action('manage_shop_order_posts_custom_column', array(&$this, 'custom_cpost_column'), 99, 2);
        add_action('init', array(&$this, 'register_new_wc_order_statuses'), 99, 3);
        add_filter('wc_order_statuses', array(&$this, 'add_new_wc_statuses_to_order_statuses'), 99, 4);
        add_filter('plugin_row_meta', array(&$this, 'wk_plugin_row_meta'), 10, 2);
        add_action('woocommerce_product_query', array(&$this, 'themelocation_product_query'), 10, 2);
        add_action('woocommerce_order_status_changed', array(&$this, 'backorder_status_custom_notification'), 10, 4);
        add_action('woocommerce_thankyou', array(&$this, 'woocommerce_thankyou_change_order_status'), 10, 5);
        add_action('woocommerce_checkout_update_order_meta', array(&$this, 'add_order_delivery_date_to_order'), 10, 1);
        add_filter('woocommerce_email_order_meta_fields', array(&$this, 'add_delivery_date_to_emails'), 10, 3);
        add_filter('woocommerce_order_details_after_order_table', array(&$this, 'add_delivery_date_to_order_received_page'), 10, 1);
        //Sync users over to blaze (Settings btn)
        add_action('wp_ajax_import_users', array($this, 'import_users'));
        //Manual Resync Products (Settings btn)
        add_action('wp_ajax_import_products', array($this, 'force_import_products'));
        //Verify Connection
        add_action('wp_ajax_verify_connection', array($this, 'verify_connection'));

        //Edit Account Actions
        add_action('woocommerce_edit_account_form', array($this, 'blaze_edit_account_front_validation'));

        add_action('woocommerce_save_account_details_errors', array($this,'blaze_edit_account_validation'), 10, 2);
        add_action('woocommerce_save_account_details', array($this, 'blaze_edit_account_save'), 12, 1);

        //Change Footer
        add_action('wp_footer', array($this,'modify_footer'), 10 ,3);


        add_filter('woocommerce_email_subject_cancelled_order', array($this, 'customizing_cancelled_email_subject'), 10, 2);
        add_action('woocommerce_admin_order_data_after_order_details', array($this, 'kia_display_order_data_in_admin'), 10, 7);
        add_action('wp_ajax_spyr_coupon_redeem_handler', array($this, 'spyr_coupon_redeem_handler'), 10, 9);
        add_action('wp_ajax_nopriv_spyr_coupon_redeem_handler', array($this, 'spyr_coupon_redeem_handler'), 11, 1);
        add_action('wp_ajax_payment_method_handler', array($this, 'payment_method_handler'), 10, 9);
        add_action('wp_ajax_nopriv_payment_method_handler', array($this, 'payment_method_handler'), 11, 1);

        //Nearby API
        add_action('woocommerce_archive_description', array($this, 'nearby_api_ui'), 20, 1);
        add_action( 'wp_ajax_nopriv_submit_nearby_api', array( $this, 'submit_nearby_api' ) );
        add_action( 'wp_ajax_submit_nearby_api', array( $this, 'submit_nearby_api' ) );
        add_action( 'pre_get_posts', array( $this,'nearby_set_products_from_shop_page') );



        //product price
        add_filter( 'woocommerce_get_price_html', array($this, 'update_blaze_product_price'), 10, 2 );


    }

    function nearby_set_products_from_shop_page( $q ) {
        $apidomain = get_option('Blaze_api_domain');
        $apikey = get_option('Blaze_api_key');
        $nearbyEnabled = get_option('blaze_enable_nearby');
        $nearbyFilterEnabled = get_option('blaze_enable_nearby_delivery_only');
	    //$multi_shops = get_option("blaze_enable_multi_shop");
	    //$result =  $multi_shops == "yes" ? "true" : "false";
	    $result = "false";

        if ( ! $q->is_main_query() ) return;
        if ( ! $q->is_post_type_archive() ) return;
        if ( $q->is_admin) return;

        if($_SESSION['nearby_set'] == true && $nearbyEnabled == "yes") {

            $urlGetNearby = $apidomain . "/api/v1/store/inventory/products?api_key=".$apikey."&lat=".$_SESSION['lat']."&long=".$_SESSION['long']."&express=true" . "&multishop=" . $result;
            $nearbyData = wp_remote_get($urlGetNearby, array('headers' => array('Content-Type' => 'application/json')));

            $nearbyResponse = json_decode($nearbyData['body']);
            $nearbyProducts = $nearbyResponse->values;
            //use Nearby API
            //Get IDs
            $nearbyProductIds = array();
            $productQuery = array();
            $idArray = array();

            foreach($nearbyProducts as $nearbyProduct) {
                array_push($idArray, $nearbyProduct->id);
            }


            array_push($productQuery,
                array(
                    'key' => 'Blaze_woo_product_id',
                    'value' => $idArray,
                    'compare' => 'IN'
                )
            );

            $q->set('meta_query', $productQuery);
       }
       else {
        if($nearbyFilterEnabled == "yes") {

             $productQuery = array();
             $productQuery['relation'] = 'AND';

             array_push($productQuery,
             array(
                 'key' => 'Blaze_woo_product_id',
                 'value' => "1",
                 'compare' => 'IN'
                 )
             );

             $q->set('meta_query', $productQuery);
        }
    }


    }
    function submit_nearby_api() {
        $data = $_POST;

        if($data['clear'] == true) {
            $_SESSION['nearby_set'] = false;
            $_SESSION['lat'] = "";
            $_SESSION['long'] = "";
            $_SESSION['address'] = "";

            wp_send_json_success(array(
            'success' => "Cleared Nearby"
          ));
          wp_die();
          return;
        }

        //Save Lat Long to session, render shop
        $_SESSION['nearby_set'] = true;
        $_SESSION['lat'] = $data['lat'];
        $_SESSION['long'] = $data['long'];
        $_SESSION['address'] = $data['address'];

        //Refresh Shop
        wp_send_json_success(array(
            'success' => "Updated Nearby"
          ));

        wp_die();
    }

    function nearby_api_ui() {
        $apidomain = get_option('Blaze_api_domain');
        $apikey = get_option('Blaze_api_key');
        $geocodeKey = get_option('blaze_geocode_key');
        $nearbyEnabled = get_option('blaze_enable_nearby');

        $text = "Find Nearby Products";
        $val = "";
        $hideClear = "";

        if($_SESSION['nearby_set'] == true) {
            $text = "Nearby Products";
            $val = $_SESSION['address'];
        }

        if(trim($geocodeKey) != "" && $nearbyEnabled == 'yes') {
            echo '
            <div style="
                height: 50px;
                display: block;
            "
                class="blaze_nearby_api"
            >
                <span>'.$text.'</span>
                &nbsp;
                <input
                value="'.$val.'"
                id="ship-address"
                name="ship-address"
                />
                <a href="#" id="clear-nearby"><span> X </span></a>
            </div>

            <script async
                src="https://maps.googleapis.com/maps/api/js?key='.$geocodeKey.'&callback=initAutocomplete&libraries=places&v=weekly">
            </script>
            ';
        }
    }

    function getShopInfo($action){
        $apidomain = get_option('Blaze_api_domain');
        $apikey ="";
        if($action == "verify_api"){
            $apikey = $_POST['blazeApiKey'];
        }else if($action == "import_products"){
            $apikey = get_option('Blaze_api_key');
        }
        $url = $apidomain . "/api/v1/store?api_key=" . $apikey;
        $result = wp_remote_get($url);
        $result = json_decode($result['body']);
        return $result;

    }


    function verify_connection()
    {
        @session_start();

        $result = $this->getShopInfo("verify_api");

        if (isset($result->shop)) {
            Blaze_connection::checkStatusCode(200);
            $response['message'] = "The connection was successful.";
        } else {
            Blaze_connection::checkStatusCode(400);
            $response['message'] = "The connection wasn't successful.";
        }
        wp_send_json_success($response);
    }


    //Price handler(Lowest needs to be displayed on pricing templates)
    function product_get_min_max_variation_price($product) {
        global $woocommerce;

        $variations_id_list = $product->get_children();
        $variations = array();

        foreach($variations_id_list as $variation_id) {
            $variation = wc_get_product( $variation_id );
            array_push($variations, $variation);
        }

        $highestVariationValue;
        $lowestVariationValue;
        foreach($variations as $variation) {
            $variationPrice = $variation->get_price();
            if(empty($highestVariationValue) && empty($lowestVariationValue)) {
                $highestVariationValue = $variationPrice;
                $lowestVariationValue = $variationPrice;
            }

            if($variationPrice <= $lowestVariationValue) {
                $lowestVariationValue = $variationPrice;
            }

            if($variationPrice >= $highestVariationValue ) {
                $highestVariationValue = $variationPrice;
            }
        }

        return array(
            'min' => $lowestVariationValue,
            'max' => $highestVariationValue
        );
    }

    function update_blaze_product_price($price_html, $product ) {
        global $woocommerce;

        $minMaxProductValues = $this->product_get_min_max_variation_price($product);
        $lowestVariationValue = $minMaxProductValues['min'];
        $highestVariationValue = $minMaxProductValues['max'];

        if(($lowestVariationValue !== $highestVariationValue)) {
            $price_html = "<p class=\"price\">" . wc_price($lowestVariationValue) . " – " . wc_price($highestVariationValue) . "</p>";
        }
        else {
            $price = $product->get_price();
            if($product->get_parent_id() !== 0) {
                $parent = wc_get_product($product->get_parent_id());
                $minMaxParentValues = $this->product_get_min_max_variation_price($parent);

                $price = $minMaxParentValues['min'];
            }


            $price_html =  "<p class=\"price\">" . wc_price($price) . "</p>";
        }
        return $price_html;
    }
    //End of Price Handler

    function payment_method_handler() {
        @session_start();
        $paymentmethod = $_REQUEST['payment_method'];
        $_SESSION['paymentoption'] = $paymentmethod;
        global $woocommerce;
        $chosen_methods = WC()->session->get('chosen_shipping_methods');
        $chosen_shipping = $chosen_methods[0];
        $productaray1 = array();
        $productaray2 = array();
        if (sizeof(WC()->cart->get_cart()) > 0) {
            foreach (WC()->cart->get_cart() as $cart_item) {
                if ($cart_item['product_id']) {
                    $prodId = $cart_item['product_id'];
                    $Blazeproductid = get_post_meta($prodId, 'Blaze_woo_product_id', true);
                    $producttype = get_post_meta($prodId, 'producttype', true);
                    $qty = $cart_item['quantity'];
                    if ($producttype == 'grams') {
                        $productattributes = get_post_meta($prodId, 'productattributes', true);
                        $productattributesarray = json_decode($productattributes);
                        $variations = $cart_item['variation']['attribute_grams'];
                        foreach ($productattributesarray as $value) {
                            if ($value->name == $variations) {
                                $weightKey = $value->weightKey;
                            }
                        }
                        $productaray1[] = array(
                            "productId" => $Blazeproductid,
                            "quantity" => $qty,
                            "useUnitQty" => true,
                            "weightKey" => $weightKey
                        );
                    } else {
                        $productaray2[] = array(
                            "productId" => $Blazeproductid,
                            "quantity" => $qty,
                            "useUnitQty" => false,
                        );
                    }
                }
            }
        }
        $productaray = array_merge($productaray1, $productaray2);
        $server_output = $this->woocommerceinfo_check_reward($productaray);
        $errorMsg = $server_output->errorMsg;
        $message = $server_output->message;
        $response = array(
            'result' => 'scusess',
            'href' => WC()->cart->get_cart_url()
        );
        header('Content-Type: application/json');
        echo json_encode($response);
        exit();
    }

    function spyr_coupon_redeem_handler() {
        @session_start();


        //API setup
        $apidomain = get_option('Blaze_api_domain');
        $apikey = get_option('Blaze_api_key');

        //Get loyalty points
        $user = wp_get_current_user();
        $currentuseremail = $user->user_email;

        $userInfoUrl = $apidomain . "/api/v1/store/user/consumerByEmail?api_key=" . $apikey . "&email=" . $currentuseremail;
        $userInfoData = wp_remote_get($userInfoUrl);
        $userInfoResponse = json_decode($userInfoData['body']);

        $memberLoyaltyPoints = $userInfoResponse->member->loyaltyPoints;

        //Get loyalty points cost for coupons
        $blazeUserId = get_user_meta($user->ID, 'Blaze_woo_user_id', false);
        $blazeUserId = $blazeUserId[0];

        $rewardsUrl = $apidomain . "/api/v1/partner/loyalty/rewards/members/" . $blazeUserId . "?api_key=" . $apikey;
        $rewardsData = wp_remote_get($rewardsUrl);
        $rewardsResponse = json_decode($rewardsData['body']);
        $rewardsList = $rewardsResponse->values;


        $costOfCoupons = -1;
        foreach ($_REQUEST['coupon_code'] as $rewardToBeAddedName) {
            foreach ($rewardsList as $rewardInBlaze) {
                $blazeRewardName = $rewardInBlaze->rewardName;
                $blazeRewardCost = $rewardInBlaze->pointsRequired;

                if($blazeRewardName == $rewardToBeAddedName) {
                    if($costOfCoupons == -1) {
                        $costOfCoupons = 0;
                    }
                    $costOfCoupons = $costOfCoupons + $blazeRewardCost;;
                }
            }
        }

        $code = implode(",", $_REQUEST['coupon_code']);

        //Reward Not Found in above list(should not happen unless rewards are updated and page is not refreshed to reflect the update)
        if($costOfCoupons == -1 && $code != 'Choose any reward name remove') {
            $response = array(
                'result' => 'error',
                'message' => 'Reward not found.'
            );
            header('Content-Type: application/json');
            echo json_encode($response);
            exit();
        }
        else if($costOfCoupons > $memberLoyaltyPoints) {
            $response = array(
                'result' => 'error',
                'message' => 'Insufficient loyalty points to redeem reward.'
            );
            header('Content-Type: application/json');
            echo json_encode($response);
            exit();
        }

        if (empty($code) || !isset($code)) {
            $response = array(
                'result' => 'error',
                'message' => 'Please enter a reward name.'
            );
            header('Content-Type: application/json');
            echo json_encode($response);
            exit();
        }
        if(isset($_SESSION['reward_name'])) {
            if($_SESSION['reward_name'] == 'remove') {
                $_SESSION['reward_name'] = '';
            }
        }
        if (isset($_SESSION['reward_name'])) {
            $_SESSION['reward_name'] = $_SESSION['reward_name'] . "," . $code;
        } else {
            $_SESSION['reward_name'] = $code;
        }
        if ($code != 'Choose any reward name remove') {
            global $woocommerce;
            $chosen_methods = WC()->session->get('chosen_shipping_methods');
            $chosen_shipping = $chosen_methods[0];
            $productaray1 = array();
            $productaray2 = array();
            if (sizeof(WC()->cart->get_cart()) > 0) {
                foreach (WC()->cart->get_cart() as $cart_item) {
                    if ($cart_item['product_id']) {
                        $prodId = $cart_item['product_id'];
                        $Blazeproductid = get_post_meta($prodId, 'Blaze_woo_product_id', true);
                        $producttype = get_post_meta($prodId, 'producttype', true);
                        $qty = $cart_item['quantity'];
                        if ($producttype == 'grams') {
                            $productattributes = get_post_meta($prodId, 'productattributes', true);
                            $productattributesarray = json_decode($productattributes);
                            $variations = $cart_item['variation']['attribute_grams'];
                            foreach ($productattributesarray as $value) {
                                if ($value->name == $variations) {
                                    $weightKey = $value->weightKey;
                                }
                            }
                            $productaray1[] = array(
                                "productId" => $Blazeproductid,
                                "quantity" => $qty,
                                "useUnitQty" => true,
                                "weightKey" => $weightKey
                            );
                        } else {
                            $productaray2[] = array(
                                "productId" => $Blazeproductid,
                                "quantity" => $qty,
                                "useUnitQty" => false,
                            );
                        }
                    }
                }
            }
            $productaray = array_merge($productaray1, $productaray2);
            $server_output = $this->woocommerceinfo_check_reward($productaray);
            $errorMsg = $server_output->errorMsg;
            $message = $server_output->message;
            if ($message) {
                $rewardtring = $_SESSION['reward_name'];
                $rewardarray = explode(",", $rewardtring);
                if (false !== $key = array_search($code, $rewardarray)) {
                    unset($rewardarray[$key]);
                }
                $_SESSION['reward_name'] = implode(",", $rewardarray);
                $response = array(
                    'result' => 'error',
                    'message' => $message
                );
                header('Content-Type: application/json');
                echo json_encode($response);
                exit();
            } elseif ($errorMsg) {
                $rewardtring = $_SESSION['reward_name'];
                $rewardarray = explode(",", $rewardtring);
                if (false !== $key = array_search($code, $rewardarray)) {
                    unset($rewardarray[$key]);
                }
                $_SESSION['reward_name'] = implode(",", $rewardarray);
                $response = array(
                    'result' => 'error',
                    'message' => $errorMsg
                );
                header('Content-Type: application/json');
                echo json_encode($response);
                exit();
            }
        } else if ($code == 'Choose any reward name remove') {
            $_SESSION['reward_name'] = 'remove';
            $_SESSION['rewardmessage'] = "Reward removed successfully";
            $response = array(
                'result' => 'success',
                'href' => WC()->cart->get_cart_url()
            );
            header('Content-Type: application/json');
            echo json_encode($response);
            exit();
        }
        $_SESSION['rewardmessage'] = "Reward applied successfully.";
        $response = array(
            'result' => 'success',
            'href' => WC()->cart->get_cart_url()
        );
        header('Content-Type: application/json');
        echo json_encode($response);
        exit();
    }

    // display the extra data in the order admin panel
    function kia_display_order_data_in_admin($order) {
        $delivery_date = get_post_meta($order->id, '_delivery_date', true);
        $delivery_time = get_post_meta($order->id, '_delivery_time', true);
        if ('' != $delivery_date) {
            ?>
            <div class="form-field-wide">
                <br/><br/>
                <h4><?php _e('Delivery Date'); ?></h4>
            <?php echo $delivery_date . ' ' . $delivery_time; ?>
            </div>
            <?php
        }
    }

    function customizing_cancelled_email_subject($formated_subject, $order) {
        $modified = $order->get_date_modified(); // Get date modified WC_DateTime object
        return sprintf(__('Order #%d  was cancelled on %s', 'woocommerce'), $order->get_id(), $modified->date_i18n('l jS \of F Y \a\t h:i:s A'));
    }

    //change footer
    function modify_footer() {
        $apidomain = get_option('Blaze_api_domain');
	    $apikey = get_option('Blaze_api_key');
	    $url = $apidomain . "/api/v1/store?api_key=" . $apikey;
	    $data = wp_remote_get($url, array(
            'timeout' => 60,
            'redirection' => 5,
            'blocking' => true
        ));
	    $response = json_decode($data['body']);
        ?>
            <style>
                .ui-footer-container {
                    width: 100%;
                    text-align: center;
                    margin-bottom: 1em;
                }
                .ui-footer-container > span {
                    font-size: .7em;
                    font-weight: 500;
                    font-family: Helvetica,Arial,sans-serif;
                    color: #767676;
                }
                .woocommerce .blockUI.blockOverlay {
                    position: relative! important;
                    display: none! important;
                }
            </style>
            <div class="ui-footer-container">
                <span>© All rights reserved | License: <?= $response->shop->license; ?> | By BLAZE 2.5.2</span>
            </div>
        <?php
    }

    function blaze_edit_account_validation( $errors, $user) {

        global $wpdb;


        $apidomain = get_option('Blaze_api_domain');
        $apikey = get_option('Blaze_api_key');

        $new_email = trim($_POST['account_email']);
        $user_id = get_current_user_id();
        $user_info = get_userdata($user_id);
        $old_user_email = $user_info->user_email;



        if(trim($old_user_email) != trim($new_email)) {
            //Check
            $urlGetMember = $apidomain . "/api/v1/store/auth/createFromProfile?api_key=" . $apikey;
            $memberData = wp_remote_post($urlGetMember, array(
                'headers' => array('Content-Type' => 'application/json; charset=utf-8'),
                'body' => json_encode(array("email" => $new_email)),
                'method' => 'POST'
            ));


            $memberResult = json_decode($memberData['body']);
            $memberMessage = $memberResult->message;


            if($memberResult->message == "This email does not exist in our system.") {
                //Good to go

            }
            else {
                //Not good to go
                $errors->add( 'blaze_account_email_already_exists', __( "Member already exists with this email." ) );
            }
        }

        return $errors;
    }
    function blaze_edit_account_save($user_id) {
        $apidomain = get_option('Blaze_api_domain');
        $apikey = get_option('Blaze_api_key');
        // For DOB
        $authorization = get_user_meta($user_id, 'token', false);
        $Blazewoouserid = get_user_meta($user_id, 'Blaze_woo_user_id', false);



        $user_id = get_current_user_id();
        $user_info = get_userdata($user_id);
        $new_email = trim($user_info->user_email);

        $update_email = false;

        $urlGetMember = $apidomain . "/api/v1/store/auth/createFromProfile?api_key=" . $apikey;
        $memberData = wp_remote_post($urlGetMember, array(
            'headers' => array('Content-Type' => 'application/json; charset=utf-8'),
            'body' => json_encode(array("email" => $new_email)),
            'method' => 'POST'
        ));

        $memberResult = json_decode($memberData['body']);

        if($memberResult->message == "This email does not exist in our system.") {
            //Good to go
            $update_email = true;
        }

        if ($update_email) {
            $updatedata = array(
                'firstName' => $_POST['account_first_name'],
                'lastName' => $_POST['account_last_name'],
                'email' => trim($_POST['account_email'])
            );


            $updateurl = $apidomain . "/api/v1/store/user/updateConsumerUser?api_key=" . $apikey;

            $data = wp_remote_post($updateurl, array(
                'headers' => array('Authorization' => $authorization[0], 'Content-Type' => 'application/json; charset=utf-8'),
                'body' => json_encode($updatedata),
                'method' => 'POST',
                'timeout' => 600,
                'redirection' => 5,
                'blocking' => true
            ));
        }

        if(isset($_POST['Blaze_dob'])) {
            update_user_meta($user_id, 'Blaze_dob', sanitize_text_field($_POST['Blaze_dob']));

            $date = strtotime($_POST['Blaze_dob']) * 1000 + 43200 * 1000; //1day*1000(for ms)


            $updatedata = array(
                'firstName' => $_POST['account_first_name'],
                'lastName' => $_POST['account_last_name'],
                'dob' => $date,
            );

            $updateurl = $apidomain . "/api/v1/store/user?api_key=" . $apikey;

            $data = wp_remote_post($updateurl, array(
                'headers' => array('Authorization' => $authorization[0], 'Content-Type' => 'application/json; charset=utf-8'),
                'body' => json_encode($updatedata),
                'method' => 'POST',
                'timeout' => 600,
                'redirection' => 5,
                'blocking' => true
            ));
            $result = json_decode($data['body']);
        }

        if (isset($_POST['password_1'])) {

            if ($_POST['password_1'] == $_POST['password_2']) {
                $current_user = wp_get_current_user();
                $usernamil = $current_user->user_email;
                $url = $apidomain . "/api/v1/store/auth/resetPassword?api_key=" . $apikey;
                $userdata = array('consumerId' => $Blazewoouserid[0], 'email' => $usernamil, 'password' => $_POST['password_1']);
                $data = wp_remote_post($url, array(
                    'headers' => array('Content-Type' => 'application/json; charset=utf-8'),
                    'body' => json_encode($userdata),
                    'method' => 'POST'
                ));
            }
        }
    }

    function blaze_edit_account_front_validation() {
        $user = wp_get_current_user();
        ?>
        <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/bootstrap-datepicker/1.3.0/css/datepicker.css">
        <script src="https://cdnjs.cloudflare.com/ajax/libs/bootstrap-datepicker/1.3.0/js/bootstrap-datepicker.js">"></script>
        <script src="https://cdn.jsdelivr.net/jquery.validation/1.15.1/jquery.validate.min.js"></script>
        <script language="JavaScript">
            jQuery(function () {
                jQuery("form.edit-account").validate({
                    rules: {
                        Blaze_dob: {
                            required: true,
                        }
                    },
                    messages: {

                        Blaze_dob: "Please enter date of birth",
                    },
                    submitHandler: function (form) {
                        form.submit();
                    }
                });
                jQuery('.datepicker').datepicker({
                    autoclose: true,
                    endDate: '+0d'
                });
            });
        </script>

        <p class="woocommerce-form-row woocommerce-form-row--wide form-row form-row-wide">
            <label for="Blaze_dob"><?php _e('Date of Birth', 'woocommerce'); ?>
                <input type="text" class="woocommerce-Input text input-text datepicker"  name="Blaze_dob" id="Blaze_dob" value="<?php echo esc_attr($user->Blaze_dob); ?>" />
        </p>
        <?php
    }

    //MANUAL RESYNC PRODS
    function force_import_products() {
		$response = array();
		$result = $this->getShopInfo("import_products");
        if(!$result->shop->onlineStoreInfo->enabled){
            $response['message'] = "Online Store Code is not available";
            wp_send_json_success($response);
            return;
        }

        global $wpdb;
        $error_logger = new Blaze_error_logger();
        $error_logger->log("Attempted to force resync of products.");

	    //update_option('Blaze_sync_locked_at', 0);

        if($this->sync->isLockedSync()) {
            $error_logger->log("Sync process already running.");
            $response['message'] = "Product Sync process already running.";
            wp_send_json_success($response);
        }

        $this->sync->execute();

        $response['message'] = "Product Sync successful.";
        wp_send_json_success($response);



        wp_die();
    }


    function import_users() {
        $apidomain = get_option('Blaze_api_domain');
        $apikey = get_option('Blaze_api_key');
        $url = $apidomain . "/api/v1/store/user/importConsumer?api_key=" . $apikey;
        $args = array(
            'role' => 'Customer',
            'role__in' => array('Customer'),
        );
        $alluser = get_users($args);
        foreach ($alluser as $user) {
            $usermail = $user->user_email;
            $firstname = get_user_meta($user->ID, 'first_name', false);
            $lastname = get_user_meta($user->ID, 'last_name', false);
            $shippingphone = get_user_meta($user->ID, 'shipping_phone', false);
            if ($shippingphone[0] == '' || $shippingphone[0] == null) {
                $shippingphone[0] = "";
            }
            $userarray[] = array(
                "firstName" => $firstname[0],
                "lastName" => $lastname[0],
                "email" => $usermail,
                "phoneNo" => $shippingphone[0]
            );
        }
        $data = wp_remote_post($url, array(
            'headers' => array('Content-Type' => 'application/json'),
            'body' => json_encode($userarray),
            'method' => 'POST',
            'timeout' => 600,
            'redirection' => 5,
            'blocking' => true
        ));
        $serveroutput = json_decode($data['body']);
        $successfulladded = $serveroutput->addSuccess;
        foreach ($successfulladded as $userdata) {
            $useremail = $userdata->email;
            $user = get_user_by('email', $useremail);
            $userId = $user->ID;
            if ($userId != '') {
                update_user_meta($userId, 'Blaze_woo_user_id', $userdata->id);
            }
        }
        wp_send_json_success($server_output);

        wp_die();
    }

    function woocommerce_thankyou_change_order_status($order_id) {
        if (!$order_id)
            return;

        $order = wc_get_order($order_id);

        if ($order->get_status() == 'processing')
            $order->update_status('placed');
    }

    function backorder_status_custom_notification($order_id, $from_status, $to_status, $order) {

        if ($order->has_status('declined') || $order->has_status('cancelled')) {

            // Getting all WC_emails objects
            $wc_emails = WC()->mailer()->get_emails();
            $customer_email = $order->get_billing_email();

            $wc_emails['WC_Email_Cancelled_Order']->recipient = $customer_email;
            $wc_emails['WC_Email_Cancelled_Order']->subject = '{site_title} has cancelled your order #{order_number}';
            // Sending the email from this instance
            $wc_emails['WC_Email_Cancelled_Order']->trigger($order_id);
        }
    }

    function themelocation_product_query($q) {
        $meta_query = $q->get('meta_query');
        $meta_query[] = array(
            'key' => '_price',
            'value' => 0,
            'compare' => '>'
        );
        $q->set('meta_query', $meta_query);
    }

    function wk_plugin_row_meta($links, $file) {
        if (plugin_basename(__FILE__) == $file) {
            $row_meta = array(
                'docs' => '<a href="' . esc_url('https://docs.google.com/document/d/1v96WBJDvBIUMW_rnebnsaU_NaEjMDhWg1u6U-IapHPM/edit?usp=sharing') . '" target="_blank" aria-label="' . esc_attr__('Plugin Additional Links', 'domain') . '" style="color:green;">' . esc_html__('View Documentation', 'domain') . '</a>'
            );

            return array_merge($links, $row_meta);
        }
        return (array) $links;
    }

    function add_new_wc_statuses_to_order_statuses($order_statuses) {

        $new_order_statuses = array();

        // add new order statuses after processing
        foreach ($order_statuses as $key => $status) {
            $new_order_statuses[$key] = $status;
            if ('wc-processing' === $key) {
                $new_order_statuses['wc-placed'] = 'Placed';
                $new_order_statuses['wc-accepted'] = 'Accepted';
                $new_order_statuses['wc-declined'] = 'Declined';
                $new_order_statuses['wc-cancelconsumer'] = 'CanceledByConsumer';
                $new_order_statuses['wc-canceldispensary'] = 'CanceledByDispensary';
                // $new_order_statuses[key] = value; // to add a new order status in woocommerce.
            }
        }

        return $new_order_statuses;
    }

    function register_new_wc_order_statuses() {
        register_post_status('wc-placed', array(
            'label' => 'Placed',
            'public' => true,
            'exclude_from_search' => false,
            'show_in_admin_all_list' => true,
            'show_in_admin_status_list' => true,
            'label_count' => _n_noop('Placed <span class="count">(%s)</span>', 'Placed <span class="count">(%s)</span>')
        ));
        register_post_status('wc-accepted', array(
            'label' => 'Accepted',
            'public' => true,
            'exclude_from_search' => false,
            'show_in_admin_all_list' => true,
            'show_in_admin_status_list' => true,
            'label_count' => _n_noop('Accepted <span class="count">(%s)</span>', 'Accepted <span class="count">(%s)</span>')
        ));
        register_post_status('wc-declined', array(
            'label' => 'Declined',
            'public' => true,
            'exclude_from_search' => false,
            'show_in_admin_all_list' => true,
            'show_in_admin_status_list' => true,
            'label_count' => _n_noop('Declined <span class="count">(%s)</span>', 'Declined <span class="count">(%s)</span>')
        ));
        register_post_status('wc-cancelconsumer', array(
            'label' => 'CanceledByConsumer',
            'public' => true,
            'exclude_from_search' => false,
            'show_in_admin_all_list' => true,
            'show_in_admin_status_list' => true,
            'label_count' => _n_noop('CanceledByConsumer <span class="count">(%s)</span>', 'CanceledByConsumer <span class="count">(%s)</span>')
        ));
        register_post_status('wc-canceldispensary', array(
            'label' => 'CanceledByDispensary',
            'public' => true,
            'exclude_from_search' => false,
            'show_in_admin_all_list' => true,
            'show_in_admin_status_list' => true,
            'label_count' => _n_noop('CanceledByDispensary <span class="count">(%s)</span>', 'CanceledByDispensary <span class="count">(%s)</span>')
        ));
        // repeat register_post_status() for each statuses
    }

    function set_custom_edit_post_columns($columns) {
        $columns['custom-columns'] = __('Blaze order Id', 'your_text_domain');
        $columns['delivery-columns'] = __('Delivery', 'your_text_domain');
        return $columns;
    }

    function custom_cpost_column($column, $post_id) {
        $orderId = get_post_meta($post_id, "Blaze_woo_order_id", true);
        $deliverydate = get_post_meta($post_id, "_delivery_date", true);
        $deliverytime = get_post_meta($post_id, "_delivery_time", true);
        switch ($column) {
            case 'custom-columns':
                echo "<b>" . $orderId . "</b>";
                break;
            case 'delivery-columns':
                echo "<b>" . $deliverydate . " " . $deliverytime . "</b>";
                break;
        }
    }

    function cloudways_extra_checkout_fields() {
        global $woocommerce;
        $checkout = WC()->checkout();
        ?>

        <div class="extra-fields">
            <h3><?php _e('Additional Fields'); ?></h3>

            <?php
            foreach ($checkout->checkout_fields['cloudways_extra_fields'] as $key => $field) :
                woocommerce_form_field($key, $field, $checkout->get_value($key));
            endforeach;
            ?>
        </div>

        <?php
    }

    function cloudways_custom_checkout_fields($fields) {
        global $woocommerce;
        $current_user = wp_get_current_user();
        $email = $current_user->user_email;
	    $phone = $woocommerce->customer->billing['phone'];

        $fields['cloudways_extra_fields'] = array(
            'cloudways_dropdown' => array(
                'type' => 'select',
                'options' => array('Email' => __('Email'), 'Text' => __('Text message')),
                'required' => true,
                'label' => __('Where should we send your confirmation?')
            ),
            'cloudways_email_field' => array(
                'type' => 'text',
                'required' => false,
                'default' => $email,
                'label' => __('EMAIL ADDRESS')
            ),
            'cloudways_phn_field' => array(
                'type' => 'text',
                'required' => false,
                'default' => $phone,
                'label' => __('PHONE NUMBER')
            ),
        );
        ?>
        <script type="text/javascript">
            jQuery(function () {
                jQuery('#cloudways_phn_field_field').hide();
                jQuery('select[name="cloudways_dropdown"]').on('change', function () {
                    var data = jQuery(this).val();
                    if (data == 'Text') {
                        jQuery('#cloudways_email_field_field').hide();
                        jQuery('#cloudways_phn_field_field').show();
                    } else {
                        jQuery('#cloudways_phn_field_field').hide();
                        jQuery('#cloudways_email_field_field').show();
                    }
                });

            });
        </script>
        <?php
        return $fields;
    }

    function woocommerce_add_text_cart() {
        global $woocommerce;
        session_start();
        $pickUpAddress = $_SESSION['pickUpAddress'];
        $pickUpcountry = $_SESSION['pickUpcountry'];
        $chosen_methods = WC()->session->get('chosen_shipping_methods');
        $chosen_shipping = $chosen_methods[0];
        if ($chosen_shipping != 'legacy_local_delivery') {
            ?><div class="Row editableInfoBox themeBorderColor light" style="flex-wrap: wrap; margin-left: -10px; margin-right: -10px;">
                <div class="editableInfoBox-left-child"><span class="title themeFontColor"><h2>Store Address</h2></span>
                    <span class="content"><div><br><span><?php echo $pickUpAddress; ?>,&nbsp;<?php echo $pickUpcountry; ?></span></div>
                        <div class="editableInfoBox-right-child"></div>
                </div>
            </div>
            <?php
        } else {
            $Blazedeliverydate = get_option('Blaze_delivery_date');
            if ($Blazedeliverydate == 'yes') {
                echo '<div class="blaze_delivery_date">';
                _e("Delivery Date (optional) ", "add_extra_fields");
                $datetimepickecss1 = plugin_dir_url('') . "/blaze-retail-woocommerce/css/jquery-ui.css";
                $datetimepickecss2 = plugin_dir_url('') . "/blaze-retail-woocommerce/css/jquery-ui-timepicker-addon.css";
                $datetimepickejs1 = plugin_dir_url('') . "/blaze-retail-woocommerce/js/jquery-ui.min.js";
                $datetimepickejs2 = plugin_dir_url('') . "/blaze-retail-woocommerce/js/jquery-ui-sliderAccess.js";
                $datetimepickejs3 = plugin_dir_url('') . "/blaze-retail-woocommerce/js/jquery-ui-timepicker-addon.js";
                ?>
                <br>
                <input type="text" name="add_delivery_date" id="datepicker">
                </div><br/>
                <link rel="stylesheet" href="<?php echo $datetimepickecss1; ?>">
                <script src="<?php echo $datetimepickejs1; ?>"></script>
                <script type="text/javascript" src="<?php echo $datetimepickejs2; ?>"></script>
                <!-- Required -->
                <link rel="stylesheet" href="<?php echo $datetimepickecss2; ?>">
                <script src="<?php echo $datetimepickejs3; ?>"></script>
                <script>
                jQuery(document).ready(function () {
                jQuery('#datepicker').datetimepicker({
                    minDate: new Date(),
                    timeFormat: 'hh:mm:ss tt'
                });
                });
                </script>
                <?php
            }
        }
    }

    function add_order_delivery_date_to_order($order_id) {
        if (isset($_POST ['add_delivery_date']) && '' != $_POST ['add_delivery_date']) {
            add_post_meta($order_id, '_delivery_date', sanitize_text_field($_POST ['add_delivery_date']));
        }
    }

    function add_delivery_date_to_emails($fields, $sent_to_admin, $order) {
        if (version_compare(get_option('woocommerce_version'), '3.0.0', ">=")) {
            $order_id = $order->get_id();
        } else {
            $order_id = $order->id;
        }
        $delivery_date = get_post_meta($order_id, '_delivery_date', true);
        if ('' != $delivery_date) {
            $fields['Delivery Date'] = array(
                'label' => __('Delivery Date', 'add_extra_fields'),
                'value' => $delivery_date,
            );
        }
        return $fields;
    }

    function add_delivery_date_to_order_received_page($order) {
        if (version_compare(get_option('woocommerce_version'), '3.0.0', ">=")) {
            $order_id = $order->get_id();
        } else {
            $order_id = $order->id;
        }
        $delivery_date = get_post_meta($order_id, '_delivery_date', true);
        if ('' != $delivery_date) {
            echo '<p><strong>' . __('Delivery Date ', 'add_extra_fields') . ':</strong> ' . $delivery_date;
        }
    }

    function custom_remove_woo_checkout_fields($fields) {
        global $woocommerce;
        $chosen_methods = WC()->session->get('chosen_shipping_methods');
        $chosen_shipping = $chosen_methods[0];
        ?>
        <script>
            jQuery( document ).ready(() => {
                jQuery("#ship-to-different-address-checkbox").prop('checked', true);
            })
        </script>
        <style>
            h3#ship-to-different-address {
                display: none;
            }
            .woocommerce-billing-fields h3 {
                display: none;
            }
            .shipping {
                display: none;
            }
            #shipping_method span.woocommerce-Price-amount { display: none; }
            .wc_payment_method.payment_method_cod {display: none;}
            .woocommerce-NoticeGroup.woocommerce-NoticeGroup-checkout {
                color: #fff;
                background: #b22222;
                padding: 2em;
            }
        </style>
        <?php
        // remove billing fields
        if ($chosen_shipping != 'legacy_local_delivery') {
            // remove billing fields
            //unset($fields['billing']['billing_first_name']);
            //unset($fields['billing']['billing_last_name']);
            unset($fields['billing']['billing_company']);
            unset($fields['billing']['billing_address_1']);
            unset($fields['billing']['billing_address_2']);
            unset($fields['billing']['billing_city']);
            unset($fields['billing']['billing_postcode']);
            unset($fields['billing']['billing_country']);
            unset($fields['billing']['billing_state']);
            //unset($fields['billing']['billing_phone']);
            //unset($fields['billing']['billing_email']);


            // remove shipping fields
            unset($fields['shipping']['shipping_first_name']);
            unset($fields['shipping']['shipping_last_name']);
            unset($fields['shipping']['shipping_company']);
            unset($fields['shipping']['shipping_address_1']);
            unset($fields['shipping']['shipping_address_2']);
            unset($fields['shipping']['shipping_city']);
            unset($fields['shipping']['shipping_postcode']);
            unset($fields['shipping']['shipping_country']);
            unset($fields['shipping']['shipping_state']);
        } else {
            // remove billing fields
            //unset($fields['billing']['billing_first_name']);
            //unset($fields['billing']['billing_last_name']);
            unset($fields['billing']['billing_company']);
            unset($fields['billing']['billing_address_1']);
            unset($fields['billing']['billing_address_2']);
            unset($fields['billing']['billing_city']);
            unset($fields['billing']['billing_postcode']);
            unset($fields['billing']['billing_country']);
            unset($fields['billing']['billing_state']);
            //unset($fields['billing']['billing_phone']);
            //unset($fields['billing']['billing_email']);

            // remove shipping fields
            unset($fields['shipping']['shipping_first_name']);
            unset($fields['shipping']['shipping_last_name']);
            unset($fields['shipping']['shipping_company']);
            //unset($fields['shipping']['shipping_address_1']);
            //unset($fields['shipping']['shipping_address_2']);
            //unset($fields['shipping']['shipping_city']);
            //unset($fields['shipping']['shipping_postcode']);
            unset($fields['shipping']['shipping_country']);
            //unset($fields['shipping']['shipping_state']);
        }
        return $fields;
    }

    public function woocommerce_product_tabs($tabs) {
        unset($tabs['additional_information']);
        return $tabs;
    }

    public function Blaze_registration_success() {
        if (isset($_GET['Blaze_reg_success']) && $_GET['Blaze_reg_success']) {
            wc_print_notice(__('Your registration has been successfully completed!', 'woocommerce'), 'success');
        }
    }

    public function checkWC() {
        if (in_array('woocommerce/woocommerce.php', apply_filters('active_plugins', get_option('active_plugins')))) {
            return true;
        } else {
            return false;
        }
    }

    public function admin_java_scripts() {
        wp_enqueue_script(
                'custom', plugins_url('/js/custom.js', __FILE__), array('jquery')
        );
        wp_localize_script(
                'custom', 'custom', array(
            'ajax' => admin_url('admin-ajax.php'),
                )
        );





    }

    public function add_java_scripts() {
        wp_enqueue_script('jquery-maskedinput', plugins_url('/js/maskedinput/jquery.maskedinput-1.3.min.js', __FILE__));
        wp_enqueue_script('autocomplete', plugins_url('/js/autocomplete.js', __FILE__));
        wp_enqueue_script('blazeplugin', plugins_url('/js/blazeplugin.js', __FILE__));
        wp_enqueue_script('submit_nearby_api', plugins_url('/js/nearbyapi.js', __FILE__));

        wp_localize_script(
            'submit_nearby_api', 'settings', array(
            'url' => admin_url('admin-ajax.php'),
            )
        );

        if (is_account_page()) {
            $this->google_maps_script_loader();
        }
    }

    private function google_maps_script_loader() {
        $api_key = get_option('Blaze_google_api_key');
        if (!$api_key) {
            return false;
        }

        global $wp_scripts;

        foreach ($wp_scripts->registered as $key => $script) {
            if (preg_match('#maps\.google(?:\w+)?\.com/maps/api/js#', $script->src)) {
                /* Remove all previous Google Maps */
                wp_deregister_script($key);
            }
        }

        $url = 'https://maps.googleapis.com/maps/api/js?v=3&libraries=places&key=' . $api_key;
        wp_enqueue_script('google-autocomplete', $url, array(), false, true);

        add_filter('script_loader_tag', array(&$this, 'add_google_autocomplete_attribute'), 10, 2);


        if (get_option('Blaze_company_country') == 'Canada') {
            wp_enqueue_script('google-autocomplete-ca', plugins_url('/js/google-autocomplete-ca.js', __FILE__));
        } else {
            wp_enqueue_script('google-autocomplete-ca', plugins_url('/js/google-autocomplete-us.js', __FILE__));
        }

        wp_enqueue_script('google-autocomplete-custom', plugins_url('/js/google-autocomplete.js', __FILE__));
    }

    public function add_google_autocomplete_attribute($tag, $handle) {
        $scripts_to_async = array('google-autocomplete');

        foreach ($scripts_to_async as $async_script) {
            if ($async_script === $handle) {
                $pos = strpos($tag, '>');
                return $pos !== false ? substr_replace($tag, ' async defer>', $pos, strlen('>')) : $tag;
            }
        }
        return $tag;
    }

    public function define_constants() {
        define('Blaze_PLUGIN_FILE', __FILE__);
    }

    public function includes() {
        include_once('includes/class-Blaze-install.php');
        include_once('includes/class-Blaze-api.php');
        include_once('includes/class-Blaze-sync.php');
        include_once('includes/class-Blaze-userSync.php');
        include_once('includes/class-Blaze-cart.php');
        include_once('includes/class-Blaze-errorLogger.php');
        include_once('includes/class-Blaze-strain.php');
        include_once('includes/class-Blaze-connection.php');
        include_once('includes/class-Blaze-shipping.php');
    }

    public static function plugin_path() {
        return untrailingslashit(plugin_dir_path(__FILE__));
    }

    // Load the settings
    function admin_init() {
        if (isset($_GET['Blaze_resync']) && $_GET['Blaze_resync'] == 1) {
            $this->sync->resetSync();
        } elseif (isset($_GET['set_dev_key'])) {
            Blaze()->api->setDevMode($_GET['set_dev_key']);
        } elseif (isset($_GET['set_debug'])) {
            Blaze()->api->setDebug((bool) $_GET['set_debug']);
        } elseif (isset($_GET['print_debug'])) {
            $error_logger = new Blaze_error_logger();
            $error_logger->get_error_log();
            $error_logger->get_debug_log();
        } elseif (isset($_GET['print_apilog'])) {
            $error_logger = new Blaze_error_logger();
            $error_logger->get_execute_api_log();
        }

        if (isset($_POST['Blaze_api_key']) && isset($_POST['Blaze_api_domain'])) {
            Blaze_connection::testConnection($_POST['Blaze_api_domain'], $_POST['Blaze_api_key']);
        }
    }

    function admin_settings() {
        woocommerce_admin_fields($this->settings);
    }

    function save_admin_settings() {
        woocommerce_update_options($this->settings);
    }

    function wp_loaded() {
        //Runs each load(TESTING ONLY)
        //$this->sync->execute();
    }

    public function connection_error_notice() {
        if (Blaze_connection::isConnected()) {
            echo "<div class='updated'><p>Connected to <b>Blaze</b><p></div>";
        } else {
            echo "<div class='error'><p>Blaze_API: connection error<p></div>";
        }
    }

    public function woocommerce_error_notice() {
        echo "<div class='error'><p>Blaze: WooCommerce Plugin not installed<p></div>";
    }

    public function woocommerceinfo_check_reward($productaray) {
        global $woocommerce;
        @session_start();
        $apidomain = get_option('Blaze_api_domain');
        $apikey = get_option('Blaze_api_key');
        if (is_user_logged_in()) {
            $user = wp_get_current_user();
            $token = get_user_meta($user->ID, 'token', false);
            $authorization = $token[0];
        } else {
            $authorization = '';
        }
        // check for current session id
        if (isset($_SESSION['sessionId'])) {
            $sessionId = $_SESSION['sessionId'];
            $url = $apidomain . "/api/v1/store/cart/woocommerce/info?api_key=" . $apikey . "&sessionId=" . $sessionId;
        } else {
            $url = $apidomain . "/api/v1/store/cart/woocommerce/info?api_key=" . $apikey;
        }
        // get shipping methods
        $chosen_methods = WC()->session->get('chosen_shipping_methods');
        $chosen_shipping = $chosen_methods[0];
        if ($chosen_shipping == 'legacy_local_delivery') {
            $chosen_shipping = "Delivery";
        } else {
            $chosen_shipping = "Pickup";
        }
        $couponcode = '';
        if (isset($_SESSION['coupon_code'])) {
            $couponcode = $_SESSION['coupon_code'];
        }
        $rewardname = '';
        if (isset($_SESSION['reward_name'])) {
            $rewardname = $_SESSION['reward_name'];
        }
        $paymentoption = 'Cash';
        if (isset($_SESSION['paymentoption'])) {
            $paymentoption = $_SESSION['paymentoption'];
        }
        $newarray['productCostRequests'] = $productaray;
        $newarray['promoCode'] = $couponcode;
        $newarray['pickupType'] = $chosen_shipping;
        $newarray['rewardName'] = $rewardname;
        $newarray['paymentOption'] = $paymentoption;
        if ($authorization) {
            $data = wp_remote_post($url, array(
                'headers' => array('Authorization' => $authorization, 'Content-Type' => 'application/json; charset=utf-8'),
                'body' => json_encode($newarray),
                'method' => 'POST'
            ));
        } else {
            $data = wp_remote_post($url, array(
                'headers' => array('Content-Type' => 'application/json; charset=utf-8'),
                'body' => json_encode($newarray),
                'method' => 'POST'
            ));
        }
        $server_output = json_decode($data['body']);
        $_SESSION['sessionId'] = $server_output->sessionId;
        $_SESSION['consumerCartId'] = $server_output->consumerCartId;
        return $server_output;
    }

}

function WooBlaze_Retail() {
    return WooBlaze_Retail::instance();
}

$Blaze = WooBlaze_Retail();
