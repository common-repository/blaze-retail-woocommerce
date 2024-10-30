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
    if ($data->showInWidget) {
        $post_id = $wpdb->get_var(
                $wpdb->prepare("SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = %s AND meta_value = %s LIMIT 1", 'Blaze_woo_product_id', $data->id)
        );
        if ($post_id) {
            $post_data = array(
                'post_title' => $data->name,
                'post_content' => $data->description,
                'post_status' => 'publish',
                'post_author' => 1,
                'post_parent' => 0,
                'post_type' => 'product',
                'menu_order' => 0
            );
            $wpdb->update($wpdb->posts, $post_data, array('ID' => $post_id));
        } else {

            $post_data = array(
                'post_title' => $data->name,
                'post_content' => $data->description,
                'post_status' => 'publish',
                'post_author' => 1,
                'post_parent' => 0,
                'post_type' => 'product',
                'menu_order' => 0
            );
            $post_id = wp_insert_post($post_data);
            update_post_meta($post_id, 'Blaze_woo_product_id', $data->id);
        }

        $category = get_term_by('name', $data->category->name, 'product_cat');
        $cat_id = $category->term_id;
        if ($cat_id == 0 || $cat_id == '') {
            wp_insert_term(
                    $data->category->name,
                    'product_cat'
            );
        }
        /* Quantity  total */

        $quantity = $data->quantities;
        $quantitytotal = 0;
        if (!empty($quantity)) {
            foreach ($quantity as $qty) {
                $quantitytotal = $quantitytotal + $qty->quantity;
            }
        }

        $prodArray['joints_qty_w'] = $quantitytotal;
        if ($prodArray['joints_qty_w'] == 0) {
            update_post_meta($post_id, '_manage_stock', 'yes');
            update_post_meta($post_id, '_stock', 0);
            update_post_meta($post_id, '_stock_status', 'outofstock');
        } else {
            $Outofstockthreshold = get_option('woocommerce_notify_no_stock_amount');
            if ($Outofstockthreshold > 0 && $Outofstockthreshold > $prodArray['joints_qty_w']) {
                update_post_meta($post_id, '_manage_stock', 'yes');
                update_post_meta($post_id, '_stock', 0);
                update_post_meta($post_id, '_stock_status', 'outofstock');
            } else {
                update_post_meta($post_id, '_manage_stock', 'no');
                update_post_meta($post_id, '_stock_status', 'instock');
            }
        }
        $category = get_term_by('name', $data->category->name, 'product_cat');
        $cat_id = $category->term_id;
        wp_set_object_terms($post_id, $cat_id, 'product_cat');
        update_post_meta($post_id, 'potencyAmount', json_encode($data->potencyAmount));
        update_post_meta($post_id, '_sku', $data->sku);
        $Blazeproducttagref = $wpdb->prefix . 'Blaze_product_tag_ref';
        $Blazeproducttag = $wpdb->prefix . 'Blaze_product_tag';
        $tags = $data->tags;
        foreach ($tags as $tag) {
            $tag_array = array(
                'name' => $tag,
            );

            $rowtag = $wpdb->get_row(
                    $wpdb->prepare("SELECT * FROM $Blazeproducttag WHERE name = %s;", $tag), ARRAY_A
            );
            if (!$rowtag) {
                $wpdb->insert($Blazeproducttag, $tag_array);
            }
        }
        global $wpdb;
        $tags_ref = $data->tags;
        $wpdb->query(
                $wpdb->prepare("DELETE FROM $Blazeproducttagref WHERE product_id = %s;", $data->id));


        foreach ($tags_ref as $tag_ref) {
            $row_ref_tag = $wpdb->get_row(
                    $wpdb->prepare("SELECT * FROM $Blazeproducttag WHERE name = %s;", $tag_ref), ARRAY_A
            );
            $tag_ref_array = array(
                'product_id' => $data->id,
                'tag_id' => $row_ref_tag['id'],
            );
            $rowtagref = $wpdb->get_row(
                    $wpdb->prepare("SELECT * FROM $Blazeproducttagref WHERE product_id = %s AND tag_id=%s;", $data->id, $row_ref_tag['id']), ARRAY_A
            );
            if (!$rowtagref) {
                $wpdb->insert($Blazeproducttagref, $tag_ref_array);
            }
        }

        $tags = $wpdb->get_results(
                $wpdb->prepare(
                        "SELECT t.id, t.name FROM $Blazeproducttagref as tr LEFT JOIN $Blazeproducttag as t ON tr.tag_id = t.id WHERE product_id = %s;", $data->id
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
        $is_grams = $data->category->unitType == 'grams';
        $is_units = $data->category->unitType == 'units';
        $is_custom = '';
        $product_type = !$is_grams && !$is_units && !$is_custom ? 'simple' : 'variable';
        wp_set_object_terms($post_id, $product_type, 'product_type');

        if ($product_type == 'variable') {
            $variations_data = array();
            $attribute_value = array();
            if ($is_grams && $is_units && !$is_custom) {



                $pricebreaks = $data->priceBreaks;
                $pb = array();
                $i = 0;
                foreach ($pricebreaks as $pricebreak) {
                    $name = ($pricebreak->displayName == '') ? $pricebreak->name : $pricebreak->displayName;
                    $pb[$i]['priceBreakType'] = $pricebreak->priceBreakType;
                    $pb[$i]['name'] = str_replace(' ', '', $name);
                    $pb[$i]['price'] = $pricebreak->price;
                    $pb[$i]['quantity'] = $pricebreak->quantity;
                    $pb[$i]['active'] = $pricebreak->active;

                    $i++;
                }

                $prodArray['price'] = json_encode($pb);
                /* price break */

                /* Price Range */
                $pricerange = $data->priceRanges;
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

                $vardata = json_decode($prodArray['price']);

                $i = 1;
                foreach ($vardata as $dataa) {
                    if ($dataa->active == 1 && $dataa->price != 0 && $dataa->price != '' && $dataa->price != null) {
                        $variation = array(
                            'sku' => $data->id . "_" . $i,
                            'name' => $dataa->name,
                            'regular_price' => $dataa->price,
                            'menu_order' => $i,
                            'instock' => 1,
                            'variation' => $dataa->name,
                        );
                        $variations_data[] = $variation;
                        $i++;
                    }
                }
            } else {

                $pricebreaks = $data->priceBreaks;
                $pb = array();
                $i = 0;
                foreach ($pricebreaks as $pricebreak) {
                    $name = ($pricebreak->displayName == '') ? $pricebreak->name : $pricebreak->displayName;
                    $pb[$i]['priceBreakType'] = $pricebreak->priceBreakType;
                    $pb[$i]['name'] = str_replace(' ', '', $name);
                    $pb[$i]['price'] = $pricebreak->price;
                    $pb[$i]['quantity'] = $pricebreak->quantity;
                    $pb[$i]['active'] = $pricebreak->active;

                    $i++;
                }
            }

            $prodArray['price'] = json_encode($pb);
            /* price break */

            /* Price Range */
            $pricerange = $data->priceRanges;
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
            $i = 1;
            $vardata = json_decode($prodArray['price']);
            foreach ($vardata as $dataa) {
                if ($dataa->active == 1 && $dataa->price != 0 && $dataa->price != '' && $dataa->price != null) {
                    $variation = array(
                        'sku' => $data->id . "_" . $i,
                        'name' => $dataa->name,
                        'regular_price' => $dataa->price,
                        'menu_order' => $i,
                        'instock' => 1,
                        'variation' => $dataa->name,
                    );
                    $variations_data[] = $variation;
                    $i++;
                }
            }
            $vardata = json_decode($prodArray['price']);
            foreach ($vardata as $v) {
                $price[$v->name] = $v->price;
            }

            $attribute_values1 = str_replace(' ', '', array_keys($price));
            $attributes = array(
                $data->category->unitType => array(
                    'name' => $data->category->unitType,
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
            if ($data->thc) {
                update_post_meta($post_id, 'thc', $data->thc);
            }
            if ($data->cbn) {
                update_post_meta($post_id, 'cbn', $data->cbn);
            }
            if ($data->cbd) {
                update_post_meta($post_id, 'cbd', $data->cbd);
            }
            if ($data->cbda) {
                update_post_meta($post_id, 'cbda', $data->cbda);
            }
            if ($data->thca) {
                update_post_meta($post_id, 'thca', $data->thca);
            }
            if ($data->brand->name) {
                update_post_meta($post_id, 'brandName', $data->brand->name);
            }
            if ($data->flowerType) {
                update_post_meta($post_id, 'flowerType', $data->flowerType);
            }
            if ($data->category->unitType) {
                update_post_meta($post_id, 'producttype', $data->category->unitType);
            }
            if ($prodArray['price']) {
                update_post_meta($post_id, 'productattributes', $prodArray['price']);
            }

            // Getting variables name and update

            $updated_variation_ids = array();
            foreach ($variations_data as $k => $variation_data) {
                $table = _get_meta_table('post');
                $variation_id = $wpdb->get_var(
                        $wpdb->prepare("SELECT post_id FROM $table WHERE meta_key = %s AND meta_value = %s ORDER BY post_id
  DESC LIMIT 1", '_sku', $variation_data['sku'])
                );
                // Generate a useful post title
                $variation_post_title = sprintf(__('Variation #%s of %s', 'woocommerce'), absint($variation_id), esc_html(get_the_title($post_id)));
				
                // Update or Add post
				if ( get_post_status ( $variation_id ) ) {
                    $wpdb->update($wpdb->posts, array(
                        'post_status' => 'publish',
                        'post_title' => $variation_post_title,
                        'post_parent' => $post_id,
                        'menu_order' => $variation_data['menu_order'],
                            ), array('ID' => $variation_id));

                    do_action('woocommerce_update_product_variation', $variation_id);
                } else {
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
                }
                if (!$variation_id) {
                    continue;
                }

                update_post_meta($variation_id, '_sku', $variation_data['sku']);
                update_post_meta($variation_id, '_virtual', 'no');
                update_post_meta($variation_id, '_downloadable', 'no');
                update_post_meta($variation_id, '_manage_stock', 'no');

                if ($variation_data['instock']) {
                    wc_update_product_stock_status($variation_id, 'instock');
                } else {
                    wc_update_product_stock_status($variation_id, 'outofstock');
                }
                // Price handling for variation
                $pricedata = json_decode($prodArray['price']);
                $regular_price = wc_format_decimal($variation_data['regular_price']);

                update_post_meta($variation_id, '_regular_price', $regular_price);
                update_post_meta($variation_id, '_price', $sale_price ? $sale_price : $regular_price);
                update_post_meta($variation_id, 'Blaze_woo_variation', $variation_data['variation']);



                delete_post_meta($variation_id, '_tax_class');
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
        $imges = $data->assets;
        $img = array();
        foreach ($imges as $imgval) {
            $img[] = $imgval->mediumURL;
        }
        if (empty($img)) {
            $catImage = $data->category;
            if (!empty($catImage)) {
                $cimage = $catImage->photo;
            }
            $img[] = $cimage->publicURL;
        }
        $prodArray['images'] = json_encode($img);
        $pro_image = json_decode($prodArray['images'])[0];

        $attach_id = createAttachment($post_id, $pro_image);
        if ($attach_id) {
            update_post_meta($post_id, '_thumbnail_id', $attach_id);
        }

        // feature image upload
        // multi product image upload
        $images = json_decode($prodArray['images']);
        $totalnumber = count($images);
        $j = 1;
        for ($j = 1; $j < $totalnumber; $j++) {
            $attach_gallery_id[] = createAttachment($post_id, json_decode($prodArray['images'])[$j]);
        }

        if (!empty($attach_gallery_id)) {
            update_post_meta($post_id, '_product_image_gallery', implode(',', $attach_gallery_id));
        } else {
            update_post_meta($post_id, '_product_image_gallery', '');
        }

        // Clear cache/transients
        ob_end_flush();
        wc_delete_product_transients($post_id);
    }
}

function createAttachment($post_id, $photo) {
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
        $error_logger = new Blaze_error_logger();
        $error_logger->log('Turn on allow_url_open and curl.' . $photo . PHP_EOL);
        return false;
    }
    if (!$content) {
        $error_logger = new Blaze_error_logger();
        $error_logger->log('File uploaded fail ' . $photo . PHP_EOL);
        return false;
    }

    file_put_contents($filename, $content);

    $type = mime_content_typenew($filename);

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
}

function mime_content_typenew($filename) {
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
