<?php
// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Generate ranking content
 */
function crp_generate_ranking_content($colleges, $category, $ranking_type, $custom_methodology = '') {
    $content = "";
    
    // Generate introduction
    $intro_prompt = "Write a 150-word introduction for a ranking of the {$ranking_type} colleges in {$category}.";
    $introduction = crp_generate_openai_content($intro_prompt);
    $content .= "<h2>Introduction</h2>\n\n";
    $content .= "<p>" . ($introduction ? $introduction : "Ranking of {$ranking_type} colleges in {$category}.") . "</p>\n\n";
    
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

        $methodology = "Our rankings of the {$ranking_type} colleges in {$category} are based on a comprehensive analysis that takes into account the following factors: " . implode(', ', $current_type['criteria']) . ". ";
        $methodology .= "Data is sourced from the College Scorecard, supplemented with additional research and expert insights. Each factor is weighted to reflect its importance in this {$ranking_type} ranking, ensuring a balanced and accurate representation of each institution's strengths.";
        $content .= "<p>{$methodology}</p>\n\n";
    }
    
    // Generate ranking content
    $content .= "<h2>{$ranking_type} Colleges in {$category}</h2>\n\n";
    
    foreach ($colleges as $rank => $college) {
        $content .= "<h3>" . ($rank + 1) . ". " . esc_html($college['name']) . "</h3>\n";
        
        $content .= "<p><strong>Overall Score:</strong> " . number_format($college['score'], 2) . " out of 100</p>\n";
        
        $summary_prompt = "Write a 100-word summary of {$college['name']} focusing on its strengths as a {$ranking_type} college in {$category}. Include key programs, notable features, and outcomes relevant to this ranking type.";
        $summary = crp_generate_openai_content($summary_prompt);
        $content .= "<p>" . ($summary ? $summary : "Information about {$college['name']} and its programs in {$category}.") . "</p>\n";
        
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
    $conclusion_prompt = "Write a 100-word conclusion summarizing the {$ranking_type} colleges in {$category} and offering advice for prospective students considering these specific aspects.";
    $conclusion = crp_generate_openai_content($conclusion_prompt);
    $content .= "<h2>Conclusion</h2>\n\n";
    $content .= "<p>" . ($conclusion ? $conclusion : "This concludes our ranking of {$ranking_type} colleges in {$category}. We hope this information helps prospective students in their college selection process.") . "</p>\n\n";
    
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
 * Create ranking post using custom REST API endpoint
 */
function crp_create_ranking_post($title, $content, $category, $ranking_type, $post_type = '') {
    if (empty($post_type)) {
        $post_type = get_option('crp_post_type', 'post');
    }

    // Check if the user has the capability to create posts
    if (!current_user_can('edit_posts')) {
        error_log('College Rankings Plugin: User does not have permission to create posts');
        return false;
    }

    $api_url = rest_url('college-rankings/v1/create-ranking');

    $body = array(
        'title' => $title,
        'content' => $content,
        'category' => $category,
        'ranking_type' => $ranking_type,
        'post_type' => $post_type,
    );

    $args = array(
        'method' => 'POST',
        'headers' => array(
            'Content-Type' => 'application/json',
            'X-WP-Nonce' => wp_create_nonce('wp_rest'),
        ),
        'body' => wp_json_encode($body),
    );

    error_log('College Rankings Plugin: Attempting to create post with the following data: ' . print_r($body, true));

    $response = wp_remote_post($api_url, $args);

    if (is_wp_error($response)) {
        error_log('College Rankings Plugin: Failed to create ranking post - ' . $response->get_error_message());
        return false;
    }

    $response_code = wp_remote_retrieve_response_code($response);
    $response_body = wp_remote_retrieve_body($response);
    error_log('College Rankings Plugin: API Response Code: ' . $response_code);
    error_log('College Rankings Plugin: API Response Body: ' . $response_body);

    if ($response_code !== 201) {
        error_log('College Rankings Plugin: Failed to create ranking post - Unexpected response code: ' . $response_code);
        return false;
    }

    $data = json_decode($response_body, true);

    if (isset($data['id'])) {
        error_log('College Rankings Plugin: Successfully created post with ID: ' . $data['id']);
        return $data['id'];
    } else {
        error_log('College Rankings Plugin: Failed to create ranking post - Unknown error. Response data: ' . print_r($data, true));
        return false;
    }
}
