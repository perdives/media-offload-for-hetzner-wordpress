<?php
/**
 * S3 Storage Handler
 *
 * @package HetznerOffload
 */

namespace HetznerOffload\Storage;

use Aws\S3\S3Client;
use Aws\Exception\AwsException;
use Exception;

/**
 * Handles all S3 storage operations for the plugin.
 */
class S3Handler {

	/**
	 * S3 Client instance
	 *
	 * @var S3Client|null
	 */
	private $s3_client;

	/**
	 * S3 bucket name
	 *
	 * @var string
	 */
	private $bucket;

	/**
	 * S3 region
	 *
	 * @var string
	 */
	private $region;

	/**
	 * Whether the S3 client is initialized
	 *
	 * @var bool
	 */
	private $is_initialized = false;

	/**
	 * Initialization error messages
	 *
	 * @var array
	 */
	private $init_errors = array();

	/**
	 * Constructor
	 */
	public function __construct() {
		$this->initialize();
	}

	/**
	 * Initialize the S3 client
	 *
	 * @return bool Whether initialization was successful.
	 */
	private function initialize() {
		$this->is_initialized = false;
		$this->init_errors    = array();

		// Check for required configuration constants.
		$required_constants = array(
			'PERDIVES_MO_HETZNER_STORAGE_ACCESS_KEY' => 'Access Key',
			'PERDIVES_MO_HETZNER_STORAGE_SECRET_KEY' => 'Secret Key',
			'PERDIVES_MO_HETZNER_STORAGE_BUCKET'     => 'Bucket',
			'PERDIVES_MO_HETZNER_STORAGE_ENDPOINT'   => 'Endpoint',
		);

		foreach ( $required_constants as $constant => $label ) {
			if ( ! defined( $constant ) || ! constant( $constant ) ) {
				$this->init_errors[] = sprintf( 'Hetzner Offload: %s is not defined or empty.', $constant );
			}
		}

		if ( ! empty( $this->init_errors ) ) {
			return false;
		}

		// Initialize S3 client.
		try {
			$this->region    = 'eu-central-1';
			$this->bucket    = PERDIVES_MO_HETZNER_STORAGE_BUCKET;
			$this->s3_client = new S3Client(
				array(
					'version'                 => 'latest',
					'region'                  => $this->region,
					'endpoint'                => 'https://' . PERDIVES_MO_HETZNER_STORAGE_ENDPOINT,
					'credentials'             => array(
						'key'    => PERDIVES_MO_HETZNER_STORAGE_ACCESS_KEY,
						'secret' => PERDIVES_MO_HETZNER_STORAGE_SECRET_KEY,
					),
					'use_path_style_endpoint' => true,
				)
			);

			$this->is_initialized = true;
			return true;
		} catch ( Exception $e ) {
			$this->init_errors[] = 'Hetzner Offload: Failed to initialize S3 client: ' . $e->getMessage();
			return false;
		}
	}

	/**
	 * Check if S3 client is initialized
	 *
	 * @return bool
	 */
	public function is_initialized() {
		return $this->is_initialized;
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
	 * Get S3 client instance
	 *
	 * @return S3Client|null
	 */
	public function get_client() {
		return $this->s3_client;
	}

	/**
	 * Get bucket name
	 *
	 * @return string
	 */
	public function get_bucket() {
		return $this->bucket;
	}

	/**
	 * Upload a file to S3
	 *
	 * @param string $local_path Local file path.
	 * @param string $s3_key     S3 object key.
	 * @return bool Whether upload was successful.
	 */
	public function upload_file( $local_path, $s3_key ) {
		if ( ! $this->is_initialized || ! file_exists( $local_path ) ) {
			return false;
		}

		try {
			$this->s3_client->putObject(
				array(
					'Bucket' => $this->bucket,
					'Key'    => $s3_key,
					'Body'   => fopen( $local_path, 'rb' ),
					'ACL'    => 'public-read',
				)
			);
			return true;
		} catch ( AwsException $e ) {
			return false;
		}
	}

	/**
	 * Delete an object from S3
	 *
	 * @param string $s3_key S3 object key.
	 * @return bool Whether deletion was successful.
	 */
	public function delete_object( $s3_key ) {
		if ( ! $this->is_initialized ) {
			return false;
		}

		try {
			$this->s3_client->deleteObject(
				array(
					'Bucket' => $this->bucket,
					'Key'    => $s3_key,
				)
			);
			return true;
		} catch ( AwsException $e ) {
			return false;
		}
	}

	/**
	 * Check if an object exists on S3
	 *
	 * @param string $s3_key S3 object key.
	 * @return bool
	 */
	public function object_exists( $s3_key ) {
		if ( ! $this->is_initialized ) {
			return false;
		}

		try {
			return $this->s3_client->doesObjectExist( $this->bucket, $s3_key );
		} catch ( AwsException $e ) {
			return false;
		}
	}

	/**
	 * Get S3 URL for a file
	 *
	 * @param string $key File key (without 'uploads/' prefix).
	 * @return string
	 */
	public function get_url( $key ) {
		$base_url = '';

		// Allow overriding with CDN URL.
		if ( defined( 'PERDIVES_MO_HETZNER_STORAGE_CDN_URL' ) && PERDIVES_MO_HETZNER_STORAGE_CDN_URL ) {
			$base_url = rtrim( PERDIVES_MO_HETZNER_STORAGE_CDN_URL, '/' );
		} else {
			$base_url = 'https://' . $this->bucket . '.' . PERDIVES_MO_HETZNER_STORAGE_ENDPOINT;
		}

		return $base_url . '/uploads/' . ltrim( $key, '/' );
	}

	/**
	 * Get all S3 objects with a given prefix
	 *
	 * @param string $prefix Object key prefix.
	 * @return array Array of objects or empty array on error.
	 */
	public function list_objects( $prefix = 'uploads/' ) {
		if ( ! $this->is_initialized ) {
			return array();
		}

		$objects = array();
		try {
			$iterator = $this->s3_client->getIterator(
				'ListObjectsV2',
				array(
					'Bucket' => $this->bucket,
					'Prefix' => $prefix,
				)
			);

			foreach ( $iterator as $object ) {
				$objects[] = $object;
			}
		} catch ( AwsException $e ) {
			return array();
		}

		return $objects;
	}
}
