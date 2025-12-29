<?php
/**
 * URL Rewriter
 *
 * @package HetznerOffload
 */

namespace HetznerOffload\Hooks;

use HetznerOffload\Storage\S3Handler;

/**
 * Handles URL rewriting for media files to point to S3.
 */
class UrlRewriter {

	/**
	 * S3 Handler instance
	 *
	 * @var S3Handler
	 */
	private $s3_handler;

	/**
	 * Whether URL rewriting is enabled
	 *
	 * @var bool
	 */
	private $enabled = false;

	/**
	 * Constructor
	 *
	 * @param S3Handler $s3_handler S3 handler instance.
	 */
	public function __construct( S3Handler $s3_handler ) {
		$this->s3_handler = $s3_handler;
		$this->enabled    = defined( 'PERDIVES_MO_OFFLOAD_ENABLED' ) && PERDIVES_MO_OFFLOAD_ENABLED === true;
	}

	/**
	 * Register WordPress hooks
	 */
	public function register_hooks() {
		if ( ! $this->enabled ) {
			return;
		}

		add_filter( 'wp_get_attachment_url', array( $this, 'filter_attachment_url' ), 10, 2 );
		add_filter( 'wp_calculate_image_srcset', array( $this, 'filter_image_srcset' ), 10, 5 );
	}

	/**
	 * Filter attachment URL to use S3
	 *
	 * @param string $url           Attachment URL.
	 * @param int    $attachment_id Attachment ID.
	 * @return string Filtered URL.
	 */
	public function filter_attachment_url( $url, $attachment_id ) {
		if ( ! $this->enabled ) {
			return $url;
		}

		$file = get_post_meta( $attachment_id, '_wp_attached_file', true );
		if ( $file ) {
			return $this->s3_handler->get_url( $file );
		}

		return $url;
	}

	/**
	 * Filter image srcset to use S3 URLs
	 *
	 * @param array  $sources       Image srcset sources.
	 * @param array  $size_array    Image size array.
	 * @param string $image_src     Image source URL.
	 * @param array  $image_meta    Image metadata.
	 * @param int    $attachment_id Attachment ID.
	 * @return array Filtered srcset sources.
	 */
	public function filter_image_srcset( $sources, $size_array, $image_src, $image_meta, $attachment_id ) {
		if ( ! $this->enabled || empty( $sources ) ) {
			return $sources;
		}

		$original_file_key = get_post_meta( $attachment_id, '_wp_attached_file', true );
		if ( ! $original_file_key ) {
			return $sources;
		}

		$base_dir = dirname( $original_file_key );
		if ( $base_dir === '.' || $base_dir === DIRECTORY_SEPARATOR ) {
			$base_dir = '';
		} else {
			$base_dir .= '/';
		}

		$original_basename = basename( $original_file_key );

		foreach ( $sources as $width => &$source_data ) {
			$source_url_basename = basename( wp_parse_url( $source_data['url'], PHP_URL_PATH ) );
			$wp_meta_path        = '';

			if ( $source_url_basename === $original_basename ) {
				$wp_meta_path = $original_file_key;
			} elseif ( isset( $image_meta['sizes'] ) && is_array( $image_meta['sizes'] ) ) {
				foreach ( $image_meta['sizes'] as $size_name => $size_info ) {
					if ( isset( $size_info['file'] ) && $size_info['file'] === $source_url_basename ) {
						$wp_meta_path = $base_dir . $size_info['file'];
						break;
					}
				}
			}

			if ( ! empty( $wp_meta_path ) ) {
				$source_data['url'] = $this->s3_handler->get_url( $wp_meta_path );
			}
		}

		return $sources;
	}
}
