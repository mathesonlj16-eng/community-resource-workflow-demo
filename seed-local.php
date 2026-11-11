<?php
/** Run through PHP CLI against the local Compose WordPress instance after activation. */
if (PHP_SAPI !== 'cli') { exit(1); }

require '/var/www/html/wp-load.php';
if (!function_exists('cwd_register_types') || !is_blog_installed()) {
    fwrite(STDERR, "Install WordPress and activate Community Workflow Demo first.\n");
    exit(1);
}

function demo_require_success($result) {
    if (is_wp_error($result)) {
        fwrite(STDERR, $result->get_error_message() . PHP_EOL);
        exit(1);
    }
    return $result;
}

foreach ([
    'resources' => ['Resources', '[cwd_directory]'],
    'suggest-a-resource' => ['Suggest a resource', '[cwd_submission]'],
] as $slug => [$title, $shortcode]) {
    if (!get_page_by_path($slug)) {
        demo_require_success(wp_insert_post([
            'post_type' => 'page', 'post_name' => $slug, 'post_title' => $title,
            'post_status' => 'publish', 'post_content' => $shortcode,
        ], true));
    }
}

$fixtures = [
    ['first-shift-guide', 'Volunteer first-shift guide', 'Training',
        'A practice guide for greeting visitors, handing off tasks and reporting a missed shift.'],
    ['accessible-event-checklist', 'Accessible event checklist', 'Accessibility',
        'A planning example covering step-free access, quiet seating and plain-language event instructions.'],
    ['welcome-desk-toolkit', 'Welcome desk toolkit', 'Community support',
        'Sample checklists for preparing an information table and directing visitors to published resources.'],
    ['workshop-facilitator-notes', 'Workshop facilitator notes', 'Training',
        'A fictional workshop outline with an opening exercise, paired practice and a feedback prompt.'],
];

foreach ($fixtures as [$slug, $title, $topic, $description]) {
    if (!term_exists($topic, 'cwd_topic')) {
        demo_require_success(wp_insert_term($topic, 'cwd_topic'));
    }
    $existing = get_page_by_path($slug, OBJECT, 'cwd_resource');
    if (!$existing) {
        $id = demo_require_success(wp_insert_post([
            'post_type' => 'cwd_resource', 'post_name' => $slug, 'post_title' => $title,
            'post_status' => 'publish', 'post_content' => $description,
        ], true));
        demo_require_success(wp_set_object_terms($id, $topic, 'cwd_topic'));
    }
}

foreach (['resources', 'suggest-a-resource'] as $slug) {
    echo get_permalink(get_page_by_path($slug)) . PHP_EOL;
}
