<?php
/**
 * Plugin Name: Black Rock - Bayut Feed & Property Validator
 * Description: Audits Houzez properties against Bayut integration rules and highlights non-matching or erroneous listings.
 * Version: 1.1.0
 * Author: Black Rock Real Estate
 */

if (!defined('ABSPATH')) {
    exit;
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

// 1. Register Shortcode for Frontend Page
add_shortcode('bayut_audit_dashboard', 'br_render_bayut_audit_page');

// 2. Add Admin Menu Item
add_action('admin_menu', 'br_bayut_validator_menu');
function br_bayut_validator_menu() {
    add_submenu_page(
        'edit.php?post_type=property',
        'Bayut Match Audit',
        'Bayut Match Audit',
        'manage_options',
        'bayut-match-audit',
        'br_render_bayut_audit_page'
    );
}

// 3. Validation Logic
function br_validate_property_for_bayut($post_id) {
    $errors = array();

    $price  = get_post_meta($post_id, 'favouriting_property_price', true);
    $size   = get_post_meta($post_id, 'favouriting_property_size', true);

    $cities     = wp_get_post_terms($post_id, 'property_city', array('fields' => 'names'));
    $localities = wp_get_post_terms($post_id, 'property_area', array('fields' => 'names'));

    $city     = !empty($cities) ? $cities[0] : '';
    $locality = !empty($localities) ? $localities[0] : '';

    if (empty($price) || floatval($price) <= 0) {
        $errors[] = 'Invalid or missing price.';
    }
    if (empty($size)) {
        $errors[] = 'Missing property area/size.';
    }

    if (strtolower(trim($city)) === 'al ain' && stristr($locality, 'reem')) {
        $errors[] = 'Location Conflict: City is "Al Ain" but Locality is "Al Reem Island".';
    }

    if (in_array(strtolower(trim($locality)), array('al reem', 'reem', 'abu dhabi', 'dubai'))) {
        $errors[] = 'Generic Locality: "' . esc_html($locality) . '" needs a specific sub-community or project node.';
    }

    if (empty($city) || empty($locality)) {
        $errors[] = 'Missing taxonomy mapping (City or Area is blank).';
    }

    return $errors;
}

// 4. Render Dashboard Table
function br_render_bayut_audit_page() {
    // Restrict access to logged-in users only
    if (!is_user_logged_in()) {
        return '<p>Please <a href="' . wp_login_url(get_permalink()) . '">log in</a> to view the Bayut Audit Dashboard.</p>';
    }

    ob_start();

    $args = array(
        'post_type'        => 'property',
        'post_status'      => 'publish',
        'posts_per_page'   => -1,
        'suppress_filters' => true, // Prevents theme & multi-language plugins from altering the query
    );

    $query = new WP_Query($args);
    ?>
    <div class="bayut-audit-wrap" style="padding: 20px 0; font-family: sans-serif;">
        <h2>Bayut Integration Audit Dashboard</h2>
        <p>Review active properties for location hierarchy conflicts and missing metadata before feed processing.</p>

        <style>
            .bayut-audit-table { width: 100%; border-collapse: collapse; margin-top: 15px; background: #fff; box-shadow: 0 1px 3px rgba(0,0,0,0.1); }
            .bayut-audit-table th, .bayut-audit-table td { padding: 12px; text-align: left; border-bottom: 1px solid #eee; }
            .bayut-audit-table th { background-color: #1d2327; color: #fff; font-size: 14px; }
            .row-pass { background-color: #f0fdf4; }
            .row-fail { background-color: #fef2f2; }
            .badge { display: inline-block; padding: 4px 8px; border-radius: 4px; font-weight: bold; font-size: 11px; text-transform: uppercase; }
            .badge-pass { background-color: #dcfce7; color: #166534; }
            .badge-fail { background-color: #fee2e2; color: #991b1b; }
            .error-list { margin: 0; padding-left: 18px; color: #991b1b; font-size: 12px; }
            .btn-edit { display: inline-block; padding: 6px 12px; background: #2271b1; color: #fff; border-radius: 4px; text-decoration: none; font-size: 12px; }
            .btn-edit:hover { background: #135e96; color: #fff; }
        </style>

        <table class="bayut-audit-table">
            <thead>
                <tr>
                    <th>Ref ID</th>
                    <th>Property Title</th>
                    <th>City</th>
                    <th>Locality / Area</th>
                    <th>Status</th>
                    <th>Validation Details</th>
                    <th>Action</th>
                </tr>
            </thead>
            <tbody>
                <?php
                if ($query->have_posts()) :
                    while ($query->have_posts()) : $query->the_post();
                        $post_id   = get_the_ID();
                        $title     = get_the_title();
                        $errors    = br_validate_property_for_bayut($post_id);
                        $is_valid  = empty($errors);

                        $cities     = wp_get_post_terms($post_id, 'property_city', array('fields' => 'names'));
                        $localities = wp_get_post_terms($post_id, 'property_area', array('fields' => 'names'));

                        $city_name     = !empty($cities) ? $cities[0] : '—';
                        $locality_name = !empty($localities) ? $localities[0] : '—';
                        $edit_url      = get_edit_post_link($post_id);
                        ?>
                        <tr class="<?php echo $is_valid ? 'row-pass' : 'row-fail'; ?>">
                            <td><strong><?php echo esc_html($post_id); ?></strong></td>
                            <td><strong><a href="<?php echo esc_url(get_permalink($post_id)); ?>" target="_blank"><?php echo esc_html($title); ?></a></strong></td>
                            <td><?php echo esc_html($city_name); ?></td>
                            <td><?php echo esc_html($locality_name); ?></td>
                            <td>
                                <?php if ($is_valid) : ?>
                                    <span class="badge badge-pass">Match 🟢</span>
                                <?php else : ?>
                                    <span class="badge badge-fail">Mismatch 🔴</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if ($is_valid) : ?>
                                    <span style="color: #166534; font-size: 12px;">Passed location & feed rules.</span>
                                <?php else : ?>
                                    <ul class="error-list">
                                        <?php foreach ($errors as $err) : ?>
                                            <li><?php echo esc_html($err); ?></li>
                                        <?php endforeach; ?>
                                    </ul>
                                <?php endif; ?>
                            </td>
                            <td>
                                <a href="<?php echo esc_url($edit_url); ?>" class="btn-edit" target="_blank">Edit Listing</a>
                            </td>
                        </tr>
                    <?php
                    endwhile;
                    wp_reset_postdata();
                else :
                    ?>
                    <tr>
                        <td colspan="7">No properties found.</td>
                    </tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
    <?php
    return ob_get_clean();
}