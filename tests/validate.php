<?php
// Unit-level pure validation checks; WordPress integration requires a real WP instance.
define('ABSPATH', __DIR__ . '/');
define('HOUR_IN_SECONDS', 3600);
$GLOBALS['cwd_test_transients'] = [];
$GLOBALS['cwd_test_storage_ok'] = true;
function wp_salt($scheme = '') { return 'test-only-wordpress-salt'; }
function get_transient($key) {
    $stored = $GLOBALS['cwd_test_transients'][$key] ?? false;
    // WordPress database-backed transients can return scalar values as strings.
    return is_int($stored) ? (string) $stored : $stored;
}
function set_transient($key, $value, $expiry) {
    if (!$GLOBALS['cwd_test_storage_ok']) { return false; }
    $GLOBALS['cwd_test_transients'][$key] = $value;
    return true;
}
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


check(cwd_submission_rate_key('192.0.2.10', 7200) === cwd_submission_rate_key('192.0.2.10', 7201),
    'stable hashed quota key within hour');
check(cwd_submission_rate_key('192.0.2.10', 7200) !== cwd_submission_rate_key('192.0.2.10', 10800),
    'quota key changes in the next hour');
check(cwd_submission_rate_key('192.0.2.10', 7200) !== cwd_submission_rate_key('192.0.2.11', 7200),
    'different source IP uses separate quota');
check(strpos(cwd_submission_rate_key('192.0.2.10', 7200), '192.0.2.10') === false,
    'quota key does not contain raw IP');
check(cwd_submission_rate_key('invalid', 7200) === cwd_submission_rate_key('', 7200),
    'missing and malformed addresses share limited fallback bucket');

$previousIp = $_SERVER['REMOTE_ADDR'] ?? null;
$_SERVER['REMOTE_ADDR'] = '192.0.2.10';
for ($i = 1; $i <= 5; $i++) {
    check(cwd_reserve_submission_slot(), 'allow valid source slot ' . $i);
}
check(!cwd_reserve_submission_slot(), 'reject the sixth submission in the same source/hour');
$corruptKey = cwd_submission_rate_key('192.0.2.10', time());
$GLOBALS['cwd_test_transients'][$corruptKey] = 'not-a-number';
check(!cwd_reserve_submission_slot(), 'reject corrupted nonnumeric transient counter');
$_SERVER['REMOTE_ADDR'] = '192.0.2.11';
check(cwd_reserve_submission_slot(), 'another source can still submit');
$_SERVER['REMOTE_ADDR'] = '192.0.2.12';
$GLOBALS['cwd_test_storage_ok'] = false;
check(!cwd_reserve_submission_slot(), 'fail closed on transient write failure');
$GLOBALS['cwd_test_storage_ok'] = true;
if ($previousIp === null) { unset($_SERVER['REMOTE_ADDR']); }
else { $_SERVER['REMOTE_ADDR'] = $previousIp; }
