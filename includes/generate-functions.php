<?php
// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Generate ranking content
 */
function crp_generate_ranking_content($colleges, $focus_area, $ranking_type, $custom_methodology = '') {
    $content = "";
    
    // Generate introduction
    $intro_prompt = "Write a 150-word introduction for a ranking of the {$ranking_type} colleges focusing on {$focus_area}.";
    $introduction = crp_generate_openai_content($intro_prompt);
    $content .= "<h2>Introduction</h2>\n\n";
    $content .= "<p>" . ($introduction ? $introduction : "Ranking of {$ranking_type} colleges focusing on {$focus_area}.") . "</p>\n\n";
    
    // Generate methodology section
    $content .= "<h2>Methodology</h2>\n\n";
    if (!empty($custom_methodology)) {
        $content .= "<p>{$custom_methodology}</p>\n\n";
    } else {
        $ranking_types = get_option('crp_ranking_types', array());
        $current_type = array_filter($ranking_types, function($type) use ($ranking_type) {
            return $type['name'] === $ranking_type;
        });
        $current_type = reset($current_type);

        $methodology = "Our rankings of the {$ranking_type} colleges focusing on {$focus_area} are based on a comprehensive analysis that takes into account the following factors: " . implode(', ', $current_type['criteria']) . ". ";
        $methodology .= "Data is sourced from the College Scorecard, supplemented with additional research and expert insights. Each factor is weighted to reflect its importance in this {$ranking_type} ranking, ensuring a balanced and accurate representation of each institution's strengths in {$focus_area}.";
        $content .= "<p>{$methodology}</p>\n\n";
    }
    
    // Generate ranking content
    $content .= "<h2>{$ranking_type} Colleges for {$focus_area}</h2>\n\n";
    
    foreach ($colleges as $rank => $college) {
        $content .= "<h3>" . ($rank + 1) . ". " . esc_html($college['name']) . "</h3>\n";
        
        $content .= "<p><strong>Overall Score:</strong> " . number_format($college['score'], 2) . " out of 100</p>\n";
        
        $summary_prompt = "Write a 100-word summary of {$college['name']} focusing on its strengths as a {$ranking_type} college for {$focus_area}. Include key programs, notable features, and outcomes relevant to this ranking type.";
        $summary = crp_generate_openai_content($summary_prompt);
        $content .= "<p>" . ($summary ? $summary : "Information about {$college['name']} and its programs in {$focus_area}.") . "</p>\n";
        
        // Add key stats
        $content .= "<h4>Key Statistics:</h4>\n";
        $content .= "<ul>\n";
        foreach ($current_type['criteria'] as $criterion) {
            $key = strtolower(str_replace(' ', '_', $criterion));
            $content .= "<li><strong>{$criterion}:</strong> " . (isset($college[$key]) ? crp_format_stat($key, $college[$key]) : "N/A") . "</li>\n";
        }
        $content .= "</ul>\n";
    }
    
    // Generate conclusion
    $conclusion_prompt = "Write a 100-word conclusion summarizing the {$ranking_type} colleges for {$focus_area} and offering advice for prospective students considering these specific aspects.";
    $conclusion = crp_generate_openai_content($conclusion_prompt);
    $content .= "<h2>Conclusion</h2>\n\n";
    $content .= "<p>" . ($conclusion ? $conclusion : "This concludes our ranking of {$ranking_type} colleges for {$focus_area}. We hope this information helps prospective students in their college selection process.") . "</p>\n\n";
    
    return $content;
}

/**
 * Format statistic based on its type
 */
function crp_format_stat($key, $value) {
    switch ($key) {
        case 'admission_rate':
        case 'retention_rate':
        case 'graduation_rate':
            return number_format($value, 1) . '%';
        case 'median_earnings':
        case 'in_state_tuition':
        case 'out_of_state_tuition':
        case 'median_debt':
            return '$' . number_format($value, 0);
        case 'enrollment':
            return number_format($value, 0);
        default:
            return $value;
    }
}

/**
 * Create ranking post
 */
function crp_create_ranking_post($title, $content, $focus_area, $ranking_type, $post_type = '') {
    if (empty($post_type)) {
        $post_type = get_option('crp_post_type', 'post');
    }

    // Check if the user has the capability to create posts
    if (!current_user_can('edit_posts')) {
        error_log('College Rankings Plugin: User does not have permission to create posts');
        return false;
    }

    $post_data = array(
        'post_title'    => wp_strip_all_tags($title),
        'post_content'  => $content,
        'post_status'   => 'draft',
        'post_type'     => $post_type,
    );

    $post_id = wp_insert_post($post_data);

    if (is_wp_error($post_id)) {
        error_log('College Rankings Plugin: Failed to create ranking post - ' . $post_id->get_error_message());
        return false;
    }

    // Add custom fields
    update_post_meta($post_id, 'crp_ranking_focus_area', $focus_area);
    update_post_meta($post_id, 'crp_ranking_type', $ranking_type);
    update_post_meta($post_id, 'crp_last_updated', current_time('mysql'));

    // If it's a regular post, set the category
    if ($post_type === 'post') {
        $category_id = get_cat_ID($focus_area);
        if (!$category_id) {
            $category_id = wp_create_category($focus_area);
        }
        wp_set_post_categories($post_id, array($category_id));
    }

    error_log('College Rankings Plugin: Successfully created post with ID: ' . $post_id);
    return $post_id;
}
