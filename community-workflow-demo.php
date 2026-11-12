<?php
/**
 * Plugin Name: Community Workflow Demo
 * Description: Fictional community resource directory with a moderated public submission workflow.
 * Version: 0.2.0
 * Requires at least: 6.2
 * Requires PHP: 8.0
 * License: MIT
 * Text Domain: community-workflow-demo
 *
 * Standalone, original example for a portfolio. No client or real organization data.
 */
if (!defined('ABSPATH')) { exit; }

function cwd_register_types(): void {
    register_post_type('cwd_resource', [
        'label' => 'Resources', 'public' => true, 'show_in_rest' => true,
        'has_archive' => true, 'rewrite' => ['slug' => 'community-resources'],
        'supports' => ['title', 'editor', 'excerpt'],
        'menu_icon' => 'dashicons-index-card',
    ]);
    register_taxonomy('cwd_topic', 'cwd_resource', [
        'label' => 'Topics', 'hierarchical' => true, 'show_in_rest' => true,
        'show_admin_column' => true, 'rewrite' => ['slug' => 'resource-topic'],
    ]);
    register_post_meta('cwd_resource', 'cwd_link', [
        'type' => 'string', 'single' => true, 'show_in_rest' => false,
        'sanitize_callback' => 'esc_url_raw', 'auth_callback' => '__return_false',
    ]);
}
add_action('init', 'cwd_register_types');

function cwd_activate(): void {
    cwd_register_types();
    flush_rewrite_rules();
}
register_activation_hook(__FILE__, 'cwd_activate');
register_deactivation_hook(__FILE__, 'flush_rewrite_rules');

/** Read a single form value. Reject arrays at validation boundaries. */
function cwd_request_text(array $input, string $key): string {
    return isset($input[$key]) && is_string($input[$key]) ? wp_unslash($input[$key]) : '';
}

/** An external link is optional, must use HTTPS, and cannot embed credentials. */
function cwd_https_link(string $raw): string {
    $link = esc_url_raw($raw, ['https']);
    if (strlen($link) > 2048 || !filter_var($link, FILTER_VALIDATE_URL)) { return ''; }
    $parts = wp_parse_url($link);
    if (strtolower($parts['scheme'] ?? '') !== 'https' || isset($parts['user']) || isset($parts['pass'])) {
        return '';
    }
    return $link;
}

function cwd_length(string $s): int { return function_exists('mb_strlen') ? mb_strlen($s) : strlen($s); }

// Best-effort, per-source-IP hourly quota. Use the web server's direct peer address only;
// never trust caller-provided X-Forwarded-For headers. Keys are HMACs, not raw IPs.
function cwd_submission_rate_key(string $ip, int $now): string {
    if (!filter_var($ip, FILTER_VALIDATE_IP)) { $ip = 'unknown'; }
    $hour = intdiv(max(0, $now), HOUR_IN_SECONDS);
    return 'cwd_rl_' . substr(hash_hmac('sha256', $hour . '|' . $ip, wp_salt('nonce')), 0, 40);
}

/**
 * Reserve one of five slots per IP/hour. Transients are not atomic under concurrency
 * and may be evicted early: this reduces casual spam, not a production abuse guarantee.
 * Fail closed when transient storage cannot record the reservation.
 */
function cwd_reserve_submission_slot(): bool {
    $ip = isset($_SERVER['REMOTE_ADDR']) && is_string($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : '';
    $key = cwd_submission_rate_key($ip, time());
    $count = get_transient($key);
    if ($count === false) { $count = 0; }
    if (!is_int($count) || $count < 0 || $count >= 5) { return false; }
    return set_transient($key, $count + 1, HOUR_IN_SECONDS) === true;
}

/** A pure, separately testable validator. Return an error string or normalized fields. */
function cwd_validate_submission(array $input) {
    foreach (['title', 'description', 'topic', 'link'] as $field) {
        $key = 'cwd_' . $field;
        if (isset($input[$key]) && !is_string($input[$key])) { return $field; }
    }
    $title = sanitize_text_field((string) ($input['cwd_title'] ?? ''));
    $description = sanitize_textarea_field((string) ($input['cwd_description'] ?? ''));
    $topic = sanitize_text_field((string) ($input['cwd_topic'] ?? ''));
    $raw_link = trim((string) ($input['cwd_link'] ?? ''));
    $link = cwd_https_link($raw_link);
    if (cwd_length($title) < 5 || cwd_length($title) > 100) { return 'title'; }
    if (cwd_length($description) < 15 || cwd_length($description) > 900) { return 'description'; }
    if ($raw_link !== '' && $link === '') { return 'link'; }
    if (cwd_length($topic) > 40) { return 'topic'; }
    return ['title' => $title, 'description' => $description, 'topic' => $topic, 'link' => $link];
}

function cwd_directory_shortcode(): string {
    $search = sanitize_text_field(cwd_request_text($_GET, 'cwd_search'));
    $topic = sanitize_title(cwd_request_text($_GET, 'cwd_filter'));
    $page_url = get_permalink();
    $suggestion = get_page_by_path('suggest-a-resource');
    $suggestion_url = $suggestion && $suggestion->post_status === 'publish' ? get_permalink($suggestion) : '';
    $args = ['post_type' => 'cwd_resource', 'post_status' => 'publish', 'posts_per_page' => 12, 's' => $search];
    if ($topic) { $args['tax_query'] = [['taxonomy' => 'cwd_topic', 'field' => 'slug', 'terms' => $topic]]; }
    $query = new WP_Query($args);
    ob_start();
    echo '<section class="cwd" aria-label="Community resources">';
    echo '<div class="cwd-intro"><h2>Find community resources</h2>';
    echo '<p>Browse staff-reviewed guides or suggest a resource for the team to review. This independent workflow demo uses fictional content.</p>';
    if ($suggestion_url) { echo '<a href="' . esc_url($suggestion_url) . '">Suggest a resource</a>'; }
    echo '</div><form method="get" action="' . esc_url($page_url) . '" class="cwd-filters">';
    if (!get_option('permalink_structure')) {
        echo '<input type="hidden" name="page_id" value="' . esc_attr((string) get_queried_object_id()) . '">';
    }
    echo '<label>Search resources <input name="cwd_search" value="' . esc_attr($search) . '" placeholder="Try training or accessibility"></label>';
    echo '<label><span>Topic</span><select name="cwd_filter" aria-label="Topic"><option value="">All topics</option>';
    $terms = get_terms(['taxonomy' => 'cwd_topic', 'hide_empty' => true]);
    if (is_wp_error($terms)) { $terms = []; }
    foreach ($terms as $term) {
        echo '<option value="' . esc_attr($term->slug) . '" ' . selected($topic, $term->slug, false) . '>' . esc_html($term->name) . '</option>';
    }
    echo '</select></label><button type="submit">Filter</button>';
    if ($search !== '' || $topic !== '') { echo '<a href="' . esc_url($page_url) . '">Clear filters</a>'; }
    echo '</form>';
    $count = (int) $query->found_posts;
    echo '<p class="cwd-count">' . $count . ($count === 1 ? ' matching resource' : ' matching resources') . '</p>';
    echo '<div class="cwd-grid">';
    if (!$query->have_posts()) { echo '<p>No published resources match. Clear filters or try another search.</p>'; }
    while ($query->have_posts()) {
        $query->the_post();
        $link = cwd_https_link((string) get_post_meta(get_the_ID(), 'cwd_link', true));
        $topics = get_the_terms(get_the_ID(), 'cwd_topic');
        echo '<article class="cwd-card">';
        foreach (is_array($topics) ? $topics : [] as $term) {
            echo '<span class="cwd-tag">' . esc_html($term->name) . '</span> ';
        }
        echo '<h3>' . esc_html(get_the_title()) . '</h3>';
        echo '<p>' . esc_html(wp_strip_all_tags(get_the_content())) . '</p>';
        if ($link !== '') { echo '<a href="' . esc_url($link) . '" rel="noreferrer">Visit external resource</a>'; }
        echo '</article>';
    }
    wp_reset_postdata();
    echo '</div></section>';
    return (string) ob_get_clean();
}
add_shortcode('cwd_directory', 'cwd_directory_shortcode');

function cwd_submission_shortcode(): string {
    ob_start();
    echo '<section class="cwd cwd-submit"><h2>Suggest a resource</h2><p>Try the staff review workflow with a fictional suggestion. Include no names, contact details or sensitive information. Staff can publish the text after review.</p>';
    $directory = get_page_by_path('resources');
    if ($directory && $directory->post_status === 'publish') {
        echo '<p><a href="' . esc_url(get_permalink($directory)) . '">Browse published resources</a></p>';
    }
    $result = sanitize_key(cwd_request_text($_GET, 'cwd_result'));
    if ($result === 'received') { echo '<p class="cwd-feedback" role="status">Thanks. Your suggestion is pending staff review.</p>'; }
    elseif ($result === 'invalid') { echo '<p class="cwd-feedback" role="alert">Use a 5–100 character title, a 15–900 character description, an available topic and an optional HTTPS link without credentials.</p>'; }
    elseif ($result === 'error') { echo '<p class="cwd-feedback" role="alert">The suggestion could not be saved. Try again later.</p>'; }
    elseif ($result === 'limited') { echo '<p class="cwd-feedback" role="alert">Too many suggestions from this connection right now. Try again later.</p>'; }
    echo '<form action="' . esc_url(admin_url('admin-post.php')) . '" method="post">';
    echo '<input type="hidden" name="action" value="cwd_submit">';
    wp_nonce_field('cwd_submit', 'cwd_nonce');
    echo '<div class="cwd-trap" aria-hidden="true"><label>Leave blank <input name="cwd_fax" tabindex="-1" autocomplete="off"></label></div>';
    echo '<label>Resource title <input name="cwd_title" required minlength="5" maxlength="100"></label>';
    echo '<label>Description <textarea name="cwd_description" required minlength="15" maxlength="900"></textarea></label>';
    echo '<label><span>Topic (optional)</span><select name="cwd_topic" aria-label="Topic (optional)"><option value="">Uncategorized</option>';
    $topics = get_terms(['taxonomy' => 'cwd_topic', 'hide_empty' => false]);
    if (!is_wp_error($topics)) {
        foreach ($topics as $term) {
            echo '<option value="' . esc_attr($term->slug) . '">' . esc_html($term->name) . '</option>';
        }
    }
    echo '</select></label>';
    echo '<label>External HTTPS link (optional) <input name="cwd_link" type="url" placeholder="https://example.org"></label>';
    echo '<button type="submit">Send for review</button></form></section>';
    return (string) ob_get_clean();
}
add_shortcode('cwd_submission', 'cwd_submission_shortcode');

function cwd_redirect_result(string $result): void {
    $base = wp_get_referer() ?: home_url('/');
    wp_safe_redirect(add_query_arg('cwd_result', $result, remove_query_arg('cwd_result', $base)), 303);
    exit;
}
function cwd_handle_submission(): void {
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST' ||
        !wp_verify_nonce(sanitize_text_field(cwd_request_text($_POST, 'cwd_nonce')), 'cwd_submit')) {
        wp_die('Could not verify request.', 'Invalid request', ['response' => 403]);
    }
    if (!empty($_POST['cwd_fax'])) { cwd_redirect_result('received'); }
    $data = cwd_validate_submission(wp_unslash($_POST));
    if (!is_array($data)) { cwd_redirect_result('invalid'); }
    $topic = $data['topic'] === '' ? null : get_term_by('slug', $data['topic'], 'cwd_topic');
    if ($topic === false) { cwd_redirect_result('invalid'); }

    // Check only structurally valid suggestions before creating any new pending post.
    if (!cwd_reserve_submission_slot()) { cwd_redirect_result('limited'); }

    // Anonymous submissions always remain pending. Only authorized WP editors may publish.
    $id = wp_insert_post([
        'post_type' => 'cwd_resource', 'post_status' => 'pending',
        'post_title' => wp_slash($data['title']), 'post_content' => wp_slash($data['description']),
    ], true);
    if (is_wp_error($id)) { cwd_redirect_result('error'); }
    $terms = $topic ? wp_set_object_terms($id, [(int) $topic->term_id], 'cwd_topic') : [];
    if (is_wp_error($terms) || ($data['link'] !== '' && !add_post_meta($id, 'cwd_link', $data['link'], true))) {
        wp_delete_post($id, true);
        cwd_redirect_result('error');
    }
    cwd_redirect_result('received');
}
add_action('admin_post_nopriv_cwd_submit', 'cwd_handle_submission');
add_action('admin_post_cwd_submit', 'cwd_handle_submission');

function cwd_styles(): void {
    wp_enqueue_style('community-workflow-demo', plugin_dir_url(__FILE__) . 'assets/style.css', [], '0.2.0');
}
add_action('wp_enqueue_scripts', 'cwd_styles');

// Authorized editors can attach a verified HTTPS source URL to a resource.
function cwd_source_meta_box(): void {
    add_meta_box('cwd_link_box', 'External resource link', 'cwd_link_box_content', 'cwd_resource', 'normal');
}
function cwd_link_box_content($post): void {
    wp_nonce_field('cwd_save_link', 'cwd_link_nonce');
    $link = get_post_meta($post->ID, 'cwd_link', true);
    echo '<label for="cwd_link">Optional HTTPS URL</label> <input type="url" id="cwd_link" name="cwd_link" value="' . esc_attr($link) . '" style="width:100%" placeholder="https://example.org">';
}
function cwd_save_source_meta(int $post_id): void {
    if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) { return; }
    if (!current_user_can('edit_post', $post_id)) { return; }
    if (!wp_verify_nonce(sanitize_text_field(cwd_request_text($_POST, 'cwd_link_nonce')), 'cwd_save_link')) { return; }
    if (!isset($_POST['cwd_link']) || !is_string($_POST['cwd_link'])) { return; }
    $raw = trim(cwd_request_text($_POST, 'cwd_link'));
    $safe = cwd_https_link($raw);
    if ($raw === '') { delete_post_meta($post_id, 'cwd_link'); }
    elseif ($safe !== '') { update_post_meta($post_id, 'cwd_link', $safe); }
}
add_action('add_meta_boxes', 'cwd_source_meta_box');
add_action('save_post_cwd_resource', 'cwd_save_source_meta');
