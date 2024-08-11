<?php
/**
 * Plugin Name: College Rankings Plugin
 * Version: 1.7
 * Author: CollegeRankings.io
 * Description: A WordPress plugin that generates college rankings using data from the College Scorecard API and Tavily API, with content summaries generated by OpenAI.
 */

// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

// Define plugin constants
define('CRP_VERSION', '1.7');
define('CRP_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('CRP_PLUGIN_URL', plugin_dir_url(__FILE__));

// Include required files
require_once CRP_PLUGIN_DIR . 'includes/api-functions.php';
require_once CRP_PLUGIN_DIR . 'includes/generate-functions.php';

// Plugin initialization
function crp_init_plugin() {
    add_action('admin_menu', 'crp_add_admin_menu');
    add_action('admin_init', 'crp_register_settings');
    add_action('admin_notices', 'crp_admin_notices');
    add_action('wp_enqueue_scripts', 'crp_enqueue_styles');
    add_action('admin_enqueue_scripts', 'crp_enqueue_admin_styles');
}
add_action('plugins_loaded', 'crp_init_plugin');

// Enqueue styles
function crp_enqueue_styles() {
    wp_enqueue_style('crp-styles', CRP_PLUGIN_URL . 'assets/style.css', array(), CRP_VERSION);
}

// Enqueue admin styles
function crp_enqueue_admin_styles($hook) {
    if (strpos($hook, 'college-rankings') !== false) {
        wp_enqueue_style('crp-admin-styles', CRP_PLUGIN_URL . 'assets/admin-style.css', array(), CRP_VERSION);
    }
}

// Add admin menu
function crp_add_admin_menu() {
    add_menu_page('College Rankings', 'College Rankings', 'manage_options', 'college-rankings', 'crp_settings_page', 'dashicons-chart-area');
    add_submenu_page('college-rankings', 'Bulk Create', 'Bulk Create', 'manage_options', 'crp-bulk-create', 'crp_bulk_create_page');
    add_submenu_page('college-rankings', 'Manage Ranking Types', 'Manage Ranking Types', 'manage_options', 'crp-manage-ranking-types', 'crp_manage_ranking_types_page');
}

// Register settings
function crp_register_settings() {
    register_setting('crp_settings', 'crp_college_scorecard_api_key');
    register_setting('crp_settings', 'crp_tavily_api_key');
    register_setting('crp_settings', 'crp_openai_api_key');
    register_setting('crp_settings', 'crp_summary_length');
    register_setting('crp_settings', 'crp_post_type');
    register_setting('crp_settings', 'crp_ranking_types');
}

// Admin notices
function crp_admin_notices() {
    if (!crp_are_api_keys_set() && (isset($_GET['page']) && $_GET['page'] === 'college-rankings')) {
        echo '<div class="notice notice-warning is-dismissible"><p>Please set your API keys in the College Rankings settings to use all features of the plugin.</p></div>';
    }
}

// Check if API keys are set
function crp_are_api_keys_set() {
    return !empty(get_option('crp_college_scorecard_api_key')) &&
           !empty(get_option('crp_tavily_api_key')) &&
           !empty(get_option('crp_openai_api_key'));
}

// Settings page
function crp_settings_page() {
    ?>
    <div class="wrap crp-admin-page">
        <h1>College Rankings Plugin Settings</h1>
        <form method="post" action="options.php">
            <?php
            settings_fields('crp_settings');
            do_settings_sections('crp_settings');
            ?>
            <table class="form-table">
                <tr valign="top">
                    <th scope="row">College Scorecard API Key</th>
                    <td><input type="text" name="crp_college_scorecard_api_key" value="<?php echo esc_attr(get_option('crp_college_scorecard_api_key')); ?>" class="regular-text" /></td>
                </tr>
                <tr valign="top">
                    <th scope="row">Tavily API Key</th>
                    <td><input type="text" name="crp_tavily_api_key" value="<?php echo esc_attr(get_option('crp_tavily_api_key')); ?>" class="regular-text" /></td>
                </tr>
                <tr valign="top">
                    <th scope="row">OpenAI API Key</th>
                    <td><input type="text" name="crp_openai_api_key" value="<?php echo esc_attr(get_option('crp_openai_api_key')); ?>" class="regular-text" /></td>
                </tr>
                <tr valign="top">
                    <th scope="row">Default Summary Length (words)</th>
                    <td><input type="number" name="crp_summary_length" value="<?php echo esc_attr(get_option('crp_summary_length', 100)); ?>" class="small-text" /></td>
                </tr>
                <tr valign="top">
                    <th scope="row">Default Post Type</th>
                    <td>
                        <select name="crp_post_type">
                            <option value="post" <?php selected(get_option('crp_post_type'), 'post'); ?>>Posts</option>
                            <option value="page" <?php selected(get_option('crp_post_type'), 'page'); ?>>Pages</option>
                            <?php
                            $custom_post_types = get_post_types(array('_builtin' => false), 'objects');
                            foreach ($custom_post_types as $post_type) {
                                echo '<option value="' . esc_attr($post_type->name) . '" ' . selected(get_option('crp_post_type'), $post_type->name, false) . '>' . esc_html($post_type->label) . '</option>';
                            }
                            ?>
                        </select>
                    </td>
                </tr>
            </table>
            <?php submit_button(); ?>
        </form>
    </div>
    <?php
}

// Bulk create page
function crp_bulk_create_page() {
    if (!crp_are_api_keys_set()) {
        echo '<div class="wrap"><div class="notice notice-error"><p>Please set your API keys in the College Rankings settings before creating rankings.</p></div></div>';
        return;
    }
    ?>
    <div class="wrap crp-admin-page">
        <h1>Bulk Create College Rankings</h1>
        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" id="crp-bulk-create-form">
            <input type="hidden" name="action" value="crp_bulk_create">
            <?php wp_nonce_field('crp_bulk_create', 'crp_bulk_create_nonce'); ?>
            
            <div id="crp-ranking-fields">
                <div class="crp-ranking-field">
                    <h3>Ranking #1</h3>
                    <table class="form-table">
                        <tr valign="top">
                            <th scope="row">Ranking Title</th>
                            <td><input type="text" name="ranking_title[]" required class="regular-text" /></td>
                        </tr>
                        <tr valign="top">
                            <th scope="row">Ranking Type</th>
                            <td>
                                <select name="ranking_type[]" class="crp-ranking-type-select">
                                    <?php
                                    $ranking_types = get_option('crp_ranking_types', array());
                                    foreach ($ranking_types as $type) {
                                        echo '<option value="' . esc_attr($type['name']) . '">' . esc_html($type['name']) . '</option>';
                                    }
                                    ?>
                                </select>
                            </td>
                        </tr>
                        <tr valign="top">
                            <th scope="row">Focus Area</th>
                            <td><input type="text" name="focus_area[]" required class="regular-text" /></td>
                        </tr>
                        <tr valign="top">
                            <th scope="row">Number of Colleges</th>
                            <td><input type="number" name="num_colleges[]" min="1" max="50" value="10" required class="small-text" /></td>
                        </tr>
                        <tr valign="top">
                            <th scope="row">Custom Methodology (optional)</th>
                            <td><textarea name="custom_methodology[]" rows="4" cols="50" class="large-text"></textarea></td>
                        </tr>
                        <tr valign="top">
                            <th scope="row">Post Type</th>
                            <td>
                                <select name="post_type[]">
                                    <option value="post">Posts</option>
                                    <option value="page">Pages</option>
                                    <?php
                                    $custom_post_types = get_post_types(array('_builtin' => false), 'objects');
                                    foreach ($custom_post_types as $post_type) {
                                        echo '<option value="' . esc_attr($post_type->name) . '">' . esc_html($post_type->label) . '</option>';
                                    }
                                    ?>
                                </select>
                            </td>
                        </tr>
                    </table>
                    <div class="crp-criteria-weights">
                        <h4>Criteria Weights</h4>
                        <div class="crp-criteria-weight-fields">
                            <!-- Criteria weight fields will be dynamically populated here -->
                        </div>
                    </div>
                </div>
            </div>
            
            <button type="button" id="add-ranking" class="button">Add Another Ranking</button>
            
            <?php submit_button('Create Rankings'); ?>
        </form>
    </div>

    <script>
    jQuery(document).ready(function($) {
        var rankingCount = 1;
        var rankingTypes = <?php echo json_encode($ranking_types); ?>;

        function updateCriteriaWeights(selectElement) {
            var selectedType = $(selectElement).val();
            var weightFields = $(selectElement).closest('.crp-ranking-field').find('.crp-criteria-weight-fields');
            weightFields.empty();

            var selectedTypeObj = rankingTypes.find(type => type.name === selectedType);
            if (selectedTypeObj) {
                selectedTypeObj.criteria.forEach(function(criterion) {
                    var fieldName = 'criteria_weights[' + ($(selectElement).closest('.crp-ranking-field').index()) + '][' + criterion + ']';
                    weightFields.append(
                        '<div class="crp-criteria-weight-field">' +
                        '<label for="' + fieldName + '">' + criterion + ':</label>' +
                        '<input type="number" name="' + fieldName + '" min="0" max="100" value="10" required class="small-text" />' +
                        '</div>'
                    );
                });
            }
        }

        $('.crp-ranking-type-select').each(function() {
            updateCriteriaWeights(this);
        });

        $(document).on('change', '.crp-ranking-type-select', function() {
            updateCriteriaWeights(this);
        });

        $('#add-ranking').click(function() {
            rankingCount++;
            var newField = $('.crp-ranking-field:first').clone();
            newField.find('h3').text('Ranking #' + rankingCount);
            newField.find('input, textarea, select').val('');
            newField.find('input[name="num_colleges[]"]').val('10');
            newField.find('.crp-criteria-weight-fields').empty();
            $('#crp-ranking-fields').append(newField);
            updateCriteriaWeights(newField.find('.crp-ranking-type-select'));
        });
    });
    </script>
    <?php
}

// Manage Ranking Types page
function crp_manage_ranking_types_page() {
    if (isset($_POST['crp_save_ranking_types'])) {
        check_admin_referer('crp_manage_ranking_types');
        $ranking_types = array();
        foreach ($_POST['ranking_type'] as $index => $type) {
            if (!empty($type)) {
                $ranking_types[] = array(
                    'name' => sanitize_text_field($type),
                    'criteria' => array_map('sanitize_text_field', explode(',', $_POST['ranking_criteria'][$index]))
                );
            }
        }
        update_option('crp_ranking_types', $ranking_types);
        echo '<div class="updated"><p>Ranking types and criteria saved.</p></div>';
    }

    $ranking_types = get_option('crp_ranking_types', array(
        array('name' => 'Overall', 'criteria' => array('Admission Rate', 'Retention Rate', 'Graduation Rate', 'Median Earnings')),
        array('name' => 'Affordability', 'criteria' => array('In-State Tuition', 'Out-of-State Tuition', 'Graduation Rate', 'Median Earnings')),
        array('name' => 'Online Programs', 'criteria' => array('Online Programs Offered', 'Graduation Rate', 'Retention Rate', 'Median Earnings'))
    ));
    ?>
    <div class="wrap crp-admin-page">
        <h1>Manage Ranking Types and Criteria</h1>
        <form method="post" action="">
            <?php wp_nonce_field('crp_manage_ranking_types'); ?>
            <table class="wp-list-table widefat fixed striped">
                <thead>
                    <tr>
                        <th>Ranking Type</th>
                        <th>Criteria (comma-separated)</th>
                    </tr>
                </thead>
                <tbody id="ranking-types-tbody">
                    <?php foreach ($ranking_types as $index => $type): ?>
                        <tr>
                            <td>
                                <input type="text" name="ranking_type[]" value="<?php echo esc_attr($type['name']); ?>" required>
                            </td>
                            <td>
                                <input type="text" name="ranking_criteria[]" value="<?php echo esc_attr(implode(', ', $type['criteria'])); ?>" required>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            <button type="button" id="add-ranking-type" class="button">
