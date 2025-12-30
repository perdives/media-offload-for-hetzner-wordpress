<?php
/**
 * WP-CLI Commands
 *
 * @package HetznerOffload
 */

namespace HetznerOffload\CLI;

use HetznerOffload\Storage\S3Handler;
use HetznerOffload\Services\VerificationService;
use Aws\Exception\AwsException;
use WP_CLI;
use WP_Query;

/**
 * WP-CLI commands for Hetzner Offload plugin.
 */
class Commands {

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
	 * Register WP-CLI commands
	 */
	public function register() {
		if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
			return;
		}

		WP_CLI::add_command( 'hetzner-offload', $this );
	}

	/**
	 * Sync existing media library to Hetzner S3.
	 *
	 * ## OPTIONS
	 *
	 * [--dry-run]
	 * : Perform a dry run without actual uploads.
	 *
	 * [--force]
	 * : Force re-upload of all files, even if they already exist on S3.
	 *
	 * ## EXAMPLES
	 *
	 *     wp hetzner-offload sync
	 *     wp hetzner-offload sync --dry-run
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Associative arguments.
	 */
	public function sync( $args, $assoc_args ) {
		$this->display_init_messages();

		if ( ! $this->s3_handler->is_initialized() ) {
			WP_CLI::error( WP_CLI::colorize( '%rHetzner Offload plugin cannot sync: S3 client not initialized or S3 configuration is missing/invalid. Please check your HETZNER_STORAGE_* constants in wp-config.php.%n' ) );
			return;
		}

		if ( ! $this->plugin_enabled ) {
			WP_CLI::warning( WP_CLI::colorize( '%YNote: Media offload is currently disabled (PERDIVES_MO_OFFLOAD_ENABLED is not set to true). New uploads are NOT being synced to S3. This command will sync/manage files on S3 directly.%n' ) );
		}

		WP_CLI::line( WP_CLI::colorize( '%CStarting Hetzner S3 library synchronization...%n' ) );

		$dry_run      = isset( $assoc_args['dry-run'] );
		$force_upload = isset( $assoc_args['force'] );

		// Pre-fetch S3 keys for efficient checking.
		if ( ! $force_upload ) {
			WP_CLI::line( WP_CLI::colorize( '%CFetching list of existing S3 objects under uploads/ prefix (this may take a while for large buckets)...%n' ) );
		}
		$existing_s3_keys = $this->verification_service->get_existing_s3_keys( $force_upload );
		if ( ! $force_upload && $existing_s3_keys !== null ) {
			WP_CLI::line( WP_CLI::colorize( sprintf( '%%GFound %d existing objects in S3 under uploads/ prefix.%%n', count( $existing_s3_keys ) ) ) );
		} elseif ( $force_upload ) {
			WP_CLI::line( WP_CLI::colorize( '%YSkipping S3 object listing due to --force flag.%n' ) );
		}

		if ( $dry_run ) {
			WP_CLI::warning( WP_CLI::colorize( '%YDry run mode enabled. No files will be uploaded.%n' ) );
		}
		if ( $force_upload ) {
			WP_CLI::warning( WP_CLI::colorize( '%YForce upload mode enabled. All files will be re-uploaded.%n' ) );
		}

		$counters = $this->verification_service->initialize_sync_counters();

		// Get total attachments count.
		$total_attachments = $this->verification_service->get_total_attachments();
		if ( $total_attachments === 0 ) {
			WP_CLI::success( WP_CLI::colorize( '%GNo attachments found to sync.%n' ) );
			return;
		}

		WP_CLI::line( WP_CLI::colorize( sprintf( '%%GFound %d attachments to process in total.%%n', $total_attachments ) ) );
		WP_CLI::line( WP_CLI::colorize( sprintf( '%%CNow starting synchronization process for %d attachments...%%n', $total_attachments ) ) );

		// Process attachments in batches.
		$progress = $this->create_progress_bar( 'Syncing attachments', $total_attachments );
		$this->verification_service->process_attachments_batch( $dry_run, $force_upload, $existing_s3_keys, $counters, $progress );

		if ( $progress ) {
			$progress->finish();
		}

		$this->display_sync_summary( $counters, $total_attachments, $dry_run );
	}

	/**
	 * Verify the integrity of the media library against Hetzner S3 storage.
	 *
	 * ## OPTIONS
	 *
	 * [--reupload-missing]
	 * : If a local media file is found to be missing from S3, attempt to re-upload it.
	 *
	 * [--delete-s3-orphans]
	 * : If an S3 object is found in the 'uploads/' prefix that does not correspond to any
	 *   WordPress media library entry, delete it from S3. Use with caution.
	 *
	 * [--cleanup-local]
	 * : If local files exist for attachments that are confirmed to be on S3, delete the local copies.
	 *
	 * [--dry-run]
	 * : Perform a dry run. Report actions that would be taken but do not actually
	 *   upload or delete any files.
	 *
	 * ## EXAMPLES
	 *
	 *     wp hetzner-offload verify
	 *     wp hetzner-offload verify --reupload-missing --dry-run
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Associative arguments.
	 */
	public function verify( $args, $assoc_args ) {
		WP_CLI::line( WP_CLI::colorize( '%CStarting Hetzner S3 library verification...%n' ) );

		$this->display_init_messages();

		if ( ! $this->s3_handler->is_initialized() ) {
			WP_CLI::error( WP_CLI::colorize( '%rHetzner Offload plugin cannot verify: S3 client not initialized or S3 configuration is missing/invalid.%n' ) );
			return;
		}

		$dry_run           = isset( $assoc_args['dry-run'] );
		$reupload_missing  = isset( $assoc_args['reupload-missing'] );
		$delete_s3_orphans = isset( $assoc_args['delete-s3-orphans'] );
		$cleanup_local     = isset( $assoc_args['cleanup-local'] );

		if ( $dry_run ) {
			WP_CLI::warning( WP_CLI::colorize( '%YDry run mode enabled. No actual changes will be made.%n' ) );
		}

		// Pre-fetch all S3 keys.
		WP_CLI::line( WP_CLI::colorize( "%CFetching list of all S3 objects under 'uploads/' prefix...%n" ) );
		$existing_s3_keys = $this->verification_service->get_all_s3_keys_lookup();
		if ( $existing_s3_keys === null ) {
			WP_CLI::error( 'Could not retrieve S3 object list. Aborting verification.' );
			return;
		}

		WP_CLI::line( sprintf( "S3 object list fetch complete. Found %d objects in S3 under 'uploads/' prefix.", count( $existing_s3_keys ) ) );

		$counters        = $this->verification_service->initialize_verify_counters();
		$s3_keys_from_wp = array();

		// Phase 1: Verify WordPress media files against S3.
		WP_CLI::line( WP_CLI::colorize( '%CPhase 1: Verifying WordPress media files against S3...%n' ) );
		$attachment_ids    = $this->verification_service->get_all_attachment_ids();
		$total_attachments = count( $attachment_ids );
		if ( $total_attachments > 0 ) {
			WP_CLI::line( sprintf( 'Found %d media attachments in WordPress. Now verifying each one against the S3 object list...', $total_attachments ) );
			$progress          = $this->create_progress_bar( 'Verifying WP attachments', $total_attachments );
			$progress_callback = $progress ? array( $progress, 'tick' ) : null;
			$this->verification_service->verify_wordpress_media( $existing_s3_keys, $counters, $s3_keys_from_wp, $dry_run, $reupload_missing, $cleanup_local, $progress_callback );
			if ( $progress ) {
				$progress->finish();
			}
		} else {
			WP_CLI::line( 'No attachments found in WordPress media library.' );
		}

		// Phase 2: Check for S3 orphans.
		WP_CLI::line( WP_CLI::colorize( '%CPhase 2: Checking for orphan S3 files...%n' ) );
		WP_CLI::line( sprintf( 'Comparing %d S3 objects against WordPress records to find orphans...', count( $existing_s3_keys ) ) );
		$progress_orphans  = $this->create_progress_bar( 'Verifying S3 objects', count( $existing_s3_keys ) );
		$progress_callback = $progress_orphans ? array( $progress_orphans, 'tick' ) : null;
		$s3_orphans        = $this->verification_service->verify_s3_orphans( $existing_s3_keys, $s3_keys_from_wp, $counters, $dry_run, $delete_s3_orphans, $progress_callback );
		if ( $progress_orphans ) {
			$progress_orphans->finish();
		}

		if ( ! empty( $s3_orphans ) ) {
			WP_CLI::warning( sprintf( 'Found %d potential S3 orphan objects.', count( $s3_orphans ) ) );
			if ( ! $delete_s3_orphans ) {
				$this->list_s3_orphans( $s3_orphans );
			}
		} else {
			WP_CLI::line( WP_CLI::colorize( "%GNo S3 orphan objects found under 'uploads/' prefix.%n" ) );
		}

		$this->display_verify_summary( $counters, $dry_run );
	}

	/**
	 * Display S3 connection and configuration information.
	 *
	 * ## EXAMPLES
	 *
	 *     wp hetzner-offload info
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Associative arguments.
	 */
	public function info( $args, $assoc_args ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed
		WP_CLI::line( WP_CLI::colorize( '%M===================================================%n' ) );
		WP_CLI::line( WP_CLI::colorize( '%BHetzner Offload - Connection Information%n' ) );
		WP_CLI::line( WP_CLI::colorize( '%M===================================================%n' ) );
		WP_CLI::line( '' );

		// WordPress Site Information.
		WP_CLI::line( WP_CLI::colorize( '%C## WordPress Site Information%n' ) );
		WP_CLI::line( sprintf( 'Site Name:     %s', get_bloginfo( 'name' ) ) );
		WP_CLI::line( sprintf( 'Site URL:      %s', get_site_url() ) );
		WP_CLI::line( sprintf( 'WordPress Path: %s', ABSPATH ) );

		$upload_dir = wp_upload_dir();
		WP_CLI::line( sprintf( 'Uploads Path:  %s', $upload_dir['basedir'] ) );
		WP_CLI::line( sprintf( 'Uploads URL:   %s', $upload_dir['baseurl'] ) );
		WP_CLI::line( '' );

		// S3 Configuration.
		WP_CLI::line( WP_CLI::colorize( '%C## S3 Configuration%n' ) );
		if ( defined( 'PERDIVES_MO_HETZNER_STORAGE_BUCKET' ) && PERDIVES_MO_HETZNER_STORAGE_BUCKET ) {
			WP_CLI::line( sprintf( 'Bucket:   %s', WP_CLI::colorize( '%G' . PERDIVES_MO_HETZNER_STORAGE_BUCKET . '%n' ) ) );
		} else {
			WP_CLI::line( 'Bucket:   ' . WP_CLI::colorize( '%rNot configured%n' ) );
		}

		if ( defined( 'PERDIVES_MO_HETZNER_STORAGE_ENDPOINT' ) && PERDIVES_MO_HETZNER_STORAGE_ENDPOINT ) {
			WP_CLI::line( sprintf( 'Endpoint: %s', PERDIVES_MO_HETZNER_STORAGE_ENDPOINT ) );
		} else {
			WP_CLI::line( 'Endpoint: ' . WP_CLI::colorize( '%rNot configured%n' ) );
		}

		if ( defined( 'PERDIVES_MO_HETZNER_STORAGE_CDN_URL' ) && PERDIVES_MO_HETZNER_STORAGE_CDN_URL ) {
			WP_CLI::line( sprintf( 'CDN URL:  %s', WP_CLI::colorize( '%C' . PERDIVES_MO_HETZNER_STORAGE_CDN_URL . '%n' ) ) );
		} else {
			WP_CLI::line( 'CDN URL:  ' . WP_CLI::colorize( '%YNot configured (using S3 endpoint)%n' ) );
		}

		WP_CLI::line( '' );

		// Plugin Status.
		WP_CLI::line( WP_CLI::colorize( '%C## Plugin Status%n' ) );

		if ( $this->s3_handler->is_initialized() ) {
			WP_CLI::line( 'S3 Client: ' . WP_CLI::colorize( '%GInitialized%n' ) );
		} else {
			WP_CLI::line( 'S3 Client: ' . WP_CLI::colorize( '%rNot initialized%n' ) );
		}

		if ( $this->plugin_enabled ) {
			WP_CLI::line( 'Mode:      ' . WP_CLI::colorize( '%GEnabled%n' ) . ' (PERDIVES_MO_OFFLOAD_ENABLED=true)' );
			WP_CLI::line( '           - Automatic S3 uploads: Enabled' );
			WP_CLI::line( '           - URL rewriting: Enabled' );
			WP_CLI::line( '           - Local file deletion: Enabled' );
		} elseif ( $this->s3_handler->is_initialized() ) {
			WP_CLI::line( 'Mode:      ' . WP_CLI::colorize( '%rDisabled%n' ) . ' (PERDIVES_MO_OFFLOAD_ENABLED not set to true)' );
			WP_CLI::line( '           - Automatic S3 uploads: Disabled' );
			WP_CLI::line( '           - URL rewriting: Disabled' );
			WP_CLI::line( '           - Local file deletion: Disabled' );
			WP_CLI::line( '           - Use WP-CLI commands to manually sync files' );
		} else {
			WP_CLI::line( 'Mode:      ' . WP_CLI::colorize( '%rDisabled%n' ) . ' (S3 client not initialized)' );
		}

		// Credentials status.
		WP_CLI::line( '' );
		WP_CLI::line( WP_CLI::colorize( '%C## Credentials%n' ) );
		WP_CLI::line( sprintf( 'Access Key: %s', defined( 'PERDIVES_MO_HETZNER_STORAGE_ACCESS_KEY' ) && PERDIVES_MO_HETZNER_STORAGE_ACCESS_KEY ? WP_CLI::colorize( '%GConfigured%n' ) : WP_CLI::colorize( '%rNot configured%n' ) ) );
		WP_CLI::line( sprintf( 'Secret Key: %s', defined( 'PERDIVES_MO_HETZNER_STORAGE_SECRET_KEY' ) && PERDIVES_MO_HETZNER_STORAGE_SECRET_KEY ? WP_CLI::colorize( '%GConfigured%n' ) : WP_CLI::colorize( '%rNot configured%n' ) ) );

		// Connection Test.
		WP_CLI::line( '' );
		WP_CLI::line( WP_CLI::colorize( '%C## Connection Test%n' ) );

		if ( ! $this->s3_handler->is_initialized() ) {
			WP_CLI::line( 'Status: ' . WP_CLI::colorize( '%rSkipped (S3 client not initialized)%n' ) );
		} else {
			WP_CLI::line( 'Testing connection to S3...' );
			$test_start    = microtime( true );
			$test_result   = $this->verification_service->test_s3_connection();
			$test_duration = microtime( true ) - $test_start;

			if ( $test_result['success'] ) {
				WP_CLI::line( sprintf( 'Status:   %s', WP_CLI::colorize( '%GConnection successful%n' ) ) );
				WP_CLI::line( sprintf( 'Duration: %.3f seconds', $test_duration ) );
				if ( isset( $test_result['object_count'] ) ) {
					WP_CLI::line( sprintf( 'Objects:  %d files found in uploads/ prefix', $test_result['object_count'] ) );
				}
			} else {
				WP_CLI::line( sprintf( 'Status: %s', WP_CLI::colorize( '%rConnection failed%n' ) ) );
				if ( isset( $test_result['error_detail'] ) ) {
					WP_CLI::line( 'Error:' );
					WP_CLI::line( $test_result['error_detail'] );
				} elseif ( isset( $test_result['error'] ) ) {
					WP_CLI::line( 'Error: ' . $test_result['error'] );
				}
			}
		}

		// Errors/Warnings.
		if ( ! empty( $this->init_errors ) ) {
			WP_CLI::line( '' );
			WP_CLI::line( WP_CLI::colorize( '%C## Messages%n' ) );
			foreach ( $this->init_errors as $msg ) {
				$is_critical = strpos( strtolower( $msg ), 'hetzner_storage_' ) !== false ||
								strpos( strtolower( $msg ), 'failed to initialize s3 client' ) !== false;

				if ( $is_critical ) {
					WP_CLI::line( WP_CLI::colorize( '%r• ' . $msg . '%n' ) );
				} else {
					WP_CLI::line( WP_CLI::colorize( '%Y• ' . $msg . '%n' ) );
				}
			}
		}

		WP_CLI::line( '' );
		WP_CLI::line( WP_CLI::colorize( '%M===================================================%n' ) );
	}

	/**
	 * Test WordPress media upload integration with S3.
	 *
	 * This command tests the complete WordPress media upload flow:
	 * - Upload through WordPress media library
	 * - Verify files are uploaded to S3 (including thumbnails)
	 * - Test URL rewriting to S3/CDN
	 * - Test deletion from both WordPress and S3
	 *
	 * ## OPTIONS
	 *
	 * [--keep]
	 * : Keep the test file on S3 after the test completes.
	 *
	 * ## EXAMPLES
	 *
	 *     wp hetzner-offload test
	 *     wp hetzner-offload test --keep
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Associative arguments.
	 */
	public function test( $args, $assoc_args ) {
		WP_CLI::line( WP_CLI::colorize( '%M===================================================%n' ) );
		WP_CLI::line( WP_CLI::colorize( '%BHetzner Offload - WordPress Media Integration Test%n' ) );
		WP_CLI::line( WP_CLI::colorize( '%M===================================================%n' ) );
		WP_CLI::line( '' );

		if ( ! $this->s3_handler->is_initialized() ) {
			WP_CLI::error( 'S3 client not initialized. Please check your configuration.' );
			return;
		}

		$keep_file = isset( $assoc_args['keep'] );

		// Check if we have WordPress functions available.
		if ( ! function_exists( 'wp_insert_attachment' ) ) {
			WP_CLI::error( 'WordPress media functions not available.' );
			return;
		}

		// Generate unique test image file.
		$unique_id       = uniqid( 'test_', true );
		$test_filename   = sprintf( 'hetzner-offload-test-%s.jpg', $unique_id );
		$local_test_path = sys_get_temp_dir() . '/' . $test_filename;

		WP_CLI::line( WP_CLI::colorize( '%C## Test Configuration%n' ) );
		WP_CLI::line( sprintf( 'Test file:  %s', $test_filename ) );
		WP_CLI::line( sprintf( 'Mode:       %s', $this->plugin_enabled ? 'Enabled (automatic offload active)' : 'Disabled (manual sync only via WP-CLI)' ) );
		WP_CLI::line( '' );

		// Step 1: Create test image file.
		WP_CLI::line( WP_CLI::colorize( '%C## Step 1: Creating Test Image%n' ) );
		if ( ! $this->verification_service->create_test_image( $local_test_path ) ) {
			WP_CLI::error( 'Failed to create test image file.' );
			return;
		}
		WP_CLI::line( WP_CLI::colorize( sprintf( '%%GTest image created (%d bytes)%%n', filesize( $local_test_path ) ) ) );
		WP_CLI::line( '' );

		// Step 2: Upload through WordPress media library.
		WP_CLI::line( WP_CLI::colorize( '%C## Step 2: Uploading via WordPress Media Library%n' ) );
		$upload_start = microtime( true );

		// Use WordPress media_handle_sideload to simulate media upload.
		$file_array = array(
			'name'     => $test_filename,
			'tmp_name' => $local_test_path,
		);

		// Disable error handling temporarily.
		$attachment_id   = media_handle_sideload( $file_array, 0, 'Hetzner Offload Test Image - ' . gmdate( 'Y-m-d H:i:s' ) );
		$upload_duration = microtime( true ) - $upload_start;

		if ( is_wp_error( $attachment_id ) ) {
			WP_CLI::error( 'Failed to upload through WordPress: ' . $attachment_id->get_error_message() );
			if ( file_exists( $local_test_path ) ) {
				wp_delete_file( $local_test_path );
			}
			return;
		}

		WP_CLI::line( WP_CLI::colorize( sprintf( '%%GWordPress upload successful (%.3f seconds)%%n', $upload_duration ) ) );
		WP_CLI::line( sprintf( 'Attachment ID: %d', $attachment_id ) );
		WP_CLI::line( '' );

		// Step 3: Verify files were uploaded to S3.
		WP_CLI::line( WP_CLI::colorize( '%C## Step 3: Verifying S3 Upload%n' ) );

		// Get all files that should have been uploaded (main file + thumbnails).
		$wp_upload_dir = wp_upload_dir();
		$metadata      = wp_get_attachment_metadata( $attachment_id );
		$attached_file = get_post_meta( $attachment_id, '_wp_attached_file', true );

		$s3_files_to_check = array();

		// Main file.
		if ( $attached_file ) {
			$local_main_path                = $wp_upload_dir['basedir'] . '/' . $attached_file;
			$s3_files_to_check['main'] = $this->s3_handler->path_to_s3_key( $local_main_path );
		}

		// Thumbnails.
		if ( isset( $metadata['sizes'] ) && is_array( $metadata['sizes'] ) ) {
			$base_dir = dirname( $attached_file );
			if ( $base_dir === '.' ) {
				$base_dir = '';
			}

			foreach ( $metadata['sizes'] as $size_name => $size_info ) {
				if ( ! empty( $size_info['file'] ) ) {
					$thumbnail_path                  = ( $base_dir ? $base_dir . '/' : '' ) . $size_info['file'];
					$local_thumbnail_path            = $wp_upload_dir['basedir'] . '/' . $thumbnail_path;
					$s3_files_to_check[ $size_name ] = $this->s3_handler->path_to_s3_key( $local_thumbnail_path );
				}
			}
		}

		$all_uploaded = true;
		foreach ( $s3_files_to_check as $type => $s3_key ) {
			$exists = $this->s3_handler->object_exists( $s3_key );
			if ( $exists ) {
				WP_CLI::line( WP_CLI::colorize( sprintf( '%%G✓ %s: %s%%n', ucfirst( $type ), $s3_key ) ) );
			} else {
				WP_CLI::line( WP_CLI::colorize( sprintf( '%%r✗ %s: %s (NOT FOUND)%%n', ucfirst( $type ), $s3_key ) ) );
				$all_uploaded = false;
			}
		}

		if ( ! $all_uploaded ) {
			WP_CLI::error( 'Some files were not uploaded to S3!' );
			wp_delete_attachment( $attachment_id, true );
			return;
		}

		WP_CLI::line( WP_CLI::colorize( sprintf( '%%GAll files uploaded to S3 (%d files)%%n', count( $s3_files_to_check ) ) ) );
		WP_CLI::line( '' );

		// Step 4: Test URL rewriting.
		WP_CLI::line( WP_CLI::colorize( '%C## Step 4: Testing URL Rewriting%n' ) );
		$wp_url = wp_get_attachment_url( $attachment_id );
		WP_CLI::line( sprintf( 'WordPress URL: %s', $wp_url ) );

		$has_cdn       = defined( 'PERDIVES_MO_HETZNER_STORAGE_CDN_URL' ) && PERDIVES_MO_HETZNER_STORAGE_CDN_URL;
		$expected_base = $has_cdn ? PERDIVES_MO_HETZNER_STORAGE_CDN_URL : 'https://' . $this->s3_handler->get_bucket() . '.' . PERDIVES_MO_HETZNER_STORAGE_ENDPOINT;

		if ( $this->plugin_enabled ) {
			// In full offload mode, URLs should be rewritten to S3/CDN.
			if ( strpos( $wp_url, $expected_base ) !== false ) {
				WP_CLI::line( WP_CLI::colorize( '%G✓ URL successfully rewritten to S3/CDN%n' ) );
				if ( $has_cdn ) {
					WP_CLI::line( '  Using CDN URL' );
				} else {
					WP_CLI::line( '  Using S3 direct URL' );
				}
			} else {
				WP_CLI::warning( 'URL was not rewritten to S3/CDN. Expected to contain: ' . $expected_base );
			}
		} elseif ( strpos( $wp_url, $wp_upload_dir['baseurl'] ) !== false ) {
			// In sync-only mode, URLs should still point to local.
			WP_CLI::line( WP_CLI::colorize( '%Y✓ URL points to local (expected in sync-only mode)%n' ) );
		}
		WP_CLI::line( '' );

		// Step 5: Test public URL accessibility.
		WP_CLI::line( WP_CLI::colorize( '%C## Step 5: Testing Public URL Accessibility%n' ) );

		// Test the actual S3 URL (construct it directly).
		$s3_key       = $s3_files_to_check['main'];
		$relative_key = preg_replace( '#^uploads/#', '', $s3_key );
		$s3_url       = $this->s3_handler->get_url( $relative_key );

		$s3_result = $this->verification_service->test_url_accessibility( $s3_url );
		if ( $s3_result['accessible'] ) {
			WP_CLI::line( sprintf( 'S3 URL:  %s (HTTP %d)', WP_CLI::colorize( '%GAccessible%n' ), $s3_result['status_code'] ) );
		} else {
			WP_CLI::error( sprintf( 'S3 URL not accessible! Status: %d', $s3_result['status_code'] ) );
			wp_delete_attachment( $attachment_id, true );
			return;
		}

		// Test WordPress URL (which may be rewritten to CDN or S3).
		$wp_result = $this->verification_service->test_url_accessibility( $wp_url );
		if ( $wp_result['accessible'] ) {
			WP_CLI::line( sprintf( 'WP URL:  %s (HTTP %d)', WP_CLI::colorize( '%GAccessible%n' ), $wp_result['status_code'] ) );
		} else {
			WP_CLI::warning( sprintf( 'WordPress URL not accessible! Status: %d', $wp_result['status_code'] ) );
		}

		WP_CLI::line( '' );

		// Step 6: Test local file cleanup (if enabled).
		WP_CLI::line( WP_CLI::colorize( '%C## Step 6: Testing Local File Cleanup%n' ) );

		$local_file_path = $wp_upload_dir['basedir'] . '/' . $attached_file;
		$local_exists    = file_exists( $local_file_path );

		if ( $this->plugin_enabled && ! $local_exists ) {
			// In full offload mode, local files should be deleted.
			WP_CLI::line( WP_CLI::colorize( '%G✓ Local file successfully deleted (expected in full offload mode)%n' ) );
		} elseif ( $this->plugin_enabled && $local_exists ) {
			WP_CLI::warning( '✗ Local file still exists (should be deleted in full offload mode)' );
		} elseif ( ! $this->plugin_enabled && $local_exists ) {
			// In sync-only mode, local files should remain.
			WP_CLI::line( WP_CLI::colorize( '%G✓ Local file kept (expected in sync-only mode)%n' ) );
		} elseif ( ! $this->plugin_enabled && ! $local_exists ) {
			WP_CLI::warning( '✗ Local file deleted (should be kept in sync-only mode)' );
		}
		WP_CLI::line( '' );

		// Step 7: Test deletion from WordPress.
		WP_CLI::line( WP_CLI::colorize( '%C## Step 7: Testing WordPress Deletion%n' ) );

		if ( ! $keep_file ) {
			WP_CLI::line( 'Deleting attachment from WordPress...' );

			// Check S3 before deletion.
			$s3_exists_before = $this->s3_handler->object_exists( $s3_key );

			// Delete attachment.
			$deleted = wp_delete_attachment( $attachment_id, true );

			if ( ! $deleted ) {
				WP_CLI::error( 'Failed to delete attachment from WordPress' );
				return;
			}

			WP_CLI::line( WP_CLI::colorize( '%G✓ Attachment deleted from WordPress%n' ) );

			// Wait briefly for deletion to propagate.
			sleep( 1 );

			// Check if S3 files were deleted.
			if ( $this->plugin_enabled ) {
				$s3_exists_after = $this->s3_handler->object_exists( $s3_key );

				if ( $s3_exists_before && ! $s3_exists_after ) {
					WP_CLI::line( WP_CLI::colorize( '%G✓ S3 files deleted (expected in full offload mode)%n' ) );
				} elseif ( $s3_exists_after ) {
					WP_CLI::warning( '✗ S3 files still exist (should be deleted in full offload mode)' );
					// Clean up manually.
					foreach ( $s3_files_to_check as $type => $key ) {
						$this->s3_handler->delete_object( $key );
					}
				}
			} else {
				WP_CLI::line( WP_CLI::colorize( '%Y✓ S3 files kept (expected in sync-only mode)%n' ) );
				// Clean up S3 files manually in sync-only mode.
				foreach ( $s3_files_to_check as $type => $key ) {
					$this->s3_handler->delete_object( $key );
				}
			}
		} else {
			WP_CLI::line( WP_CLI::colorize( '%YAttachment kept (--keep flag used)%n' ) );
			WP_CLI::line( sprintf( 'Attachment ID: %d', $attachment_id ) );
			WP_CLI::line( sprintf( 'WordPress URL: %s', $wp_url ) );
		}

		WP_CLI::line( '' );
		WP_CLI::line( WP_CLI::colorize( '%M===================================================%n' ) );
		WP_CLI::success( WP_CLI::colorize( '%GAll WordPress integration tests passed successfully!%n' ) );
		WP_CLI::line( WP_CLI::colorize( '%M===================================================%n' ) );
	}

	/**
	 * Create a test image file.
	 *
	 * Creates a simple colored image for testing.
	 *
	 * @param string $file_path Path where to create the image.
	 * @return bool True on success, false on failure.
	 */

	/**
	 * Display initialization messages
	 */
	private function display_init_messages() {
		if ( empty( $this->init_errors ) ) {
			return;
		}

		foreach ( $this->init_errors as $msg ) {
			$is_critical = strpos( strtolower( $msg ), 'hetzner_storage_' ) !== false ||
							strpos( strtolower( $msg ), 'failed to initialize s3 client' ) !== false;

			if ( $is_critical && ! $this->s3_handler->is_initialized() ) {
				WP_CLI::error( $msg );
			} else {
				WP_CLI::warning( $msg );
			}
		}
	}

	/**
	 * Get existing S3 keys for efficient checking
	 *
	 * @param bool $force_upload Whether force upload is enabled.
	 * @return array|null Array of S3 keys or null if fetching failed.
	 */

	/**
	 * Get all S3 keys as lookup array
	 *
	 * @return array|null
	 */

	/**
	 * Initialize sync counters
	 *
	 * @return array
	 */

	/**
	 * Initialize verify counters
	 *
	 * @return array
	 */

	/**
	 * Get total attachments count
	 *
	 * @return int
	 */

	/**
	 * Create progress bar
	 *
	 * @param string $label Label for progress bar.
	 * @param int    $count Total count.
	 * @return object|null
	 */
	private function create_progress_bar( $label, $count ) {
		try {
			return \WP_CLI\Utils\make_progress_bar( WP_CLI::colorize( "%B{$label}%n" ), $count );
		} catch ( \Error $e ) {
			WP_CLI::warning( 'Progress bar could not be initialized. Proceeding without visual progress.' );
			return null;
		}
	}

	/**
	 * Process attachments in batches
	 *
	 * @param bool        $dry_run          Dry run mode.
	 * @param bool        $force_upload     Force upload mode.
	 * @param array|null  $existing_s3_keys Existing S3 keys.
	 * @param array       $counters         Counters array (passed by reference).
	 * @param object|null $progress         Progress bar object.
	 */

	/**
	 * Process a single attachment
	 *
	 * @param int        $attachment_id    Attachment ID.
	 * @param bool       $dry_run          Dry run mode.
	 * @param bool       $force_upload     Force upload mode.
	 * @param array|null $existing_s3_keys Existing S3 keys.
	 * @param array      $counters         Counters array (passed by reference).
	 */

	/**
	 * Process file upload to S3
	 *
	 * @param string     $local_path        Local file path.
	 * @param string     $s3_key            S3 object key.
	 * @param int        $attachment_id     Attachment ID.
	 * @param bool       $dry_run           Dry run mode.
	 * @param bool       $force_upload      Force upload mode.
	 * @param array|null $existing_s3_keys  Existing S3 keys.
	 * @param array      $counters          Counters array (passed by reference).
	 */

	/**
	 * Verify WordPress media files against S3
	 *
	 * @param array $existing_s3_keys Existing S3 keys lookup.
	 * @param array $counters         Counters array (passed by reference).
	 * @param array $s3_keys_from_wp  S3 keys from WordPress (passed by reference).
	 * @param bool  $dry_run          Dry run mode.
	 * @param bool  $reupload_missing Whether to reupload missing files.
	 * @param bool  $cleanup_local    Whether to cleanup local files.
	 */

	/**
	 * Handle file verification
	 *
	 * @param bool   $local_exists     Whether local file exists.
	 * @param bool   $s3_exists        Whether S3 object exists.
	 * @param string $local_path       Local file path.
	 * @param string $s3_key           S3 object key.
	 * @param int    $attachment_id    Attachment ID.
	 * @param array  $counters         Counters array (passed by reference).
	 * @param bool   $dry_run          Dry run mode.
	 * @param bool   $reupload_missing Whether to reupload missing files.
	 * @param bool   $cleanup_local    Whether to cleanup local files.
	 */

	/**
	 * Verify S3 orphans
	 *
	 * @param array $existing_s3_keys  Existing S3 keys lookup.
	 * @param array $s3_keys_from_wp   S3 keys from WordPress.
	 * @param array $counters          Counters array (passed by reference).
	 * @param bool  $dry_run           Dry run mode.
	 * @param bool  $delete_s3_orphans Whether to delete orphans.
	 */

	/**
	 * Delete S3 orphans
	 *
	 * @param array $orphans  Orphan S3 keys.
	 * @param array $counters Counters array (passed by reference).
	 * @param bool  $dry_run  Dry run mode.
	 */

	/**
	 * List S3 orphans
	 *
	 * @param array $orphans Orphan S3 keys.
	 */
	private function list_s3_orphans( $orphans ) {
		$max_to_list  = 20;
		$orphan_count = count( $orphans );
		WP_CLI::line( sprintf( 'To delete them, run again with --delete-s3-orphans. Listing first %d (at most) S3 Orphan Keys:', $max_to_list ) );

		$limit = min( $orphan_count, $max_to_list );
		for ( $i = 0; $i < $limit; $i++ ) {
			WP_CLI::line( '- ' . $orphans[ $i ] );
		}

		if ( $orphan_count > $max_to_list ) {
			WP_CLI::line( sprintf( '...and %d more.', $orphan_count - $max_to_list ) );
		}
	}

	/**
	 * Test if a URL is publicly accessible
	 *
	 * @param string $url URL to test.
	 * @return array Result with 'accessible' and 'status_code' keys.
	 */

	/**
	 * Get all attachment IDs
	 *
	 * @return array
	 */

	/**
	 * Get attachment files (primary, original, thumbnails)
	 *
	 * @param int    $attachment_id    Attachment ID.
	 * @param string $base_upload_path Base upload path.
	 * @return array
	 */

	/**
	 * Test S3 connection
	 *
	 * @return array Test result with 'success', 'error', 'error_code', 'error_detail', and 'object_count' keys.
	 */

	/**
	 * Get friendly error message from AWS error code
	 *
	 * @param string $error_code   AWS error code.
	 * @param int    $status_code  HTTP status code.
	 * @return string Friendly error message.
	 */

	/**
	 * Display sync summary
	 *
	 * @param array $counters          Counters array.
	 * @param int   $total_attachments Total attachments count.
	 * @param bool  $dry_run           Dry run mode.
	 */
	private function display_sync_summary( $counters, $total_attachments, $dry_run ) {
		WP_CLI::line( WP_CLI::colorize( '%M---------------------------------------------------%n' ) );
		WP_CLI::line( WP_CLI::colorize( '%BSynchronization Summary:%n' ) );
		WP_CLI::line( WP_CLI::colorize( '%M---------------------------------------------------%n' ) );
		WP_CLI::line( sprintf( 'Total attachments scanned: %d', $total_attachments ) );
		WP_CLI::line( sprintf( 'Total file operations attempted: %d', $counters['total_files_processed'] ) );

		if ( $dry_run ) {
			WP_CLI::line( 'Files that would be uploaded: ' . WP_CLI::colorize( sprintf( '%%G%d%%n', $counters['files_uploaded'] ) ) );
		} else {
			WP_CLI::line( 'Files successfully uploaded: ' . WP_CLI::colorize( sprintf( '%%G%d%%n', $counters['files_uploaded'] ) ) );
		}

		WP_CLI::line( 'Files skipped (already exist on S3): ' . WP_CLI::colorize( sprintf( '%%Y%d%%n', $counters['files_skipped_exists'] ) ) );
		WP_CLI::line( 'Local files not found: ' . WP_CLI::colorize( sprintf( '%%r%d%%n', $counters['files_local_not_found'] ) ) );
		WP_CLI::line( 'S3 upload errors: ' . WP_CLI::colorize( sprintf( '%%r%d%%n', $counters['files_s3_errors'] ) ) );
		WP_CLI::line( WP_CLI::colorize( '%M---------------------------------------------------%n' ) );

		if ( $dry_run ) {
			WP_CLI::success( WP_CLI::colorize( '%GDry run synchronization process completed.%n' ) );
		} else {
			WP_CLI::success( WP_CLI::colorize( '%GLibrary synchronization process completed.%n' ) );
		}
	}

	/**
	 * Display verify summary
	 *
	 * @param array $counters Counters array.
	 * @param bool  $dry_run  Dry run mode.
	 */
	private function display_verify_summary( $counters, $dry_run ) {
		WP_CLI::line( WP_CLI::colorize( '%M---------------------------------------------------%n' ) );
		WP_CLI::line( WP_CLI::colorize( '%BVerification Summary:%n' ) );
		WP_CLI::line( WP_CLI::colorize( '%M---------------------------------------------------%n' ) );
		WP_CLI::line( sprintf( 'WordPress Attachments Scanned: %d', $counters['wp_attachments_scanned'] ) );
		WP_CLI::line( sprintf( 'WordPress Files (versions/thumbnails) Scanned: %d', $counters['wp_files_scanned'] ) );
		WP_CLI::line( WP_CLI::colorize( sprintf( '%%CLocal Files Currently on Disk: %d%%n', $counters['local_files_exist'] ) ) );
		WP_CLI::line( WP_CLI::colorize( sprintf( '%%YFiles missing on S3 (but present locally): %d%%n', $counters['s3_missing'] ) ) );
		WP_CLI::line( WP_CLI::colorize( sprintf( '%%GFiles re-uploaded to S3: %d%%n', $counters['s3_reuploaded'] ) ) );
		WP_CLI::line( WP_CLI::colorize( sprintf( '%%rFailed S3 re-uploads: %d%%n', $counters['s3_reupload_failed'] ) ) );
		WP_CLI::line( WP_CLI::colorize( sprintf( '%%GLocal files cleaned up (because on S3): %d%%n', $counters['local_cleaned'] ) ) );
		WP_CLI::line( WP_CLI::colorize( sprintf( '%%rFailed local cleanups: %d%%n', $counters['local_cleanup_failed'] ) ) );
		WP_CLI::line( sprintf( 'Files offloaded (local missing, S3 exists): %d', $counters['s3_exists_local_missing'] ) );
		WP_CLI::line( WP_CLI::colorize( sprintf( '%%rProblematic (both local & S3 missing): %d%%n', $counters['local_missing_s3_missing'] ) ) );
		WP_CLI::line( WP_CLI::colorize( '%M---------------------------------------------------%n' ) );
		WP_CLI::line( sprintf( 'S3 Objects Scanned (in uploads/): %d', $counters['s3_objects_scanned'] ) );
		WP_CLI::line( WP_CLI::colorize( sprintf( '%%YS3 Orphan Objects Found: %d%%n', $counters['s3_orphans_found'] ) ) );
		WP_CLI::line( WP_CLI::colorize( sprintf( '%%GS3 Orphan Objects Deleted: %d%%n', $counters['s3_orphans_deleted'] ) ) );
		WP_CLI::line( WP_CLI::colorize( sprintf( '%%rFailed S3 Orphan Deletes: %d%%n', $counters['s3_orphan_delete_failed'] ) ) );
		WP_CLI::line( WP_CLI::colorize( '%M---------------------------------------------------%n' ) );

		if ( $dry_run ) {
			WP_CLI::success( WP_CLI::colorize( '%GDry run verification process completed.%n' ) );
		} else {
			WP_CLI::success( WP_CLI::colorize( '%GVerification process completed.%n' ) );
		}
	}
}
