<?php
// Unit-level pure validation checks; WordPress integration requires a real WP instance.
define('ABSPATH', __DIR__ . '/');
$GLOBALS['hooks'] = [];
function add_action(...$args) {}
function add_shortcode(...$args) {}
function register_activation_hook(...$args) {}
function register_deactivation_hook(...$args) {}
function sanitize_text_field($s) { return trim(strip_tags($s)); }
function sanitize_textarea_field($s) { return trim(strip_tags($s)); }
function esc_url_raw($s, $protocols = []) { return trim($s); }
function wp_parse_url($s) { return parse_url($s); }
require dirname(__DIR__) . '/community-workflow-demo.php';
function check($predicate, $message) { if (!$predicate) { fwrite(STDERR, "FAIL: $message\n"); exit(1); } echo "PASS: $message\n"; }
$good = cwd_validate_submission(['cwd_title'=>'Volunteer workshop guide','cwd_description'=>'A fictional handbook for volunteer training.','cwd_topic'=>'Training','cwd_link'=>'https://example.org/guide']);
check(is_array($good) && $good['title']==='Volunteer workshop guide', 'valid fields retained');
check(cwd_validate_submission(['cwd_title'=>'bad','cwd_description'=>'A long enough description'])==='title', 'reject too-short title');
check(cwd_validate_submission(['cwd_title'=>'A good title','cwd_description'=>'short'])==='description', 'reject too-short description');
check(cwd_validate_submission(['cwd_title'=>'A good title','cwd_description'=>'A long enough description','cwd_link'=>'http://example.org'])==='link', 'reject insecure HTTP link');
check(cwd_validate_submission(['cwd_title'=>'A good title','cwd_description'=>'A long enough description','cwd_link'=>'javascript:alert(1)'])==='link', 'reject scripted link');
check(cwd_validate_submission(['cwd_title'=>'A good title','cwd_description'=>'A long enough description','cwd_topic'=>str_repeat('x',41)])==='topic', 'reject excessive topic length');

$base = ['cwd_title' => 'Workshop notes', 'cwd_description' => 'Fictional notes for a practice workshop.'];
foreach (['title', 'description', 'topic', 'link'] as $field) {
    check(cwd_validate_submission(array_replace($base, ['cwd_' . $field => ['unexpected']])) === $field,
        'reject array-shaped ' . $field);
}
check(cwd_validate_submission($base + ['cwd_link' => 'https://name:pass@example.org']) === 'link',
    'reject credential-bearing HTTPS URL');
check(cwd_validate_submission($base)['link'] === '', 'allow omitted URL and topic');
