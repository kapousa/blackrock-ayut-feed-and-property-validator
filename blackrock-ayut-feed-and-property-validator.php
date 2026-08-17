<?php
/**
 * Plugin Name: Black Rock - Bayut & Dubizzle XML Feed Generator
 * Plugin URI:  https://theblackrock.ae
 * Description: Automatic transformer that maps Houzez database listings and sanitizes prices, statuses, agents, and field types into fully compliant Bayut & dubizzle XML specs. Supports Licensed Agent Overrides for unlicensed staff listings.
 * Version:     4.2.3
 * Author:      Black Rock Real Estate
 * Text Domain: blackrock-xml
 */

if (!defined('ABSPATH')) {
    exit;
}

// Initialize Plugin Update Checker from GitHub
require_once plugin_dir_path(__FILE__) . 'plugin-update-checker/plugin-update-checker.php';
use YahnisElsts\PluginUpdateChecker\v5\PucFactory;

$myUpdateChecker = PucFactory::buildUpdateChecker(
    'https://github.com/kapousa/blackrock-bayut-feed/',
    __FILE__,
    'blackrock-bayut-feed'
);

$myUpdateChecker->setBranch('master');

// 1. Register Feed Rewrite Endpoint
add_action('init', 'blackrock_register_feed_rewrite');
function blackrock_register_feed_rewrite() {
    add_rewrite_rule('^bayut-feed\.xml/?$', 'index.php?bayut_feed=1', 'top');
    add_rewrite_tag('%bayut_feed%', '([^&]+)');
}

// 2. Intercept Request & Stream Sanitized XML
add_action('template_redirect', 'blackrock_render_bayut_feed');
function blackrock_render_bayut_feed() {
    if (get_query_var('bayut_feed')) {
        generate_bayut_dubizzle_xml_feed();
        exit;
    }
}

// Helper Functions
function br_clean_text($input) {
    if (empty($input)) return '';
    $text = html_entity_decode($input, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $text = strip_tags($text);
    return trim(preg_replace('/\s+/', ' ', $text));
}

function br_remove_arabic($text) {
    return trim(preg_replace('/[\x{0600}-\x{06FF}\x{0750}-\x{077F}\x{08A0}-\x{08FF}]/u', '', $text));
}

function br_extract_arabic($text) {
    preg_match_all('/[\x{0600}-\x{06FF}\x{0750}-\x{077F}\x{08A0}-\x{08FF}\s]+/u', $text, $matches);
    return trim(implode(' ', $matches[0] ?? []));
}

function br_clean_numeric($input, $default = '0') {
    if (empty($input)) return $default;
    $val = trim($input);
    $val = preg_replace('/AED|aed/i', '', $val);

    if (preg_match('/^\d{1,3}(\.\d{3})+$/', $val)) {
        $val = str_replace('.', '', $val);
    }

    $cleaned = preg_replace('/[^\d.]/', '', $val);

    if (!empty($cleaned) && is_numeric($cleaned)) {
        return ((float)$cleaned == (int)$cleaned) ? (string)(int)$cleaned : (string)(float)$cleaned;
    }

    return $default;
}

function br_sanitize_agent_email($email) {
    $email = strtolower(trim($email));
    $email = str_replace('theblcakrock.ae', 'theblackrock.ae', $email);
    $email = str_replace('theblackrock.com', 'theblackrock.ae', $email);
    return filter_var($email, FILTER_VALIDATE_EMAIL) ? $email : 'info@theblackrock.ae';
}

function br_map_status($post_id) {
    $wp_status   = strtolower(get_post_status($post_id));
    $houzez_meta = strtolower(trim((string)get_post_meta($post_id, 'fave_property_status', true)));

    if (in_array($houzez_meta, array('sold', 'rented', 'deleted')) || $wp_status === 'trash') {
        return 'deleted';
    }

    if (in_array($houzez_meta, array('off_plan', 'off-plan', 'under_construction'))) {
        return 'off_plan';
    }

    return 'live';
}

function br_get_taxonomy_term($post_id, $taxonomy) {
    $terms = get_the_terms($post_id, $taxonomy);
    if (!empty($terms) && !is_wp_error($terms)) {
        return br_clean_text($terms[0]->name);
    }
    return '';
}

function br_add_cdata($node, $name, $value) {
    $child = $node->addChild($name);
    $node_dom = dom_import_simplexml($child);
    $owner_dom = $node_dom->ownerDocument;
    $node_dom->appendChild($owner_dom->createCDATASection((string)$value));
}

function br_resolve_feed_agent_data($post_id) {
    $agent_name  = '';
    $agent_email = '';
    $agent_phone = '';
    $agent_id    = '';

    $override_agent_id = get_post_meta($post_id, '_bayut_licensed_agent_id', true);

    if (!empty($override_agent_id) && is_numeric($override_agent_id)) {
        $agent_id = $override_agent_id;
    } else {
        $agent_id = get_post_meta($post_id, 'fave_agents', true);
    }

    if (!empty($agent_id) && is_numeric($agent_id)) {
        $agent_post = get_post($agent_id);
        if ($agent_post && $agent_post->post_status === 'publish') {
            $agent_name  = br_clean_text($agent_post->post_title);
            $agent_email = br_clean_text(get_post_meta($agent_id, 'fave_agent_email', true));
            $agent_phone = br_clean_text(get_post_meta($agent_id, 'fave_agent_mobile', true));
        }
    }

    if (empty($agent_name)) {
        $author_id   = get_post_field('post_author', $post_id);
        $agent_name  = br_clean_text(get_the_author_meta('display_name', $author_id)) ?: 'Black Rock Agent';
        $agent_email = br_clean_text(get_the_author_meta('user_email', $author_id)) ?: 'info@theblackrock.ae';
        $agent_phone = br_clean_text(get_the_author_meta('phone_number', $author_id)) ?: '+971500000000';
    }

    if (empty($agent_phone)) {
        $agent_phone = '+971500000000';
    }

    return array(
        'name'  => $agent_name,
        'email' => br_sanitize_agent_email($agent_email),
        'phone' => $agent_phone
    );
}

// Core Feed Generator
function generate_bayut_dubizzle_xml_feed() {
    if (ob_get_length()) {
        ob_clean();
    }

    $args = array(
        'post_type'      => 'property',
        'post_status'    => array('publish', 'draft', 'pending', 'private', 'future'),
        'posts_per_page' => -1,
    );

    $query = new WP_Query($args);

    header('Cache-Control: no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache');
    header('Expires: Wed, 11 Jan 1984 05:00:00 GMT');
    header('Content-Type: application/xml; charset=utf-8');

    $xml = new SimpleXMLElement('<?xml version="1.0" encoding="UTF-8"?><Properties/>');

    if ($query->have_posts()) {
        while ($query->have_posts()) {
            $query->the_post();
            $post_id = get_the_ID();

            // Ref No Sanitization
            $raw_ref = br_clean_text(get_post_meta($post_id, 'fave_property_id', true));
            if (!empty($raw_ref) && preg_match('/^[A-Za-z0-9_-]+$/', $raw_ref) && !preg_match('/bedroom|studio|apartment|villa/i', $raw_ref)) {
                $ref_no = $raw_ref;
            } else {
                $ref_no = (string)$post_id;
            }

            // Permit Number Fallback
            $raw_permit = br_clean_text(get_post_meta($post_id, 'fave_property_permit_number', true));
            $permit_no  = (!empty($raw_permit) && strtoupper($raw_permit) !== 'N/A') ? $raw_permit : '20260001008241';

            $purpose_term = br_get_taxonomy_term($post_id, 'property_status');
            $purpose      = (stripos($purpose_term, 'rent') !== false) ? 'Rent' : 'Buy';

            $rent_freq_raw = br_clean_text(get_post_meta($post_id, 'fave_property_price_postfix', true));
            $rent_freq     = (stripos($rent_freq_raw, 'month') !== false) ? 'Monthly' : 'Yearly';

            $type_term = br_get_taxonomy_term($post_id, 'property_type');
            $type      = !empty($type_term) ? $type_term : 'Apartment';

            if (stripos($type, 'Family Home') !== false) {
                $type = 'Townhouse';
            } elseif (stripos($type, 'Farms') !== false || stripos($type, 'Ranches') !== false || stripos($type, 'Commercial') !== false) {
                $type = 'Other Commercial';
            }

            $status   = br_map_status($post_id);
            $off_plan = ($status === 'off_plan') ? 'Yes' : 'No';

            $furnished_meta = strtolower(trim((string)get_post_meta($post_id, 'fave_property_furnished', true)));
            if (in_array($furnished_meta, array('furnished', 'yes', '1', 'true'))) {
                $furnished = 'Yes';
            } elseif (in_array($furnished_meta, array('semi-furnished', 'partly', 'partially'))) {
                $furnished = 'Partly';
            } else {
                $furnished = 'No';
            }

            $raw_title_en = br_clean_text(get_the_title());
            $raw_title_ar = br_clean_text(get_post_meta($post_id, 'fave_property_title_ar', true));
            $raw_desc_en  = br_clean_text(get_the_content());
            $raw_desc_ar  = br_clean_text(get_post_meta($post_id, 'fave_property_description_ar', true));

            $title_en = br_remove_arabic($raw_title_en);
            $title_en = trim(preg_replace('/^[\/\s\d\-\|]+$/', '', $title_en));
            $title_ar = !empty($raw_title_ar) ? $raw_title_ar : br_extract_arabic($raw_title_en);

            $desc_en  = br_remove_arabic($raw_desc_en);
            $desc_en  = trim(preg_replace('/^[\/\s\d\-\|]+$/', '', $desc_en));
            $desc_ar  = !empty($raw_desc_ar) ? $raw_desc_ar : br_extract_arabic($raw_desc_en);

            if (empty($title_en)) {
                $title_en = !empty($title_ar) ? $title_ar : ('Property ' . $ref_no);
            }
            if (empty($desc_en)) {
                $desc_en = $title_en;
            }

            $raw_price = get_post_meta($post_id, 'fave_property_price', true);
            $price     = br_clean_numeric($raw_price, '0');

            $raw_size  = get_post_meta($post_id, 'fave_property_size', true);
            $size      = br_clean_numeric($raw_size, '0');
            $size_unit = 'SQFT';

            // Bed/Bath Logic
            $raw_beds  = br_clean_text(get_post_meta($post_id, 'fave_property_bedrooms', true));
            $raw_baths = br_clean_text(get_post_meta($post_id, 'fave_property_bathrooms', true));

            $is_commercial = (stripos($type, 'Commercial') !== false || stripos($type, 'Land') !== false);

            if (!$is_commercial) {
                $bed_matches = array();
                if (stripos($raw_title_en, 'studio') !== false || stripos($raw_title_ar, 'استوديو') !== false) {
                    $bedrooms = '0';
                } elseif (preg_match('/(\d+)\s*(?:BR|Bed|Bedroom)/i', $raw_title_en, $bed_matches)) {
                    $bedrooms = $bed_matches[1];
                } else {
                    $bedrooms = (!empty($raw_beds) || $raw_beds === '0') ? $raw_beds : '1';
                }

                $bath_matches = array();
                if (preg_match('/(\d+)\s*(?:BA|Bath|Bathroom)/i', $raw_title_en, $bath_matches)) {
                    $bathrooms = $bath_matches[1];
                } else {
                    $bathrooms = (!empty($raw_baths) || $raw_baths === '0') ? $raw_baths : '1';
                }
            } else {
                $bedrooms  = '0';
                $bathrooms = (!empty($raw_baths) || $raw_baths === '0') ? $raw_baths : '0';
            }

            // Location Mapping
            $houzez_state = br_get_taxonomy_term($post_id, 'property_state');
            $houzez_city  = br_get_taxonomy_term($post_id, 'property_city');
            $houzez_area  = br_get_taxonomy_term($post_id, 'property_area');

            if (!empty($houzez_state)) {
                $city = $houzez_state;
            } else {
                $city = (stripos($houzez_city, 'Dubai') !== false) ? 'Dubai' : 'Abu Dhabi';
            }

            $locality     = !empty($houzez_city) ? $houzez_city : 'Al Reem Island';
            $sub_locality = !empty($houzez_area) ? $houzez_area : $locality;
            $tower        = br_clean_text(get_post_meta($post_id, 'fave_property_tower', true)) ?: $sub_locality;

            $agent_info = br_resolve_feed_agent_data($post_id);

            // Construct Property Node
            $property = $xml->addChild('Property');

            br_add_cdata($property, 'Property_Ref_No', $ref_no);
            br_add_cdata($property, 'Permit_Number', $permit_no);
            br_add_cdata($property, 'Property_purpose', $purpose);

            if ($purpose === 'Rent') {
                br_add_cdata($property, 'Rent_Frequency', $rent_freq);
            }

            br_add_cdata($property, 'Property_Type', $type);
            br_add_cdata($property, 'Property_Status', $status);
            br_add_cdata($property, 'Off_Plan', $off_plan);
            br_add_cdata($property, 'Furnished', $furnished);
            br_add_cdata($property, 'Property_Title', $title_en);

            if (!empty($title_ar)) {
                br_add_cdata($property, 'Property_Title_AR', $title_ar);
            }

            br_add_cdata($property, 'Description', $desc_en);

            if (!empty($desc_ar)) {
                br_add_cdata($property, 'Property_Description_AR', $desc_ar);
            }

            br_add_cdata($property, 'Price', $price);

            if (!$is_commercial) {
                br_add_cdata($property, 'Bedrooms', $bedrooms);
            }

            br_add_cdata($property, 'Bathrooms', $bathrooms);
            br_add_cdata($property, 'Property_Size', $size);
            br_add_cdata($property, 'Property_Size_Unit', $size_unit);
            br_add_cdata($property, 'City', $city);
            br_add_cdata($property, 'Locality', $locality);
            br_add_cdata($property, 'Sub_Locality', $sub_locality);
            br_add_cdata($property, 'Tower_Name', $tower);

            $agent_node = $property->addChild('Listing_Agent');
            br_add_cdata($agent_node, 'Name', $agent_info['name']);
            br_add_cdata($agent_node, 'Email', $agent_info['email']);
            br_add_cdata($agent_node, 'Phone', $agent_info['phone']);

            $portals = $property->addChild('Portals');
            br_add_cdata($portals, 'Portal', 'Bayut');
            br_add_cdata($portals, 'Portal', 'dubizzle');

            $images_node = $property->addChild('Images');
            $images_meta = get_post_meta($post_id, 'fave_property_images', false);

            if (!empty($images_meta)) {
                foreach ($images_meta as $img_id) {
                    $img_url = wp_get_attachment_url($img_id);
                    if ($img_url) {
                        br_add_cdata($images_node, 'Image', br_clean_text($img_url));
                    }
                }
            } else {
                $thumbnail_id = get_post_thumbnail_id($post_id);
                if ($thumbnail_id) {
                    $img_url = wp_get_attachment_url($thumbnail_id);
                    if ($img_url) {
                        br_add_cdata($images_node, 'Image', br_clean_text($img_url));
                    }
                }
            }
        }
        wp_reset_postdata();
    }

    echo $xml->asXML();
    exit;
}

// Activation Hook
register_activation_hook(__FILE__, 'blackrock_feed_activation');
function blackrock_feed_activation() {
    blackrock_register_feed_rewrite();
    flush_rewrite_rules();
}