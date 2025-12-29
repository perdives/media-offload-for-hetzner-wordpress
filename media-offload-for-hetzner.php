<?php
/**
 * Plugin Name: Offload to Hetzner Storage
 * Plugin URI: https://perdives.com/plugins/media-offload-for-hetzner-wordpress
 * Description: Offload WordPress media to Hetzner S3 compatible storage. Automatically syncs your media library to Hetzner's S3-compatible object storage.
 * Version: 1.0.0
 * Requires at least: 5.0
 * Requires PHP: 8.1
 * Author: Perdives
 * Author URI: https://perdives.com
 * License: GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: media-offload-for-hetzner
 * Domain Path: /languages
 *
 * @package HetznerOffload
 * @copyright 2025 Perdives
 * 
 * This program is free software; you can redistribute it and/or
 * modify it under the terms of the GNU General Public License
 * as published by the Free Software Foundation; either version 2
 * of the License, or (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program; if not, write to the Free Software
 * Foundation, Inc., 51 Franklin Street, Fifth Floor, Boston, MA  02110-1301, USA.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Initialize the Hetzner Offload plugin.
 */
function perdives_mo_init() {
	HetznerOffload\Plugin::get_instance();
}

// Use the Perdives PHP Support Notices package to check compatibility BEFORE loading autoloader.
// This prevents fatal parse errors from Composer dependencies that require PHP 8.1+ syntax.
require_once __DIR__ . '/vendor/perdives/php-support-notices-for-wordpress/standalone-checker.php';

if ( ! perdives_check_php_version( __FILE__, '8.9' ) ) {
	// PHP version not supported - admin notice hooked, stop loading.
	return;
}

// PHP version is supported - safe to load autoloader and dependencies.
require_once __DIR__ . '/vendor/autoload.php';

// Initialize the plugin.
add_action( 'plugins_loaded', 'perdives_mo_init' );
