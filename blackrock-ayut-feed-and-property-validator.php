<?php
/**
 * Plugin Name: Bayut Audit & Taxonomy Validator
 * Description: Renders Houzez properties in a custom table layout and audits XML feeds against Bayut taxonomy specifications.
 * Version: 1.2
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
    'blackrock-ayut-feed-and-property-validator' // your plugin's folder name
);

$myUpdateChecker->setBranch('master');


/**
 * Shortcode handler to render property audit portal.
 * Usage: [bayut_audit_portal]
 */
function br_render_bayut_audit_page() {
    ob_start();

    // Query parameters engineered to bypass language/theme filters and fetch all statuses
    $args = array(
        'post_type'           => array('property', 'houzez_property'), // Auto-detects default CPT & Houzez CPT
        'post_status'         => array('publish', 'draft', 'pending', 'private'),
        'posts_per_page'      => -1,
        'ignore_sticky_posts' => true,
        'suppress_filters'    => true, // Essential: bypasses WPML, Polylang, and theme query hooks
        'no_found_rows'       => true,
    );

    $query = new WP_Query($args);

    if (!$query->have_posts()) {
        echo '<div style="padding: 15px; background: #fff3cd; color: #856404; border: 1px solid #ffeeba; border-radius: 4px;">';
        echo '<strong>Notice:</strong> No property listings were found in the database. Please verify your Custom Post Type slug or post status.';
        echo '</div>';
        return ob_get_clean();
    }

    ?>
    <style>
        .bayut-audit-wrapper { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; margin: 20px 0; }
        .bayut-audit-table { width: 100%; border-collapse: collapse; background: #fff; box-shadow: 0 1px 3px rgba(0,0,0,0.1); }
        .bayut-audit-table th { background: #1e293b; color: #fff; padding: 12px; text-align: left; font-size: 14px; }
        .bayut-audit-table td { padding: 10px 12px; border-bottom: 1px solid #e2e8f0; font-size: 13px; color: #334155; }
        .bayut-audit-table tr:hover { background-color: #f8fafc; }
        .status-badge { padding: 4px 8px; border-radius: 4px; font-weight: 600; font-size: 11px; text-transform: uppercase; }
        .status-pass { background: #dcfce7; color: #166534; }
        .status-fail { background: #fee2e2; color: #991b1b; }
        .bayut-fix-note { font-size: 11px; color: #64748b; margin-top: 4px; }
    </style>

    <div class="bayut-audit-wrapper">
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
                <?php
                while ($query->have_posts()) : $query->the_post();
                    $post_id   = get_the_ID();
                    $ref_no    = get_post_meta($post_id, 'fave_property_id', true) ?: $post_id;
                    $prop_type = get_post_meta($post_id, 'fave_property_type', true) ?: 'Apartment';
                    $city      = get_post_meta($post_id, 'fave_property_city', true) ?: 'N/A';
                    $locality  = get_post_meta($post_id, 'fave_property_area', true) ?: 'N/A';

                    // Basic Bayut Taxonomy Rules Check
                    $is_valid = true;
                    $issue_msg = '';

                    if (strcasecmp($city, 'Al Reem Island') === 0) {
                        $is_valid = false;
                        $issue_msg = 'City cannot be "Al Reem Island". Change City to "Abu Dhabi".';
                    } elseif (strcasecmp($city, 'Al Ain') === 0 && strcasecmp($locality, 'Al Reem Island') === 0) {
                        $is_valid = false;
                        $issue_msg = 'Location Mismatch: Reem Island is in Abu Dhabi, not Al Ain.';
                    } elseif (strcasecmp($locality, 'Al Reem') === 0) {
                        $is_valid = false;
                        $issue_msg = 'Locality must be "Al Reem Island".';
                    }
                ?>
                    <tr>
                        <td><strong><?php echo esc_html($ref_no); ?></strong></td>
                        <td>
                            <a href="<?php the_permalink(); ?>" target="_blank" style="color: #2563eb; text-decoration: none; font-weight: 500;">
                                <?php the_title(); ?>
                            </a>
                        </td>
                        <td><?php echo esc_html($prop_type); ?></td>
                        <td><?php echo esc_html($city); ?></td>
                        <td><?php echo esc_html($locality); ?></td>
                        <td>
                            <?php if ($is_valid) : ?>
                                <span class="status-badge status-pass">PASS</span>
                            <?php else : ?>
                                <span class="status-badge status-fail">FAIL</span>
                                <div class="bayut-fix-note"><?php echo esc_html($issue_msg); ?></div>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endwhile; ?>
            </tbody>
        </table>
    </div>
    <?php
    wp_reset_postdata();
    return ob_get_clean();
}
add_shortcode('bayut_audit_portal', 'br_render_bayut_audit_page');
