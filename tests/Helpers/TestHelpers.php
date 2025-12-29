<?php
/**
 * Test Helper Utilities
 *
 * @package HetznerOffload\Tests
 */

namespace HetznerOffload\Tests\Helpers;

/**
 * Provides utility methods for testing
 */
class TestHelpers {

	/**
	 * Create a temporary test file with content
	 *
	 * @param string $content File content.
	 * @param string $extension File extension (default: txt).
	 * @return string Path to created file.
	 */
	public static function create_temp_file( $content = null, $extension = 'txt' ) {
		$content  = $content ?? 'Test file content: ' . time();
		$filename = sys_get_temp_dir() . '/hetzner-test-' . uniqid() . '.' . $extension;

		file_put_contents( $filename, $content );

		return $filename;
	}

	/**
	 * Create a temporary test image file
	 *
	 * @param int $width Image width.
	 * @param int $height Image height.
	 * @param string $format Image format (png, jpg).
	 * @return string|false Path to created image or false on failure.
	 */
	public static function create_temp_image( $width = 100, $height = 100, $format = 'png' ) {
		if ( ! function_exists( 'imagecreatetruecolor' ) ) {
			return false;
		}

		$image    = imagecreatetruecolor( $width, $height );
		$filename = sys_get_temp_dir() . '/hetzner-test-image-' . uniqid() . '.' . $format;

		// Add some color to make it a valid image
		$bg_color = imagecolorallocate( $image, 255, 255, 255 );
		imagefill( $image, 0, 0, $bg_color );

		$fg_color = imagecolorallocate( $image, 0, 0, 255 );
		imagefilledrectangle( $image, $width / 4, $height / 4, 3 * $width / 4, 3 * $height / 4, $fg_color );

		if ( $format === 'png' ) {
			imagepng( $image, $filename );
		} elseif ( $format === 'jpg' || $format === 'jpeg' ) {
			imagejpeg( $image, $filename, 90 );
		}

		imagedestroy( $image );

		return file_exists( $filename ) ? $filename : false;
	}

	/**
	 * Clean up temporary files
	 *
	 * @param array $file_paths Array of file paths to delete.
	 * @return void
	 */
	public static function cleanup_temp_files( array $file_paths ) {
		foreach ( $file_paths as $file_path ) {
			if ( file_exists( $file_path ) ) {
				unlink( $file_path );
			}
		}
	}

	/**
	 * Generate unique S3 key for testing
	 *
	 * @param string $prefix Key prefix.
	 * @param string $extension File extension.
	 * @return string S3 key.
	 */
	public static function generate_s3_key( $prefix = 'uploads/tests/', $extension = 'txt' ) {
		return rtrim( $prefix, '/' ) . '/phpunit-' . uniqid() . '.' . $extension;
	}

	/**
	 * Check if Hetzner credentials are configured
	 *
	 * @return bool True if all required constants are defined.
	 */
	public static function has_hetzner_credentials() {
		$required = array(
			'PERDIVES_MO_HETZNER_STORAGE_ENDPOINT',
			'PERDIVES_MO_HETZNER_STORAGE_ACCESS_KEY',
			'PERDIVES_MO_HETZNER_STORAGE_SECRET_KEY',
			'PERDIVES_MO_HETZNER_STORAGE_BUCKET',
		);

		foreach ( $required as $constant ) {
			if ( ! defined( $constant ) || ! constant( $constant ) ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Get configuration status message
	 *
	 * @return string Status message.
	 */
	public static function get_config_status() {
		$constants = array(
			'PERDIVES_MO_HETZNER_STORAGE_ENDPOINT'   => defined( 'PERDIVES_MO_HETZNER_STORAGE_ENDPOINT' ) && HETZNER_STORAGE_ENDPOINT,
			'PERDIVES_MO_HETZNER_STORAGE_ACCESS_KEY' => defined( 'PERDIVES_MO_HETZNER_STORAGE_ACCESS_KEY' ) && HETZNER_STORAGE_ACCESS_KEY,
			'PERDIVES_MO_HETZNER_STORAGE_SECRET_KEY' => defined( 'PERDIVES_MO_HETZNER_STORAGE_SECRET_KEY' ) && HETZNER_STORAGE_SECRET_KEY,
			'PERDIVES_MO_HETZNER_STORAGE_BUCKET'     => defined( 'PERDIVES_MO_HETZNER_STORAGE_BUCKET' ) && HETZNER_STORAGE_BUCKET,
		);

		$status = array();
		foreach ( $constants as $name => $is_set ) {
			$status[] = sprintf( '%s: %s', $name, $is_set ? 'SET' : 'NOT SET' );
		}

		return implode( "\n", $status );
	}

	/**
	 * Wait for S3 eventual consistency
	 *
	 * @param int $seconds Seconds to wait.
	 * @return void
	 */
	public static function wait_for_s3_consistency( $seconds = 1 ) {
		sleep( $seconds );
	}
}
