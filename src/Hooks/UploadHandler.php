<?php
/**
 * Upload Handler
 *
 * @package HetznerOffload
 */

namespace HetznerOffload\Hooks;

use HetznerOffload\Storage\S3Handler;

/**
 * Handles file uploads and deletions for WordPress attachments.
 */
class UploadHandler {

	/**
	 * S3 Handler instance
	 *
	 * @var S3Handler
	 */
	private $s3_handler;

	/**
	 * Whether local file deletion is enabled
	 *
	 * @var bool
	 */
	private $delete_local_files = false;

	/**
	 * Constructor
	 *
	 * @param S3Handler $s3_handler S3 handler instance.
	 */
	public function __construct( S3Handler $s3_handler ) {
		$this->s3_handler         = $s3_handler;
		$this->delete_local_files = defined( 'PERDIVES_MO_OFFLOAD_ENABLED' ) && PERDIVES_MO_OFFLOAD_ENABLED === true;
	}

	/**
	 * Register WordPress hooks
	 */
	public function register_hooks() {
		if ( ! $this->s3_handler->is_initialized() ) {
			return;
		}

		add_filter( 'wp_update_attachment_metadata', array( $this, 'handle_upload' ), 20, 2 );

		if ( $this->delete_local_files ) {
			add_action( 'delete_attachment', array( $this, 'handle_delete' ) );
		}
	}

	/**
	 * Handle attachment upload to S3
	 *
	 * @param array $data          Attachment metadata.
	 * @param int   $attachment_id Attachment ID.
	 * @return array Unmodified metadata.
	 */
	public function handle_upload( $data, $attachment_id ) {
		if ( ! $this->s3_handler->is_initialized() ) {
			return $data;
		}

		$wp_upload_dir    = wp_upload_dir();
		$base_upload_path = $wp_upload_dir['basedir'];
		$uploaded_files   = array();

		// Get primary file path.
		$primary_wp_meta_path = ! empty( $data['file'] ) ? $data['file'] : get_post_meta( $attachment_id, '_wp_attached_file', true );
		if ( ! $primary_wp_meta_path ) {
			return $data;
		}

		$local_primary_path = $base_upload_path . '/' . $primary_wp_meta_path;
		$s3_primary_key     = $this->s3_handler->path_to_s3_key( $local_primary_path );

		// Upload primary file.
		if ( file_exists( $local_primary_path ) && $this->s3_handler->upload_file( $local_primary_path, $s3_primary_key ) ) {
			$uploaded_files[] = $local_primary_path;
		}

		// Upload true original if different (e.g., -scaled images).
		$true_original_path = wp_get_original_image_path( $attachment_id );
		if ( $true_original_path && $true_original_path !== $local_primary_path ) {
			$s3_original_key = $this->s3_handler->path_to_s3_key( $true_original_path );

			if ( file_exists( $true_original_path ) && $this->s3_handler->upload_file( $true_original_path, $s3_original_key ) ) {
				$uploaded_files[] = $true_original_path;
			}
		}

		// Upload thumbnails.
		if ( isset( $data['sizes'] ) && is_array( $data['sizes'] ) ) {
			$primary_file_dir = dirname( $primary_wp_meta_path );
			if ( $primary_file_dir === '.' ) {
				$primary_file_dir = '';
			}

			foreach ( $data['sizes'] as $size_name => $size_info ) {
				if ( empty( $size_info['file'] ) ) {
					continue;
				}

				$thumbnail_filename = $size_info['file'];
				$local_thumb_path   = $base_upload_path . '/' . ( $primary_file_dir ? $primary_file_dir . '/' : '' ) . $thumbnail_filename;
				$s3_thumb_key       = $this->s3_handler->path_to_s3_key( $local_thumb_path );

				if ( file_exists( $local_thumb_path ) && $this->s3_handler->upload_file( $local_thumb_path, $s3_thumb_key ) ) {
					$uploaded_files[] = $local_thumb_path;
				}
			}
		}

		// Delete local files if enabled.
		if ( $this->delete_local_files ) {
			foreach ( array_unique( $uploaded_files ) as $local_file ) {
				if ( file_exists( $local_file ) ) {
					wp_delete_file( $local_file );
				}
			}
		}

		return $data;
	}

	/**
	 * Handle attachment deletion from S3
	 *
	 * @param int $attachment_id Attachment ID.
	 */
	public function handle_delete( $attachment_id ) {
		if ( ! $this->delete_local_files ) {
			return;
		}

		$wp_meta_path = get_post_meta( $attachment_id, '_wp_attached_file', true );
		if ( ! $wp_meta_path ) {
			return;
		}

		$wp_upload_dir = wp_upload_dir();

		// Delete main file.
		$local_main_path = $wp_upload_dir['basedir'] . '/' . $wp_meta_path;
		$s3_main_key     = $this->s3_handler->path_to_s3_key( $local_main_path );
		$this->s3_handler->delete_object( $s3_main_key );

		// Delete thumbnails.
		$metadata = wp_get_attachment_metadata( $attachment_id );
		if ( isset( $metadata['sizes'] ) && is_array( $metadata['sizes'] ) ) {
			$base_dir = dirname( $wp_meta_path );
			if ( $base_dir === '.' || $base_dir === DIRECTORY_SEPARATOR ) {
				$base_dir = '';
			} else {
				$base_dir .= '/';
			}

			foreach ( $metadata['sizes'] as $size_name => $size_info ) {
				if ( empty( $size_info['file'] ) ) {
					continue;
				}

				$thumbnail_path      = $base_dir . $size_info['file'];
				$local_thumbnail_path = $wp_upload_dir['basedir'] . '/' . $thumbnail_path;
				$s3_thumb_key        = $this->s3_handler->path_to_s3_key( $local_thumbnail_path );
				$this->s3_handler->delete_object( $s3_thumb_key );
			}
		}

		// Delete true original if exists.
		$original_image = wp_get_original_image_path( $attachment_id );
		if ( $original_image ) {
			$relative_original = str_replace( $wp_upload_dir['basedir'] . '/', '', $original_image );
			if ( $relative_original !== $wp_meta_path ) {
				$s3_original_key = $this->s3_handler->path_to_s3_key( $original_image );
				$this->s3_handler->delete_object( $s3_original_key );
			}
		}
	}
}
