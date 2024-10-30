<?php

/**
 * WooCommerce BLAZE Sync
 *
 * @author      BLAZE
 * @category    API
 * @package     WooCommerce/API
 */
if (!defined('ABSPATH'))
    exit;

class Blaze_woo_sync {

    const SYNC_EXECUTION_INTERVAL = 3600;
    const Blaze_API_PATH = '/api/v1/store';
    const PLUGIN_VERSION = "2.5.2";
    private $model_actions = array();
    private $is_categories_changed = false;
    private $categoryIdsMap = array();
    private $error_logger;
    private $models = array(
        'product_category',
        'product_tag',
        'product_tag_ref',
        'product',
        'failed_image'
    );
    private $tables = array();
    private $domain;
    public function getTables() {
        return $this->tables;
    }

    public function getTablesSql($prefix, $collate) {
        $sql = "
                CREATE TABLE `{$prefix}Blaze_product_category` (
                  `id` bigint(20) NOT NULL AUTO_INCREMENT,
                  `catid` varchar(255) DEFAULT NULL,
                  `name` varchar(255) DEFAULT NULL,
                  `description` text,
                  `lft` int(11) DEFAULT NULL,
                  `rgt` int(11) DEFAULT NULL,
                  `level` smallint(6) DEFAULT NULL,
                  `parent_id` bigint(20) DEFAULT NULL,
                  PRIMARY KEY (`id`)
                ) ENGINE=InnoDB $collate;

                CREATE TABLE `{$prefix}Blaze_product_tag` (
                    `id` bigint(20) NOT NULL AUTO_INCREMENT,
                    `name` varchar(255) NOT NULL,
                    PRIMARY KEY (`id`)
                ) ENGINE=InnoDB $collate;

                CREATE TABLE `{$prefix}Blaze_product_tag_ref` (
                    `id` bigint(20) NOT NULL AUTO_INCREMENT,
                    `product_id` varchar(255) DEFAULT NULL,
                    `tag_id` bigint(20) NOT NULL DEFAULT '0',
                    PRIMARY KEY (`id`)
                ) ENGINE=InnoDB $collate;

                CREATE TABLE `{$prefix}Blaze_product` (
                  `id` bigint(20) NOT NULL AUTO_INCREMENT,
                  `pro_id` varchar(255) NOT NULL,
                  `name` varchar(255) NOT NULL,
                  `description` text,
                  `qty_w` float(18,2) DEFAULT 0.00,
                  `joints_qty_w` varchar(100) DEFAULT NULL,
                  `qty_o` float(18,2) DEFAULT 0.00,
                  `joints_qty_o` varchar(255) DEFAULT NULL,
                  `category_id` varchar(255) DEFAULT NULL,
                  `images` text DEFAULT NULL,
                  `flowerType` varchar(255) DEFAULT NULL,
                  `weightPerUnit` varchar(255) DEFAULT NULL,
                  `productSaleType` varchar(255) DEFAULT NULL,
                  `sku` varchar(50) DEFAULT NULL,
                  `unitPrice` decimal(18,4) DEFAULT NULL,
                  `brandId` varchar(255) DEFAULT NULL,
                  `importId` varchar(255) DEFAULT NULL,
                  `brandName` varchar(255) DEFAULT NULL,
                  `companyId` varchar(255) DEFAULT NULL,
                  `shopId` varchar(255) DEFAULT NULL,
                  `vendorId` varchar(255) DEFAULT NULL,
                  `price_type` varchar(255) DEFAULT NULL,
                  `price` text DEFAULT NULL,
                  `thc` decimal(20,2) DEFAULT NULL,
                  `cbn` decimal(20,2) DEFAULT NULL,
                  `cbd` decimal(20,2) DEFAULT NULL,
                  `cbda` decimal(20,2) DEFAULT NULL,
                  `thca` decimal(20,2) DEFAULT NULL,
                  `vendorName` varchar(255) DEFAULT NULL,
                  `taxtype` varchar(255) DEFAULT NULL,
                  `taxorder` varchar(255) DEFAULT NULL,
                  `is_free_shipping` tinyint(1) DEFAULT 0,
                  `is_custom_price` tinyint(1) DEFAULT 0,
                  `is_on_shop` tinyint(1) DEFAULT 1,
                  `strain` tinyint(3) unsigned NOT NULL DEFAULT '0',
                  PRIMARY KEY (`id`)
                ) ENGINE=InnoDB $collate;
            ";
        return $sql;
    }

    public function __construct() {
        global $wpdb;
        add_action('woocommerce_product_meta_end', array($this, 'pharmacy_extra_info')); // add action for cron
        add_action('Blaze_synchronize', array($this, 'executeFromJob')); // add action for cron
        add_filter('cron_schedules', array($this, 'add_cron_schedules')); // add sync interval
        add_filter('woocommerce_dropdown_variation_attribute_options_html', array($this, 'filter_dropdown_option_html'), 12, 2);

        /*
         *  Start logger
         */
        $this->domain = get_option('Blaze_api_domain');
        $this->error_logger = new Blaze_error_logger();
        $this->error_logger->set_error_handler();
        
        foreach ($this->models as $model) {
            $this->tables[$model] = $wpdb->prefix . 'Blaze_' . $model;
        }
    }

    public function filter_dropdown_option_html($html, $args) {
        $productoption = get_option('Blaze_product_option');
        if ($args['attribute'] == 'units') {
            if($productoption !="Both"){
                if ($productoption == "Quantity") {
                    $html .= '<style type="text/css">.variations { display: none !important; }</style>
                <script>
                    var optionToUse = "";
                    jQuery("select#units option").each(function() {
                        if( jQuery(this).val() != "") {
                            optionToUse =  jQuery(this).val()
                        }
                    })
                    jQuery("select#units").val(optionToUse)
                </script>';
                } else {
                    echo '<style type="text/css">.single-product form.cart .quantity { display: none !important; }</style>';
                }
            }
        }
        return $html;
    }


    public function pharmacy_extra_info() {
        $thc = get_post_meta(get_the_ID(), 'thc', true);
        $cbn = get_post_meta(get_the_ID(), 'cbn', true);
        $cbd = get_post_meta(get_the_ID(), 'cbd', true);
        $thca = get_post_meta(get_the_ID(), 'thca', true);
        $cbda = get_post_meta(get_the_ID(), 'cbda', true);
        $vendorname = get_post_meta(get_the_ID(), 'vendorname', true);
        $brandName = get_post_meta(get_the_ID(), 'brandName', true);
        $flowerType = get_post_meta(get_the_ID(), 'flowerType', true);

        
        if (!empty($brandName)) {
            echo "<table class='pharmacyextra_info'>";
            echo '<tr><td class="product_brandName"><strong>' . __('Brand') . "</strong><br>$brandName</td></tr>";
            echo "</table>";
        }
        echo "<table class='pharmacyextra_info'>";
        if (!empty($vendorname)) {
            echo '<tr><td class="product_vendorName"><strong>' . __('Source') . "</strong><br>$vendorname</td>";
        }
        if (!empty($flowerType)) {
            echo '<td class="product_flowerType"><strong>' . __('Type') . "</strong><br>$flowerType</td></tr>";
        }
        echo "</table>";
       
        $producttype = get_post_meta(get_the_ID(), 'producttype', true);
        if ($producttype == "units") {
            //echo '<style type="text/css">.product-type-variable .variations { display: none !important; } </style>';
        }

        echo '<style type="text/css">span.sku_wrapper { display: none; } span.tagged_as {
    display: none; } </style>';
        echo "<table class='pharmacyextra_info'>";
        if (!empty($thc) && $thc != '0.00') {
            echo '<tr><td><strong>' . __('THC') . "</strong><br>$thc %</td>";
        }
        if (!empty($cbn) && $cbn != '0.00') {
            echo '<td><strong>' . __('CBN') . "</strong><br>$cbn %</td>";
        }
        if (!empty($cbd) && $cbd != '0.00') {
            echo '<td><strong>' . __('CBD') . "</strong><br>$cbd %</td>";
        }
        if (!empty($thca) && $thca != '0.00') {
            echo '<td><strong>' . __('THCA') . "</strong><br>$thca %</td>";
        }
        if (!empty($cbda) && $cbda != '0.00') {
            echo '<td><strong>' . __('CBDA') . "</strong><br>$cbda %</td></tr>";
        }
        echo "</table>";
    }

    public function add_cron_schedules($schedules) {
        $schedules['Blaze_sync'] = array(
            'interval' => self::SYNC_EXECUTION_INTERVAL, 'display' => __('Sync interval')
        );
        return $schedules;
    }

    private function getSyncId() {
        if (get_option('Blaze_resync', 0)) {
            update_option('Blaze_sync_id', 0);
            update_option('Blaze_resync', 0);
            $this->clearTables();
            return 0;
        } else {
            return get_option('Blaze_sync_id', 0);
        }
    }

    private function updateSyncId($id) {
        update_option('Blaze_sync_id', $id);
    }

    private function getSyncPage() {
        return get_option('Blaze_sync_page', 1);
    }

    private function updateSyncPage($page) {
        update_option('Blaze_sync_page', $page);
    }

    public function resetSync() {
        update_option('Blaze_resync', 1);
    }

    public function clearTables() {
        global $wpdb;
        $wpdb->query("TRUNCATE TABLE `{$wpdb->prefix}Blaze_product_category`;");
        $wpdb->query("TRUNCATE TABLE `{$wpdb->prefix}Blaze_product_tag`;");
        $wpdb->query("TRUNCATE TABLE `{$wpdb->prefix}Blaze_product_tag_ref`;");
        $wpdb->query("TRUNCATE TABLE `{$wpdb->prefix}Blaze_product`;");
        $wpdb->query("TRUNCATE TABLE `{$wpdb->prefix}sync_record`;");
    }

    public function dropTables() {
        global $wpdb;
        delete_option('Blaze_sync_id');
        delete_option('Blaze_sync_locked_at');
        $wpdb->query("DROP TABLE `{$wpdb->prefix}Blaze_product_category`;");
        $wpdb->query("DROP TABLE `{$wpdb->prefix}Blaze_product_tag`;");
        $wpdb->query("DROP TABLE `{$wpdb->prefix}Blaze_product_tag_ref`;");
        $wpdb->query("DROP TABLE `{$wpdb->prefix}Blaze_product`;");
        $wpdb->query("DROP TABLE `{$wpdb->prefix}sync_record`;");
    }

    public function isLockedSync() {
        $time = get_option('Blaze_sync_locked_at', 0);
        if (!$time || time() - $time > self::SYNC_EXECUTION_INTERVAL) {
            return false;
        } else {
            return true;
        }
    }

    private function lockSync() {
        if (!get_option('Blaze_sync_locked_at', 0)) {
            add_option('Blaze_sync_locked_at', time());
        } else {
            update_option('Blaze_sync_locked_at', time());
        }
    }

    private function unlockSync() {
        delete_option('Blaze_sync_locked_at');
    }

    public function executeFromJob() {
        if ($this->isLockedSync()) {
            $this->error_logger->log("Sync Process attempted startup, already running.");
            return;
        }

        $this->execute();
    }
    private function createSyncRecordTable(){
        global $wpdb;
        $table_name = $wpdb->prefix . 'sync_record'; //wp_sync_record
        $table_exists = $wpdb->get_var("SHOW TABLES LIKE '{$table_name}';");

        //Create sync_record table if it doesn't exist.

        if(is_null($table_exists)){
            $sql = "CREATE TABLE `{$table_name}` (
            `id` int(20) NOT NULL AUTO_INCREMENT,
            `shop_id` VARCHAR(255) NOT NULL,
            `sync_date` TIMESTAMP,
            PRIMARY KEY (`id`));";
            if(!function_exists('dbDelta')) {
                require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
            }
            dbDelta($sql);
        }
    }
    private function getSyncTimeStamp($sync_date){
        if(count($sync_date)>0){
	        return strtotime($sync_date[0]['sync_date'].'UTC')*1000;
        }
        return 0;
    }

    private function getShopId($apiKey){
        $response = new Blaze_API_response();
        $path = $this->domain . self::Blaze_API_PATH . "?api_key=" . $apiKey ;
        $data = wp_remote_get(esc_url_raw($path), array(
            'timeout' => 600,
        ));
        if(is_wp_error($data)) {
            $this->error_logger->log("API Error when executing trying to get shop info");
            $this->error_logger->log(print_r($data,true));
        }
        $response->setRawResponse(wp_remote_retrieve_body( $data ));
        $response->setStatusCode(wp_remote_retrieve_response_code($data));
        $data = json_decode($response->raw_response);
        return $data->shop->id;
    }
    private function verifyShopId($shopId, $sync_date) {
        //Verify API key belongs the same shop in sync_record table
        if(count($sync_date)>0){
            return $sync_date[0]['shop_id'] == $shopId;
        }
        return false;
    }

    public function execute() {

        global $Blaze_sync_request, $wpdb;
        $Blaze_sync_request = true;
        $shopId = '';
        $useNewApiToSync = false;
        set_time_limit(0);
        $apiKey = get_option('Blaze_api_key');
        if($apiKey != ""){
            $this->createSyncRecordTable();
            $shopId = $this->getShopId($apiKey);
        }
        $sync_date = $wpdb->get_results($wpdb->prepare("SELECT * FROM {$wpdb->prefix}sync_record ORDER BY id DESC LIMIT %d;", 1) ,ARRAY_A );
        $timestamp = $this->getSyncTimeStamp($sync_date);
        $useNewApiToSync = $this->verifyShopId($shopId, $sync_date);

        if ($this->isLockedSync()) {
            $this->error_logger->log("Sync Process attempted startup, already running.");
            return;
        }

        $this->error_logger->timer_start();
        $versionColumn = $wpdb->get_results("SELECT plugin_version FROM `{$wpdb->prefix}sync_record` ");
        $row = $wpdb->get_results("SELECT potencyAmount FROM `{$wpdb->prefix}Blaze_product` ");
        $lastRowSyncRecord  = $wpdb->get_results("SELECT plugin_version FROM `{$wpdb->prefix}sync_record` ORDER BY id DESC LIMIT 1 ");

        if(empty($versionColumn)){
          $wpdb->query("ALTER TABLE `{$wpdb->prefix}sync_record` ADD `plugin_version` VARCHAR(255) DEFAULT NULL");
        }

        $column_name = "potencyAmount";
        $table_name = $wpdb->prefix.'Blaze_product';
        $dataType = $wpdb->get_var("SELECT data_type FROM information_schema.columns WHERE table_name = '$table_name' and column_name = '$column_name'");

        if (empty($row)) {
            $wpdb->query("ALTER TABLE `{$wpdb->prefix}Blaze_product` ADD `potencyAmount` text DEFAULT NULL");
        }

        if ($dataType == "varchar") {
          $wpdb->query("ALTER TABLE `{$wpdb->prefix}Blaze_product` Modify column `potencyAmount` text DEFAULT NULL");
        }


        $productQuantitySql = "SELECT COUNT(*) FROM {$wpdb->prefix}blaze_product;";
        $productQuantity = $wpdb->get_var($productQuantitySql);
        //Verify if users reactivate the plugin
        if(strcmp($productQuantity, "0") == 0 || is_null($lastRowSyncRecord[0]->plugin_version)){
            //Use old sync API when there is no products.
            $useNewApiToSync = false;
            $this->clearTables();
        }

        $this->error_logger->log("Sync Process started successfully.");

        $this->lockSync();
        $this->closeImageTable();
        $this->openImageFailedTable();
        $this->syncSettings();

        $api = WooBlaze_Retail()->api;
        $apimodelname = array('categories', 'products');
        foreach ($apimodelname as $apiname) {

            $syncId = $this->getSyncId();
            $modelName = $apiname;
            $limit = 200;
            $page = $this->getSyncPage();
            $lastPage = 0;
            $limitPage = $this->getSyncPage() + 19;

            if($apiname == "categories") {
                $this->error_logger->log("Syncing Categories");
            }
            else if ($apiname == 'products') {
                $this->error_logger->log("Syncing Products");
            }


            $actions = array(
                'create' => array(),
                'update' => array(),
                'delete' => array(),
            );
            $this->model_actions = array(
                'category' => $actions,
                'product' => $actions,
            );

            do {
                
                // $this->error_logger->timer_start();
                $this->error_logger->log("Retrieving ".$modelName." page ".$page.".");
                $apiresponse = $api->executeSync($syncId, $modelName, $page, $limit, $timestamp, $useNewApiToSync);
                $function = $modelName . "ChangeArray";
                $response = $this->$function($apiresponse);
                $count = 0;
                foreach ($response->data as $model => $diff) {
                    foreach ($diff->created as $data) {
                        $data = (array) $data;
                        $count++;

                        $this->updateEntity($model, $data);
                        $this->addAction($model, 'update', $data);
                    }
                    $newSyncId = $diff->sync_id;
                }

                $this->error_logger->log($count." ".$modelName." retrieved, and updated entities.");
                // $this->error_logger->timer_stop("sync for " + $apiname);
                $page++;
                unset($count);
                unset($response);
            } while ($page <= $lastPage && $page <= $limitPage);
    
            //Logging
            $this->error_logger->log("Beginning CRUD for " . $apiname);

            $model_action_to_log;
            if($apiname == "categories") {
                $model_action_to_log = "category";
            }
            else if ($apiname == "products") {
                $model_action_to_log = "product";

            }
            $this->error_logger->log(
                count($this->model_actions[$model_action_to_log]['create']) .
                " ".$modelName." to be created."
            );

            $this->error_logger->log(
                count($this->model_actions[$model_action_to_log]['update']) .
                " ".$modelName." to be updated."
            );
                
            $this->error_logger->log(
                count($this->model_actions[$model_action_to_log]['delete']) .
                " ".$modelName." to be deleted."
            );
            //

            // Create/update
            $this->doActions();

            if (get_option('Blaze_product_not_deleted')) {
                $notdelete = get_option('Blaze_product_not_deleted');
                if ($notdelete != 'yes') {
                    $this->deleteNotblazeProducts();
                    $this->draftblazeProducts();
                }
            } else {
                $this->draftblazeProducts();
                $this->deleteNotblazeProducts();
            }

            if ($page <= $limitPage) {
                $this->updateSyncId($newSyncId);
                $this->updateSyncPage(1);
            } else {
                $this->updateSyncPage($page);
            }
        }

        $this->error_logger->timer_stop("Data Sync Time");
        $this->error_logger->timer_start();
        $this->processImagesQueue();
        $this->error_logger->log("Retrying Image Queue(for failed Images.");
        $this->closeImageTable();
        $this->updateSyncRecordDate($shopId);

        $this->error_logger->timer_stop("Image Sync Time");
        $this->error_logger->log("Sync process completed successfully");
        $this->unlockSync();
    }
    private function updateSyncRecordDate($shopId)
    {
        global $wpdb;
	    $now = date("Y-m-d H:i:s");
      $plugin_version = self::PLUGIN_VERSION;
	    $sql = "INSERT INTO {$wpdb->prefix}sync_record (shop_id, sync_date, plugin_version) VALUES ('{$shopId}','{$now}', '{$plugin_version}');";
        $success = $wpdb->query($sql);
        if ($success) {
            $this->error_logger->log("Sync date recorded successfully");
        } else {
            $this->error_logger->log("There was a problem recording the sync date");
        }
    }

    // to handle different method calls

    private function addAction($model, $action, $data) {
        switch ($model) {
            case 'delivery_address':
                $this->model_actions['customer']['update'][] = array(
                    'patient_id' => $data['patient_id']
                );
                break;
            case 'discount':
                $this->model_actions['coupon'][$action][] = $data['id'];
                break;
            case 'patient':
                $this->model_actions['customer'][$action][] = array(
                    'user_id' => $data['user_id']
                );
                break;
            case 'product_category':
                $this->model_actions['category'][$action][] = $data['catid'];
                $this->is_categories_changed = true;
                break;
            case 'product_office_qty':
                $this->model_actions['product']['update'][] = $data['product_id'];
                break;
            case 'product_price':
                $this->model_actions['product']['update'][] = $data['product_id'];
                break;
            case 'product_tag_ref':
                $this->model_actions['product']['update'][] = $data['product_id'];
                break;
            case 'product':
                $this->model_actions['product'][$action][] = $data['pro_id'];
                break;
            case 'user':
                $this->model_actions['customer'][$action][] = array(
                    'user_id' => $data['id']
                );
                break;
            case 'shipping_method':
                $this->model_actions['shipping'][$action][] = $data['id'];
                break;
            case 'special_item':
                $this->model_actions['product']['update'][] = $data['product_id'];
                break;
            case 'special':
                $this->model_actions['product']['update'][] = $data['product_id'];
                break;
            case 'order':
                $this->model_actions['order'][$action][] = $data['id'];
                $this->model_actions['product']['update'][] = array(
                    'order_id' => $data['id']
                );
                break;
            case 'order_item':
                $this->model_actions['product']['update'][] = array(
                    'order_id' => $data['order_id']
                );
                break;
            case 'patient_point':
                $this->model_actions['customer']['update'][] = array(
                    'patient_id' => $data['patient_id']
                );
                break;
        }
    }

    private function filterEntity($model, $data) {
        global $wpdb;
        $output = array();
        $cols = $wpdb->get_col("DESC " . $this->tables[$model], 0);
        foreach ($data as $key => $value) {
            if (in_array($key, $cols)) {
                $output[$key] = $value;
            }
        }
        return $output;
    }

    private function createEntity($model, $data) {
        global $wpdb;
        $fdata = $this->filterEntity($model, $data);
        $wpdb->insert($this->tables[$model], $fdata);
    }

    private function updateEntity($model, $data) {
        global $wpdb;
        ob_start();
        if ($model == "product_category") {
            $row = $wpdb->get_row(
                    $wpdb->prepare("SELECT id FROM {$this->tables[$model]} WHERE catid = %s LIMIT 1;", $data['catid']), ARRAY_A
            );
            if ($row) {
                $wpdb->update($this->tables[$model], $data, array('catid' => $data['catid']));
            } else {
                $this->createEntity($model, $data);
            }
        } else {

            $row = $wpdb->get_row(
                    $wpdb->prepare("SELECT id FROM {$this->tables[$model]} WHERE pro_id = %s LIMIT 1;", $data['pro_id']), ARRAY_A
            );
            if ($row) {
                $wpdb->update($this->tables[$model], $data, array('pro_id' => $data['pro_id']));
            } else {
                $this->createEntity($model, $data);
            }
        }
        ob_end_flush();
    }

    private function deleteEntity($model, $id) {
        global $wpdb;
        $data = $wpdb->get_row(
                $wpdb->prepare("SELECT * FROM {$this->tables[$model]} WHERE id = %s LIMIT 1;", $id), ARRAY_A
        );

        $wpdb->delete($this->tables[$model], array('pro_id' => $id));
        return $data;
    }

    private function doActions() {
        // translate order to products
        foreach ($this->model_actions as $model => $actions) {
            foreach ($actions as $action => $ids) {
                foreach ($ids as $id) {
                    $method = ucfirst($action) . ucfirst($model);
                    $this->$method($id);
                }
            }
        }
    }

    private function deleteNotblazeProducts() {
        //delete products not related to blaze
        global $wpdb;
        ob_start();
        $products = $wpdb->get_results(
                "SELECT p.ID, pm.meta_value FROM {$wpdb->posts} p
                LEFT JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id AND pm.`meta_key` = 'Blaze_woo_product_id'
                WHERE p.post_type = 'product'", ARRAY_A
        );

        $tmp_products = $wpdb->get_results(
                "SELECT pro_id FROM {$this->tables['product']}", ARRAY_A
        );

        $product_ids = array();
        foreach ($tmp_products as $tmp_product) {
            $product_ids[] = $tmp_product['pro_id'];
        }

        foreach ($products as $product) {
            if (!in_array($product['meta_value'], $product_ids) || $product['meta_value'] == null) {
                wp_delete_post($product['ID'], true);
            }
        }
        ob_end_flush();
    }

    private function draftblazeProducts() {
        //change product  status to draft if products not inculded in the api
        global $wpdb;
        ob_start();
        $products = $wpdb->get_results(
                "SELECT p.ID, pm.meta_value FROM {$wpdb->posts} p
                LEFT JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id AND pm.`meta_key` = 'Blaze_woo_product_id'
                WHERE p.post_type = 'product'", ARRAY_A
        );
        $apidomain = get_option('Blaze_api_domain');
        $apikey = get_option('Blaze_api_key');
	    //$multi_shops = get_option("blaze_enable_multi_shop");
	    //$result =  $multi_shops == "yes" ? "true" : "false";
	    $result = "false";
	    $error_logger = new Blaze_error_logger();

	    //$error_logger->log("Enable multishop draftblazeProducts " . $multi_shops);

        $path = $apidomain . "/api/v1/store/inventory/products?api_key=" . $apikey . "&multishop=" . $result;
        $data = wp_remote_get($path, array(
            'timeout' => 600,
            'redirection' => 5,
            'blocking' => true
        ));
        $response = json_decode($data['body']);

        $error_logger->log("Products Size" . count($response->values));

        $tmp_products = $response->values;

        $product_ids = array();
        foreach ($tmp_products as $tmp_product) {
            $product_ids[] = $tmp_product->id;
        }


        foreach ($products as $product) {
            if (!in_array($product['meta_value'], $product_ids)) {
                $query = array(
                    'ID' => $product['ID'],
                    'post_status' => 'draft',
                );
                wp_update_post($query, true);
            }
        }
        ob_end_flush();
    }

    private function updateCategoriesParentIds() {
        global $wpdb;
        ob_start();
        $categories = $wpdb->get_results(
                "SELECT level, catid, parent_id FROM {$this->tables['product_category']} ORDER BY lft", ARRAY_A
        );

        $lastIdByLevel = array();
        foreach ($categories as $k => $category) {
            if ($category['level'] <= 1) {
                $categories[$k]['parent_id'] = null;
            } else {
                $categories[$k]['parent_id'] = $lastIdByLevel[$category['level'] - 1];
            }
            $lastIdByLevel[$category['level']] = $category['catid'];
        }

        foreach ($categories as $category) {
            $wpdb->update($this->tables['product_category'], $category, array('catid' => $category['catid']));
        }
        ob_end_flush();
    }

    private function createCategoriesIdMap() {
        ob_start();
        $terms = get_terms('product_cat', array('hide_empty' => false));
        foreach ($terms as $term) {
            $this->categoryIdsMap[$term->slug] = $term->term_id;
        }
        ob_end_flush();
    }

    private function updateCategoriesTree() {
        global $wpdb;
        ob_start();
        $tmp_cats = $data = $wpdb->get_results(
                "SELECT * FROM {$this->tables['product_category']}", ARRAY_A
        );
        foreach ($tmp_cats as $tmp_cat) {
            if ($tmp_cat['level'] == 0) {
                continue;
            }
            if ($tmp_cat['parent_id']) {
                $parent_id = $this->categoryIdsMap[$tmp_cat['parent_id']];
            } else {
                $parent_id = 0;
            }
            // $term = get_term_by('slug', $tmp_cat['catid'], 'product_cat');
            $cateslug = strtolower($tmp_cat['name']);
            $cateslug = str_replace(" ", "-", $cateslug);
            $term = get_term_by('slug', $cateslug, 'product_cat');
            wp_update_term(
                    $term->term_id, 'product_cat', array('parent' => $parent_id)
            );
        }
        ob_end_flush();
    }

    private function createCustomer($user_id) {
        global $wpdb;

        if (!is_numeric($user_id)) {
            return;
        }

        $tmp_user = $wpdb->get_row(
                $wpdb->prepare("SELECT * FROM {$this->tables['user']} WHERE id = %s;", $user_id), ARRAY_A
        );

        if (!$tmp_user) {
            return;
        }

        $tmp_patient = $wpdb->get_row(
                $wpdb->prepare("SELECT * FROM {$this->tables['patient']} WHERE user_id = %s;", $user_id), ARRAY_A
        );

        $tmp_delivery_address = $wpdb->get_row(
                $wpdb->prepare("SELECT * FROM {$this->tables['delivery_address']} WHERE user_id = %s;", $user_id), ARRAY_A
        );

        $tmp_points = $wpdb->get_row(
                $wpdb->prepare("SELECT SUM(value) as value FROM {$this->tables['patient_point']} WHERE patient_id = %s;", $tmp_patient['id']), ARRAY_A
        );
        $points = $tmp_points['value'] ? $tmp_points['value'] : '0';

        $table = _get_meta_table('user');
        $wc_user_id = $wpdb->get_var(
                $wpdb->prepare("SELECT user_id FROM $table WHERE meta_key = %s AND meta_value = %s", 'Blaze_woo_user_id', $user_id)
        );
        $user = get_user_by('id', $wc_user_id);

        if ($user) {
            wp_update_user(array(
                'ID' => $wc_user_id,
                'user_email' => $tmp_user['email_address'],
            ));
        } else {
            global $Blaze_user_validation_off;
            $Blaze_user_validation_off = true;
            $wc_user_id = wc_create_new_customer($tmp_user['email_address'], $tmp_user['username'], 'test');
            if (is_wp_error($wc_user_id)) {
                return;
            };
        }

        $wpdb->get_results(
                $wpdb->prepare("UPDATE {$wpdb->users} SET user_pass = %s WHERE ID = %s", $tmp_user['Blaze_hash'], (int) $wc_user_id)
        );

        $country = get_option('Blaze_company_country') == 'Canada' ? 'CA' : 'US';

        $update_metas = array(
            'first_name' => $tmp_patient['first_name'],
            'last_name' => $tmp_patient['last_name'],
            'billing_country' => $country,
            'billing_first_name' => $tmp_patient['first_name'],
            'billing_last_name' => $tmp_patient['last_name'],
            'billing_address_1' => $tmp_delivery_address['address'],
            'billing_city' => $tmp_delivery_address['city'],
            'billing_state' => $tmp_delivery_address['state'],
            'billing_postcode' => $tmp_delivery_address['zip'],
            'billing_email' => $tmp_patient['email'],
            'billing_phone' => $tmp_delivery_address['phone'],
            'shipping_country' => $country,
            'shipping_first_name' => $tmp_patient['first_name'],
            'shipping_last_name' => $tmp_patient['last_name'],
            'shipping_address_1' => $tmp_delivery_address['address'],
            'shipping_city' => $tmp_delivery_address['city'],
            'shipping_state' => $tmp_delivery_address['state'],
            'shipping_postcode' => $tmp_delivery_address['zip'],
            'Blaze_dob' => date('m/d/Y', strtotime($tmp_patient['dob'])),
            'Blaze_dmv' => $tmp_patient['dmv'],
            'Blaze_sex' => $tmp_patient['gender'],
            'Blaze_address' => $tmp_delivery_address['address'],
            'Blaze_csz' => $tmp_delivery_address['city'] . ' ' . $tmp_delivery_address['state'] . ' ' . $tmp_delivery_address['zip'],
            'Blaze_phone' => $tmp_patient['phone'],
            'Blaze_woo_user_id' => $tmp_user['id'],
        );

        if (class_exists('WC_Points_Rewards_Manager')) {
            WC_Points_Rewards_Manager::set_points_balance($wc_user_id, $points, 'admin-adjustment');
        }

        foreach ($update_metas as $key => $value) {
            update_user_meta($wc_user_id, $key, $value);
        }
    }

    private function updateCustomer($user_id) {
        $this->createCustomer($user_id);
    }

    private function deleteCustomer($id) {
        global $wpdb;

        $table = _get_meta_table('user');
        $id = $wpdb->get_var(
                $wpdb->prepare("SELECT user_id FROM $table WHERE meta_key = %s AND meta_value = %s", 'Blaze_woo_user_id', $id)
        );

        require_once(ABSPATH . 'wp-admin/includes/user.php');
        wp_delete_user($id);
    }

    private function createCoupon($id) {
        
    }

    private function updateCoupon($id) {
        
    }

    private function deleteCoupon($id) {
        
    }

    private function createCategory($id) {
        global $wpdb;
        ob_start();
        $tmp_cat = $wpdb->get_row(
                $wpdb->prepare("SELECT name FROM {$this->tables['product_category']} WHERE catid = %s LIMIT 1;", $id), ARRAY_A
        );

        $cateslug = str_replace(" ", "-", strtolower($tmp_cat['name']));
        $args = array(
            'taxonomy' => 'product_cat',
            'post_type' => 'product',
            'name' => $tmp_cat['name'],
            'slug' => $cateslug,
            'parent' => 0,
        );

        $existing_term = get_term_by('slug', $cateslug, 'product_cat');
        if ($existing_term) {
            $args = array(
                'taxonomy' => 'product_cat',
                'post_type' => 'product',
                'name' => $tmp_cat['name'],
                'slug' => $cateslug,
                'parent' => $existing_term->parent,
            );
            wp_update_term(
                    $existing_term->term_id, 'product_cat', $args
            );
        } else {
            wp_insert_term(
                    $tmp_cat['name'], 'product_cat', $args
            );
        }
        ob_end_flush();
    }

    private function updateCategory($id) {
        $this->createCategory($id);
    }

    private function deleteCategory($id) {
        ob_start();
        $term = get_term_by('slug', $id, 'product_cat');
        if ($term) {
            wp_delete_term($term->term_id, 'product_cat');
        }
        ob_end_flush();
    }

    private function createProduct($id) {
        ob_start();
        $this->createCategoriesIdMap();
        global $wpdb;
        $tmp_product = $wpdb->get_row(
                $wpdb->prepare("SELECT * FROM {$this->tables['product']} WHERE pro_id = %s LIMIT 1;", $id), ARRAY_A
        );

        if (!$tmp_product) {
            return;
        }

        $post_data = array(
            'post_title' => $tmp_product['name'],
            'post_content' => $tmp_product['description'],
            'post_status' => 'publish',
            'post_author' => 1,
            'post_parent' => 0,
            'post_type' => 'product',
            'menu_order' => 0
        );


        $post_id = $wpdb->get_var(
                $wpdb->prepare("SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = %s AND meta_value = %s LIMIT 1", 'Blaze_woo_product_id', $id)
        );

        if (isset($tmp_product['is_on_shop']) && !$tmp_product['is_on_shop']) {
            if ($post_id) {
                $this->error_logger->log("Product {$id} -|- {$post_data['post_title']} \n -> Deleting Product" );
                wp_delete_post($post_id, true);
            }
            return;
        }

        if (!$post_id) {
            $post_id = wp_insert_post($post_data);
            
            $this->error_logger->log(" Product {$id} -|- {$post_data['post_title']}-> Creating New Product");
            if (!$post_id) {
                $this->error_logger->log("-> Deleting New Product Product(no post id)");
                return;
            }

            
            update_post_meta($post_id, 'Blaze_woo_product_id', $tmp_product['pro_id']);

            add_post_meta($post_id, 'total_sales', '0', true);
            update_post_meta($post_id, '_visibility', 'visible');

            update_post_meta($post_id, '_downloadable', 'no');
            update_post_meta($post_id, '_virtual', 'no');

        } else {
            $this->error_logger->log(" Product {$id} -|- {$post_data['post_title']}-> Updating existing Product");
            $wpdb->update($wpdb->posts, $post_data, array('ID' => $post_id));
        }

        
        update_post_meta($post_id, 'sf_product_description', $tmp_product['description']);
        update_post_meta($post_id, 'sf_product_short_description', $tmp_product['description']);
        update_post_meta($post_id, 'potencyAmount', $tmp_product['potencyAmount']);
        // Add any default post meta
        update_post_meta($post_id, '_sku', $tmp_product['sku']);

        $name;
        if($tmp_product['category_id'] && $tmp_product['category_id'] != "") {
            $name = $wpdb->get_row(
                $wpdb->prepare("SELECT name FROM {$this->tables['product_category']} WHERE catid = %s LIMIT 1;", $tmp_product['category_id']), ARRAY_A
            );
        }
        
        if (!empty($name)) {
            $namecat = str_replace(".", "-", $name['name']);
            $namecat = str_replace("-", " ", $namecat);
            $catname = preg_replace('/[^a-zA-Z0-9_\s\[.\]\\/-]/s', '', $namecat);
            $cateslug = preg_replace('/\s+/', '-', strtolower($catname));
            wp_set_post_terms($post_id, $this->categoryIdsMap[$cateslug], 'product_cat');
        } else {
            wp_set_post_terms($post_id, $this->categoryIdsMap['uncategorized'], 'product_cat');
        }

        // Update tags
        $this->updatePostTags($post_id, $id);

        $is_grams = $tmp_product['price_type'] == 'grams';
        $is_units = $tmp_product['price_type'] == 'units';
        $is_custom = $tmp_product['is_custom_price'];
        
        $product_type = !$is_grams && !$is_units && !$is_custom ? 'simple' : 'variable';
        $this->error_logger->log("-> Product Type {$product_type}");

        wp_set_object_terms($post_id, $product_type, 'product_type');

        // Sales and prices
        if ($product_type == 'variable') {
            update_post_meta($post_id, '_regular_price', '');
            //update_post_meta($post_id, '_sale_price', '');
            update_post_meta($post_id, '_price', '');
        } else {
            $this->error_logger->log("-> Updating Simple Price to {$tmp_product['unitPrice']}");
            update_post_meta($post_id, '_regular_price', wc_format_decimal($tmp_product['unitPrice']));
            //update_post_meta($post_id, '_sale_price', '');
            update_post_meta($post_id, '_price', wc_format_decimal($tmp_product['unitPrice']));
            //update_post_meta($post_id, '_product_attributes', '');
        }


        update_post_meta($post_id, '_backorders', 'no');


        if ($tmp_product['joints_qty_w'] == 0) {
            $this->error_logger->log(" -> Out of stock Jts Qty w is zero");
            update_post_meta($post_id, '_manage_stock', 'yes');
            update_post_meta($post_id, '_stock', 0);
            update_post_meta($post_id, '_stock_status', 'outofstock');
        } else {
            $Outofstockthreshold = get_option('woocommerce_notify_no_stock_amount');
            if($Outofstockthreshold > 0 && $Outofstockthreshold > $tmp_product['joints_qty_w']){
                $this->error_logger->log("-> Out of stock: Qty available less than threshhold");
                update_post_meta($post_id, '_manage_stock', 'yes');
                update_post_meta($post_id, '_stock', 0);
                update_post_meta($post_id, '_stock_status', 'outofstock');
            }else{
                $this->error_logger->log("-> In stock");
                 update_post_meta($post_id, '_manage_stock', 'no');
            }
           
        }

        if ($product_type == 'variable') {
            
            $this->error_logger->log("-> Begin Save Variations");
            // $this->error_logger->timer_start();
            $this->saveVariations($post_id, $tmp_product);
            // $this->error_logger->timer_stop("all variations for product " + json_decode($tmp_product['images'])[0]);


        }



        // $this->error_logger->timer_start();
        
        //feature image upload
        //$attach_id = $this->createAttachment($post_id, json_decode($tmp_product['images'])[0], "product");
        $this->addImageToImageTable(json_decode($tmp_product['images'])[0], "product", $post_id);

        // $this->error_logger->timer_stop("attachment ".basename(json_decode($tmp_product['images'])[0]));

        // $pre_attach_id = get_post_thumbnail_id($post_id, '_thumbnail_id');

        // if ($attach_id && (!$pre_attach_id || $attach_id != $pre_attach_id)) {
        //     update_post_meta($post_id, '_thumbnail_id', $attach_id);
        // }
        // feature image upload
        // multi product image upload
        $images = json_decode($tmp_product['images']);
        $totalnumber = count($images);
        $j = 1;
        for ($j = 1; $j < $totalnumber; $j++) {
            // $this->error_logger->timer_start();
            // $attach_gallery_id[] = $this->createAttachment($post_id, json_decode($tmp_product['images'])[$j], "product_gallery");
            $this->addImageToImageTable(json_decode($tmp_product['images'])[$j], "product_gallery", $post_id);
            // $this->error_logger->timer_stop("attachment " . basename(json_decode($tmp_product['images'])[$j]));
        }

        // if (!empty($attach_gallery_id)) {
        //     update_post_meta($post_id, '_product_image_gallery', implode(',', $attach_gallery_id));
        // }

        // Clear cache/transients
        @ob_end_flush();
        wc_delete_product_transients($post_id);
        
        $this->error_logger->log("-> Completed");
    }

    private function updatePostTags($post_id, $product_id) {
        ob_start();
        global $wpdb;

        $tags = $wpdb->get_results(
                $wpdb->prepare(
                        "SELECT t.id, t.name FROM {$this->tables['product_tag_ref']} as tr LEFT JOIN {$this->tables['product_tag']} as t ON tr.tag_id = t.id WHERE product_id = %s;", $product_id
                ), ARRAY_A
        );

        $tags_ids = array();
        foreach ($tags as $tag) {
            if ($tag['name']) {
                $existing_term = get_term_by('slug', $tag['id'], 'product_tag');

                $args = array(
                    'name' => $tag['name'],
                    'slug' => $tag['id'],
                    'description' => '',
                );

                if ($existing_term) {
                    wp_update_term(
                            $existing_term->term_id, 'product_tag', $args
                    );
                    $tags_ids[] = $existing_term->term_id;
                } else {
                    $new_term = wp_insert_term(
                            $tag['name'], 'product_tag', $args
                    );
                    $tags_ids[] = $new_term['term_id'];
                }
            }
        }

        $tags_ids_unique = array_unique(array_map('intval', $tags_ids));
        wp_set_post_terms($post_id, $tags_ids_unique, 'product_tag');
        ob_end_flush();
    }

    protected function getProductShelfQty($id, $add_prepack_qty = false) {
        global $wpdb;

        /* if ($add_prepack_qty) {
          $qty = $wpdb->get_row(
          $wpdb->prepare("SELECT SUM(qty) as qty FROM {$this->tables['product_office_qty']} WHERE product_id = %s AND item_type !='joint';", $id), ARRAY_A
          );
          } else {
          $qty = $wpdb->get_row(
          $wpdb->prepare("SELECT * FROM {$this->tables['product_office_qty']} WHERE product_id = %s AND item_type = 'gram';", $id), ARRAY_A
          );
          } */
        $qty['qty'] = 1;
        $qty = $qty['qty'];

        // and add qty in incomplete orders
        /* $order_items_total_qty = $wpdb->get_row(
          $wpdb->prepare("
          SELECT SUM(oi.qty + oi.qty_free) as qty FROM {$this->tables['order_item']} oi
          LEFT JOIN {$this->tables['order']} o ON oi.order_id = o.id
          WHERE o.status = 'incomplete' AND oi.product_id = %s
          ", $id), ARRAY_A
          ); */
        $order_items_total_qty['qty'] = 1;
        $qty += $order_items_total_qty['qty'];

        return $qty;
    }

    protected function getSpecialPrice($tmp_product, $charge_or_custom_price_id) {
        global $wpdb;
        ob_start();

        $special_data = array(
            'start_date' => '',
            'end_date' => '',
            'special_price' => '',
        );

        if ($tmp_product['is_custom_price']) {
            $tmp_special_item = $wpdb->get_row(
                    $wpdb->prepare("SELECT * FROM {$this->tables['special_item']} WHERE product_id = %s AND product_price_id = %s;", $tmp_product['id'], $charge_or_custom_price_id), ARRAY_A
            );
        } else {
            $tmp_special_item = $wpdb->get_row(
                    $wpdb->prepare("SELECT * FROM {$this->tables['special_item']} WHERE product_id = %s AND charge_by = %s;", $tmp_product['id'], $charge_or_custom_price_id), ARRAY_A
            );
        }

        if (!$tmp_special_item) {
            return $special_data;
        }

        $tmp_special = $wpdb->get_row(
                $wpdb->prepare("SELECT * FROM {$this->tables['special']} WHERE id = %s;", $tmp_special_item['special_id']), ARRAY_A
        );

        if ($tmp_special && $tmp_special['condition_type'] == 1 && $tmp_special_item['is_active'] == 1) {
            $special_data = array(
                'start_date' => strtotime($tmp_special['start_date']),
                'end_date' => strtotime($tmp_special['end_date']),
                'special_price' => $tmp_special_item['special_price'],
            );
        }
        ob_end_flush();
        return $special_data;
    }

    protected function saveVariations($post_id, $tmp_product) {
        ob_start();
        global $wpdb;

        $is_grams = $tmp_product['price_type'] == 'grams';
        $is_units = $tmp_product['price_type'] == 'units';
        $is_custom = $tmp_product['is_custom_price'];
        $variations_data = array();
        $attribute_value = array();

        //$total_qty = $this->getProductShelfQty($tmp_product['pro_id']);

        if ($is_grams && $is_units && !$is_custom) {
            $this->error_logger->log("-> is grams or units");
            $i = 1;

            foreach ($vardata as $data) {
                $this->error_logger->log(print_r($data,true));
                $this->error_logger->log("-> Variation {$data->name} (index {$i}), Price {$data->price}");
                if ($data->active == 1 && $data->price != 0 && $data->price != '' && $data->price != null) {
                    $variation = array(
                        'sku' => $tmp_product['pro_id'] . "_" . $i,
                        'name' => $data->name,
                        'regular_price' => $data->price,
                        'sale_price' => $data->salePrice,
                        'menu_order' => $i,
                        'instock' => 1,
                        'variation' => $data->name,
                    );
                    $variations_data[] = $variation;
                    $i++;
                }
            }
        } else {

            $this->error_logger->log("-> Is Custom");
            $i = 1;
            $vardata = json_decode($tmp_product['price']);
            foreach ($vardata as $data) {
                $this->error_logger->log("-> Variation {$data->name} (index {$i}), Price {$data->price}");
                if ($data->active == 1 && $data->price != 0 && $data->price != '' && $data->price != null) {
                    $variation = array(
                        'sku' => $tmp_product['pro_id'] . "_" . $i,
                        'name' => $data->name,
                        'regular_price' => $data->price,
                        'sale_price' => ($data->salePrice ? $data->salePrice : null ) ,
                        'menu_order' => $i,
                        'instock' => 1,
                        'variation' => $data->name,
                    );
                    $variations_data[] = $variation;
                    $i++;
                }
            }
        }
        //Getting variables name and update
        $vardata = json_decode($tmp_product['price']);
        foreach ($vardata as $v) {
            $price[$v->name] = $v->price;
        }

        $attribute_values1 = str_replace(' ', '', array_keys($price));
        $attributes = array(
            $tmp_product['price_type'] => array(
                'name' => $tmp_product['price_type'],
                'value' => implode(' | ', $attribute_values1),
                'position' => '1',
                'is_visible' => '1',
                'is_variation' => '1',
                'is_taxonomy' => '0',
            )
        );
        if (count($attributes)) {
            update_post_meta($post_id, '_product_attributes', $attributes);
        }
        if ($tmp_product['thc']) {
            update_post_meta($post_id, 'thc', $tmp_product['thc']);
        }
        if ($tmp_product['cbn']) {
            update_post_meta($post_id, 'cbn', $tmp_product['cbn']);
        }
        if ($tmp_product['cbd']) {
            update_post_meta($post_id, 'cbd', $tmp_product['cbd']);
        }
        if ($tmp_product['cbda']) {
            update_post_meta($post_id, 'cbda', $tmp_product['cbda']);
        }
        if ($tmp_product['thca']) {
            update_post_meta($post_id, 'thca', $tmp_product['thca']);
        }
        if ($tmp_product['brandName']) {
            update_post_meta($post_id, 'brandName', $tmp_product['brandName']);
        }
        if ($tmp_product['shopId']) {
            update_post_meta($post_id, 'shopid', $tmp_product['shopId']);
        }
        if ($tmp_product['vendorName']) {
            update_post_meta($post_id, 'vendorname', $tmp_product['vendorName']);
        }
        if ($tmp_product['flowerType']) {
            update_post_meta($post_id, 'flowerType', $tmp_product['flowerType']);
        }
        if ($tmp_product['price_type']) {
            update_post_meta($post_id, 'producttype', $tmp_product['price_type']);
        }
        if ($tmp_product['price']) {
            update_post_meta($post_id, 'productattributes', $tmp_product['price']);
        }
        $this->error_logger->log("-> Meta for post completed");
        // Getting variables name and update

        $updated_variation_ids = array();
        if(count($variations_data) == 0) {
            $this->error_logger->log("ALL Invalid Variations, creating empty no stock/no price variation");
            $data = $vardata[0];
            $variation = array(
                'sku' => $tmp_product['pro_id'] . "_" . 0,
                'name' => $data->name,
                'regular_price' => 0,
                'sale_price' => null  ,
                'menu_order' => 0,
                'instock' => 0,
                'variation' => $data->name,
            );
            $variations_data[] = $variation;

            update_post_meta($post_id, '_manage_stock', 'yes');
            update_post_meta($post_id, '_stock', 0);
            update_post_meta($post_id, '_stock_status', 'outofstock');
        }
        $realPostPrice = 123;
        $realPostRegularPrice = 123;
        $realPostSalePrice = 123;
        $i = 0;
        foreach ($variations_data as $k => $variation_data) {
            $table = _get_meta_table('post');
            $variation_id = $wpdb->get_var(
                    $wpdb->prepare("SELECT post_id FROM $table WHERE meta_key = %s AND meta_value = %s LIMIT 1", '_sku', $variation_data['sku'])
            );

            // Generate a useful post title
            $variation_post_title = sprintf(__('Variation #%s of %s', 'woocommerce'), absint($variation_id), esc_html(get_the_title($post_id)));

            
            $this->error_logger->log("-> Variation Post Title is {$variation_post_title} (ID is {$variation_id}) (status is ".get_post_status ( $variation_id ).")");

            //If variation id, or post status are invalid, create
            if ( !$variation_id || !get_post_status ( $variation_id )) {
                $this->error_logger->log("-> Adding Variations");
                $variation = array(
                    'post_title' => $variation_post_title,
                    'post_content' => '',
                    'post_status' => 'publish',
                    'post_author' => get_current_user_id(),
                    'post_parent' => $post_id,
                    'post_type' => 'product_variation',
                    'menu_order' => $variation['menu_order']
                );

                $variation_id = wp_insert_post($variation);
                do_action('woocommerce_create_product_variation', $variation_id);
               
            } else { 
                $this->error_logger->log("-> Updating Variations");
                $wpdb->update($wpdb->posts, array(
                    'post_status' => 'publish',
                    'post_title' => $variation_post_title,
                    'post_parent' => $post_id,
                    'menu_order' => $variation_data['menu_order'],
                        ), array('ID' => $variation_id));

                do_action('woocommerce_update_product_variation', $variation_id);
            }

            // Only continue if we have a variation ID
            if (!$variation_id) {
                continue;
            }

            update_post_meta($variation_id, '_sku', $variation_data['sku']);
            //update_post_meta($variation_id, '_thumbnail_id', '');
            update_post_meta($variation_id, '_virtual', 'no');
            update_post_meta($variation_id, '_downloadable', 'no');
            update_post_meta($variation_id, '_manage_stock', 'no');

            if ($variation_data['instock']) {
                $this->error_logger->log("-> Variation In Stock");
                wc_update_product_stock_status($variation_id, 'instock');
            } else {
                
                $this->error_logger->log("-> Variation Out of stock");
                wc_update_product_stock_status($variation_id, 'outofstock');
            }

            
            $this->error_logger->log("-> Pricing handling for variation ({$variation_data['regular_price']}) ");
            // Price handling for variation
            $pricedata = json_decode($tmp_product['price']);
            $sale_price = wc_format_decimal($variation_data['sale_price']);

            $regular_price = wc_format_decimal($variation_data['regular_price']);

            update_post_meta($variation_id, '_regular_price', $regular_price);
            update_post_meta($variation_id, '_price', $sale_price ? $sale_price : $regular_price);
            if($sale_price) {
                update_post_meta($variation_id, '_sale_price', $sale_price);
            }
            else {
                delete_post_meta($variation_id, '_sale_price');
            }

            if($i == 0) {
                $realPostPrice = $sale_price ? $sale_price : $regular_price;
                $realPostRegularPrice = $regular_price;
                $realPostSalePrice = $sale_price;
            }
            update_post_meta($variation_id, 'Blaze_woo_variation', $variation_data['variation']);
            
            delete_post_meta($variation_id, '_tax_class');
            //update_post_meta($variation_id, '_download_limit', '');
            //update_post_meta($variation_id, '_download_expiry', '');
            //update_post_meta($variation_id, '_downloadable_files', '');
            wp_set_object_terms($variation_id, '', 'product_shipping_class');

            // Update taxonomies - don't use wc_clean as it destroys sanitized characters
            $updated_attribute_keys = array();
            foreach ($attributes as $attribute) {
                if ($attribute['is_variation']) {
                    $attribute_key = 'attribute_' . $attribute['name'];
                    $value = str_replace('-', '', $variation_data['name']);
                    $updated_attribute_keys[] = $attribute_key;
                    update_post_meta($variation_id, $attribute_key, $value);
                }
            }

            // Remove old taxonomies attributes so data is kept up to date - first get attribute key names
            $delete_attribute_keys = $wpdb->get_col($wpdb->prepare("SELECT meta_key FROM {$wpdb->postmeta} WHERE meta_key LIKE 'attribute_%%' AND meta_key NOT IN ( '" . implode("','", $updated_attribute_keys) . "' ) AND post_id = %d;", $variation_id));

            foreach ($delete_attribute_keys as $key) {
                delete_post_meta($variation_id, $key);
            }
            $updated_variation_ids[] = $variation_id;
            $i++;
        }
        //Real price update
        update_post_meta($post_id, '_regular_price', $realPostRegularPrice);
        update_post_meta($post_id, '_price', $realPostPrice);
        if($realPostSalePrice) {
            update_post_meta($post_id, '_sale_price', $realPostSalePrice);
        }


        $updated_variation_ids_string = implode(',', $updated_variation_ids);
        if ($updated_variation_ids_string == '') {
            $posts_to_delete = $wpdb->get_results(
                    $wpdb->prepare("SELECT ID FROM {$wpdb->posts} WHERE post_parent = %s AND post_type = 'product_variation'", $post_id), ARRAY_A);
        } else {
            $posts_to_delete = $wpdb->get_results(
                    $wpdb->prepare("SELECT ID FROM {$wpdb->posts} WHERE post_parent = %s AND post_type = 'product_variation' AND ID NOT IN (" . $updated_variation_ids_string . ")", $post_id), ARRAY_A
            );
        }
        foreach ($posts_to_delete as $post) {
            wp_delete_post($post['ID'], true);
        }
        ob_end_flush();
        // Update parent if variable so price sorting works and stays in sync with the cheapest child
        WC_Product_Variable::sync($post_id);
    }
    protected function createAttachment($post_id, $photo, $type) {
        try {
            if (strlen($photo) == 0) {
                return false;
            }

            global $wpdb;
            ob_start();

            require_once(ABSPATH . 'wp-admin/includes/admin.php');

            $time = current_time('mysql');
            if ($post = get_post($post_id)) {
                if (substr($post->post_date, 0, 4) > 0)
                    $time = $post->post_date;
            }
            $name = basename($photo);

            $name_parts = pathinfo($name);
            $title = trim(substr($name, 0, -(1 + strlen($name_parts['extension']))));

            $existing_attachment_id = $wpdb->get_var(
                    $wpdb->prepare("SELECT ID FROM {$wpdb->posts} WHERE post_name = %s", $title)
            );
            if ($existing_attachment_id) {
                return $existing_attachment_id;
            }

            $uploads = wp_upload_dir($time);
            $filename = $uploads['path'] . "/$name";
            $url = $uploads['url'] . "/$name";
            if (!file_exists($uploads['path'])) {
                mkdir($uploads['path'], 0777, true);
            }

            if (ini_get('allow_url_fopen')) {
                $content = file_get_contents($photo);
            } elseif (function_exists('curl_version')) {
                $curl = curl_init($photo);
                curl_setopt($curl, CURLOPT_RETURNTRANSFER, 1);
                $content = curl_exec($curl);
                curl_close($curl);
            } else {
                $this->error_logger = new Blaze_error_logger();
                $this->error_logger->log('Turn on allow_url_open and curl.' . $photo . PHP_EOL);
                return false;
            }

            if (!$content) {
                $this->error_logger = new Blaze_error_logger();
                $this->error_logger->log('File uploaded fail ' . $photo . PHP_EOL);
                return false;
            }

            file_put_contents($filename, $content);
            $type = $this->mime_content_type($filename);

            $stat = stat(dirname($filename));
            $perms = $stat['mode'] & 0000666;
            @chmod($filename, $perms);

            // Construct the attachment array
            $attachment = array(
                'post_mime_type' => $type,
                'guid' => $url,
                'post_parent' => $post_id,
                'post_title' => $title,
                'post_content' => '',
            );

            // Save the data
            $existing_attachment_id = $wpdb->get_var(
                    $wpdb->prepare("SELECT ID FROM {$wpdb->posts} WHERE post_name = %s", $title)
            );
            if ($existing_attachment_id) {
                $id = $existing_attachment_id;
            } else {
                $id = wp_insert_attachment($attachment, $filename, $post_id);
            }

            if (!is_wp_error($id)) {
                wp_update_attachment_metadata($id, wp_generate_attachment_metadata($id, $filename));
            }
            ob_end_flush();
            return $id;
        } catch (exception $e) {
            $this->addImageToImageTable($photo, $type, $post_id);
            return false;
        }
    }

    function openImageFailedTable() {
        global $wpdb;
        $wpdb->hide_errors();

        $collate = '';

        if ($wpdb->has_cap('collation')) {
            if (!empty($wpdb->charset)) {
                $collate .= "DEFAULT CHARACTER SET $wpdb->charset";
            }
            if (!empty($wpdb->collate)) {
                $collate .= " COLLATE $wpdb->collate";
            }
        }
        $prefix = $wpdb->prefix;
        $sql = "
            CREATE TABLE `{$prefix}Blaze_images` (
                `id` bigint(20) NOT NULL AUTO_INCREMENT,
                `image_url` text DEFAULT NULL,
                `target_id` bigint(20) DEFAULT NULL,
                `target_type` text DEFAULT NULL,
                PRIMARY KEY (`id`)
            ) ENGINE=InnoDB $collate;
        ";
        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');

        dbDelta($sql);
        $this->error_logger->log("Opened failed image queue");
    }

    function closeImageTable() {
        global $wpdb;
        $this->error_logger->log("Close failed Image Queue");
        $wpdb->query("DROP TABLE IF EXISTS `{$wpdb->prefix}Blaze_images`;");
    }

    function addImageToImageTable($imageUrl, $imageType, $targetId) {
        global $wpdb;
        $failedImage = array(
            'image_url' => $imageUrl,
            'target_type' => $imageType,
            'target_id' => $targetId,
        );
        
        $table = $wpdb->prefix . "Blaze_images";
        if($failedImage) {
            $wpdb->insert($table, $failedImage);
        }
        $this->error_logger->log("Added '". $imageUrl . "' to image failure queue.");
    }

    function processImagesQueue() {
        global $wpdb;
        //Get images
        $failedImages = $wpdb->get_results("SELECT * FROM {$wpdb->prefix}Blaze_images;");
        $this->error_logger->log("Starting images!");

        foreach ($failedImages as $failedImage) {
            $post_id = $failedImage->target_id;
            if($failedImage->target_type ==  "product") {
                $this->error_logger->log("Creating image! (".$failedImage->image_url.")");
                $attach_id = $this->createAttachment($post_id, $failedImage->image_url, "failedTwice");

                $pre_attach_id = get_post_thumbnail_id($post_id, '_thumbnail_id');

                if ($attach_id && (!$pre_attach_id || $attach_id != $pre_attach_id)) {
                    $this->error_logger->log("Attached image to post");
                    update_post_meta($post_id, '_thumbnail_id', $attach_id);
                    $this->error_logger->log("Removing from failed image queue");
                    $wpdb->query( $wpdb->prepare("DELETE FROM  {$wpdb->prefix}Blaze_images WHERE id = %s;", $failedImages->id));
                }
            }
        }
    }

    protected function mime_content_type($filename) {
        ob_start();
        if (function_exists('mime_content_type')) {
            $type = mime_content_type($filename);
        } elseif (class_exists('finfo')) { // php 5.3+
            $finfo = new finfo(FILEINFO_MIME);
            $type = explode('; ', $finfo->file($filename));
            $type = $type[0];
        } else {
            $type = 'image/' . substr($filename, strrpos($filename, '.') + 1);
        }
        ob_end_flush();
        return $type;
    }

    protected function updateProduct($id) {
        $this->createProduct($id);
    }

    protected function deleteProduct($id) {
        global $wpdb;

        $post_id = $wpdb->get_var(
                $wpdb->prepare("SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = %s AND meta_value = %s", 'Blaze_woo_product_id', $id)
        );

        wp_delete_post($post_id, true);
    }

    protected function createShipping($id) {
        
    }

    protected function updateShipping($id) {
        
    }

    protected function deleteShipping($id) {
        
    }

    protected function createOrder($id) {
        $this->updateOrder($id);
    }

    protected function updateOrder($id) {
        
    }

    protected function deleteOrder($id) {
        $this->updateOrder($id);
    }

    protected function syncSettings() {
        ob_start();
        global $wpdb;
        $apidomain = get_option('Blaze_api_domain');
        $apikey = get_option('Blaze_api_key');
        if ($apidomain != '' && $apikey != '') {
            $url = $apidomain . "/api/v1/store?api_key=" . $apikey;
            $data = wp_remote_get(esc_url_raw($url), array(
                'timeout' => 300,
                'sslverify' => false,
                'redirection' => 5,
                'blocking' => true
            ));
            $response = json_decode($data['body']);
            $enableDelivery = $response->shop->onlineStoreInfo->enableDelivery;
            $enableStorePickup = $response->shop->onlineStoreInfo->enableStorePickup;
            $zoneId = $wpdb->get_results("SELECT zone_id FROM {$wpdb->prefix}woocommerce_shipping_zones WHERE zone_name = 'BLAZE Shipping'");
    
            if(sizeof($zoneId) != 0 ){
                    $zoneID= $zoneId[0]->zone_id;
                    $deliverymethodID = $wpdb->get_results("SELECT instance_id FROM {$wpdb->prefix}woocommerce_shipping_zone_methods WHERE zone_id = $zoneID AND method_id='legacy_local_delivery'");
                    $instanceid=$deliverymethodID[0]->instance_id;
                    if($enableDelivery==1 && $instanceid ==''){
                    $shhpingmethoddelivery=array(
                        'zone_id' => $zoneID,
                        'method_id' => 'legacy_local_delivery',
                        'method_order' => 1,
                        'is_enabled' => 1,
                        );
                    $wpdb->insert($wpdb->prefix . 'woocommerce_shipping_zone_methods', $shhpingmethoddelivery);
                }else{
                    
                    if($enableDelivery==1){
                        $wpdb->query("UPDATE {$wpdb->prefix}woocommerce_shipping_zone_methods SET is_enabled=1 WHERE instance_id=$instanceid");
                    }else{
                        $wpdb->query("UPDATE {$wpdb->prefix}woocommerce_shipping_zone_methods SET is_enabled=0 WHERE instance_id=$instanceid");
                    }
                }
                $pickupmethodID = $wpdb->get_results("SELECT instance_id FROM {$wpdb->prefix}woocommerce_shipping_zone_methods WHERE zone_id = $zoneID AND method_id='legacy_local_pickup_1'");
                $instanceid=$pickupmethodID[0]->instance_id;
                if($enableStorePickup==1 && $instanceid ==''){
                    $shhpingmethodpickup=array(
                        'zone_id' => $zoneID,
                        'method_id' => 'legacy_local_pickup_1',
                        'method_order' => 1,
                        'is_enabled' => 1,
                        );
                    $wpdb->insert($wpdb->prefix . 'woocommerce_shipping_zone_methods', $shhpingmethodpickup);
                }else{
                    if($enableStorePickup==1){
                        $wpdb->query("UPDATE {$wpdb->prefix}woocommerce_shipping_zone_methods SET is_enabled=1 WHERE instance_id=$instanceid");
                    }else{
                        $wpdb->query("UPDATE {$wpdb->prefix}woocommerce_shipping_zone_methods SET is_enabled=0 WHERE instance_id=$instanceid");
                    }
                }
            }else{
                $shippingzone = array(
                        'zone_name' => "BLAZE Shipping",
                        'zone_order' => 0
                    );
                $wpdb->insert($wpdb->prefix . 'woocommerce_shipping_zones', $shippingzone);
                $zoneID = $wpdb->insert_id;
                if($enableDelivery==1){
                    $shhpingmethoddelivery=array(
                        'zone_id' => $zoneID,
                        'method_id' => 'legacy_local_delivery',
                        'method_order' => 1,
                        'is_enabled' => 1,
                        );
                    $wpdb->insert($wpdb->prefix . 'woocommerce_shipping_zone_methods', $shhpingmethoddelivery);
                }
                if($enableStorePickup == 1){
                    $shhpingmethodpickup=array(
                        'zone_id' => $zoneID,
                        'method_id' => 'legacy_local_pickup_1',
                        'method_order' => 2,
                        'is_enabled' => 1,
                        );
                    $wpdb->insert($wpdb->prefix . 'woocommerce_shipping_zone_methods', $shhpingmethodpickup);
                }
            }
        }


        //Product
        update_option('woocommerce_weight_unit', 'g');
        update_option('woocommerce_dimension_unit', 'in');

        // Account
        update_option('woocommerce_enable_myaccount_registration', 'yes');
        update_option('woocommerce_registration_generate_username', 'yes');
        update_option('woocommerce_registration_generate_password', 'no');
        update_option('woocommerce_enable_signup_and_login_from_checkout', 'no');

        //Hide out of stock items from the catalog

        update_option('woocommerce_hide_out_of_stock_items', 'yes');

        // Checkout
        update_option('woocommerce_enable_guest_checkout', 'no');
        update_option('woocommerce_enable_checkout_login_reminder', 'yes');

        // Company
        // update_option('Blaze_company_country', $settings['general']['country']);
        // update_option('Blaze_company_state', $settings['general']['state']);

        ob_end_flush();
    }

// ===================================== debug code ========================================


    public static function debug($text) {
        $log = debug_backtrace();
        $t = array();
        foreach ($log as $l) {
            if (isset($l['file']) && isset($l['line'])) {
                $t[] = $l['file'] . ' - ' . $l['line'] . ' - ' . json_encode($l['args']);
            }
        }

        $debug_file = Blaze::plugin_path() . '/deb.txt';
        if (file_exists($debug_file)) {
            $content = file_get_contents($debug_file);
        } else {
            $content = '';
        }
        $content .= '========================' . PHP_EOL . $text . PHP_EOL . implode(PHP_EOL, $t) . PHP_EOL;
        file_put_contents($debug_file, $content);
    }

    public function test() {
        //$this->execute();
    }

    public function productsChangeArray($output) {
        ob_start();
        $data = json_decode($output->raw_response);
        $new_data = array();

        foreach ($data->values as $val) {

            $prodArray['name'] = trim($val->name);
            $prodArray['pro_id'] = $val->id;
            $prodArray['description'] = $val->description;
            $prodArray['category_id'] = $val->categoryId;
            $prodArray['flowerType'] = $val->flowerType;
            $prodArray['weightPerUnit'] = $val->weightPerUnit;
            $prodArray['productSaleType'] = $val->productSaleType;
            $prodArray['sku'] = $val->sku;
            $prodArray['unitPrice'] = $val->unitPrice;
            $prodArray['brandId'] = $val->brandId;
            $prodArray['importId'] = $val->importId;
            $prodArray['brandName'] = $val->brand->name;
            $prodArray['companyId'] = $val->companyId;
            $prodArray['shopId'] = $val->shopId;
            $prodArray['vendorId'] = $val->vendorId;
            $prodArray['thc'] = $val->thc;
            $prodArray['cbn'] = $val->cbn;
            $prodArray['cbd'] = $val->cbd;
            $prodArray['cbda'] = $val->cbda;
            $prodArray['thca'] = $val->thca;
            $prodArray['potencyAmount'] = json_encode($val->potencyAmount);
            $vendorName = $val->vendor;


            $vendor = array();
            if (!empty($vendorName)) {
                $vendor = $vendorName->name;
            }
            $prodArray['vendorName'] = $vendor;

            //Products images and category image
            $imges = $val->assets;
            $img = array();
            foreach ($imges as $imgval) {
                $img[] = $imgval->publicURL;
            }
            if (empty($img)) {
                $catImage = $val->category;
                if (!empty($catImage)) {
                    $cimage = $catImage->photo;
                }
                $img[] = $cimage->publicURL;
            }
            $prodArray['images'] = json_encode($img);
            //Products images and category image

            /* category */
            $category = $val->category;
            $cat = array();
            if (!empty($category)) {
                $cat = $category->unitType;
            }
            $prodArray['price_type'] = $cat;
            /* category */

            $pricebreaks = $val->priceBreaks;
            $pb = array();
            $i = 0;
            foreach ($pricebreaks as $pricebreak) {
                $name = ($pricebreak->displayName == '') ? $pricebreak->name : $pricebreak->displayName;
                $pb[$i]['priceBreakType'] = $pricebreak->priceBreakType;
                $pb[$i]['name'] = str_replace(' ', '', $name);
                $pb[$i]['price'] = $pricebreak->price;
                $pb[$i]['salePrice'] = $pricebreak->salePrice;
                $pb[$i]['quantity'] = $pricebreak->quantity;
                $pb[$i]['active'] = $pricebreak->active;

                $i++;
            }

            $prodArray['price'] = json_encode($pb);
            /* price break */

            /* Price Range */
            $pricerange = $val->priceRanges;
            $priceran = array();
            $i = 0;
            foreach ($pricerange as $pr) {
                $priceran[$i]['price'] = $pr->price;
                $priceran[$i]['name'] = str_replace(' ', '', $pr->weightTolerance->name);
                $priceran[$i]['startWeight'] = $pr->weightTolerance->startWeight;
                $priceran[$i]['endWeight'] = $pr->weightTolerance->endWeight;
                $priceran[$i]['weightKey'] = $pr->weightTolerance->weightKey;
                $priceran[$i]['active'] = true;

                $i++;
            }


            if (!empty($priceran)) {
                $prodArray['price'] = json_encode($priceran);
            }
            /* price range */

            /* Quantity  total */

            $quantity = $val->quantities;
            $quantitytotal = 0;
            if (!empty($quantity)) {
                foreach ($quantity as $qty) {
                    $quantitytotal = $quantitytotal + $qty->quantity;
                }
            }

            $prodArray['joints_qty_w'] = $quantitytotal;
            /* tax */
            $prodArray['taxType'] = $val->taxType;
            $prodArray['taxOrder'] = $val->taxOrder;


            //Insert data in temp table wp_Blaze_product_tag
            global $wpdb;
            $tags = $val->tags;
            foreach ($tags as $tag) {
                $tag_array = array(
                    'name' => $tag,
                );

                $rowtag = $wpdb->get_row(
                        $wpdb->prepare("SELECT * FROM {$wpdb->prefix}Blaze_product_tag WHERE name = %s;", $tag), ARRAY_A
                );
                if (!$rowtag) {
                    $wpdb->insert($wpdb->prefix . 'Blaze_product_tag', $tag_array);
                }
            }
            //Insert data in temp table wp_Blaze_product_tag
            global $wpdb;
            $tags_ref = $val->tags;

            //get current tags
            $wpdb->query(
                    $wpdb->prepare("DELETE FROM {$wpdb->prefix}Blaze_product_tag_ref WHERE product_id = %s;", $val->id));


            foreach ($tags_ref as $tag_ref) {
                $row_ref_tag = $wpdb->get_row(
                        $wpdb->prepare("SELECT * FROM {$wpdb->prefix}Blaze_product_tag WHERE name = %s;", $tag_ref), ARRAY_A
                );
                $tag_ref_array = array(
                    'product_id' => $val->id,
                    'tag_id' => $row_ref_tag['id'],
                );
                $rowtagref = $wpdb->get_row(
                        $wpdb->prepare("SELECT * FROM {$wpdb->prefix}Blaze_product_tag_ref WHERE product_id = %s AND tag_id=%s;", $val->id, $row_ref_tag['id']), ARRAY_A
                );
                if (!$rowtagref) {
                    $wpdb->insert($wpdb->prefix . 'Blaze_product_tag_ref', $tag_ref_array);
                }
            }

            $new_data[] = (object) $prodArray;
        }

        $return_data = (object) array();
        $return_data->success = 1;
        $return_data->total = 1;
        $return_data->data = (object) array();
        $return_data->data->product = (object) array();
        $return_data->data->product->created = $new_data;
        $return_data->data->product->created_total = count($new_data);
        $return_data->data->product->sync_id = time();
        ob_end_flush();
        return $return_data;
    }

    //create json for category from categoryapi
    public function categoriesChangeArray($output) {
        $data = json_decode($output->raw_response);
        $new_data = array();

        foreach ($data->values as $val) {

            $prodArray['catid'] = $val->id;
            $prodArray['name'] = trim($val->name);
            $prodArray['catid'] = $val->id;
            $prodArray['lft'] = 1;
            $prodArray['rgt'] = 1;
            $prodArray['level'] = $val->priority;
            $prodArray['parent_id'] = 0;

            $new_data[] = (object) $prodArray;
        }

        $return_data = (object) array();
        $return_data->success = 1;
        $return_data->total = 1;
        $return_data->data = (object) array();
        $return_data->data->product_category = (object) array();
        $return_data->data->product_category->created = $new_data;
        $return_data->data->product_category->created_total = count($new_data);
        $return_data->data->product_category->sync_id = time();
        return $return_data;
    }

// =========================================================================================
}
