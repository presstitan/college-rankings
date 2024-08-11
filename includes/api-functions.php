<?php
// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Fetch schools from Tavily API
 */
function crp_fetch_tavily_schools($category, $num_colleges) {
    $api_key = get_option('crp_tavily_api_key');
    if (empty($api_key)) {
        error_log('College Rankings Plugin: Tavily API key is not set');
        return array();
    }

    $endpoint = "https://api.tavily.com/search";
    $query = "top {$num_colleges} colleges for {$category} in the United States";
    $args = array(
        'headers' => array(
            'Content-Type' => 'application/json',
            'X-API-Key' => $api_key
        ),
        'body' => json_encode(array(
            'query' => $query,
            'include_domains' => ['edu'],
            'max_results' => $num_colleges * 2 // Fetch more to account for potential filtering
        ))
    );

    $response = wp_remote_post($endpoint, $args);

    if (is_wp_error($response)) {
        error_log('College Rankings Plugin: Error fetching data from Tavily API - ' . $response->get_error_message());
        return array();
    }

    $body = wp_remote_retrieve_body($response);
    $data = json_decode($body, true);

    if (json_last_error() !== JSON_ERROR_NONE) {
        error_log('College Rankings Plugin: Error decoding JSON from Tavily API - ' . json_last_error_msg());
        return array();
    }

    $schools = array();
    if (isset($data['results'])) {
        foreach ($data['results'] as $result) {
            if (isset($result['title']) && strpos($result['title'], 'University') !== false || strpos($result['title'], 'College') !== false) {
                $schools[] = $result['title'];
            }
        }
    }

    return array_slice($schools, 0, $num_colleges);
}

/**
 * Fetch data from College Scorecard API
 */
function crp_fetch_college_scorecard_data($school_name) {
    $api_key = get_option('crp_college_scorecard_api_key');
    if (empty($api_key)) {
        error_log('College Rankings Plugin: College Scorecard API key is not set');
        return false;
    }

    $endpoint = "https://api.data.gov/ed/collegescorecard/v1/schools.json";
    $query = array(
        'school.name' => $school_name,
        'api_key' => $api_key,
        'fields' => 'school.name,2018.student.size,2018.admissions.admission_rate.overall,2018.cost.tuition.out_of_state,2018.cost.tuition.in_state,2018.student.retention_rate.four_year.full_time,2018.completion.rate_suppressed.overall,2018.aid.median_debt.completers.overall,2018.earnings.6_yrs_after_entry.median'
    );

    $response = wp_remote_get(add_query_arg($query, $endpoint));

    if (is_wp_error($response)) {
        error_log('College Rankings Plugin: Error fetching data from College Scorecard API - ' . $response->get_error_message());
        return false;
    }

    $body = wp_remote_retrieve_body($response);
    $data = json_decode($body, true);

    if (json_last_error() !== JSON_ERROR_NONE) {
        error_log('College Rankings Plugin: Error decoding JSON from College Scorecard API - ' . json_last_error_msg());
        return false;
    }

    if (empty($data['results'])) {
        error_log('College Rankings Plugin: No results found for ' . $school_name);
        return false;
    }

    return $data;
}

/**
 * Generate content using OpenAI
 */
function crp_generate_openai_content($prompt) {
    $api_key = get_option('crp_openai_api_key');
    if (empty($api_key)) {
        error_log('College Rankings Plugin: OpenAI API key is not set');
        return false;
    }

    $endpoint = "https://api.openai.com/v1/engines/text-davinci-002/completions";
    $args = array(
        'headers' => array(
            'Content-Type' => 'application/json',
            'Authorization' => 'Bearer ' . $api_key
        ),
        'body' => json_encode(array(
            'prompt' => $prompt,
            'max_tokens' => intval(get_option('crp_summary_length', 100)) * 1.5,
            'temperature' => 0.7,
            'n' => 1,
            'stop' => null
        ))
    );

    $response = wp_remote_post($endpoint, $args);

    if (is_wp_error($response)) {
        error_log('College Rankings Plugin: Error generating content with OpenAI - ' . $response->get_error_message());
        return false;
    }

    $body = wp_remote_retrieve_body($response);
    $data = json_decode($body, true);

    if (json_last_error() !== JSON_ERROR_NONE) {
        error_log('College Rankings Plugin: Error decoding JSON from OpenAI API - ' . json_last_error_msg());
        return false;
    }

    return isset($data['choices'][0]['text']) ? trim($data['choices'][0]['text']) : false;
}

/**
 * Fetch college data
 */
function crp_fetch_college_data($category, $num_colleges, $ranking_type) {
    $colleges = array();
    $school_names = crp_fetch_tavily_schools($category, $num_colleges);

    if (empty($school_names)) {
        error_log('College Rankings Plugin: No schools found for category: ' . $category);
        return $colleges;
    }

    foreach ($school_names as $school_name) {
        $scorecard_data = crp_fetch_college_scorecard_data($school_name);
        
        if ($scorecard_data && isset($scorecard_data['results'][0])) {
            $college_data = crp_process_college_data($scorecard_data['results'][0], $category, $ranking_type);
        } else {
            // Fallback to OpenAI for data augmentation
            $college_data = crp_augment_college_data_with_openai($school_name, $category, $ranking_type);
        }

        if ($college_data) {
            $colleges[] = $college_data;
        }

        if (count($colleges) >= $num_colleges) {
            break;
        }
    }

    if (empty($colleges)) {
        error_log('College Rankings Plugin: No college data could be fetched or generated');
        return array();
    }

    usort($colleges, function($a, $b) use ($ranking_type) {
        return $b['score'] <=> $a['score'];
    });

    return array_slice($colleges, 0, $num_colleges);
}

/**
 * Process college data from Scorecard
 */
function crp_process_college_data($school_data, $category, $ranking_type) {
    $college = array(
        'name' => $school_data['school.name'],
        'enrollment' => $school_data['2018.student.size'],
        'admission_rate' => $school_data['2018.admissions.admission_rate.overall'] * 100,
        'out_of_state_tuition' => $school_data['2018.cost.tuition.out_of_state'],
        'in_state_tuition' => $school_data['2018.cost.tuition.in_state'],
        'retention_rate' => $school_data['2018.student.retention_rate.four_year.full_time'] * 100,
        'graduation_rate' => $school_data['2018.completion.rate_suppressed.overall'] * 100,
        'median_debt' => $school_data['2018.aid.median_debt.completers.overall'],
        'median_earnings' => $school_data['2018.earnings.6_yrs_after_entry.median'],
    );

    $college['score'] = crp_calculate_composite_score($college, $category, $ranking_type);

    return $college;
}

/**
 * Augment college data with OpenAI when Scorecard data is unavailable
 */
function crp_augment_college_data_with_openai($school_name, $category, $ranking_type) {
    $prompt = "Provide the following information for {$school_name} in the context of {$category} education:
    1. Estimated enrollment
    2. Estimated admission rate (as a percentage)
    3. Estimated in-state tuition
    4. Estimated out-of-state tuition
    5. Estimated retention rate (as a percentage)
    6. Estimated graduation rate (as a percentage)
    7. Estimated median earnings 6 years after entry
    8. Estimated median debt for completers
    
    Provide only the numeric values for each point, separated by commas.";

    $response = crp_generate_openai_content($prompt);
    if (!$response) {
        return false;
    }

    $data = explode(',', $response);
    if (count($data) !== 8) {
        error_log('College Rankings Plugin: Unexpected OpenAI response format for college data augmentation');
        return false;
    }

    $college = array(
        'name' => $school_name,
        'enrollment' => intval(trim($data[0])) ?: null,
        'admission_rate' => floatval(trim($data[1])) ?: null,
        'in_state_tuition' => floatval(trim($data[2])) ?: null,
        'out_of_state_tuition' => floatval(trim($data[3])) ?: null,
        'retention_rate' => floatval(trim($data[4])) ?: null,
        'graduation_rate' => floatval(trim($data[5])) ?: null,
        'median_earnings' => floatval(trim($data[6])) ?: null,
        'median_debt' => floatval(trim($data[7])) ?: null,
    );

    $college['score'] = crp_calculate_composite_score($college, $category, $ranking_type);

    return $college;
}

/**
 * Calculate composite score for a college
 */
function crp_calculate_composite_score($college, $category, $ranking_type) {
    $ranking_types = get_option('crp_ranking_types', array());
    $current_type = array_filter($ranking_types, function($type) use ($ranking_type) {
        return $type['name'] === $ranking_type;
    });
    $current_type = reset($current_type);
    
    if (!$current_type) {
        error_log("College Rankings Plugin: Ranking type '$ranking_type' not found");
        return 0; // or some default score
    }

    $score = 0;
    $weight_per_criterion = 100 / count($current_type['criteria']);

    foreach ($current_type['criteria'] as $criterion) {
        $key = strtolower(str_replace(' ', '_', $criterion));
        if (!isset($college[$key]) || $college[$key] === null) {
            // Skip this criterion if the data is missing
            continue;
        }
        switch ($key) {
            case 'admission_rate':
                $score += (1 - $college[$key] / 100) * $weight_per_criterion;
                break;
            case 'retention_rate':
            case 'graduation_rate':
                $score += ($college[$key] / 100) * $weight_per_criterion;
                break;
            case 'median_earnings':
                $score += (min($college[$key], 100000) / 100000) * $weight_per_criterion;
                break;
            case 'in_state_tuition':
            case 'out_of_state_tuition':
                $score += (1 - min($college[$key], 50000) / 50000) * $weight_per_criterion;
                break;
            case 'enrollment':
                // Assuming larger enrollment is better, up to 50,000 students
                $score += (min($college[$key], 50000) / 50000) * $weight_per_criterion;
                break;
            case 'median_debt':
                // Lower debt is better, assuming max debt of $100,000
                $score += (1 - min($college[$key], 100000) / 100000) * $weight_per_criterion;
                break;
            default:
                // For any unrecognized criteria, we'll just add a neutral score
                $score += $weight_per_criterion / 2;
        }
    }

    return $score;
}
