<?php
/**
 * Plugin Name: Bayut Audit Portal Shortcode
 * Description: Parses XML feed dynamically and displays Bayut property audit table.
 * Version: 1.2
 */

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly
}

// Initialize Plugin Update Checker from GitHub
require_once plugin_dir_path(__FILE__) . 'plugin-update-checker/plugin-update-checker.php';
use YahnisElsts\PluginUpdateChecker\v5\PucFactory;

$myUpdateChecker = PucFactory::buildUpdateChecker(
    'https://github.com/kapousa/blackrock-ayut-feed-and-property-validator/',
    __FILE__,
    'blackrock-ayut-feed-and-property-validator' // your plugin's folder name
);

$myUpdateChecker->setBranch('master');

function br_render_bayut_audit_page() {
    ob_start();

    // ------------------------------------------------------------------
    // Option A: If reading directly from an XML string or file path/URL
    // Replace $xml_data with file_get_contents('YOUR_XML_URL_OR_PATH') if needed.
    // ------------------------------------------------------------------
    $xml_data = '<?xml version="1.0" encoding="UTF-8"?>
    <Properties>
        <Property>
            <Property_Ref_No><![CDATA[ 20885 ]]></Property_Ref_No>
            <Property_Type><![CDATA[ Apartment ]]></Property_Type>
            <Property_Title><![CDATA[ 1-Bedroom w/ balcony | High Floor | Vibrant Living ]]></Property_Title>
            <City><![CDATA[ Abu Dhabi ]]></City>
            <Locality><![CDATA[ Muheira ]]></Locality>
            <Property_Status><![CDATA[ live ]]></Property_Status>
        </Property>
        <Property>
            <Property_Ref_No><![CDATA[ 20878 ]]></Property_Ref_No>
            <Property_Type><![CDATA[ Family Home ]]></Property_Type>
            <Property_Title><![CDATA[ Hot Deal | 4BR Townhouse | Single Row | Premium Location ]]></Property_Title>
            <City><![CDATA[ Al Reem Island ]]></City>
            <Locality><![CDATA[ Maysan ]]></Locality>
            <Property_Status><![CDATA[ live ]]></Property_Status>
        </Property>
        <Property>
            <Property_Ref_No><![CDATA[ 20875 ]]></Property_Ref_No>
            <Property_Type><![CDATA[ Apartment ]]></Property_Type>
            <Property_Title><![CDATA[ Spacious 1-Bedroom | High Floor | Modern layout | Balcony ]]></Property_Title>
            <City><![CDATA[ Abu Dhabi ]]></City>
            <Locality><![CDATA[ Al Reem ]]></Locality>
            <Property_Status><![CDATA[ live ]]></Property_Status>
        </Property>
        <Property>
            <Property_Ref_No><![CDATA[ 20805 ]]></Property_Ref_No>
            <Property_Type><![CDATA[ Apartment ]]></Property_Type>
            <Property_Title><![CDATA[ Fully Furnished | Powder Room | Available Soon | Modern waterfront living ]]></Property_Title>
            <City><![CDATA[ Yas Island ]]></City>
            <Locality><![CDATA[ Mayan ]]></Locality>
            <Property_Status><![CDATA[ live ]]></Property_Status>
        </Property>
        <Property>
            <Property_Ref_No><![CDATA[ 20806 ]]></Property_Ref_No>
            <Property_Type><![CDATA[ Apartment ]]></Property_Type>
            <Property_Title><![CDATA[ Fully Furnished First-Floor Studio with Open Main Road View ]]></Property_Title>
            <City><![CDATA[ Masdar City ]]></City>
            <Locality><![CDATA[ The Gate ]]></Locality>
            <Property_Status><![CDATA[ live ]]></Property_Status>
        </Property>
        <Property>
            <Property_Ref_No><![CDATA[ 20808 ]]></Property_Ref_No>
            <Property_Type><![CDATA[ Other Commercial ]]></Property_Type>
            <Property_Title><![CDATA[ Near to main road | Electricity and water ]]></Property_Title>
            <City><![CDATA[ Al Ain ]]></City>
            <Locality><![CDATA[ Al Reem Island ]]></Locality>
            <Property_Status><![CDATA[ live ]]></Property_Status>
        </Property>
        <Property>
            <Property_Ref_No><![CDATA[ 20812 ]]></Property_Ref_No>
            <Property_Type><![CDATA[ Apartment ]]></Property_Type>
            <Property_Title><![CDATA[ Luxury 1-Bedoom Apartment for Sale in The Row Saadiyat Island | Prime Location ]]></Property_Title>
            <City><![CDATA[ Saadiyat Island ]]></City>
            <Locality><![CDATA[ The Row Saadiyat ]]></Locality>
            <Property_Status><![CDATA[ live ]]></Property_Status>
        </Property>
        <Property>
            <Property_Ref_No><![CDATA[ 20816 ]]></Property_Ref_No>
            <Property_Type><![CDATA[ Apartment ]]></Property_Type>
            <Property_Title><![CDATA[ Affordable 1-Bedroom Apartment for Sale in District Reportage | Al Reem Island ]]></Property_Title>
            <City><![CDATA[ Abu Dhabi ]]></City>
            <Locality><![CDATA[ Al Reem Island ]]></Locality>
            <Property_Status><![CDATA[ live ]]></Property_Status>
        </Property>
    </Properties>';

    $properties = array();

    // Parse XML
    if (!empty($xml_data)) {
        $xml = simplexml_load_string($xml_data, 'SimpleXMLElement', LIBXML_NOCDATA);
        if ($xml && isset($xml->Property)) {
            foreach ($xml->Property as $prop) {
                $ref_no = trim((string)$prop->Property_Ref_No);

                // Try finding matching WP Post by reference number meta key
                $wp_permalink = '#';
                $wp_posts = get_posts(array(
                    'post_type'  => 'property',
                    'meta_key'   => 'fave_property_id',
                    'meta_value' => $ref_no,
                    'posts_per_page' => 1
                ));

                if (!empty($wp_posts)) {
                    $wp_permalink = get_permalink($wp_posts[0]->ID);
                }

                $properties[] = array(
                    'ref_no'        => $ref_no,
                    'title'         => trim((string)$prop->Property_Title),
                    'type'          => trim((string)$prop->Property_Type),
                    'city'          => trim((string)$prop->City),
                    'locality'      => trim((string)$prop->Locality),
                    'status'        => strtolower(trim((string)$prop->Property_Status)) === 'live' ? 'PASS' : 'FAIL',
                    'permalink'     => $wp_permalink
                );
            }
        }
    }
    ?>
    <style>
        .bayut-audit-table {
            width: 100%;
            border-collapse: collapse;
            font-family: inherit;
            margin: 20px 0;
            background: #fff;
            box-shadow: 0 1px 3px rgba(0,0,0,0.05);
        }
        .bayut-audit-table th {
            background-color: #1e293b;
            color: #ffffff;
            text-align: left;
            padding: 12px 16px;
            font-size: 14px;
            font-weight: 600;
        }
        .bayut-audit-table td {
            padding: 14px 16px;
            border-bottom: 1px solid #e2e8f0;
            font-size: 14px;
            color: #334155;
            vertical-align: middle;
        }
        .bayut-audit-table tr:hover {
            background-color: #f8fafc;
        }
        .bayut-title-link {
            color: #2563eb;
            text-decoration: none;
            font-weight: 500;
        }
        .bayut-title-link:hover {
            text-decoration: underline;
        }
        .status-badge-pass {
            background-color: #dcfce7;
            color: #15803d;
            padding: 4px 10px;
            border-radius: 4px;
            font-weight: 700;
            font-size: 11px;
            display: inline-block;
            text-align: center;
        }
        .status-badge-fail {
            background-color: #fee2e2;
            color: #b91c1c;
            padding: 4px 10px;
            border-radius: 4px;
            font-weight: 700;
            font-size: 11px;
            display: inline-block;
            text-align: center;
        }
    </style>

    <table class="bayut-audit-table">
        <thead>
            <tr>
                <th>Ref No</th>
                <th>Property Title</th>
                <th>Type</th>
                <th>City</th>
                <th>Locality</th>
                <th>Bayut Status</th>
            </tr>
        </thead>
        <tbody>
            <?php if (!empty($properties)) : ?>
                <?php foreach ($properties as $item) : ?>
                    <tr>
                        <td><strong><?php echo esc_html($item['ref_no']); ?></strong></td>
                        <td>
                            <?php if ($item['permalink'] !== '#') : ?>
                                <a href="<?php echo esc_url($item['permalink']); ?>" class="bayut-title-link">
                                    <?php echo esc_html($item['title']); ?>
                                </a>
                            <?php else : ?>
                                <?php echo esc_html($item['title']); ?>
                            <?php endif; ?>
                        </td>
                        <td><?php echo esc_html($item['type']); ?></td>
                        <td><?php echo esc_html($item['city']); ?></td>
                        <td><?php echo esc_html($item['locality']); ?></td>
                        <td>
                            <?php if ($item['status'] === 'PASS') : ?>
                                <span class="status-badge-pass">PASS</span>
                            <?php else : ?>
                                <span class="status-badge-fail">FAIL</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            <?php else : ?>
                <tr>
                    <td colspan="6" style="text-align: center; padding: 20px;">No properties found.</td>
                </tr>
            <?php endif; ?>
        </tbody>
    </table>

    <?php
    return ob_get_clean();
}

// Register shortcode and secondary alias
add_shortcode('bayut_audit_portal', 'br_render_bayut_audit_page');
add_shortcode('bayut_audit_dashboard', 'br_render_bayut_audit_page');