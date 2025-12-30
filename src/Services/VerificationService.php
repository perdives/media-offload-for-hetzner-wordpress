<?php
/**
 * Verification Service
 *
 * @package HetznerOffload
 */

namespace HetznerOffload\Services;

use HetznerOffload\Storage\S3Handler;
use WP_Query;

/**
 * Service class for media verification and system info operations.
 * Extracted from CLI Commands to be reusable by both CLI and admin interfaces.
 */
class VerificationService {

	/**
	 * S3 Handler instance
	 *
	 * @var S3Handler
	 */
	private $s3_handler;

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
		$this->s3_handler     = $s3_handler;
		$this->init_errors    = $init_errors;
		$this->plugin_enabled = $plugin_enabled;
	}

	/**
	 * Create a test image file.
	 *
	 * @param string $file_path Path where to create the image.
	 * @return bool True on success, false on failure.
	 */
	public function create_test_image( $file_path ) {
		// Create a 800x600 test image.
		$width  = 800;
		$height = 600;
		$image  = imagecreatetruecolor( $width, $height );

		if ( ! $image ) {
			return false;
		}

		// Create a gradient background.
		for ( $y = 0; $y < $height; $y++ ) {
			$r     = (int) ( 255 * ( $y / $height ) );
			$g     = (int) ( 100 + 155 * ( $y / $height ) );
			$b     = 255 - (int) ( 155 * ( $y / $height ) );
			$color = imagecolorallocate( $image, $r, $g, $b );
			imagefilledrectangle( $image, 0, $y, $width, $y + 1, $color );
		}

		// Add some text.
		$white = imagecolorallocate( $image, 255, 255, 255 );
		$black = imagecolorallocate( $image, 0, 0, 0 );

		$text        = 'Hetzner Offload Test Image';
		$font_size   = 5;
		$text_width  = imagefontwidth( $font_size ) * strlen( $text );
		$text_height = imagefontheight( $font_size );
		$x           = (int) ( ( $width - $text_width ) / 2 );
		$y           = (int) ( ( $height - $text_height ) / 2 );

		// Shadow.
		imagestring( $image, $font_size, $x + 2, $y + 2, $text, $black );
		// Text.
		imagestring( $image, $font_size, $x, $y, $text, $white );

		// Add timestamp.
		$timestamp = gmdate( 'Y-m-d H:i:s' ) . ' UTC';
		imagestring( $image, 3, 10, $height - 20, $timestamp, $white );

		// Save as JPEG.
		$success = imagejpeg( $image, $file_path, 90 );

		return $success;
	}

	/**
	 * Get existing S3 keys for efficient checking.
	 *
	 * @param bool $force_upload Whether force upload is enabled.
	 * @return array|null Array of S3 keys or null if fetching failed.
	 */
	public function get_existing_s3_keys( $force_upload ) {
		if ( $force_upload ) {
			return null;
		}

		$existing_keys = array();
		$objects       = $this->s3_handler->list_objects( 'uploads/' );

		foreach ( $objects as $object ) {
			$existing_keys[ $object['Key'] ] = true;
		}

		return $existing_keys;
	}

	/**
	 * Get all S3 keys as lookup array.
	 *
	 * @return array|null Lookup array of S3 keys or null on failure.
	 */
	public function get_all_s3_keys_lookup() {
		$objects = $this->s3_handler->list_objects( 'uploads/' );
		if ( empty( $objects ) && ! is_array( $objects ) ) {
			return null;
		}

		$lookup = array();
		foreach ( $objects as $object ) {
			if ( isset( $object['Key'] ) ) {
				$lookup[ $object['Key'] ] = true;
			}
		}

		return $lookup;
	}

	/**
	 * Initialize sync counters.
	 *
	 * @return array Counters array.
	 */
	public function initialize_sync_counters() {
		return array(
			'total_files_processed' => 0,
			'files_uploaded'        => 0,
			'files_skipped_exists'  => 0,
			'files_local_not_found' => 0,
			'files_s3_errors'       => 0,
		);
	}

	/**
	 * Initialize verify counters.
	 *
	 * @return array Counters array.
	 */
	public function initialize_verify_counters() {
		return array(
			'wp_attachments_scanned'   => 0,
			'wp_files_scanned'         => 0,
			'local_files_exist'        => 0,
			's3_missing'               => 0,
			's3_exists_local_missing'  => 0,
			's3_reuploaded'            => 0,
			's3_reupload_failed'       => 0,
			'local_cleaned'            => 0,
			'local_cleanup_failed'     => 0,
			'local_missing_s3_missing' => 0,
			's3_objects_scanned'       => 0,
			's3_orphans_found'         => 0,
			's3_orphans_deleted'       => 0,
			's3_orphan_delete_failed'  => 0,
		);
	}

	/**
	 * Get total attachments count.
	 *
	 * @return int Total number of attachments.
	 */
	public function get_total_attachments() {
		$query = new WP_Query(
			array(
				'post_type'      => 'attachment',
				'post_status'    => 'inherit',
				'posts_per_page' => 1,
				'fields'         => 'ids',
			)
		);

		return $query->found_posts;
	}

	/**
	 * Process attachments in batches.
	 *
	 * @param bool        $dry_run          Dry run mode.
	 * @param bool        $force_upload     Force upload mode.
	 * @param array|null  $existing_s3_keys Existing S3 keys.
	 * @param array       $counters         Counters array (passed by reference).
	 * @param object|null $progress         Progress bar object.
	 */
	public function process_attachments_batch( $dry_run, $force_upload, $existing_s3_keys, &$counters, $progress ) {
		$batch_size = 100;
		$paged      = 1;

		do {
			$query = new WP_Query(
				array(
					'post_type'      => 'attachment',
					'post_status'    => 'inherit',
					'posts_per_page' => $batch_size,
					'paged'          => $paged,
				)
			);

			if ( empty( $query->posts ) ) {
				break;
			}

			foreach ( $query->posts as $attachment ) {
				$this->process_attachment(
					$attachment->ID,
					$dry_run,
					$force_upload,
					$existing_s3_keys,
					$counters
				);

				if ( $progress ) {
					$progress->tick();
				}
			}

			++$paged;

			// Free memory.
			if ( function_exists( '\WP_CLI\Utils\stop_the_insanity' ) ) {
				\WP_CLI\Utils\stop_the_insanity();
			}
		} while ( $query->max_num_pages >= $paged );
	}

	/**
	 * Process a single attachment.
	 *
	 * @param int        $attachment_id    Attachment ID.
	 * @param bool       $dry_run          Dry run mode.
	 * @param bool       $force_upload     Force upload mode.
	 * @param array|null $existing_s3_keys Existing S3 keys.
	 * @param array      $counters         Counters array (passed by reference).
	 */
	public function process_attachment( $attachment_id, $dry_run, $force_upload, $existing_s3_keys, &$counters ) {
		$wp_upload_dir    = wp_upload_dir();
		$base_upload_path = $wp_upload_dir['basedir'];

		$primary_wp_meta_path = get_post_meta( $attachment_id, '_wp_attached_file', true );
		if ( ! $primary_wp_meta_path ) {
			return;
		}

		// Process primary file.
		$local_primary_path = $base_upload_path . '/' . $primary_wp_meta_path;
		$s3_primary_key     = $this->s3_handler->path_to_s3_key( $local_primary_path );
		$this->process_file_upload( $local_primary_path, $s3_primary_key, $attachment_id, $dry_run, $force_upload, $existing_s3_keys, $counters );

		// Process true original if different.
		$true_original_path = wp_get_original_image_path( $attachment_id );
		if ( $true_original_path && $true_original_path !== $local_primary_path ) {
			$s3_original_key = $this->s3_handler->path_to_s3_key( $true_original_path );
			$this->process_file_upload( $true_original_path, $s3_original_key, $attachment_id, $dry_run, $force_upload, $existing_s3_keys, $counters );
		}

		// Process thumbnails.
		$metadata = wp_get_attachment_metadata( $attachment_id );
		if ( isset( $metadata['sizes'] ) && is_array( $metadata['sizes'] ) ) {
			$primary_dir = dirname( $primary_wp_meta_path );
			if ( $primary_dir === '.' ) {
				$primary_dir = '';
			}

			foreach ( $metadata['sizes'] as $size_name => $size_info ) {
				if ( empty( $size_info['file'] ) ) {
					continue;
				}

				$thumbnail_filename = $size_info['file'];
				$local_thumb_path   = $base_upload_path . '/' . ( $primary_dir ? $primary_dir . '/' : '' ) . $thumbnail_filename;
				$s3_thumb_key       = $this->s3_handler->path_to_s3_key( $local_thumb_path );

				$this->process_file_upload( $local_thumb_path, $s3_thumb_key, $attachment_id, $dry_run, $force_upload, $existing_s3_keys, $counters );
			}
		}
	}

	/**
	 * Process file upload to S3.
	 *
	 * @param string     $local_path        Local file path.
	 * @param string     $s3_key            S3 object key.
	 * @param int        $attachment_id     Attachment ID.
	 * @param bool       $dry_run           Dry run mode.
	 * @param bool       $force_upload      Force upload mode.
	 * @param array|null $existing_s3_keys  Existing S3 keys.
	 * @param array      $counters          Counters array (passed by reference).
	 */
	public function process_file_upload( $local_path, $s3_key, $attachment_id, $dry_run, $force_upload, $existing_s3_keys, &$counters ) {
		++$counters['total_files_processed'];

		if ( ! file_exists( $local_path ) ) {
			++$counters['files_local_not_found'];
			return;
		}

		// Check if file already exists on S3.
		if ( ! $force_upload ) {
			if ( $existing_s3_keys !== null && isset( $existing_s3_keys[ $s3_key ] ) ) {
				++$counters['files_skipped_exists'];
				return;
			} elseif ( $existing_s3_keys === null && $this->s3_handler->object_exists( $s3_key ) ) {
				++$counters['files_skipped_exists'];
				return;
			}
		}

		if ( $dry_run ) {
			++$counters['files_uploaded'];
			return;
		}

		// Upload file.
		if ( $this->s3_handler->upload_file( $local_path, $s3_key ) ) {
			++$counters['files_uploaded'];
		} else {
			++$counters['files_s3_errors'];
		}
	}

	/**
	 * Verify WordPress media files against S3.
	 *
	 * @param array         $existing_s3_keys  Existing S3 keys lookup.
	 * @param array         $counters          Counters array (passed by reference).
	 * @param array         $s3_keys_from_wp   S3 keys from WordPress (passed by reference).
	 * @param bool          $dry_run           Dry run mode.
	 * @param bool          $reupload_missing  Whether to reupload missing files.
	 * @param bool          $cleanup_local     Whether to cleanup local files.
	 * @param callable|null $progress_callback Optional progress callback.
	 */
	public function verify_wordpress_media( $existing_s3_keys, &$counters, &$s3_keys_from_wp, $dry_run, $reupload_missing, $cleanup_local, $progress_callback = null ) {
		$attachment_ids    = $this->get_all_attachment_ids();
		$total_attachments = count( $attachment_ids );

		if ( $total_attachments === 0 ) {
			return;
		}

		$wp_upload_dir    = wp_upload_dir();
		$base_upload_path = $wp_upload_dir['basedir'];

		foreach ( $attachment_ids as $attachment_id ) {
			++$counters['wp_attachments_scanned'];
			$attachment_files = $this->get_attachment_files( $attachment_id, $base_upload_path );

			foreach ( $attachment_files as $file_info ) {
				++$counters['wp_files_scanned'];
				$local_path                 = $file_info['local_path'];
				$s3_key                     = $file_info['s3_key'];
				$s3_keys_from_wp[ $s3_key ] = true;

				$local_exists = file_exists( $local_path );
				$s3_exists    = isset( $existing_s3_keys[ $s3_key ] );

				$this->handle_file_verification(
					$local_exists,
					$s3_exists,
					$local_path,
					$s3_key,
					$attachment_id,
					$counters,
					$dry_run,
					$reupload_missing,
					$cleanup_local
				);
			}

			if ( $progress_callback ) {
				call_user_func( $progress_callback );
			}
		}
	}

	/**
	 * Handle file verification.
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
	public function handle_file_verification( $local_exists, $s3_exists, $local_path, $s3_key, $attachment_id, &$counters, $dry_run, $reupload_missing, $cleanup_local ) {
		// Count local files that exist.
		if ( $local_exists ) {
			++$counters['local_files_exist'];
		}

		if ( $local_exists && ! $s3_exists ) {
			++$counters['s3_missing'];
			if ( $reupload_missing ) {
				if ( $dry_run ) {
					++$counters['s3_reuploaded'];
				} elseif ( $this->s3_handler->upload_file( $local_path, $s3_key ) ) {
					++$counters['s3_reuploaded'];
					if ( $cleanup_local && wp_delete_file( $local_path ) ) {
						++$counters['local_cleaned'];
					}
				} else {
					++$counters['s3_reupload_failed'];
				}
			}
		} elseif ( $local_exists && $s3_exists ) {
			if ( $cleanup_local && ! $reupload_missing ) {
				if ( $dry_run ) {
					++$counters['local_cleaned'];
				} elseif ( wp_delete_file( $local_path ) ) {
					++$counters['local_cleaned'];
				} else {
					++$counters['local_cleanup_failed'];
				}
			}
		} elseif ( ! $local_exists && $s3_exists ) {
			++$counters['s3_exists_local_missing'];
		} elseif ( ! $local_exists && ! $s3_exists ) {
			++$counters['local_missing_s3_missing'];
		}
	}

	/**
	 * Verify S3 orphans.
	 *
	 * @param array         $existing_s3_keys  Existing S3 keys lookup.
	 * @param array         $s3_keys_from_wp   S3 keys from WordPress.
	 * @param array         $counters          Counters array (passed by reference).
	 * @param bool          $dry_run           Dry run mode.
	 * @param bool          $delete_s3_orphans Whether to delete orphans.
	 * @param callable|null $progress_callback Optional progress callback.
	 * @return array Array of orphan S3 keys.
	 */
	public function verify_s3_orphans( $existing_s3_keys, $s3_keys_from_wp, &$counters, $dry_run, $delete_s3_orphans, $progress_callback = null ) {
		$counters['s3_objects_scanned'] = count( $existing_s3_keys );

		$s3_orphans = array();

		foreach ( array_keys( $existing_s3_keys ) as $s3_key ) {
			if ( ! isset( $s3_keys_from_wp[ $s3_key ] ) ) {
				$s3_orphans[] = $s3_key;
				++$counters['s3_orphans_found'];
			}

			if ( $progress_callback ) {
				call_user_func( $progress_callback );
			}
		}

		if ( ! empty( $s3_orphans ) && $delete_s3_orphans ) {
			$this->delete_s3_orphans( $s3_orphans, $counters, $dry_run );
		}

		return $s3_orphans;
	}

	/**
	 * Delete S3 orphans.
	 *
	 * @param array $orphans  Orphan S3 keys.
	 * @param array $counters Counters array (passed by reference).
	 * @param bool  $dry_run  Dry run mode.
	 */
	public function delete_s3_orphans( $orphans, &$counters, $dry_run ) {
		foreach ( $orphans as $orphan_key ) {
			if ( $dry_run ) {
				++$counters['s3_orphans_deleted'];
			} elseif ( $this->s3_handler->delete_object( $orphan_key ) ) {
				++$counters['s3_orphans_deleted'];
			} else {
				++$counters['s3_orphan_delete_failed'];
			}
		}
	}

	/**
	 * Test if a URL is publicly accessible.
	 *
	 * @param string $url URL to test.
	 * @return array Result with 'accessible' and 'status_code' keys.
	 */
	public function test_url_accessibility( $url ) {
		$response = wp_remote_head(
			$url,
			array(
				'timeout'     => 10,
				'redirection' => 5,
				'sslverify'   => true,
			)
		);

		if ( is_wp_error( $response ) ) {
			return array(
				'accessible'  => false,
				'status_code' => 0,
				'error'       => $response->get_error_message(),
			);
		}

		$status_code = wp_remote_retrieve_response_code( $response );

		return array(
			'accessible'  => $status_code >= 200 && $status_code < 300,
			'status_code' => $status_code,
		);
	}

	/**
	 * Get all attachment IDs.
	 *
	 * @return array Array of attachment IDs.
	 */
	public function get_all_attachment_ids() {
		$query = new WP_Query(
			array(
				'post_type'      => 'attachment',
				'post_status'    => 'inherit',
				'posts_per_page' => -1,
				'fields'         => 'ids',
			)
		);

		return $query->posts;
	}

	/**
	 * Get attachment files (primary, original, thumbnails).
	 *
	 * @param int    $attachment_id    Attachment ID.
	 * @param string $base_upload_path Base upload path.
	 * @return array Array of file information.
	 */
	public function get_attachment_files( $attachment_id, $base_upload_path ) {
		$files                = array();
		$primary_wp_meta_path = get_post_meta( $attachment_id, '_wp_attached_file', true );

		if ( ! $primary_wp_meta_path ) {
			return $files;
		}

		// Primary file.
		$local_primary_path = $base_upload_path . '/' . $primary_wp_meta_path;
		$s3_primary_key     = $this->s3_handler->path_to_s3_key( $local_primary_path );
		$files[]            = array(
			'local_path' => $local_primary_path,
			's3_key'     => $s3_primary_key,
			'type'       => 'primary',
		);

		// True original if different.
		$true_original_path = wp_get_original_image_path( $attachment_id );
		if ( $true_original_path && $true_original_path !== $local_primary_path ) {
			$s3_original_key = $this->s3_handler->path_to_s3_key( $true_original_path );
			$files[]         = array(
				'local_path' => $true_original_path,
				's3_key'     => $s3_original_key,
				'type'       => 'true_original',
			);
		}

		// Thumbnails.
		$metadata = wp_get_attachment_metadata( $attachment_id );
		if ( isset( $metadata['sizes'] ) && is_array( $metadata['sizes'] ) ) {
			$primary_dir = dirname( $primary_wp_meta_path );
			if ( $primary_dir === '.' ) {
				$primary_dir = '';
			}

			foreach ( $metadata['sizes'] as $size_name => $size_info ) {
				if ( empty( $size_info['file'] ) ) {
					continue;
				}

				$thumbnail_filename = $size_info['file'];
				$local_thumb_path   = $base_upload_path . '/' . ( $primary_dir ? $primary_dir . '/' : '' ) . $thumbnail_filename;
				$s3_thumb_key       = $this->s3_handler->path_to_s3_key( $local_thumb_path );
				$files[]            = array(
					'local_path' => $local_thumb_path,
					's3_key'     => $s3_thumb_key,
					'type'       => $size_name,
				);
			}
		}

		return $files;
	}

	/**
	 * Test S3 connection.
	 *
	 * @return array Test result with 'success', 'error', 'error_code', 'error_detail', and 'object_count' keys.
	 */
	public function test_s3_connection() {
		try {
			$client = $this->s3_handler->get_client();
			if ( ! $client ) {
				return array(
					'success' => false,
					'error'   => 'S3 client not available',
				);
			}

			// Try to list objects with a small limit to test connection.
			$result = $client->listObjectsV2(
				array(
					'Bucket'  => $this->s3_handler->get_bucket(),
					'Prefix'  => 'uploads/',
					'MaxKeys' => 10,
				)
			);

			$object_count = isset( $result['KeyCount'] ) ? (int) $result['KeyCount'] : 0;

			return array(
				'success'      => true,
				'object_count' => $object_count,
			);
		} catch ( \Aws\Exception\AwsException $e ) {
			$error_code    = $e->getAwsErrorCode();
			$status_code   = $e->getStatusCode();
			$error_message = $this->get_friendly_error_message( $error_code, $status_code );

			return array(
				'success'      => false,
				'error'        => $error_message,
				'error_code'   => $error_code,
				'error_detail' => $e->getMessage(),
			);
		} catch ( \Exception $e ) {
			return array(
				'success'      => false,
				'error'        => 'Connection error',
				'error_detail' => $e->getMessage(),
			);
		}
	}

	/**
	 * Get friendly error message from AWS error code.
	 *
	 * @param string $error_code   AWS error code.
	 * @param int    $status_code  HTTP status code.
	 * @return string Friendly error message.
	 */
	public function get_friendly_error_message( $error_code, $status_code ) {
		$messages = array(
			'AccessDenied'          => 'Access denied - check your credentials and bucket permissions',
			'NoSuchBucket'          => 'Bucket not found - verify the bucket name exists',
			'InvalidAccessKeyId'    => 'Invalid access key - check your PERDIVES_MO_HETZNER_STORAGE_ACCESS_KEY',
			'SignatureDoesNotMatch' => 'Invalid secret key - check your PERDIVES_MO_HETZNER_STORAGE_SECRET_KEY',
			'PermanentRedirect'     => 'Wrong endpoint - check your PERDIVES_MO_HETZNER_STORAGE_ENDPOINT region',
			'RequestTimeout'        => 'Connection timeout - check your network connection',
		);

		if ( isset( $messages[ $error_code ] ) ) {
			return $messages[ $error_code ];
		}

		return sprintf( '%s (HTTP %d)', $error_code ? $error_code : 'Unknown error', $status_code );
	}
}
