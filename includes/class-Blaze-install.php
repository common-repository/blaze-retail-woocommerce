<?php

/**
 * WooCommerce BLAZE Install
 *
 * @author      BLAZE
 * @category    API
 * @package     WooCommerce/API
 */
if (!defined('ABSPATH'))
    exit;

class Blaze_woo_install {

    /**
     * Hook in tabs.
     */
    public function __construct() {
        register_activation_hook(Blaze_PLUGIN_FILE, array($this, 'install'));
        register_deactivation_hook(Blaze_PLUGIN_FILE, array($this, 'uninstall'));
    }

    // Install BLAZE

    public function install() {
        $this->api_settings();
        $this->create_tables();
        $this->create_cron_jobs();
        $this->Blaze_set_woocommerce_default_settings();
    }

    // Uninstall BLAZE

    public function uninstall() {
        $this->clear_cron_jobs();
        WooBlaze_Retail()->sync->dropTables();
    }

    private function api_settings() {
        add_option('Blaze_api_domain', 'https://api.blaze.me', null, true);
        add_option('Blaze_api_key', '', null, true);
        if(get_option('Blaze_api_domain') != 'https://api.blaze.me'){
            update_option('Blaze_api_domain', 'https://api.blaze.me');
        }

        $settings_file = WooBlaze_Retail::plugin_path() . '/api_settings.txt';
        if (file_exists($settings_file)) {
            $content = file_get_contents($settings_file);
            list($domain, $apikey, $country, $state) = explode('|', $content);
            update_option('Blaze_api_domain', $domain);
            update_option('Blaze_api_key', $apikey);
            update_option('Blaze_company_country', $country);

            if (!get_option('Blaze_show_verify') && $country != 'Canada') {
                update_option('Blaze_show_verify', 'yes');
            }

            update_option('Blaze_docs_required', 'yes');
            $this->Blaze_set_woocommerce_general_info($country, $state);
            unlink($settings_file);
        }

        if (!get_option('Blaze_show_pp_gram')) {
            add_option('Blaze_show_pp_gram', 'no');
        }
        if (!get_option('Blaze_strain_as_category')) {
            add_option('Blaze_strain_as_category', 'no');
        }
        if (!get_option('Blaze_id_required')) {
            add_option('Blaze_id_required', 'yes');
        }
    }

    private function Blaze_set_woocommerce_default_settings() {

        $woocommerce_cod_settings = array(
            'enabled' => 'yes',
            'title' => 'Cash on Delivery',
            'description' => '',
            'instructions' => '',
            'enable_for_methods' => '',
            'enable_for_virtual' => 'yes',
        );
        update_option('woocommerce_cod_settings', $woocommerce_cod_settings);
    }

    // Create cron jobs (clear them first)

    private function create_cron_jobs() {
        wp_schedule_event(time(), 'Blaze_sync', 'Blaze_synchronize');
        wp_schedule_event(time(), 'Blaze_sync', 'Blaze_upload_files');
    }

    // Delete cron jobs (clear them first)

    private function clear_cron_jobs() {
        wp_clear_scheduled_hook('Blaze_synchronize');
        wp_clear_scheduled_hook('Blaze_upload_files');
    }

    // Create temporary tables for sync

    private function create_tables() {
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

        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');

        $Blaze_tables = '';
        $Blaze_tables .= WooBlaze_Retail()->sync->getTablesSql($wpdb->prefix, $collate);
        dbDelta($Blaze_tables);
    }

    // Set default country, state and currency in woocommerce gemeral info

    private function Blaze_set_woocommerce_general_info($country, $state) {

        $country_code = 'US';
        $state = $state ? $state : 'CA';
        $currency = 'USD';

        $default_country = $country_code . ':' . $state;
        $specific_allowed_countries = array($country_code);

        if (isset($default_country)) {
            update_option('woocommerce_default_country', $default_country);
        }
        if (isset($specific_allowed_countries) && is_array($specific_allowed_countries)) {
            update_option('woocommerce_allowed_countries', 'specific');
            update_option('woocommerce_specific_allowed_countries', $specific_allowed_countries);
        }
        if (isset($currency)) {
            update_option('woocommerce_currency', $currency);
        }
    }

}

return new Blaze_woo_install();
