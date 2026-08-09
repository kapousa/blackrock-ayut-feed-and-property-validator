<?php
/**
 * Plugin Name: Black Rock - Bayut Audit Portal Shortcode
 * Description: Displays Bayut property audit table with status checks and location details.
 * Version: 1.3
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

    // Query 'property' custom post type (Houzez default)
    $args = array(
        'post_type'      => 'property',
        'posts_per_page' => -1,
        'post_status'    => 'publish',
    );

    $query = new WP_Query($args);

    ?>
    <style>
        .bayut-audit-table {
            width: 100%;
            border-collapse: collapse;
            font-family: inherit;
            margin: 20px 0;
            background: #fff;
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
            <?php if ($query->have_posts()) : ?>
                <?php while ($query->have_posts()) : $query->the_post();
                    $post_id = get_the_ID();

                    // 1. Get Reference Number (or Post ID if meta isn't set)
                    $ref_no = get_post_meta($post_id, 'fave_property_id', true);
                    if (empty($ref_no)) {
                        $ref_no = $post_id;
                    }

                    // 2. Get Property Type (Houzez Taxonomy -> Post Meta fallback)
                    $type_terms = wp_get_post_terms($post_id, 'property_type');
                    if (!empty($type_terms) && !is_wp_error($type_terms)) {
                        $property_type = $type_terms[0]->name;
                    } else {
                        $property_type = get_post_meta($post_id, 'fave_property_type', true);
                    }
                    $property_type = !empty($property_type) ? $property_type : 'Apartment';

                    // 3. Get City (Houzez Taxonomy -> Post Meta fallback)
                    $city_terms = wp_get_post_terms($post_id, 'property_city');
                    if (!empty($city_terms) && !is_wp_error($city_terms)) {
                        $city = $city_terms[0]->name;
                    } else {
                        $city = get_post_meta($post_id, 'fave_property_city', true);
                    }
                    $city = !empty($city) ? $city : 'N/A';

                    // 4. Get Locality / Area (Houzez Taxonomy -> Post Meta fallback)
                    $area_terms = wp_get_post_terms($post_id, 'property_area');
                    if (!empty($area_terms) && !is_wp_error($area_terms)) {
                        $locality = $area_terms[0]->name;
                    } else {
                        $locality = get_post_meta($post_id, 'fave_property_area', true);
                    }
                    $locality = !empty($locality) ? $locality : 'N/A';

                    // 5. Determine Audit Status
                    $status_flag = get_post_meta($post_id, '_bayut_audit_status', true);
                    $is_pass = ($status_flag !== 'FAIL');
                ?>
                    <tr>
                        <td><strong><?php echo esc_html($ref_no); ?></strong></td>
                        <td>
                            <a href="<?php the_permalink(); ?>" class="bayut-title-link">
                                <?php the_title(); ?>
                            </a>
                        </td>
                        <td><?php echo esc_html($property_type); ?></td>
                        <td><?php echo esc_html($city); ?></td>
                        <td><?php echo esc_html($locality); ?></td>
                        <td>
                            <?php if ($is_pass) : ?>
                                <span class="status-badge-pass">PASS</span>
                            <?php else : ?>
                                <span class="status-badge-fail">FAIL</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endwhile; wp_reset_postdata(); ?>
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