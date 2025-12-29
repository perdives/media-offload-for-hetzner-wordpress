<?php
/**
 * Main Plugin Class
 *
 * @package HetznerOffload
 */

namespace HetznerOffload;

use HetznerOffload\Storage\S3Handler;
use HetznerOffload\Hooks\UrlRewriter;
use HetznerOffload\Hooks\UploadHandler;
use HetznerOffload\CLI\Commands;
use HetznerOffload\Admin\SettingsPage;

/**
 * Main plugin class that orchestrates all components.
 */
class Plugin {

	/**
	 * S3 Handler instance
	 *
	 * @var S3Handler
	 */
	private $s3_handler;

	/**
	 * URL Rewriter instance
	 *
	 * @var UrlRewriter
	 */
	private $url_rewriter;

	/**
	 * Upload Handler instance
	 *
	 * @var UploadHandler
	 */
	private $upload_handler;

	/**
	 * WP-CLI Commands instance
	 *
	 * @var Commands
	 */
	private $cli_commands;

	/**
	 * Settings Page instance
	 *
	 * @var SettingsPage
	 */
	private $settings_page;

	/**
	 * Whether the plugin is fully enabled
	 *
	 * @var bool
	 */
	private $plugin_enabled = false;

	/**
	 * Initialization error messages
	 *
	 * @var array
	 */
	private $init_errors = array();

	/**
	 * Plugin instance
	 *
	 * @var Plugin|null
	 */
	private static $instance = null;

	/**
	 * Get plugin instance
	 *
	 * @return Plugin
	 */
	public static function get_instance() {
		if ( self::$instance === null ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Constructor
	 */
	private function __construct() {
		$this->initialize();
	}

	/**
	 * Initialize the plugin
	 */
	private function initialize() {
		// Initialize S3 handler.
		$this->s3_handler = new S3Handler();

		// Collect initialization errors.
		$this->init_errors = $this->s3_handler->get_init_errors();

		// Determine if plugin is fully enabled.
		$this->plugin_enabled = $this->s3_handler->is_initialized() &&
								defined( 'PERDIVES_MO_OFFLOAD_ENABLED' ) &&
								PERDIVES_MO_OFFLOAD_ENABLED === true;

		// Add informational messages about plugin state.
		if ( $this->s3_handler->is_initialized() ) {
			if ( ! defined( 'PERDIVES_MO_OFFLOAD_ENABLED' ) ) {
				$this->init_errors[] = 'Hetzner Offload: Plugin is disabled. Media offload is inactive. Define PERDIVES_MO_OFFLOAD_ENABLED as true in wp-config.php to enable automatic offloading to Hetzner S3 (URL rewriting, local file deletion, automatic uploads).';
			} elseif ( PERDIVES_MO_OFFLOAD_ENABLED !== true ) {
				$this->init_errors[] = 'Hetzner Offload: Plugin is disabled. Media offload is inactive because PERDIVES_MO_OFFLOAD_ENABLED is not set to true. Set to true to enable automatic offloading to Hetzner S3.';
			}
		}

		// Initialize components.
		$this->url_rewriter   = new UrlRewriter( $this->s3_handler );
		$this->upload_handler = new UploadHandler( $this->s3_handler );
		$this->cli_commands   = new Commands( $this->s3_handler, $this->init_errors, $this->plugin_enabled );
		$this->settings_page  = new SettingsPage( $this->s3_handler, $this->init_errors, $this->plugin_enabled );

		// Register hooks.
		$this->register_hooks();
	}

	/**
	 * Register WordPress hooks
	 */
	private function register_hooks() {
		// Register upload handler and URL rewriter hooks (only if fully enabled).
		if ( $this->plugin_enabled ) {
			$this->upload_handler->register_hooks();
			$this->url_rewriter->register_hooks();
		}

		// Register WP-CLI commands.
		$this->cli_commands->register();

		// Register settings page.
		$this->settings_page->register();
	}

	/**
	 * Get S3 Handler instance
	 *
	 * @return S3Handler
	 */
	public function get_s3_handler() {
		return $this->s3_handler;
	}

	/**
	 * Get initialization errors
	 *
	 * @return array
	 */
	public function get_init_errors() {
		return $this->init_errors;
	}

	/**
	 * Check if plugin is fully enabled
	 *
	 * @return bool
	 */
	public function is_enabled() {
		return $this->plugin_enabled;
	}
}
