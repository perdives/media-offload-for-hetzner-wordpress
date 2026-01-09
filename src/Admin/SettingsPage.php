<?php
/**
 * Settings Page
 *
 * @package HetznerOffload
 */

namespace HetznerOffload\Admin;

use HetznerOffload\Storage\S3Handler;
use HetznerOffload\Services\VerificationService;

/**
 * Settings page for Hetzner Offload plugin.
 */
class SettingsPage {

	/**
	 * S3 Handler instance
	 *
	 * @var S3Handler
	 */
	private $s3_handler;

	/**
	 * Verification Service instance
	 *
	 * @var VerificationService
	 */
	private $verification_service;

	/**
	 * Initialization errors
	 *
	 * @var array
	 */
	private $init_errors;

	/**
	 * Whether plugin is fully enabled
	 *
	 * @var bool
	 */
	private $plugin_enabled;

	/**
	 * Constructor
	 *
	 * @param S3Handler $s3_handler    S3 handler instance.
	 * @param array     $init_errors   Initialization errors.
	 * @param bool      $plugin_enabled Whether plugin is fully enabled.
	 */
	public function __construct( S3Handler $s3_handler, array $init_errors, $plugin_enabled ) {
		$this->s3_handler           = $s3_handler;
		$this->init_errors          = $init_errors;
		$this->plugin_enabled       = $plugin_enabled;
		$this->verification_service = new VerificationService( $s3_handler, $init_errors, $plugin_enabled );
	}

	/**
	 * Register settings page
	 */
	public function register() {
		add_action( 'admin_menu', array( $this, 'add_settings_page' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_styles' ) );

		// Add to network admin menu if multisite.
		if ( is_multisite() ) {
			add_action( 'network_admin_menu', array( $this, 'add_network_settings_page' ) );
		}

		// Add settings link to plugin actions.
		$plugin_basename = plugin_basename( dirname( __DIR__, 2 ) . '/media-offload-for-hetzner.php' );
		add_filter( 'plugin_action_links_' . $plugin_basename, array( $this, 'add_plugin_action_links' ) );
		if ( is_multisite() ) {
			add_filter( 'network_admin_plugin_action_links_' . $plugin_basename, array( $this, 'add_plugin_action_links' ) );
		}
	}

	/**
	 * Enqueue settings page styles
	 *
	 * @param string $hook Current admin page hook.
	 */
	public function enqueue_styles( $hook ) {
		// Only load on our settings page (regular admin or network admin).
		if ( $hook !== 'settings_page_hetzner-offload' && $hook !== 'settings_page_hetzner-offload-network' ) {
			return;
		}

		wp_enqueue_style(
			'hetzner-offload-settings',
			plugins_url( 'assets/dist/css/settings.css', dirname( __DIR__ ) ),
			array(),
			'1.0.0'
		);
	}

	/**
	 * Add settings link to plugin action links
	 *
	 * @param array $links Existing plugin action links.
	 * @return array Modified plugin action links.
	 */
	public function add_plugin_action_links( $links ) {
		// Use network admin URL if in network admin context, otherwise regular admin.
		$settings_url = is_network_admin()
			? network_admin_url( 'settings.php?page=hetzner-offload' )
			: admin_url( 'options-general.php?page=hetzner-offload' );

		$settings_link = sprintf(
			'<a href="%s">%s</a>',
			$settings_url,
			__( 'Settings', 'media-offload-for-hetzner-wordpress' )
		);

		array_unshift( $links, $settings_link );

		return $links;
	}

	/**
	 * Add settings page to WordPress admin menu
	 */
	public function add_settings_page() {
		add_options_page(
			'Hetzner Offload',
			'Hetzner Offload',
			'manage_options',
			'hetzner-offload',
			array( $this, 'render_settings_page' )
		);
	}

	/**
	 * Add settings page to network admin menu
	 */
	public function add_network_settings_page() {
		add_submenu_page(
			'settings.php',
			'Hetzner Offload',
			'Hetzner Offload',
			'manage_network_options',
			'hetzner-offload',
			array( $this, 'render_settings_page' )
		);
	}

	/**
	 * Render settings page
	 */
	public function render_settings_page() {
		// Check if we should show the storage list.
		$show_storage_list = isset( $_GET['view'] ) && $_GET['view'] === 'storage-list'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		?>
		<div class="wrap">
			<h1 class="perdives-mo-title">Hetzner Offload - Status & Verification</h1>

			<?php if ( $show_storage_list ) : ?>
				<?php $this->render_storage_list(); ?>
			<?php else : ?>
				<?php $this->render_system_status(); ?>
				<?php $this->render_cli_commands(); ?>
				<?php $this->render_connection_info(); ?>
				<?php $this->render_verification_results(); ?>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Render system status section
	 */
	private function render_system_status() {
		?>
		<div class="perdives-mo-section">
			<h2>System Status</h2>
			<div class="perdives-mo-section__inner">
				<table class="perdives-mo-table system-status" role="presentation">
					<tbody>
						<tr>
							<th scope="row">S3 Client</th>
							<td>
								<?php if ( $this->s3_handler->is_initialized() ) : ?>
									<span class="dashicons dashicons-yes-alt"></span> Initialized
								<?php else : ?>
									<span class="dashicons dashicons-dismiss"></span> Not initialized
								<?php endif; ?>
							</td>
						</tr>
						<tr>
							<th scope="row">Plugin Mode</th>
							<td>
								<?php if ( $this->plugin_enabled ) : ?>
									<span class="dashicons dashicons-yes-alt"></span> <strong>Enabled</strong>
									<p class="description">Automatic S3 uploads, URL rewriting, and local file deletion are active</p>
								<?php elseif ( $this->s3_handler->is_initialized() ) : ?>
									<span class="dashicons dashicons-warning"></span> <strong>Disabled</strong>
									<p class="description">S3 is configured but PERDIVES_MO_OFFLOAD_ENABLED is not set to true. Use WP-CLI commands to manually sync files.</p>
								<?php else : ?>
									<span class="dashicons dashicons-dismiss"></span> <strong>Disabled</strong>
									<p class="description">S3 client not initialized. Check your configuration.</p>
								<?php endif; ?>
							</td>
						</tr>
					</tbody>
				</table>

				<?php if ( ! empty( $this->init_errors ) ) : ?>
					<h3>Messages</h3>
					<?php foreach ( $this->init_errors as $msg ) : ?>
						<?php
						$is_critical = strpos( strtolower( $msg ), 'hetzner_storage_' ) !== false ||
									strpos( strtolower( $msg ), 'failed to initialize s3 client' ) !== false;
						$notice_type = $is_critical ? 'error' : 'warning';
						?>
						<div class="notice notice-<?php echo esc_attr( $notice_type ); ?> inline">
							<p><?php echo esc_html( $msg ); ?></p>
						</div>
					<?php endforeach; ?>
				<?php endif; ?>
			</div>
		</div>
		<?php
	}

	/**
	 * Render connection info section
	 */
	private function render_connection_info() {
		$upload_dir = wp_upload_dir();
		?>
		<div class="perdives-mo-section">
			<h2>Connection Information</h2>
			<div class="perdives-mo-section__inner">
				<h3>WordPress Configuration</h3>
				<table class="perdives-mo-table" role="presentation">
				<tbody>
					<?php if ( is_multisite() ) : ?>
						<tr>
							<th scope="row">Network</th>
							<td>
								<?php echo esc_html( get_network()->domain . get_network()->path ); ?>
								<?php if ( is_network_admin() ) : ?>
									<p class="description">Viewing network-wide settings</p>
								<?php endif; ?>
							</td>
						</tr>
						<tr>
							<th scope="row">Site ID</th>
							<td><code><?php echo esc_html( get_current_blog_id() ); ?></code></td>
						</tr>
					<?php endif; ?>
					<tr>
						<th scope="row">Site Name</th>
						<td><?php echo esc_html( get_bloginfo( 'name' ) ); ?></td>
					</tr>
					<tr>
						<th scope="row">Site URL</th>
						<td><code><?php echo esc_html( get_site_url() ); ?></code></td>
					</tr>
					<tr>
						<th scope="row">Uploads Path</th>
						<td>
							<code><?php echo esc_html( $upload_dir['basedir'] ); ?></code>
							<?php if ( is_multisite() && get_current_blog_id() > 1 ) : ?>
								<p class="description">Multisite subsite - includes sites/<?php echo esc_html( get_current_blog_id() ); ?>/ prefix</p>
							<?php endif; ?>
						</td>
					</tr>
					<tr>
						<th scope="row">Uploads URL</th>
						<td><code><?php echo esc_html( $upload_dir['baseurl'] ); ?></code></td>
					</tr>
				</tbody>
			</table>

			<h3>S3 Configuration</h3>
			<table class="perdives-mo-table" role="presentation">
				<tbody>
					<tr>
						<th scope="row">Bucket</th>
						<td>
							<?php if ( defined( 'PERDIVES_MO_HETZNER_STORAGE_BUCKET' ) && PERDIVES_MO_HETZNER_STORAGE_BUCKET ) : ?>
								<span class="dashicons dashicons-yes-alt"></span> <code><?php echo esc_html( PERDIVES_MO_HETZNER_STORAGE_BUCKET ); ?></code>
							<?php else : ?>
								<span class="dashicons dashicons-dismiss"></span> Not configured
							<?php endif; ?>
						</td>
					</tr>
					<tr>
						<th scope="row">Endpoint</th>
						<td>
							<?php if ( defined( 'PERDIVES_MO_HETZNER_STORAGE_ENDPOINT' ) && PERDIVES_MO_HETZNER_STORAGE_ENDPOINT ) : ?>
								<code><?php echo esc_html( PERDIVES_MO_HETZNER_STORAGE_ENDPOINT ); ?></code>
							<?php else : ?>
								<span class="dashicons dashicons-dismiss"></span> Not configured
							<?php endif; ?>
						</td>
					</tr>
					<tr>
						<th scope="row">CDN URL</th>
						<td>
							<?php if ( defined( 'PERDIVES_MO_HETZNER_STORAGE_CDN_URL' ) && PERDIVES_MO_HETZNER_STORAGE_CDN_URL ) : ?>
								<code><?php echo esc_html( PERDIVES_MO_HETZNER_STORAGE_CDN_URL ); ?></code>
							<?php else : ?>
								<span class="dashicons dashicons-minus"></span> Not configured
								<p class="description">Using S3 endpoint for URLs</p>
							<?php endif; ?>
						</td>
					</tr>
					<tr>
						<th scope="row">Access Key</th>
						<td>
							<?php if ( defined( 'PERDIVES_MO_HETZNER_STORAGE_ACCESS_KEY' ) && PERDIVES_MO_HETZNER_STORAGE_ACCESS_KEY ) : ?>
								<span class="dashicons dashicons-yes-alt"></span> Configured
							<?php else : ?>
								<span class="dashicons dashicons-dismiss"></span> Not configured
							<?php endif; ?>
						</td>
					</tr>
					<tr>
						<th scope="row">Secret Key</th>
						<td>
							<?php if ( defined( 'PERDIVES_MO_HETZNER_STORAGE_SECRET_KEY' ) && PERDIVES_MO_HETZNER_STORAGE_SECRET_KEY ) : ?>
								<span class="dashicons dashicons-yes-alt"></span> Configured
							<?php else : ?>
								<span class="dashicons dashicons-dismiss"></span> Not configured
							<?php endif; ?>
						</td>
					</tr>
				</tbody>
			</table>

			<?php if ( $this->s3_handler->is_initialized() ) : ?>
				<h3>Connection Test</h3>
				<?php
				$test_result = $this->verification_service->test_s3_connection();
				?>
				<?php if ( $test_result['success'] ) : ?>
					<div class="notice notice-success inline">
						<p>
							<span class="dashicons dashicons-yes-alt"></span>
							<strong>Connection successful</strong>
							<?php if ( isset( $test_result['object_count'] ) ) : ?>
								— <?php echo esc_html( $test_result['object_count'] ); ?> files found in uploads/ prefix
							<?php endif; ?>
						</p>
					</div>
				<?php else : ?>
					<div class="notice notice-error inline">
						<p>
							<span class="dashicons dashicons-dismiss"></span>
							<strong>Connection failed</strong>
						</p>
						<?php if ( isset( $test_result['error_detail'] ) ) : ?>
							<p><?php echo esc_html( $test_result['error_detail'] ); ?></p>
						<?php elseif ( isset( $test_result['error'] ) ) : ?>
							<p><?php echo esc_html( $test_result['error'] ); ?></p>
						<?php endif; ?>
					</div>
				<?php endif; ?>
			<?php endif; ?>
			</div>
		</div>
		<?php
	}

	/**
	 * Render verification results section
	 */
	private function render_verification_results() {
		if ( ! $this->s3_handler->is_initialized() ) {
			?>
			<div class="perdives-mo-section">
				<h2>Verification Results</h2>
				<div class="perdives-mo-section__inner">
					<div class="notice notice-info inline">
						<p>S3 client not initialized. Configure your S3 settings to run verification.</p>
					</div>
				</div>
			</div>
			<?php
			return;
		}

		?>
		<div class="perdives-mo-section">
			<h2>Verification Results</h2>
			<div class="perdives-mo-section__inner">
			<?php
			// Get S3 keys.
			$existing_s3_keys = $this->verification_service->get_all_s3_keys_lookup();

			if ( $existing_s3_keys === null ) {
				?>
				<div class="notice notice-error inline">
					<p>Failed to retrieve S3 object list.</p>
				</div>
				<?php
				return;
			}

			// Initialize counters.
			$counters        = $this->verification_service->initialize_verify_counters();
			$s3_keys_from_wp = array();

			// Run verification (without any actions, dry-run mode).
			$this->verification_service->verify_wordpress_media( $existing_s3_keys, $counters, $s3_keys_from_wp, true, false, false );
			$orphans = $this->verification_service->verify_s3_orphans( $existing_s3_keys, $s3_keys_from_wp, $counters, true, false );
			?>

			<h3>Summary</h3>
			<table class="perdives-mo-table" role="presentation">
				<tbody>
					<tr>
						<th scope="row">WordPress Attachments</th>
						<td><strong><?php echo esc_html( number_format_i18n( $counters['wp_attachments_scanned'] ) ); ?></strong></td>
					</tr>
					<tr>
						<th scope="row">Total Files</th>
						<td>
							<?php echo esc_html( number_format_i18n( $counters['wp_files_scanned'] ) ); ?>
							<p class="description">Including thumbnails and image sizes</p>
						</td>
					</tr>
					<tr>
						<th scope="row">Files on Local Disk</th>
						<td><?php echo esc_html( number_format_i18n( $counters['local_files_exist'] ) ); ?></td>
					</tr>
					<tr>
						<th scope="row">Files Offloaded to S3</th>
						<td><?php echo esc_html( number_format_i18n( $counters['s3_exists_local_missing'] ) ); ?></td>
					</tr>
					<tr>
						<th scope="row">S3 Objects</th>
						<td><?php echo esc_html( number_format_i18n( $counters['s3_objects_scanned'] ) ); ?></td>
					</tr>
				</tbody>
			</table>

			<?php
			// Check if there are any issues.
			$has_issues = $counters['s3_missing'] > 0 || $counters['local_missing_s3_missing'] > 0 || $counters['s3_orphans_found'] > 0;
			?>

			<?php if ( ! $has_issues ) : ?>
				<div class="notice notice-success inline">
					<p><span class="dashicons dashicons-yes-alt"></span> <strong>All files verified successfully!</strong> No issues found.</p>
				</div>
			<?php endif; ?>

			<?php if ( $counters['s3_missing'] > 0 ) : ?>
				<h3><span class="dashicons dashicons-warning"></span> Files Missing on S3</h3>
				<div class="notice notice-warning inline">
					<p>
						<strong><?php echo esc_html( number_format_i18n( $counters['s3_missing'] ) ); ?> file(s)</strong> exist locally but are missing from S3.
					</p>
					<p>These files should be uploaded to S3 to ensure they're properly offloaded.</p>
					<p><strong>Fix:</strong> Run <code>wp hetzner-offload verify --reupload-missing</code></p>
				</div>
			<?php endif; ?>

			<?php if ( $counters['local_missing_s3_missing'] > 0 ) : ?>
				<h3><span class="dashicons dashicons-dismiss"></span> Files Missing Everywhere</h3>
				<div class="notice notice-error inline">
					<p>
						<strong><?php echo esc_html( number_format_i18n( $counters['local_missing_s3_missing'] ) ); ?> file(s)</strong> are referenced in WordPress but don't exist locally or on S3.
					</p>
					<p>These are broken media library entries. The files have been lost and cannot be recovered.</p>
					<p><strong>Action needed:</strong> You may want to remove these media library entries manually.</p>
				</div>
			<?php endif; ?>

			<?php if ( $counters['s3_orphans_found'] > 0 ) : ?>
				<h3><span class="dashicons dashicons-warning"></span> Orphan Files on S3</h3>
				<div class="notice notice-warning inline">
					<p>
						<strong><?php echo esc_html( number_format_i18n( $counters['s3_orphans_found'] ) ); ?> file(s)</strong> exist on S3 but don't correspond to any WordPress media library entry.
					</p>
					<p>These could be leftover files from deleted media or files uploaded outside of WordPress.</p>
					<p><strong>Fix (caution!):</strong> Run <code>wp hetzner-offload verify --delete-s3-orphans</code> to remove them.</p>
				</div>

				<?php if ( ! empty( $orphans ) && count( $orphans ) <= 100 ) : ?>
					<details>
						<summary><strong>View list of orphan files (<?php echo count( $orphans ); ?>)</strong></summary>
						<ul>
							<?php foreach ( array_slice( $orphans, 0, 100 ) as $orphan ) : ?>
								<li><em><?php echo esc_html( $orphan ); ?></em></li>
							<?php endforeach; ?>
							<?php if ( count( $orphans ) > 100 ) : ?>
								<li><em>...and <?php echo count( $orphans ) - 100; ?> more</em></li>
							<?php endif; ?>
						</ul>
					</details>
				<?php elseif ( count( $orphans ) > 100 ) : ?>
					<p class="description">Too many orphan files to display (<?php echo count( $orphans ); ?> total). Use WP-CLI to view the full list.</p>
				<?php endif; ?>
			<?php endif; ?>

			<h3>Hetzner Storage List</h3>
			<p>
				<a href="<?php echo esc_url( add_query_arg( 'view', 'storage-list' ) ); ?>" class="button">
					View All Storage Objects
				</a>
			</p>
			<p class="description">View all files stored in your Hetzner S3 bucket</p>
			</div>
		</div>
		<?php
	}

	/**
	 * Render storage list section
	 */
	private function render_storage_list() {
		// Get all S3 objects if initialized.
		$all_objects = null;
		if ( $this->s3_handler->is_initialized() ) {
			$all_objects = $this->s3_handler->list_objects( 'uploads/' );
		}

		$total_objects = is_array( $all_objects ) ? count( $all_objects ) : 0;
		?>
		<div class="perdives-mo-section">

			<h2>Hetzner Storage Objects</h2>
			<div class="perdives-mo-section__inner">
				<?php if ( ! $this->s3_handler->is_initialized() ) : ?>
					<div class="notice notice-error inline">
						<p>S3 client not initialized. Cannot retrieve storage objects.</p>
					</div>
				<?php elseif ( $all_objects === null ) : ?>
					<div class="notice notice-error inline">
						<p>Failed to retrieve objects from S3.</p>
					</div>
				<?php else : ?>
					<p>
						<strong><?php echo esc_html( number_format_i18n( $total_objects ) ); ?></strong> total objects
					</p>

					<?php if ( ! empty( $all_objects ) ) : ?>
						<table class="wp-list-table widefat fixed striped">
							<thead>
								<tr>
									<th style="width: 60%;">S3 Key</th>
									<th>Size</th>
									<th>Last Modified</th>
									<th>URL</th>
								</tr>
							</thead>
							<tbody>
								<?php foreach ( $all_objects as $object ) : ?>
									<?php
									$key                = $object['Key'];
									$size               = isset( $object['Size'] ) ? $object['Size'] : 0;
									$last_modified      = isset( $object['LastModified'] ) ? $object['LastModified'] : null;
									$key_without_prefix = preg_replace( '#^uploads/#', '', $key );
									$url                = $this->s3_handler->get_url( $key_without_prefix );
									?>
									<tr>
										<td><code style="font-size: 11px;"><?php echo esc_html( $key ); ?></code></td>
										<td><?php echo esc_html( size_format( $size, 2 ) ); ?></td>
										<td>
											<?php
											if ( $last_modified ) {
												echo esc_html( $last_modified->format( 'Y-m-d H:i:s' ) );
											} else {
												echo '—';
											}
											?>
										</td>
										<td>
											<a href="<?php echo esc_url( $url ); ?>" target="_blank" rel="noopener noreferrer">
												View
											</a>
										</td>
									</tr>
								<?php endforeach; ?>
							</tbody>
						</table>
					<?php else : ?>
						<p>No objects found in the S3 bucket.</p>
					<?php endif; ?>
				<?php endif; ?>
			</div>
		</div>
		<?php
	}

	/**
	 * Render CLI commands section
	 */
	private function render_cli_commands() {
		?>
		<div class="perdives-mo-section">
			<h2>WP-CLI Commands</h2>
			<div class="perdives-mo-section__inner">
				<table class="perdives-mo-table" role="presentation">
					<tbody>
						<tr>
							<th scope="row"><code>wp hetzner-offload sync</code></th>
							<td>Upload existing media library to S3</td>
						</tr>
						<tr>
							<th scope="row"><code>wp hetzner-offload verify</code></th>
							<td>Check integrity and find missing files or orphans</td>
						</tr>
						<tr>
							<th scope="row"><code>wp hetzner-offload info</code></th>
							<td>Display connection and configuration details</td>
						</tr>
						<tr>
							<th scope="row"><code>wp hetzner-offload test</code></th>
							<td>Test media upload integration with S3</td>
						</tr>
					</tbody>
				</table>
				<p class="description">Add <code>--help</code> to any command for detailed options</p>
			</div>
		</div>
		<?php
	}
}
