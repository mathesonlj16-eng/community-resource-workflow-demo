<?php
/**
 * One-time installation for a disposable CI-only WordPress container.
 * Run through: docker compose exec -T -e TEST_ADMIN_PASSWORD=... wordpress php < tests/wp-install.php
 * Do not execute against an existing website.
 */
if (PHP_SAPI !== 'cli' || !getenv('TEST_ADMIN_PASSWORD')) {
    fwrite(STDERR, "CI-only installation requires CLI and TEST_ADMIN_PASSWORD.\n");
    exit(1);
}
$_SERVER['HTTP_HOST'] = '127.0.0.1:8088';
$_SERVER['SERVER_NAME'] = '127.0.0.1';
$_SERVER['REQUEST_URI'] = '/';
require '/var/www/html/wp-load.php';
require_once ABSPATH . 'wp-admin/includes/upgrade.php';
require_once ABSPATH . 'wp-admin/includes/plugin.php';

if (is_blog_installed()) {
    fwrite(STDERR, "Refusing to change an existing WordPress installation.\n");
    exit(1);
}
wp_install(
    'Disposable Community Security Test',
    'security_demo_admin',
    'security-demo@example.invalid',
    false,
    '',
    getenv('TEST_ADMIN_PASSWORD')
);
update_option('home', 'http://127.0.0.1:8088');
update_option('siteurl', 'http://127.0.0.1:8088');
$result = activate_plugin('community-workflow-demo/community-workflow-demo.php');
if (is_wp_error($result)) {
    fwrite(STDERR, "Plugin activation failed: " . $result->get_error_message() . "\n");
    exit(1);
}
if (!post_type_exists('cwd_resource')) {
    fwrite(STDERR, "Plugin activation did not register cwd_resource.\n");
    exit(1);
}
echo "Disposable WordPress installation and plugin activation completed.\n";
