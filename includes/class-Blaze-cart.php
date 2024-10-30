<?php

/**
 * WooCommerce BLAZE Integration Cart APIs
 *
 * @author      BLAZE
 * @category    API
 * @package     WooCommerce/API
 */
if (!defined('ABSPATH'))
    exit;

class Blaze_woo_cart
{

    protected $order_data = null;
    protected $order_tax_total = 0;
    public $productsArray = null;

    public function __construct()
    {
        @session_start();
        $this->userSync = new Blaze_woo_user_sync(false);
        add_action('woocommerce_checkout_process', array($this, 'orderProcessed'));
        add_action('woocommerce_cart_calculate_fees', array($this, 'woo_add_cart_fee'), 100, 1);
        add_filter('woocommerce_calculated_total', function () {
            @session_start();
            global $woocommerce;
            if (is_cart()) {
                ?>
                <style type="text/css">
                    form.woocommerce-shipping-calculator {
                        display: none;
                    }

                    #shipping_method span.woocommerce-Price-amount {
                        display: none;
                    }

                    .shipping span.woocommerce-Price-amount.amount {
                        display: none !important;
                    }

                    .woocommerce-cart th.product-price {
                        display: none;
                    }

                    .woocommerce-cart td.product-price {
                        display: none;
                    }
                </style>
            <?php
            }
            $productaray1 = array();
            $productaray2 = array();
            $chosen_methods = WC()->session->get('chosen_shipping_methods');
            $chosen_shipping = $chosen_methods[0];
            if (sizeof(WC()->cart->get_cart()) > 0) {
                foreach (WC()->cart->get_cart() as $cart_item) {
                    if ($cart_item['product_id']) {
                        $prodId = $cart_item['product_id'];
                        $Blazeproductid = get_post_meta($prodId, 'Blaze_woo_product_id', true);
                        $producttype = get_post_meta($prodId, 'producttype', true);

                        // get product type and attributes.
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


            //Sort shopping
            if ($chosen_shipping == 'legacy_local_delivery') {
                $chosen_shipping = "Delivery";
            ?>
                <style type="text/css">
                    p.woocommerce-shipping-destination {
                        display: block !important;
                    }
                </style>
                <?php
            } else {
                $chosen_shipping = "Pickup";
                if (is_cart()) {
                ?>
                    <style type="text/css">
                        p.woocommerce-shipping-destination {
                            display: none !important;
                        }
                    </style>
                <?php
                }
            }
            $mandatoryFee = 0;
            if ($chosen_shipping == "Pickup") {
                //Pickup type, mandatory product..
                $pickupTypeMandateProduct = get_option('Blaze_required_pickup_product');
                if ($pickupTypeMandateProduct == "") {
                    //Product was Empty
                } else {
                    $apidomain = get_option('Blaze_api_domain');
                    $apikey = get_option('Blaze_api_key');
                    $checkCartUrl = $apidomain . "/api/v1/store/inventory/products/" . $pickupTypeMandateProduct . "?api_key=" . $apikey;

                    $checkCartData = wp_remote_post($checkCartUrl, array(
                        'headers' => array('Content-Type' => 'application/json; charset=utf-8'),
                        'method' => 'GET'
                    ));
                    //Product Not Found
                    $outputProduct = json_decode($checkCartData['body']);
                    if ($outputProduct->message != "") {
                        //print_r('{"result":"failure","messages":"Mandatory Pickup Product Not Found. Contact site-owner to resolve the issue.","reload":"false"}');
                    } else {
                        $mandatoryProduct[] = array(
                            "productId" => $outputProduct->id,
                            "quantity" => 1,
                            "useUnitQty" => false,
                        );
                        $mandatoryFee = $outputProduct->unitPrice;
                        $productaray = array_merge($productaray, $mandatoryProduct);
                    }
                }
            }


            $server_output = $this->woocommerceinfo($productaray, "__construct");
            $message = $server_output->message;
            if ($message != '') {
                $server_output = $_SESSION['server_output'];
            }
            $this->productsArray = $server_output;
            $subTotal = $server_output->subTotal;
            $this->order_data['total_amount'] = $server_output->total;

            if ($chosen_shipping == 'Delivery') {
                WC()->cart->set_subtotal($subTotal);
                return $this->order_data['total_amount'];
            } else {

                WC()->cart->set_subtotal($subTotal - $mandatoryFee);
                $this->order_data['total_amount'] = $server_output->total - $server_output->deliveryFee;
                return $this->order_data['total_amount'];
            }
        });

        add_filter('woocommerce_cart_item_subtotal', array($this, 'cartItemSubtotal'), 10, 9);
        add_action('woocommerce_add_to_cart', array($this, 'action_woocommerce_add_to_cart'), 10, 6);
        add_filter('woocommerce_cart_shipping_method_full_label', array($this, 'wdo_remove_shipping_label_cart_page'), 10, 2);
        add_action('woocommerce_checkout_update_order_meta', array($this, 'before_checkout_create_order'), 20, 2);
        add_action('woocommerce_add_to_cart_validation', array($this, 'addToCart'), 1, 5);
        add_filter('woocommerce_coupon_error', array($this, 'woocommerce_coupon_error'), 10, 3);
        remove_filter('woocommerce_coupon_code', array($this, 'strtolower'), 1);
        add_action('woocommerce_cart_totals_after_shipping', array($this, 'action_woocommerce_after_carts'), 10, 0);
        add_action('woocommerce_review_order_after_shipping', array($this, 'action_woocommerce_review_order_after_shipping'), 10, 2);
        add_action('woocommerce_after_cart_table', array($this, 'action_woocommerce_after_cart_contents'), 10, 0);
        add_action('woocommerce_before_cart_table', array($this, 'wpdesk_cart_free_shipping_text'), 10, 2);
        add_action('woocommerce_after_checkout_form', array($this, 'action_woocommerce_after_cart_contents'), 10, 0);
        add_action('woocommerce_remove_cart_item', array($this, 'woocommerce_after_remove_product_call'), 10, 2);
        add_action('woocommerce_cart_totals_before_order_total', array($this, 'action_woocommerce_cart_totals_before_order_total'), 10, 0);
    }

    function action_woocommerce_cart_totals_before_order_total()
    {
        @session_start();
        $paymentoption = "Cash";
        if ($_SESSION['paymentoption']) {
            $paymentoption = $_SESSION['paymentoption'];
        }
        echo '<tr class="paymentoption">
                <th>Payment Method</th>
                <td data-title="Payment Method"><span class="woocommerce-Price-amount amount"><span class="woocommerce-Price-currencySymbol payment-method">' . $paymentoption . '</td>
            </tr>';
    }

    function remove_product($product_id) {
	    @session_start();
	    $error_logger = new Blaze_error_logger();
	    $shop_id = $this->get_shop_id($product_id);
        $error_logger->log(json_encode($product_id));
	    $carts = [];
	    if(isset($_SESSION['carts'])) {
		    $carts = $_SESSION['carts'];
	    }

	    $carts[$shop_id] = array_diff($carts[$shop_id], [$product_id]);
	    if(count($carts[$shop_id]) == 0) {
	        unset($carts[$shop_id]);
	    }
        $message = "Remove element " . json_encode($carts);
	    $error_logger->log($message);

	    $_SESSION['carts'] = $carts;

	    return $shop_id;
    }

    function woocommerce_after_remove_product_call($cart_item_key, $cart)
    {
        @session_start();
        $product_id = $cart->cart_contents[$cart_item_key]['product_id'];
	    $Blazeproductid = get_post_meta($product_id, 'Blaze_woo_product_id', true);
        $this->remove_product($Blazeproductid);
        $qty = $cart->cart_contents[$cart_item_key]['quantity'];
        $productaray1 = array();
        $productaray2 = array();
        global $woocommerce;
        if (sizeof(WC()->cart->get_cart()) > 1) {
            foreach (WC()->cart->get_cart() as $cart_item) {
                if ($cart_item['product_id'] != $product_id) {
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
        } else {
            $_SESSION['coupon_code'] = '';
            $_SESSION['reward_name'] = '';
        }
        $productaray = array_merge($productaray1, $productaray2);
        $server_output = $this->woocommerceinfo($productaray, "woocommerce_after_remove_product_call");
        $message = $server_output->message;
        if ($message) {
            wp_redirect(WC()->cart->get_cart_url());
            exit();
        }
    }

    function wpdesk_cart_free_shipping_text()
    {
        if ($_SESSION['errormessage']) {
            echo '<p style="color: #ff0000">' . $_SESSION['errormessage'] . '</p>';
            unset($_SESSION['errormessage']);
        }
    }

    function action_woocommerce_after_cart_contents()
    {
        @session_start();
        $rewardnameoption = get_option('Blaze_reward_name');
        $blazepaymentoptions = get_option('Blaze_payment_options');
        $apidomain = get_option('Blaze_api_domain');
        $apikey = get_option('Blaze_api_key');
        $rewasrmessage = '';
        if (isset($_SESSION['rewardmessage'])) {
            $rewasrmessage = $_SESSION['rewardmessage'];
            unset($_SESSION['rewardmessage']);
        }
        if ($rewardnameoption == 'yes') {
            if (is_user_logged_in()) {
                $status = 0;
                $user = wp_get_current_user();
                $membership = get_user_meta($user->ID, 'membershipAccepted', false);
                $token = get_user_meta($user->ID, 'token', false);
                $blazeUserId = get_user_meta($user->ID, 'Blaze_woo_user_id', false);
                $blazeUserId = $blazeUserId[0];
                $memberstatus = $membership[0];
                // if($memberstatus != 1){
                $currentuseremail = $user->user_email;

                $url = $apidomain . "/api/v1/store/user/consumerByEmail?api_key=" . $apikey . "&email=" . $currentuseremail;
                $data = wp_remote_get($url);
                $response = json_decode($data['body']);

                if ($blazeUserId == "") {
                    $blazeUserId  = $response->id;
                    update_user_meta($user->ID, 'Blaze_woo_user_id', $blazeUserId);
                }

                $loyaltyPoints = $response->member->loyaltyPoints;
                $memberstatus = (array) $response->member;
                if (!empty($memberstatus)) {
                    $status = 1;
                    update_usermeta($user->ID, 'membershipAccepted', true);
                }
            }
            if ($token[0] != '' && ($memberstatus == 1 || $status == 1)) {
                $url = $apidomain . "/api/v1/partner/loyalty/rewards/members/" . $blazeUserId . "?api_key=" . $apikey;
                $data = wp_remote_get($url);
                $response = json_decode($data['body']);
                $allrewardname = $response->values;
                ?>
                <link href="https://cdnjs.cloudflare.com/ajax/libs/select2/4.0.10/css/select2.min.css" rel="stylesheet" />
                <script src="https://cdnjs.cloudflare.com/ajax/libs/select2/4.0.10/js/select2.min.js"></script>
                <div class="rewardform blaze_reward_select">
                    <form class="col-lg-8 padding-bottom-2x woocommerce-cart-form" id="ajax-coupon-redeem">
                        <div class="cart-coupon">
                            <p class="text-gray text-sm">Choose Reward</p>
                            <div class="col-md-8 col-sm-7  choose-reward payment-option">
                                <div class=" coupon-input">
                                    <div class="form-element">
                                        <label class="screen-reader-text" for="reward_name">Reward:</label>
                                        <select name="coupon" class="rewardsname" id="coupon" name="rewards[]" multiple="multiple">
                                            <option value="">Choose reward</option>
                                            <?php
                                            foreach ($allrewardname as $rewardname) {
                                                echo "<option value='$rewardname->rewardName'>$rewardname->rewardName | $rewardname->pointsRequired PTS | $rewardname->discountInfo</option>";
                                            }
                                            ?>
                                        </select>
                                    </div>
                                </div>
                                <div class=" coupon-btn">
                                    <input type="button" class="button btn btn-default btn-ghost btn-block space-top-none space-bottom input-text"" id=" redeen-reward" name="redeem-coupon" value="Apply Reward">
                                </div>
                            </div>
                            <p class="loyaltyPoints">Your total loyalty points: <?php echo $loyaltyPoints; ?></p>
                            <p class="reward-result" style="color: #ff0000"></p>
                            <?php
                            if ($rewasrmessage) {
                                echo '<p class="reward" style="color: #008000">' . $rewasrmessage . '</p>';
                            }
                            ?>
                        </div>
                    </form>
                </div>
                <script>
                    jQuery(document).ready(function() {
                        jQuery('.rewardsname').select2();
                    });
                </script>
            <?php
            }
        }
        if ($blazepaymentoptions == 'yes') {
            //if (is_user_logged_in()) {
            $url = $apidomain . "/api/v1/store/paymentoptions?api_key=" . $apikey;
            $data = wp_remote_get($url);
            $response = json_decode($data['body']);
            $paymentoption = $response->values;
            ?>
            <style type="text/css">
                .payment-option {
                    display: flex;
                }

                .payment-option select {
                    width: 100%;
                    max-width: 100%;
                    min-width: 214px;
                }

                .payment-option .coupon-btn {
                    margin-left: 3px;
                }
            </style>
            <div class="blaze-payment-option">
                <p class="text-gray choose-payment text-sm">Choose Payment Option</p>
                <div class="col-md-8 col-sm-7 payment-option coupon-input">
                    <div class=" coupon-input">
                        <div class="form-element">
                            <label class="screen-reader-text" for="payment_name">Payment option:</label>
                            <select class="input-text" name="payment-option" id="payment_option" required>
                                <?php
                                foreach ($paymentoption as $option) {
                                    if ($option->enabled && $option->paymentOption != 'Split') {
                                        $selected_option = 'Hello ' . ($option->paymentOption == $_SESSION['paymentoption'] ? 'selected' : '');
                                        echo '<option value="' . $option->paymentOption . '"  ' . $selected_option . '>' . $option->paymentOption . '</option>';
                                ?>
                                    <?php } ?>
                                <?php } ?>
                            </select>
                        </div>
                    </div>
                    <div class=" coupon-btn">
                        <input type="button" class="button btn btn-default btn-ghost btn-block space-top-none space-bottom" id="select-paymnet" name="select-paymnet" value="Apply">
                    </div>
                </div>
            </div>
            <?php
        }
    }


    function blz_format_price($price)
    {
        global $woocommerce;
        if (strpos($price, ".") == false) {
            $pieces = $price .= ".00";
        } else {
            $pieces = explode(".", $price);

            if (strlen($pieces[1]) == 1) {
                $pieces = $price .= "0";
            }
        }

        $price =  "$" . $price;

        return $price;
    }

    function action_woocommerce_review_order_after_shipping()
    {
        @session_start();
	    $error_logger = new Blaze_error_logger();
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

	    /*$carts = $_SESSION['carts'];
	    $cart_ids = [];

	    foreach (array_keys($carts) as $value) {
	        $result_array = [];
		    foreach ($carts[$value] as $prod) {
			    $newArray = array_filter($productaray, function ($k) use ($prod) {
		            return $k['productId'] == $prod;
                });
                $result_array = array_merge($result_array, $newArray);
		    }

		    $server_output = $this->woocommerceinfo($result_array, "action_woocommerce_review_order_after_shipping", $value);
		    $cart_ids[$value] = Array($server_output->consumerCartId);
	    }

	    $mm = "Cart ids " . json_encode($cart_ids);
	    $error_logger->log( $mm );
        $_SESSION['cart_ids'] = $cart_ids*/;

        //$mess = "all products " . json_encode($productaray);
        //$error_logger->log($mess);
        $server_output = $this->woocommerceinfo($productaray, "action_woocommerce_review_order_after_shipping");
        //$mmmm = "response server " . json_encode($server_output);
        //$error_logger->log($mmmm);
        $message = $server_output->message;
        if ($message != '') {
            $server_output = $_SESSION['server_output'];
        }
        $discount = $server_output->totalDiscount;
        $promoCode = $server_output->promoCode;
        $rewardName = $_SESSION['reward_name'];


        if (substr($rewardName, 0, 1) == ",") {
            $rewardName = substr($rewardName, 1);
        }

        //
        //
        //Start of Taxes
        $apidomain = get_option('Blaze_api_domain');
        $apikey = get_option('Blaze_api_key');
        $url = $apidomain . "/api/v1/store/taxes?api_key=" . $apikey;
        $data = wp_remote_get($url);
        $response = json_decode($data['body']);
        $responseArray = [];
        foreach ($response as $resp) {
            $responseArray[] = (array) $resp;
        }
        $city = array_search('City', array_column($responseArray, 'taxTerritory'));
        $county = array_search('County', array_column($responseArray, 'taxTerritory'));
        $state = array_search('State', array_column($responseArray, 'taxTerritory'));
        $federal = array_search('Federal', array_column($responseArray, 'taxTerritory'));

        // total
        $totalTax = $server_output->taxResult->totalPostCalcTax;
        if ($totalTax != '') {
            echo '
            <tr>
                <th>Total Tax</th>
                <td>' . wc_price($totalTax) . '</td>
            </tr>
            ';
        }

        //Excise
        $totalExciseTax = $server_output->taxResult->totalExciseTax;
        $totalALPostExciseTax = $server_output->taxResult->totalALPostExciseTax;
        $totalExcise = $totalExciseTax + $totalALPostExciseTax;

        if ($totalExcise != '') {
            echo '
            <tr>
                <th style="font-weight: 300">&nbsp;&nbsp;Excise Tax</th>
                <td style="position: relative; left: -7px;">' . $this->blz_format_price($totalExcise) . '</td>
            </tr>
            ';
        }

        //City
        $totalCityTax = $server_output->taxResult->totalCityTax;
        if ($totalCityTax != '') {
            echo '
            <tr>
                <th style="font-weight: 300">&nbsp;&nbsp;' . $responseArray[$city]['name'] . ' Tax</th>
                <td style="position: relative; left: -7px;">' . $this->blz_format_price($totalCityTax) . '</td>
            </tr>
            ';
        }

        //County
        $totalCountyTax = $server_output->taxResult->totalCountyTax;
        if ($totalCountyTax != '') {
            echo '
            <tr>
                <th style="font-weight: 300">&nbsp;&nbsp;' . $responseArray[$county]['name'] . ' Tax</th>
                <td style="position: relative; left: -7px;">' . $this->blz_format_price($totalCountyTax) . '</td>
            </tr>
            ';
        }

        //State
        $totalStateTax = $server_output->taxResult->totalStateTax;
        if ($totalStateTax != '') {
            echo '
            <tr>
                <th style="font-weight: 300">&nbsp;&nbsp;' . $responseArray[$state]['name'] . ' Tax</th>
                <td style="position: relative; left: -7px;">' . $this->blz_format_price($totalStateTax) . '</td>
            </tr>
            ';
        }

        //Federal
        $totalFederalTax = $server_output->taxResult->totalFedTax;
        if ($totalFederalTax != '') {
            echo '
            <tr>
                <th style="font-weight: 300">&nbsp;&nbsp;' . $responseArray[$federal]['name'] . ' Tax</th>
                <td style="position: relative; left: -7px;">' . $this->blz_format_price($totalFederalTax) . '</td>
            </tr>
            ';
        }
        //End of custom taxes
        //
        //


        $errorMsg = $server_output->errorMsg;
        if ($promoCode) {
            $listPromoCode = explode(",", $promoCode);
            if (isset($discount) && $discount != null) {
            ?>
                <tr class="cart-discount coupon-test5">
                    <th>Coupon:</th>
                    <td>
                        <?php
                        foreach ($listPromoCode as &$value) {
                        ?>
                            <span class="woocommerce-Price-amount amount">
                                <span class="woocommerce-Price-currencySymbol">
                                </span>
                                <?= $value; ?>
                            </span>
                            <a href="javascript:void(0);" class="woocommerce-remove-coupon-blaze" data-coupon="<?= $value; ?>">
                                x
                            </a>
                        <?php } ?>
                    </td>
                </tr>
            <?php
            } else {
            ?>
                <tr class="cart-discount coupon-test5">
                    <th>Coupon:</th>
                    <td>
                        <?php
                        foreach ($listPromoCode as &$value) {
                        ?>
                            <span class="woocommerce-Price-amount amount">
                                <span class="woocommerce-Price-currencySymbol">
                                </span>
                                <?= $value; ?>
                            </span>
                            <a href="javascript:void(0);" class="woocommerce-remove-coupon-blaze" data-coupon="<?= $value; ?>">
                                x
                            </a>
                        <?php } ?>
                    </td>
                </tr>
            <?php
            }
        }
        if ($rewardName != '' && $rewardName != "remove" && $rewardName != ",remove") {
            echo '<tr class="cart-discount coupon-test5">
                <th>Reward Name:</th>
                <td data-title="Coupon: ' . $rewardName . '"><span class="woocommerce-Price-amount amount"><span class="woocommerce-Price-currencySymbol"></span>' . $rewardName . '</span> <a href="javascript:void(0);" class="woocommerce-remove-reward-blaze" data-coupon="' . $rewardName . '">x</a></td>
            </tr>';
        }
    }



    function action_woocommerce_after_carts()
    {
        @session_start();
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

        $server_output = $this->woocommerceinfo($productaray, "action_woocommerce_after_carts");
        $message = $server_output->message;
        if ($message != '') {
            $server_output = $_SESSION['server_output'];
        }
        $discount = $server_output->totalDiscount;
        $promoCode = $server_output->promoCode;
        $rewardName = $_SESSION['reward_name'];

        if (substr($rewardName, 0, 1) == ",") {
            $rewardName = substr($rewardName, 1);
        }

        //
        //
        //Start of Taxes
        $apidomain = get_option('Blaze_api_domain');
        $apikey = get_option('Blaze_api_key');
        $url = $apidomain . "/api/v1/store/taxes?api_key=" . $apikey;
        $data = wp_remote_get($url);
        $response = json_decode($data['body']);
        $responseArray = [];
        foreach ($response as $resp) {
            $responseArray[] = (array) $resp;
        }
        $city = array_search('City', array_column($responseArray, 'taxTerritory'));
        $county = array_search('County', array_column($responseArray, 'taxTerritory'));
        $state = array_search('State', array_column($responseArray, 'taxTerritory'));
        $federal = array_search('Federal', array_column($responseArray, 'taxTerritory'));

        // total
        $totalTax = $server_output->taxResult->totalPostCalcTax;
        if ($totalTax != '') {
            echo '
            <tr>
                <th>Total Tax</th>
                <td>' . wc_price($totalTax) . '</td>
            </tr>
            ';
        }

        //Excise
        $totalExciseTax = $server_output->taxResult->totalExciseTax;
        $totalALPostExciseTax = $server_output->taxResult->totalALPostExciseTax;
        $totalExcise = $totalExciseTax + $totalALPostExciseTax;

        if ($totalExcise != '') {
            echo '
            <tr>
                <th style="font-weight: 300">&nbsp;&nbsp;Excise Tax</th>
                <td style="position: relative; left: -7px;">' . $this->blz_format_price($totalExcise) . '</td>
            </tr>
            ';
        }

        //City
        $totalCityTax = $server_output->taxResult->totalCityTax;
        if ($totalCityTax != '') {
            echo '
            <tr>
                <th style="font-weight: 300">&nbsp;&nbsp;' . $responseArray[$city]['name'] . ' Tax</th>
                <td style="position: relative; left: -7px;">' . $this->blz_format_price($totalCityTax) . '</td>
            </tr>
            ';
        }

        //County
        $totalCountyTax = $server_output->taxResult->totalCountyTax;
        if ($totalCountyTax != '') {
            echo '
            <tr>
                <th style="font-weight: 300">&nbsp;&nbsp;' . $responseArray[$county]['name'] . ' Tax</th>
                <td style="position: relative; left: -7px;">' . $this->blz_format_price($totalCountyTax) . '</td>
            </tr>
            ';
        }

        //State
        $totalStateTax = $server_output->taxResult->totalStateTax;
        if ($totalStateTax != '') {
            echo '
            <tr>
                <th style="font-weight: 300">&nbsp;&nbsp;' . $responseArray[$state]['name'] . ' Tax</th>
                <td style="position: relative; left: -7px;">' . $this->blz_format_price($totalStateTax) . '</td>
            </tr>
            ';
        }

        //Federal
        $totalFederalTax = $server_output->taxResult->totalFedTax;
        if ($totalFederalTax != '') {
            echo '
            <tr>
                <th style="font-weight: 300">&nbsp;&nbsp;' . $responseArray[$federal]['name'] . ' Tax</th>
                <td style="position: relative; left: -7px;">' . $this->blz_format_price($totalFederalTax) . '</td>
            </tr>
            ';
        }
        //End of custom taxes
        //
        //


        $errorMsg = $server_output->errorMsg;
        if ($rewardName != '' && $rewardName != "remove" && $rewardName != ",remove") {
            echo '<tr class="cart-discount reward-test5">
                <th>Reward Name:</th>
                <td data-title="Coupon: ' . $rewardName . '"><span class="woocommerce-Price-amount amount"><span class="woocommerce-Price-currencySymbol"></span>' . $rewardName . '</span> <a href="javascript:void(0);" class="woocommerce-remove-reward-blaze" data-coupon="' . $promoCode . '">x</a></td>
            </tr>';
        }
        if ($promoCode) {
            $listPromoCode = explode(",", $promoCode);
            if (isset($discount) && $discount != null) {
            ?>
                <tr class="cart-discount coupon-test5">
                    <th>Coupon:</th>
                    <td>
                        <?php
                        foreach ($listPromoCode as &$value) {
                        ?>
                            <span class="woocommerce-Price-amount amount">
                                <span class="woocommerce-Price-currencySymbol">
                                </span>
                                <?= $value; ?>
                            </span>
                            <a href="javascript:void(0);" class="woocommerce-remove-coupon-blaze" data-coupon="<?= $value; ?>">
                                x
                            </a>
                        <?php } ?>
                    </td>
                </tr>
            <?php
            } else {
            ?>
                <tr class="cart-discount coupon-test5">
                    <th>Coupon:</th>
                    <td>
                        <?php
                        foreach ($listPromoCode as &$value) {
                        ?>
                            <span class="woocommerce-Price-amount amount">
                                <span class="woocommerce-Price-currencySymbol">
                                </span>
                                <?= $value; ?>
                            </span>
                            <a href="javascript:void(0);" class="woocommerce-remove-coupon-blaze" data-coupon="<?= $value; ?>">
                                x
                            </a>
                        <?php } ?>
                    </td>
                </tr>
                <?php
            }
        }
    }

    function kfg_show_backorders($is_visible, $id)
    {
        $product = new wC_Product($id);

        if (!$product->is_in_stock() && !$product->backorders_allowed()) {
            $is_visible = false;
        }

        return $is_visible;
    }

    function validate_coupon_dont_repeate($coupon)
    {
        @session_start();
        $couponList = explode(",", $_SESSION['coupon_code']);
        $couponList = array_filter($couponList, function ($k) use ($coupon) {
            return $k == $coupon;
        });
        return count($couponList) == 0;
    }

    function woocommerce_coupon_error($err, $err_code, $coupon)
    {
        @session_start();
        if ($_POST['coupon_code'] != '') {
            $input = explode("-", $_POST['coupon_code']);
            if (count($input) < 2) {
                if ($this->validate_coupon_dont_repeate($_POST['coupon_code'])) {
                    if ($_SESSION['coupon_code'] == '') {
                        $_SESSION['coupon_code'] = $_POST['coupon_code'];
                    } else {
                        $_SESSION['coupon_code'] = $_SESSION['coupon_code'] . ',' . $_POST['coupon_code'];
                    }
                } else {
                    $err = "Coupon code has already been added.";
                    return $err;
                }
            } else {
                $result = explode(",", $_SESSION['coupon_code']);
                $result = array_filter($result, function ($k) use ($input) {
                    return $k != $input[1];
                });
                $_SESSION['coupon_code'] = join(",", $result);
            }

            global $woocommerce;
            $productaray1 = array();
            $productaray2 = array();

            // check cart object and apply coupon on cart items
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
            $server_output = $this->woocommerceinfo($productaray, "woocommerce_coupon_error");
            $message = $server_output->message;
            if ($message != '') {
                $server_output = $_SESSION['server_output'];
            }
            $message = $server_output->totalDiscount;
            $promoCode = $server_output->promoCode;
            $membergroup = $server_output->memberGroup->discount;
            if ($message == 0 && $promoCode != '' && count($input) < 2) {
                //Check if delivery fee different
                $_SESSION['coupon_code'] = $server_output->promoCode;
                $err = "Coupon code applied successfully.";
                return $err;
            } else if ($message == '' || count($input) > 1) {
                if (count($input) > 1) {
                ?>
                    <script type="text/javascript">
                        var couponcode = '';
                        jQuery("#coupon_code").val(couponcode);
                    </script>
        <?php
                    $_SESSION['coupon_code'] = $server_output->promoCode;
                    $_SESSION['sessionId'] = '';
                    $err = "Coupon code removed successfully.";
                    return $err;
                }
                $_SESSION['coupon_code'] = '';
                return $err;
            } else if ($promoCode) {
                $_SESSION['coupon_code'] = $server_output->promoCode;
                $err = "Coupon code applied successfully.";
                return $err;
            } else if ($message == $membergroup && $promoCode == '' && count($input) < 2) {
                $_SESSION['coupon_code'] = '';
                return $err;
            } else if ($message != '') {
                $_SESSION['coupon_code'] = '';
                $err = $message;
                return $err;
            } else {
                $_SESSION['coupon_code'] = '';
                $err = "Coupon code removed successfully.";
            }
        }
        $_SESSION['coupon_code'] = '';
        return $err;
    }

    // function to add items to the current cart of the user.
    public function addToCart($passed, $product_id, $quantity, $variation_id = 0, $variations = array())
    {
        global $woocommerce;
        @session_start();
        $productaray1 = array();
        $productaray2 = array();
        $productaray3 = array();
        $productaray4 = array();
	    $error_logger = new Blaze_error_logger();


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
        } else {
            $_SESSION['coupon_code'] = '';
            $_SESSION['carts'] = [];
        }
        $productaray12 = array_merge($productaray1, $productaray2);
        $Blazeproductid = get_post_meta($product_id, 'Blaze_woo_product_id', true);
        $qty = $quantity;
        $producttype = get_post_meta($product_id, 'producttype', true);
        if ($producttype == 'grams') {
            $productattributes = get_post_meta($product_id, 'productattributes', true);
            $productattributesarray = json_decode($productattributes);
            if (isset($variations['attribute_grams'])) {
                $variations = $variations['attribute_grams'];
            }
            foreach ($productattributesarray as $value) {
                if ($value->name == $variations) {
                    $weightKey = $value->weightKey;
                }
            }
            $productaray3[] = array(
                "productId" => $Blazeproductid,
                "quantity" => $qty,
                "useUnitQty" => true,
                "weightKey" => $weightKey
            );
        } else {
            $productaray4[] = array(
                "productId" => $Blazeproductid,
                "quantity" => $qty,
                "useUnitQty" => false,
            );
        }

        $productaray123 = array_merge($productaray3, $productaray4);
        $productaray = array_merge($productaray12, $productaray123);
        $error_logger->log(json_encode($productaray));

	    $shop_id = $this->save_shops_and_products($Blazeproductid);
        /*$carts = $_SESSION['carts'];
        $result_array = [];
	    $shop_id = $this->save_shops_and_products($Blazeproductid);
        foreach ($carts[$shop_id] as $value) {
            $data = array_filter($productaray, function($arr) use ($value) {
                return $arr->productId == $value;
            });
            $result_array = array_merge($result_array, $data);
        }*/

        $server_output = $this->woocommerceinfo($productaray, "addToCart", $shop_id);
        /*$ss = "array_result " . json_encode($result_array);
        $error_logger->log($ss);
	    $server_output = $this->woocommerceinfo($result_array, "addToCart", $shop_id);*/
        $this->productsArray = $server_output;
        $productinfo = $server_output->message;
        if ($productinfo) {
            $message = $productinfo;
            wc_add_notice($message, 'error');
            return false;
        }
        return true;
    }

    function get_shop_id($product_id) {
	    global $wpdb;
	    $table_name = 'wp_Blaze_product';
	    $retrieve_data = $wpdb->get_results("SELECT shopId FROM $table_name WHERE pro_id = '$product_id' LIMIT 1");
	    $shop_id = $retrieve_data[0]->shopId;
	    return $shop_id;
    }

    function save_shops_and_products($product_id) {
        @session_start();

	    $error_logger = new Blaze_error_logger();
	    $shop_id = $this->get_shop_id($product_id);

	    $carts = [];
	    if(isset($_SESSION['carts'])) {
		    $carts = $_SESSION['carts'];
	    }

	    if(isset($carts[$shop_id])) {
		    $existShop = array_filter($carts[$shop_id], function ($k) use ($product_id) {
			    return $k == $product_id;
		    });
		    if(count($existShop) == 0) {
			    array_push( $carts[$shop_id], $product_id);
		    }
	    } else {
		    $carts[$shop_id] = [$product_id];
	    }
        $message = "Added Product " . json_encode($carts);
	    $error_logger->log($message);

	    $_SESSION['carts'] = $carts;

	    return $shop_id;
    }

    // function to place an order in woocommerce with blaze order id.

    function before_checkout_create_order($order_id)
    {
        $order = wc_get_order($order_id);
        $blazeId = $_SESSION['blazeId'];
        $consumerOrderId = $_SESSION['consumerOrderId'];
        update_post_meta($order_id, 'Blaze_woo_consumerOrderId', $consumerOrderId);
        update_post_meta($order_id, 'Blaze_woo_order_id', $blazeId);
        update_post_meta($order_id, '_payment_method_title', $_SESSION['paymentOption']);
        unset($_SESSION['blazeId']);
        unset($_SESSION['consumerOrderId']);
        unset($_SESSION['pickUpAddress']);
        unset($_SESSION['pickUpcountry']);
        unset($_SESSION['coupon_code']);
        unset($_SESSION['reward_name']);
        unset($_SESSION['paymentOption']);
        global $woocommerce;

        $server_output = $this->productsArray;
        $i = 0;
        foreach ($order->get_items() as $item_values) :
            $linetotal = $server_output->items[$i]->finalPrice;
            $item_id = $item_values->get_id();
            wc_update_order_item_meta($item_id, '_line_subtotal', $linetotal);
            wc_update_order_item_meta($item_id, '_line_total', $linetotal);
            $i++;
        endforeach;
    }

    // to remove : from shipping method names on cart and checkout pages

    function wdo_remove_shipping_label_cart_page($label, $method = NULL)
    {
        $shipping_label = str_replace(':', '', $label);
        return $shipping_label;
    }

    // define the woocommerce_add_to_cart callback 

    function action_woocommerce_add_to_cart($cart_item_key, $product_id, $quantity, $variation_id, $variation, $cart_item_data)
    {

        global $woocommerce;
        if (sizeof(WC()->cart->get_cart()) > 0) {
            foreach (WC()->cart->get_cart() as $cart_item) {
                $prodId = $cart_item['product_id'];
                $variationid = $cart_item['variation_id'];
                if ($prodId == $product_id) {
                    if ($variationid == $variation_id) {
                        $qty = $cart_item['quantity'];
                    }
                }
            }
        }
        $qty = $qty - 1;
        $producttype = get_post_meta($product_id, 'producttype', true);

        if ($producttype == 'units') {
            $productattributes = get_post_meta($product_id, 'productattributes', true);
            $payload = json_decode($productattributes);
            $variations = $variation['attribute_units'];

            foreach ($payload as $value) {
                if ($value->name == $variations) {
                    $vquantity = $value->quantity;
                    $quantity = $vquantity + $qty;
                }
            }
            WC()->cart->set_quantity($cart_item_key, $quantity);
        }
    }

    // function to add taxes/fee in the cart

    public function woo_add_cart_fee()
    {
        @session_start();
        global $woocommerce;
        $apidomain = get_option('Blaze_api_domain');
        $apikey = get_option('Blaze_api_key');
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

        //Sort shopping
        if ($chosen_shipping == 'legacy_local_delivery') {
            $chosen_shipping = "Delivery";
        } else {
            $chosen_shipping = "Pickup";
        }
        $mandatoryFee = 0;
        $mandatoryFeeName = "";
        if ($chosen_shipping == "Pickup") {
            //Pickup type, mandatory product..
            $pickupTypeMandateProduct = get_option('Blaze_required_pickup_product');
            if ($pickupTypeMandateProduct == "") {
                //Product was Empty
            } else {
                $checkCartUrl = $apidomain . "/api/v1/store/inventory/products/" . $pickupTypeMandateProduct . "?api_key=" . $apikey;

                $checkCartData = wp_remote_post($checkCartUrl, array(
                    'headers' => array('Content-Type' => 'application/json; charset=utf-8'),
                    'method' => 'GET',
                ));
                //Product Not Found
                $outputProduct = json_decode($checkCartData['body']);
                if ($outputProduct->message != "") {
                    //print_r('{"result":"failure","messages":"Mandatory Pickup Product Not Found. Contact site-owner to resolve the issue.","reload":"false"}');   
                } else {
                    $mandatoryProduct[] = array(
                        "productId" => $outputProduct->id,
                        "quantity" => 1,
                        "useUnitQty" => false,
                    );
                    $mandatoryFeeName = $outputProduct->name;
                    $mandatoryFee = $outputProduct->unitPrice;

                    $productaray = array_merge($productaray, $mandatoryProduct);
                }
            }
        }

        $server_output = $this->woocommerceinfo($productaray, "woo_add_cart_fee");

        $message = $server_output->message;
        if ($message != '') {
            $server_output = $_SESSION['server_output'];
        }
        $totalTax = $server_output->taxResult->totalPostCalcTax;
        $totalCityTax = $server_output->taxResult->totalCityTax;
        $totalStateTax = $server_output->taxResult->totalStateTax;
        $totalCountyTax = $server_output->taxResult->totalCountyTax;
        $totalExciseTax = $server_output->taxResult->totalExciseTax;
        $totalALPostExciseTax = $server_output->taxResult->totalALPostExciseTax;
        $membergroup = $server_output->memberGroup->discount;
        $totalExcise = $totalExciseTax + $totalALPostExciseTax;
        $deliveryFee = $server_output->deliveryFee;
        $promoCode = $server_output->promoCode;
        $rewardName = $server_output->rewardName;
        $discount = $server_output->totalDiscount;
        $creditCardFee = $server_output->creditCardFee;
        $paymentoption = "Cash";
        if ($_SESSION['paymentoption']) {
            $paymentoption = $_SESSION['paymentoption'];
        }
        $url = $apidomain . "/api/v1/store/taxes?api_key=" . $apikey;
        $data = wp_remote_get($url);
        $response = json_decode($data['body']);
        $responseArray = [];
        foreach ($response as $resp) {
            $responseArray[] = (array) $resp;
        }
        $city = array_search('City', array_column($responseArray, 'taxTerritory'));
        $county = array_search('County', array_column($responseArray, 'taxTerritory'));
        $state = array_search('State', array_column($responseArray, 'taxTerritory'));
        //Removed taxes from fees, added functionallity to rewards/coupons hook.
        if ($totalCityTax != '') {
            //$woocommerce->cart->add_fee(__('Total ' . $responseArray[$city]['name'] . ' Tax', 'woocommerce'), $totalCityTax);
        }
        if ($totalExcise != '') {
            // $woocommerce->cart->add_fee(__('Total Excise Tax', 'woocommerce'), $totalExcise);
        }
        if ($totalStateTax != '') {
            //$woocommerce->cart->add_fee(__('Total ' . $responseArray[$state]['name'] . ' Tax', 'woocommerce'), $totalStateTax);
        }

        if ($totalCountyTax != '') {
            // $woocommerce->cart->add_fee(__('Total ' . $responseArray[$county]['name'] . ' Tax', 'woocommerce'), $totalCountyTax);
        }
        if ($totalTax != '') {
            //$woocommerce->cart->add_fee(__('Total Tax', 'woocommerce'), $totalTax);
        }
        if ($chosen_shipping == 'Delivery') {
            $woocommerce->cart->add_fee(__('Delivery Fee', 'woocommerce'), $deliveryFee);
        }
        if ($membergroup != '' && $promoCode == '') {
            $woocommerce->cart->add_fee(__('Cart Discount', 'woocommerce'), $membergroup);
        }
        if ($creditCardFee != '' && $creditCardFee != 0) {
            $woocommerce->cart->add_fee(__('Credit Card Fee', 'woocommerce'), $creditCardFee);
        }
        if ($mandatoryFee != 0) {
            $woocommerce->cart->add_fee(__($mandatoryFeeName, 'woocommerce'), $mandatoryFee);
        }
        if ($discount != '' && ($promoCode != '' || $rewardName != '')) {
            $woocommerce->cart->add_fee(__('Total Discount', 'woocommerce'), $discount);
        }
    }

    // to process the order to BLAZE

    public function orderProcessed($order_id)
    {
        $apidomain = get_option('Blaze_api_domain');
        $apikey = get_option('Blaze_api_key');
        @session_start();
        $user_id = get_current_user_id();
        global $woocommerce;
        if (isset($_POST['cloudways_dropdown'])) {
            $notificationType = $_POST['cloudways_dropdown'];
        }
        $ordercomments = '';
        if (isset($_POST['order_comments'])) {
            $ordercomments = $_POST['order_comments'];
        }

        ?>
        <style>
            .woocommerce .blockUI.blockOverlay {
                position: absolute ! important;
                display: block ! important;
            }
        </style>
<?php

        $productaray1 = array();
        $productaray2 = array();
        if (sizeof(WC()->cart->get_cart()) > 0) {
            foreach (WC()->cart->get_cart() as $cart_item) {
                if ($cart_item['product_id']) {
                    $prodId = $cart_item['product_id'];
                    $Blazeproductid = get_post_meta($prodId, 'Blaze_woo_product_id', true);
                    $qty = $cart_item['quantity'];
                    $producttype = get_post_meta($prodId, 'producttype', true);
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
        $sessionId = $_SESSION['sessionId'];
        $consumerCartId = $_SESSION['consumerCartId'];
        $customer = WC()->session->get('customer');
        $fname = $_POST['billing_first_name'];
        $lname = $_POST['billing_first_name'];
        $address = $_POST['shipping_address_1'];
        $address1 = $_POST['shipping_address_2'];

	    if (WC()->session->get('blaze_address')) {
		    $blaze_address =  WC()->session->get('blaze_address');
		    $address = $blaze_address['address'];
		    $address1 = "";
	    } else if(WC()->session->get('customer')) {
		    $blaze_address = WC()->session->get('customer');
		    $address = $blaze_address['shipping_address'];
		    $address1 = "";
	    }

        update_user_meta($user_id, 'billing_first_name', $fname);
        update_user_meta($user_id, 'billing_last_name', $lname);

        if ($address1) {
            $address = $address . ", " . $address1;
        }
        $city = $_POST['shipping_city'];
        $postcode = $_POST['shipping_postcode'];
        $state = $_POST['shipping_state'];

        # send delivery date and time.
        if (isset($_POST['add_delivery_date'])) {
            $adddeliverydate = $_POST['add_delivery_date'];
            if ($adddeliverydate != '') {
                # get shop's timezone to use in deliverydate and time.
                $url = $apidomain . "/api/v1/store?api_key=" . $apikey;
                $data = wp_remote_get($url);
                $response = json_decode($data['body']);
                $timeZone = $response->shop->timeZone;
                $ip = $_SERVER['REMOTE_ADDR'];
                $ipresponse = json_decode(file_get_contents("http://ip-api.com/json/" . $ip . "?fields=timezone"), true);
                $DateTime = new DateTime($adddeliverydate, new DateTimeZone($ipresponse['timezone']));
                $DateTime->setTimezone(new DateTimeZone($timeZone));
                $delivertime = $DateTime->getTimestamp() * 1000;
            }
        }
        $country = $customer['country'];
        $email = $_POST['billing_email'];
        $phone = $_POST['billing_phone'];
        $optional_email = $_POST['cloudways_email_field'] != $_POST['billing_email'] ? $_POST['cloudways_email_field'] : "";
        $optional_phone = $_POST['cloudways_phn_field'] != $_POST['billing_phone'] ? $_POST['billing_phone'] : "";

        update_user_meta($user_id, 'billing_phone', $phone);
        update_user_meta($user_id, 'billing_email', $email);
        $chosen_methods = WC()->session->get('chosen_shipping_methods');
        $chosen_shipping = $chosen_methods[0];
        if ($chosen_shipping == 'legacy_local_delivery') {
            $chosen_shipping = "Delivery";

            update_user_meta($user_id, 'shipping_city', $city);
            update_user_meta($user_id, 'shipping_postcode', $postcode);
            update_user_meta($user_id, 'shipping_state', $state);
            update_user_meta($user_id, 'shipping_address_1', $_POST['shipping_address_1']);
            update_user_meta($user_id, 'shipping_address_2', $_POST['shipping_address_2']);
        } else {
            $chosen_shipping = "Pickup";
        }

        if (isset($_SESSION['coupon_code'])) {
            $couponcode = $_SESSION['coupon_code'];
        } else {
            $couponcode = "none";
        }

        if (isset($_SESSION['reward_name'])) {
            $rewardname = $_SESSION['reward_name'];
        } else {
            $rewardname = "";
        }

        $usertoken = get_user_meta($user_id, 'token', true);
        if ($usertoken == "") {
            $userSync = new Blaze_woo_user_sync(false);
            $authenticateMessage = $userSync->authenticate_existing_user(wp_get_current_user());
            if ($authenticateMessage != "") {

                print_r('{"result":"failure","messages":"' . $authenticateMessage . '","reload":"false"}');
                die;
            }
            $usertoken = get_user_meta($user_id, 'token', true);
        }
        $usertoken = "Token " . $usertoken;
        $blazepaymentoptions = get_option('Blaze_payment_options');
        if ($blazepaymentoptions == "yes") {
            $paymentoption = 'Cash';
            if (isset($_SESSION['paymentoption'])) {
                $paymentoption = $_SESSION['paymentoption'];
            }
        } else {
            if ($_POST['payment_method'] == 'cod') {
                $paymentoption = 'Cash';
            } else {
                $paymentoption = 'Credit';
            }
        }

	    $error_logger = new Blaze_error_logger();
        $carts = $_SESSION['carts'];
        //$cart_ids = $_SESSION['cart_ids'];

	    foreach (array_keys($carts) as $value) {
		    $result_array = [];
		    foreach ( $carts[ $value ] as $prod ) {
			    $newArray     = array_filter( $productaray, function ( $k ) use ( $prod ) {
				    return $k['productId'] == $prod;
			    } );
			    $result_array = array_merge( $result_array, $newArray );
		    }

		    $server_output_ids = $this->woocommerceinfo($result_array, "orderProcess", $value);


		    if ( $chosen_shipping == 'Delivery' ) {
			    $orderdata = array(
				    "memo"                => $ordercomments,
				    "email"               => $email,
				    "address"             => array(
					    "companyId" => "56ce8bf389288da6df3a6602",
					    "address"   => $address,
					    "city"      => $city,
					    "state"     => $state,
					    "zipCode"   => $postcode,
					    "country"   => $country
				    ),
				    "primaryPhone"        => $phone,
				    "promoCode"           => $couponcode,
				    "rewardName"          => $rewardname,
				    "consumerCartId"      => $server_output_ids->consumerCartId,
				    "sessionId"           => $sessionId,
				    "productCostRequests" => $result_array,
				    "notificationType"    => $notificationType,
				    "pickupType"          => $chosen_shipping,
				    "deliveryDate"        => $delivertime,
				    "paymentOption"       => $paymentoption,
				    "placeOrder"          => "True",
                    "optionalEmail"       => $optional_email,
                    "optionalPhone"       => $optional_phone
			    );

			    $mess = "data " . json_encode($orderdata);
			    $error_logger->log($mess);

			    if ( $fname != '' && $lname != '' && $address != '' && $city != '' && $postcode != '' && $state != '' ) {
				    $url          = $apidomain . "/api/v1/store/cart/woocommerce/cart/submit?api_key=" . $apikey . "&shopId=" . $value;
				    $data         = wp_remote_post( $url, array(
					    'headers' => array(
						    'Authorization' => $usertoken,
						    'Content-Type'  => 'application/json; charset=utf-8'
					    ),
					    'body'    => json_encode( $orderdata, true ),
					    'method'  => 'POST',
                        'timeout' => 20000
				    ) );
				    $serveroutput = json_decode( $data['body'] );
				    $message      = $serveroutput->message;
				    $error_logger->log(json_encode($serveroutput));
				    if ( $message != '' ) {
					    print_r( '{"result":"failure","messages":"' . $message . '","reload":"false"}' );
					    die;
				    }

				    $consumerOrderId             = $serveroutput->id;
				    $blazeId                     = $serveroutput->orderNo;
				    $_SESSION['consumerOrderId'] = $consumerOrderId;
				    $_SESSION['blazeId']         = $blazeId;
				    unset( $_SESSION['sessionId'] );
				    unset( $_SESSION['consumerCartId'] );
				    //unset($_SESSION['coupon_code']);
			    }
		    } else {
			    //Pickup type, mandatory product..
			    $pickupTypeMandateProduct = get_option( 'Blaze_required_pickup_product' );

			    if ( $pickupTypeMandateProduct == "" ) {
			    } else {

				    $checkCartUrl = $apidomain . "/api/v1/store/inventory/products/" . $pickupTypeMandateProduct . "?api_key=" . $apikey;
				    $checkCartData = wp_remote_post( $checkCartUrl, array(
					    'headers' => array( 'Content-Type' => 'application/json; charset=utf-8' ),
					    'method'  => 'GET'
				    ) );
				    //Product Not Found
				    $outputProduct = json_decode( $checkCartData['body'] );

				    $error_logger->log(json_encode($outputProduct));
				    if ( $outputProduct->message != "" ) {
					    print_r( '{"result":"failure","messages":"Mandatory Pickup Product Not Found. Contact site-owner to resolve the issue.","reload":"false"}' );
					    die;
				    }
				    $mandatoryProduct[] = array(
					    "productId"  => $outputProduct->id,
					    "quantity"   => 1,
					    "useUnitQty" => false,
				    );
				    $productaray        = array_merge( $productaray, $mandatoryProduct );
			    }

			    $orderdata = array(
				    "memo"                => $ordercomments,
				    "email"               => $email,
				    "address"             => array(
					    "companyId" => "56ce8bf389288da6df3a6602",
					    "address"   => $address,
					    "city"      => $city,
					    "state"     => $state,
					    "zipCode"   => $postcode,
					    "country"   => $country
				    ),
				    "primaryPhone"        => $phone,
				    "promoCode"           => $couponcode,
				    "rewardName"          => $rewardname,
				    "consumerCartId"      => $server_output_ids->consumerCartId,
				    "sessionId"           => $sessionId,
				    "productCostRequests" => $result_array,
				    "notificationType"    => $notificationType,
				    "pickupType"          => $chosen_shipping,
				    "paymentOption"       => $paymentoption,
				    "placeOrder"          => "True",
				    "optionalEmail"       => $optional_email,
				    "optionalPhone"       => $optional_phone
			    );

			    $mess = "data else " . json_encode($orderdata);
			    $error_logger->log($mess);


			    if ( $fname != '' && $lname != '' ) {
				    $url          = $apidomain . "/api/v1/store/cart/woocommerce/cart/submit?api_key=" . $apikey . "&shopId=" . $value;
				    $data         = wp_remote_post( $url, array(
					    'headers' => array(
						    'Authorization' => $usertoken,
						    'Content-Type'  => 'application/json; charset=utf-8'
					    ),
					    'body'    => json_encode( $orderdata, true ),
					    'method'  => 'POST',
                        'timeout' => 20000
				    ) );
				    $serveroutput = json_decode( $data['body'] );
				    $message      = $serveroutput->message;
				    if ( $message != '' ) {
					    print_r( '{"result":"failure","messages":"' . $message . '","reload":"false"}' );
					    die;
				    }
				    $consumerOrderId             = $serveroutput->id;
				    $blazeId                     = $serveroutput->orderNo;
				    $_SESSION['consumerOrderId'] = $consumerOrderId;
				    $_SESSION['blazeId']         = $blazeId;
                    $_SESSION['paymentOption'] = $serveroutput->cart->paymentOption;
				    unset( $_SESSION['sessionId'] );
				    unset( $_SESSION['consumerCartId']);
				    unset($_SESSION['carts']);
			    }
		    }
	    }
    }

    // to calculate cart item line total

    public function cartItemSubtotal($subtotal, $cart_item, $cart_item_key)
    {
        @session_start();
        $product = $cart_item['product_id'];
        $linesubtotal = $cart_item['line_subtotal'];
        $producttype = get_post_meta($product, 'producttype', true);
        if ($producttype == "grams") {
            $qty = $cart_item['quantity'];
        } else {
            $qty = $cart_item['quantity'];
        }

        $Blazeproductid = get_post_meta($product, 'Blaze_woo_product_id', true);
        $server_output = $this->productsArray;
        foreach ($server_output->items as $struct) {
            if ($Blazeproductid == $struct->productId) {
                if ($producttype == "grams") {
                    if ($struct->requestQuantity == $qty && !$struct->is_parsed) {
                        $item = $struct;
                        $item->is_parsed = 1;
                        break;
                    } else {
                        continue;
                    }
                } else {
                    if ($struct->quantity == $qty && !$struct->is_parsed) {
                        $item = $struct;
                        $item->is_parsed = 1;
                        break;
                    } else {
                        continue;
                    }
                }
            }
        }

        $cart_item['line_total'] = $item->finalPrice;
        return wc_price($cart_item['line_total']);
    }

    // Main API to get products data.

    public function woocommerceinfo($productaray, $method, $shop_id=0)
    {
        global $woocommerce;
        @session_start();
        $apidomain = get_option('Blaze_api_domain');
        $apikey = get_option('Blaze_api_key');

	    //$multi_shops = get_option("blaze_enable_multi_shop");
	    $multi_shops = "no";
	    $error_logger = new Blaze_error_logger();

	    $error_logger->log($method);

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

        if($multi_shops == "yes" && $shop_id != 0) {
            $url = $url . "&shopId=" . $shop_id;
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
        if (WC()->session->get('blaze_address')) {
            $blaze_address =  WC()->session->get('blaze_address');
            $newarray['deliveryAddress'] = array(
                'address' => $blaze_address['address'],
                'zipCode' => $blaze_address['zip'],
                'latitude' => $blaze_address['lat'],
                'longitude' => $blaze_address['lng'],
            );
        } else if(WC()->session->get('customer')) {
	        $blaze_address =  WC()->session->get('customer');
	        $newarray['deliveryAddress'] = array(
		        'address' => $blaze_address['shipping_address'],
		        'city' => $blaze_address['shipping_city'],
		        'state' => $blaze_address['shipping_state'],
		        'zipCode' => $blaze_address['shipping_postcode'],
	        );
        }

        if ($authorization) {
            $data = wp_remote_post($url, array(
                'headers' => array('Authorization' => $authorization, 'Content-Type' => 'application/json; charset=utf-8'),
                'body' => json_encode($newarray),
                'method' => 'POST',
            ));
        } else {
            $data = wp_remote_post($url, array(
                'headers' => array('Content-Type' => 'application/json; charset=utf-8'),
                'body' => json_encode($newarray),
                'method' => 'POST',
            ));
        }



        $server_output = json_decode($data['body']);
        $message = $server_output->message;
        //discount corection
        if ($server_output->totalDiscount > 0 && $couponcode == "") {
            $server_output->total = $server_output->total + $server_output->totalDiscount;
        }

        if ($message != '') {
            $_SESSION['errormessage'] = $message;
            return $server_output;
        }
        $_SESSION['server_output'] = $server_output;
        $_SESSION['sessionId'] = $server_output->sessionId;
        $_SESSION['consumerCartId'] = $server_output->consumerCartId;
        $_SESSION['pickUpAddress'] = $server_output->pickUpAddress->address;
        $_SESSION['pickUpcountry'] = $server_output->pickUpAddress->country;
        return $server_output;
    }
}
