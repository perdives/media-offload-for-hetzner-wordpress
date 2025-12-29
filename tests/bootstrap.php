<?php
/**
 * PHPUnit bootstrap file for Hetzner Offload plugin tests
 *
 * @package HetznerOffload
 */

// Require composer autoloader
require_once dirname(__DIR__) . '/vendor/autoload.php';

// Load Yoast PHPUnit Polyfills
require_once dirname(__DIR__) . '/vendor/yoast/phpunit-polyfills/phpunitpolyfills-autoload.php';

// Define test constants
define('PERDIVES_MO_TESTS', true);
define('PERDIVES_MO_PLUGIN_DIR', dirname(__DIR__));
define('PERDIVES_MO_PLUGIN_FILE', dirname(__DIR__) . '/media-offload-for-hetzner.php');

/**
 * Attempt to load WordPress from phpunit.xml configuration
 * This gives us access to all WordPress functions and wp-config.php constants
 */
function load_wordpress_context() {
    // Check if WP_TEST_INSTALL_PATH is set in phpunit.xml
    $wp_install_path = $_ENV['WP_TEST_INSTALL_PATH'] ?? $_SERVER['WP_TEST_INSTALL_PATH'] ?? getenv('WP_TEST_INSTALL_PATH');

    if (empty($wp_install_path)) {
        return false;
    }

    // Ensure path ends with wp-load.php
    $wp_load_path = rtrim($wp_install_path, '/') . '/wp-load.php';

    if (file_exists($wp_load_path)) {
        define('WP_USE_THEMES', false);

        // Use WP_DEBUG from .env or default to true
        if (!defined('WP_DEBUG')) {
            $wp_debug = $_ENV['WP_DEBUG'] ?? $_SERVER['WP_DEBUG'] ?? getenv('WP_DEBUG');
            define('WP_DEBUG', $wp_debug === 'true' || $wp_debug === '1');
        }

        // Prevent WordPress from loading the default theme
        $_SERVER['REQUEST_URI'] = '/wp-admin/';

        require_once $wp_load_path;

        echo "WordPress loaded from: $wp_load_path\n";
        return true;
    } else {
        echo "Warning: WP_TEST_INSTALL_PATH set but wp-load.php not found at: $wp_load_path\n";
    }

    return false;
}

// Try to load WordPress
if (!load_wordpress_context()) {
    echo "Warning: WordPress not loaded. Using stub functions.\n";
    echo "Tests will use environment variables for credentials.\n\n";

    // Fallback to environment variables (use $_ENV as primary source in PHP 8.4+)
    if (!empty($_ENV['HETZNER_TEST_ENDPOINT'] ?? $_SERVER['HETZNER_TEST_ENDPOINT'] ?? null)) {
        define('PERDIVES_MO_HETZNER_STORAGE_ENDPOINT', $_ENV['HETZNER_TEST_ENDPOINT'] ?? $_SERVER['HETZNER_TEST_ENDPOINT']);
    }
    if (!empty($_ENV['HETZNER_TEST_ACCESS_KEY'] ?? $_SERVER['HETZNER_TEST_ACCESS_KEY'] ?? null)) {
        define('PERDIVES_MO_HETZNER_STORAGE_ACCESS_KEY', $_ENV['HETZNER_TEST_ACCESS_KEY'] ?? $_SERVER['HETZNER_TEST_ACCESS_KEY']);
    }
    if (!empty($_ENV['HETZNER_TEST_SECRET_KEY'] ?? $_SERVER['HETZNER_TEST_SECRET_KEY'] ?? null)) {
        define('PERDIVES_MO_HETZNER_STORAGE_SECRET_KEY', $_ENV['HETZNER_TEST_SECRET_KEY'] ?? $_SERVER['HETZNER_TEST_SECRET_KEY']);
    }
    if (!empty($_ENV['HETZNER_TEST_BUCKET'] ?? $_SERVER['HETZNER_TEST_BUCKET'] ?? null)) {
        define('PERDIVES_MO_HETZNER_STORAGE_BUCKET', $_ENV['HETZNER_TEST_BUCKET'] ?? $_SERVER['HETZNER_TEST_BUCKET']);
    }
    if (!empty($_ENV['HETZNER_TEST_CDN_URL'] ?? $_SERVER['HETZNER_TEST_CDN_URL'] ?? null)) {
        $cdn_url = $_ENV['HETZNER_TEST_CDN_URL'] ?? $_SERVER['HETZNER_TEST_CDN_URL'];
        // Add https:// if not present
        if (!preg_match('#^https?://#', $cdn_url)) {
            $cdn_url = 'https://' . $cdn_url;
        }
        define('PERDIVES_MO_HETZNER_STORAGE_CDN_URL', $cdn_url);
    }

    // WordPress stubs
    if (!function_exists('add_action')) {
        function add_action($hook, $callback, $priority = 10, $args = 1) { return true; }
        function add_filter($hook, $callback, $priority = 10, $args = 1) { return true; }
        function apply_filters($hook, $value) { return $value; }
        function do_action($hook) { return null; }
    }
    if (!defined('ABSPATH')) {
        define('ABSPATH', '/tmp/wordpress/');
    }
}

echo "Hetzner Offload Test Bootstrap Loaded\n";
