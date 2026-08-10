<?php
/**
 * Plugin Name: Black Rock - Bayut Audit Portal Shortcode
 * Description: Parses live XML feed dynamically and displays Bayut property audit table with dashboard navigation and CSV export.
 * Version: 1.7.0
 * Author: Black Rock Real Estate
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
    'blackrock-ayut-feed-and-property-validator'
);

$myUpdateChecker->setBranch('master');

// 1. CSV Export Handler
add_action('template_redirect', 'handle_bayut_validator_export', 1);
function handle_bayut_validator_export() {
    if (isset($_GET['action']) && $_GET['action'] === 'export_bayut_validator') {
        if (!current_user_can('manage_options') && !current_user_can('houzez_manager')) {
            wp_die('Unauthorized.');
        }

        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename=bayut_validator_report_'.date('Y-m-d').'.csv');
        $output = fopen('php://output', 'w');
        fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF));

        fputcsv($output, array('Ref No', 'Property Title', 'Type', 'City (Emirate)', 'Locality (City)', 'Sub Locality (Area)', 'Bayut Status', 'Validation Notes', 'WP Link'));

        $properties = br_fetch_and_parse_bayut_feed();
        foreach ($properties as $item) {
            fputcsv($output, array(
                $item['ref_no'],
                $item['title'],
                $item['type'],
                $item['city'],
                $item['locality'],
                $item['sub_locality'],
                $item['status'],
                $item['notes'],
                $item['permalink']
            ));
        }

        fclose($output);
        exit;
    }
}

// Helper to parse live XML feed & validate criteria
function br_fetch_and_parse_bayut_feed() {
    $feed_url = site_url('/bayut-feed.xml');
    $response = wp_remote_get($feed_url, array('timeout' => 15, 'sslverify' => false));
    $properties = array();

    if (!is_wp_error($response) && wp_remote_retrieve_response_code($response) === 200) {
        $xml_data = wp_remote_retrieve_body($response);

        if (!empty($xml_data)) {
            $xml = simplexml_load_string($xml_data, 'SimpleXMLElement', LIBXML_NOCDATA);
            if ($xml && isset($xml->Property)) {
                foreach ($xml->Property as $prop) {
                    $ref_no = trim((string)$prop->Property_Ref_No);

                    $wp_permalink = '#';
                    $wp_posts = get_posts(array(
                        'post_type'      => 'property',
                        'post_status'    => array('publish', 'draft', 'pending', 'private', 'future'),
                        'meta_key'       => 'fave_property_id',
                        'meta_value'     => $ref_no,
                        'posts_per_page' => 1
                    ));

                    if (!empty($wp_posts)) {
                        $wp_permalink = get_permalink($wp_posts[0]->ID);
                    }

                    // Extract XML Data
                    $title        = trim((string)$prop->Property_Title);
                    $type         = trim((string)$prop->Property_Type);
                    $city         = trim((string)$prop->City);
                    $locality     = trim((string)$prop->Locality);
                    $sub_locality = trim((string)$prop->Sub_Locality);
                    $prop_status  = strtolower(trim((string)$prop->Property_Status));
                    $permit_no    = trim((string)$prop->Permit_Number);
                    $price        = floatval(trim((string)$prop->Price));
                    $agent_phone  = isset($prop->Listing_Agent->Phone) ? trim((string)$prop->Listing_Agent->Phone) : '';

                    // Comprehensive Multi-Check Validation
                    $notes = array();

                    if ($prop_status !== 'live') {
                        $notes[] = 'Status: ' . strtoupper($prop_status);
                    }
                    if ($price <= 0) {
                        $notes[] = 'Invalid Price';
                    }
                    if (empty($permit_no) || $permit_no === '20260001008241') {
                        $notes[] = 'Default Permit';
                    }
                    if (empty($agent_phone) || $agent_phone === '+971500000000') {
                        $notes[] = 'Default Agent Phone';
                    }

                    $status = empty($notes) ? 'PASS' : 'FAIL';
                    $notes_str = empty($notes) ? 'All Specs Valid' : implode(', ', $notes);

                    $properties[] = array(
                        'ref_no'       => $ref_no,
                        'title'        => $title,
                        'type'         => $type,
                        'city'         => $city,
                        'locality'     => $locality,
                        'sub_locality' => $sub_locality,
                        'status'       => $status,
                        'notes'        => $notes_str,
                        'permalink'    => $wp_permalink
                    );
                }
            }
        }
    }
    return $properties;
}

// 2. Render Page
function br_render_bayut_audit_page() {
    $export_url = add_query_arg(['action' => 'export_bayut_validator'], home_url('/'));
    $properties = br_fetch_and_parse_bayut_feed();

    ob_start();
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
        .audit-notes {
            font-size: 12px;
            color: #64748b;
        }
    </style>

    <div class="user-dashboard-right">
        <div class="dashboard-content-area">
            <div class="dashboard-area">
                <div class="dashboard-header clearfix" style="margin-bottom: 30px;">
                    <div class="float-left">
                        <h2 class="title">Bayut Feed Validator</h2>
                    </div>
                    <div class="float-right">
                        <a href="/user-dashboard-2/" class="btn btn-primary" style="margin-right: 10px;">Back To Dashboard</a>
                        <a href="<?php echo esc_url($export_url); ?>" class="btn btn-success">Export CSV</a>
                    </div>
                </div>

                <div class="table-responsive">
                    <table class="bayut-audit-table">
                        <thead>
                            <tr>
                                <th>Ref No</th>
                                <th>Property Title</th>
                                <th>Type</th>
                                <th>City</th>
                                <th>Locality</th>
                                <th>Sub Locality</th>
                                <th>Status</th>
                                <th>Audit Notes</th>
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
                                        <td><?php echo esc_html($item['sub_locality']); ?></td>
                                        <td>
                                            <?php if ($item['status'] === 'PASS') : ?>
                                                <span class="status-badge-pass">PASS</span>
                                            <?php else : ?>
                                                <span class="status-badge-fail">FAIL</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <span class="audit-notes"><?php echo esc_html($item['notes']); ?></span>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php else : ?>
                                <tr>
                                    <td colspan="8" style="text-align: center; padding: 20px;">No properties found in feed.</td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

    <?php
    return ob_get_clean();
}

// Register shortcode and secondary alias
add_shortcode('bayut_audit_portal', 'br_render_bayut_audit_page');
add_shortcode('bayut_audit_dashboard', 'br_render_bayut_audit_page');