<?php

/**
 * WooCommerce BLAZE Error Strain
 *
 * @author      BLAZE
 * @category    API
 * @package     WooCommerce/API
 */
if (!defined('ABSPATH'))
    exit;

class Blaze_strain {

    const STRAIN_blaze_ID = 1;
    const STRAIN_SATIVA_ID = 2;
    const STRAIN_HYBRID_ID = 3;
    const STRAIN_blaze_NAME = 'blaze';
    const STRAIN_SATIVA_NAME = 'Sativa';
    const STRAIN_HYBRID_NAME = 'Hybrid';

    private static $term_ids;

    public static function getStrainSlug($strain_id) {
        return 'strain' . $strain_id;
    }

    public static function addProductsToStrainCategory($products) {
        if (!is_array($products)) {
            return;
        }

        global $wpdb;

        foreach ($products as $product) {
            $post_id = $wpdb->get_var(
                    $wpdb->prepare("SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = %s AND meta_value = %s", 'Blaze_woo_product_id', $product['id'])
            );
            if ($post_id) {
                $terms = self::getTermIds();
            }
        }
    }

    public static function createProductCategoriesByStrain() {
        foreach (self::getStrainSlugWithName() as $slug => $name) {
            if (!get_term_by('slug', $slug, 'product_cat')) {
                $args = array(
                    'taxonomy' => 'product_cat',
                    'post_type' => 'product',
                    'name' => $name,
                    'slug' => $slug,
                    'parent' => 0,
                    'description' => 'Strain category',
                );
                wp_insert_term($name, 'product_cat', $args);
            }
        }
    }

    public static function deleteProductCategoriesByStrain() {
        foreach (array_keys(self::getStrainSlugWithName()) as $slug) {
            $term = get_term_by('slug', $slug, 'product_cat', 'ARRAY_A');
            if ($term) {
                wp_delete_term($term['term_id'], 'product_cat');
            }
        }
    }

    public static function getTermIds() {
        return self::$term_ids;
    }

    private static function getInitTermIdByStrainId($id) {
        $term = get_term_by('slug', self::getStrainSlug($id), 'product_cat', 'ARRAY_A');
        return is_array($term) && isset($term['term_id']) ? $term['term_id'] : false;
    }

    private static function getStrainSlugWithName() {
        return array(
            self::getStrainSlug(self::STRAIN_blaze_ID) => self::STRAIN_blaze_NAME,
            self::getStrainSlug(self::STRAIN_SATIVA_ID) => self::STRAIN_SATIVA_NAME,
            self::getStrainSlug(self::STRAIN_HYBRID_ID) => self::STRAIN_HYBRID_NAME,
        );
    }

}
