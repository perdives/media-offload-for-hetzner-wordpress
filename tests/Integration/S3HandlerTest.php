<?php
/**
 * Integration tests for S3Handler
 *
 * @package HetznerOffload\Tests
 */

namespace HetznerOffload\Tests\Integration;

use HetznerOffload\Storage\S3Handler;
use PHPUnit\Framework\TestCase;
use Yoast\PHPUnitPolyfills\TestCases\TestCase as PolyfillTestCase;

/**
 * S3Handler Integration Test Suite
 *
 * Tests real S3 operations against Hetzner Object Storage.
 * Requires valid Hetzner credentials to be set in phpunit.xml or environment.
 *
 * @group integration
 * @group s3
 */
class S3HandlerTest extends PolyfillTestCase {

	/**
	 * S3Handler instance
	 *
	 * @var S3Handler
	 */
	private $handler;

	/**
	 * Test file path for uploads
	 *
	 * @var string
	 */
	private $test_file_path;

	/**
	 * S3 key for test file
	 *
	 * @var string
	 */
	private $test_s3_key;

	/**
	 * Set up test environment before each test
	 */
	protected function set_up() {
		parent::set_up();

		// Create a temporary test file
		$this->test_file_path = sys_get_temp_dir() . '/hetzner-test-' . uniqid() . '.txt';
		file_put_contents( $this->test_file_path, 'Test content for Hetzner Offload: ' . time() );

		// Generate unique S3 key for this test
		// Note: upload_file() uses full key with 'uploads/', but get_url() expects key without 'uploads/'
		$this->test_s3_key = 'uploads/tests/phpunit-test-' . uniqid() . '.txt';

		// Initialize S3Handler
		$this->handler = new S3Handler();
	}

	/**
	 * Clean up after each test
	 */
	protected function tear_down() {
		// Clean up test file from local filesystem
		if ( file_exists( $this->test_file_path ) ) {
			unlink( $this->test_file_path );
		}

		// Clean up test file from S3
		if ( $this->handler && $this->handler->is_initialized() ) {
			$this->handler->delete_object( $this->test_s3_key );
		}

		parent::tear_down();
	}

	/**
	 * Test: S3Handler initialization with valid credentials
	 *
	 * Verifies that S3Handler can successfully initialize when
	 * all required constants are defined.
	 */
	public function test_initialization_success() {
		$this->assertTrue(
			$this->handler->is_initialized(),
			'S3Handler should initialize successfully with valid credentials. ' .
			'Errors: ' . implode( ', ', $this->handler->get_init_errors() )
		);

		$this->assertEmpty(
			$this->handler->get_init_errors(),
			'S3Handler should have no initialization errors'
		);

		$this->assertNotNull(
			$this->handler->get_client(),
			'S3 client should be instantiated'
		);

		$this->assertNotEmpty(
			$this->handler->get_bucket(),
			'Bucket name should be set'
		);
	}

	/**
	 * Test: S3Handler initialization without credentials
	 *
	 * Verifies that S3Handler fails gracefully when credentials are missing.
	 */
	public function test_initialization_without_credentials() {
		// This test requires running separately without credentials set
		// Skipping in normal test runs
		$this->markTestSkipped(
			'This test requires running without credentials. ' .
			'Run manually by unsetting HETZNER_* env vars.'
		);
	}

	/**
	 * Test: Upload file to S3
	 *
	 * Verifies that files can be successfully uploaded to Hetzner Object Storage.
	 */
	public function test_upload_file() {
		$this->skip_if_not_initialized();

		$result = $this->handler->upload_file( $this->test_file_path, $this->test_s3_key );

		$this->assertTrue(
			$result,
			'File upload should succeed'
		);
	}

	/**
	 * Test: Upload non-existent file fails
	 *
	 * Verifies that uploading a non-existent file returns false.
	 */
	public function test_upload_nonexistent_file() {
		$this->skip_if_not_initialized();

		$result = $this->handler->upload_file(
			'/path/to/nonexistent/file.txt',
			'uploads/tests/should-not-exist.txt' // upload_file uses full S3 key with uploads/
		);

		$this->assertFalse(
			$result,
			'Uploading non-existent file should fail'
		);
	}

	/**
	 * Test: Check if object exists on S3
	 *
	 * Verifies that we can check for file existence on S3.
	 */
	public function test_object_exists() {
		$this->skip_if_not_initialized();

		// Upload test file first
		$this->handler->upload_file( $this->test_file_path, $this->test_s3_key );

		// Wait briefly for S3 consistency
		sleep( 1 );

		$exists = $this->handler->object_exists( $this->test_s3_key );

		$this->assertTrue(
			$exists,
			'Uploaded object should exist on S3'
		);
	}

	/**
	 * Test: Check non-existent object returns false
	 *
	 * Verifies that checking for a non-existent object returns false.
	 */
	public function test_object_not_exists() {
		$this->skip_if_not_initialized();

		$exists = $this->handler->object_exists( 'uploads/tests/does-not-exist-' . uniqid() . '.txt' );

		$this->assertFalse(
			$exists,
			'Non-existent object should return false'
		);
	}

	/**
	 * Test: Delete object from S3
	 *
	 * Verifies that objects can be successfully deleted from S3.
	 */
	public function test_delete_object() {
		$this->skip_if_not_initialized();

		// Upload test file first
		$this->handler->upload_file( $this->test_file_path, $this->test_s3_key );

		// Wait briefly for S3 consistency
		sleep( 1 );

		// Delete the object
		$result = $this->handler->delete_object( $this->test_s3_key );

		$this->assertTrue(
			$result,
			'Object deletion should succeed'
		);

		// Verify it no longer exists
		sleep( 1 );
		$exists = $this->handler->object_exists( $this->test_s3_key );

		$this->assertFalse(
			$exists,
			'Deleted object should no longer exist on S3'
		);
	}

	/**
	 * Test: Delete non-existent object succeeds
	 *
	 * S3 delete operations are idempotent, so deleting non-existent
	 * objects should return true.
	 */
	public function test_delete_nonexistent_object() {
		$this->skip_if_not_initialized();

		$result = $this->handler->delete_object( 'uploads/tests/does-not-exist-' . uniqid() . '.txt' );

		// AWS S3 deleteObject is idempotent and returns success even if object doesn't exist
		$this->assertTrue(
			$result,
			'Deleting non-existent object should succeed (idempotent operation)'
		);
	}

	/**
	 * Test: List objects with prefix
	 *
	 * Verifies that we can list objects from S3 with a given prefix.
	 */
	public function test_list_objects() {
		$this->skip_if_not_initialized();

		// Upload a test file
		$this->handler->upload_file( $this->test_file_path, $this->test_s3_key );

		// Wait briefly for S3 consistency
		sleep( 1 );

		// List objects with test prefix
		$objects = $this->handler->list_objects( 'uploads/tests/' );

		$this->assertIsArray(
			$objects,
			'list_objects should return an array'
		);

		$this->assertNotEmpty(
			$objects,
			'list_objects should return at least our test object'
		);

		// Check that our test object is in the list
		$found = false;
		foreach ( $objects as $object ) {
			if ( isset( $object['Key'] ) && $object['Key'] === $this->test_s3_key ) {
				$found = true;
				break;
			}
		}

		$this->assertTrue(
			$found,
			'Our test object should be in the list'
		);
	}

	/**
	 * Test: Get URL for object
	 *
	 * Verifies that get_url returns a valid URL format.
	 */
	public function test_get_url() {
		$this->skip_if_not_initialized();

		$key = '2024/12/test-image.jpg';
		$url = $this->handler->get_url( $key );

		$this->assertIsString(
			$url,
			'get_url should return a string'
		);

		$this->assertStringStartsWith(
			'https://',
			$url,
			'URL should start with https://'
		);

		$this->assertStringContainsString(
			'uploads/',
			$url,
			'URL should contain uploads/ path'
		);

		$this->assertStringContainsString(
			$key,
			$url,
			'URL should contain the file key'
		);
	}

	/**
	 * Test: Get URL with CDN override
	 *
	 * Verifies that CDN URL constant overrides default URL.
	 */
	public function test_get_url_with_cdn() {
		$this->skip_if_not_initialized();

		if ( ! defined( 'PERDIVES_MO_HETZNER_STORAGE_CDN_URL' ) || ! PERDIVES_MO_HETZNER_STORAGE_CDN_URL ) {
			$this->markTestSkipped( 'PERDIVES_MO_HETZNER_STORAGE_CDN_URL not configured' );
		}

		$key = '2024/12/test-image.jpg';
		$url = $this->handler->get_url( $key );

		$this->assertStringStartsWith(
			PERDIVES_MO_HETZNER_STORAGE_CDN_URL,
			$url,
			'URL should start with CDN URL when configured'
		);
	}

	/**
	 * Test: Uploaded file is publicly accessible via HTTP
	 *
	 * Verifies that uploaded files can be downloaded via their public URL.
	 * Tests both direct S3 URL and CDN URL if configured.
	 */
	public function test_file_is_accessible_via_http() {
		$this->skip_if_not_initialized();

		// Create test content
		$test_content = 'Test content for HTTP accessibility: ' . uniqid();
		file_put_contents( $this->test_file_path, $test_content );

		// Upload file
		$upload_result = $this->handler->upload_file( $this->test_file_path, $this->test_s3_key );
		$this->assertTrue( $upload_result, 'File upload should succeed' );

		// Wait for S3 consistency and CDN propagation
		sleep( 2 );

		// Get the public URL (strip 'uploads/' prefix as get_url() adds it back)
		$relative_key = preg_replace( '#^uploads/#', '', $this->test_s3_key );
		$url = $this->handler->get_url( $relative_key );

		// Make HTTP request to verify file is accessible
		$response = $this->fetch_url( $url );

		$this->assertNotFalse(
			$response,
			'HTTP request should succeed. URL: ' . $url
		);

		$this->assertEquals(
			$test_content,
			$response,
			'Downloaded content should match uploaded content'
		);
	}

	/**
	 * Test: File accessible via CDN URL
	 *
	 * Specifically tests CDN URL accessibility if configured.
	 */
	public function test_file_accessible_via_cdn() {
		$this->skip_if_not_initialized();

		if ( ! defined( 'PERDIVES_MO_HETZNER_STORAGE_CDN_URL' ) || ! PERDIVES_MO_HETZNER_STORAGE_CDN_URL ) {
			$this->markTestSkipped( 'PERDIVES_MO_HETZNER_STORAGE_CDN_URL not configured' );
		}

		// Create test content
		$test_content = 'CDN test content: ' . uniqid();
		file_put_contents( $this->test_file_path, $test_content );

		// Upload file
		$upload_result = $this->handler->upload_file( $this->test_file_path, $this->test_s3_key );
		$this->assertTrue( $upload_result, 'File upload should succeed' );

		// Wait for S3 consistency and CDN propagation
		sleep( 3 );

		// Get the CDN URL (strip 'uploads/' prefix as get_url() adds it back)
		$relative_key = preg_replace( '#^uploads/#', '', $this->test_s3_key );
		$cdn_url = $this->handler->get_url( $relative_key );

		// Verify URL uses CDN
		$this->assertStringStartsWith(
			PERDIVES_MO_HETZNER_STORAGE_CDN_URL,
			$cdn_url,
			'URL should use CDN domain'
		);

		// Make HTTP request to CDN URL
		$response = $this->fetch_url( $cdn_url );

		$this->assertNotFalse(
			$response,
			'CDN URL should be accessible. URL: ' . $cdn_url
		);

		$this->assertEquals(
			$test_content,
			$response,
			'Content from CDN should match uploaded content'
		);
	}

	/**
	 * Test: Full workflow - upload, verify, delete
	 *
	 * Integration test that runs through a complete file lifecycle.
	 */
	public function test_full_workflow() {
		$this->skip_if_not_initialized();

		// 1. Verify file doesn't exist initially
		$exists = $this->handler->object_exists( $this->test_s3_key );
		$this->assertFalse( $exists, 'File should not exist initially' );

		// 2. Upload file
		$upload_result = $this->handler->upload_file( $this->test_file_path, $this->test_s3_key );
		$this->assertTrue( $upload_result, 'Upload should succeed' );

		// Wait for S3 consistency
		sleep( 1 );

		// 3. Verify file exists
		$exists = $this->handler->object_exists( $this->test_s3_key );
		$this->assertTrue( $exists, 'File should exist after upload' );

		// 4. Get URL
		$relative_key = preg_replace( '#^uploads/#', '', $this->test_s3_key );
		$url = $this->handler->get_url( $relative_key );
		$this->assertStringContainsString( $relative_key, $url, 'URL should contain key' );

		// 5. Verify it appears in list
		$objects = $this->handler->list_objects( 'uploads/tests/' );
		$found = false;
		foreach ( $objects as $object ) {
			if ( isset( $object['Key'] ) && $object['Key'] === $this->test_s3_key ) {
				$found = true;
				break;
			}
		}
		$this->assertTrue( $found, 'File should appear in object listing' );

		// 6. Delete file
		$delete_result = $this->handler->delete_object( $this->test_s3_key );
		$this->assertTrue( $delete_result, 'Delete should succeed' );

		// Wait for S3 consistency
		sleep( 1 );

		// 7. Verify file no longer exists
		$exists = $this->handler->object_exists( $this->test_s3_key );
		$this->assertFalse( $exists, 'File should not exist after deletion' );
	}

	/**
	 * Test: File integrity verification with SHA256
	 *
	 * Verifies that uploaded files maintain integrity by comparing
	 * SHA256 hashes of original and downloaded content.
	 */
	public function test_file_integrity_with_sha256() {
		$this->skip_if_not_initialized();

		// Create test content (larger content for better hash testing)
		$test_content = str_repeat( 'File integrity test content: ' . uniqid() . "\n", 100 );
		file_put_contents( $this->test_file_path, $test_content );

		// Calculate SHA256 hash of original file
		$original_hash = hash_file( 'sha256', $this->test_file_path );

		// Upload file
		$upload_result = $this->handler->upload_file( $this->test_file_path, $this->test_s3_key );
		$this->assertTrue( $upload_result, 'File upload should succeed' );

		// Wait for S3 consistency
		sleep( 2 );

		// Get the public URL and download file (strip 'uploads/' prefix as get_url() adds it back)
		$relative_key = preg_replace( '#^uploads/#', '', $this->test_s3_key );
		$url = $this->handler->get_url( $relative_key );
		$downloaded_content = $this->fetch_url( $url );

		$this->assertNotFalse(
			$downloaded_content,
			'File should be downloadable from: ' . $url
		);

		// Calculate SHA256 hash of downloaded content
		$downloaded_hash = hash( 'sha256', $downloaded_content );

		// Compare hashes
		$this->assertEquals(
			$original_hash,
			$downloaded_hash,
			'SHA256 hash of downloaded file should match original file. ' .
			'Original: ' . $original_hash . ', Downloaded: ' . $downloaded_hash
		);

		// Also verify content matches exactly
		$this->assertEquals(
			$test_content,
			$downloaded_content,
			'Downloaded content should match original content exactly'
		);
	}

	/**
	 * Helper: Fetch URL content via HTTP
	 *
	 * @param string $url URL to fetch.
	 * @return string|false Content on success, false on failure.
	 */
	private function fetch_url( $url ) {
		// Try file_get_contents first
		$context = stream_context_create(
			array(
				'http' => array(
					'timeout' => 10,
					'ignore_errors' => true,
				),
			)
		);

		$content = @file_get_contents( $url, false, $context );

		// Check if request was successful
		if ( $content !== false ) {
			return $content;
		}

		// Fallback to cURL if available
		if ( function_exists( 'curl_init' ) ) {
			$ch = curl_init( $url );
			curl_setopt( $ch, CURLOPT_RETURNTRANSFER, true );
			curl_setopt( $ch, CURLOPT_TIMEOUT, 10 );
			curl_setopt( $ch, CURLOPT_FOLLOWLOCATION, true );

			$content = curl_exec( $ch );
			$http_code = curl_getinfo( $ch, CURLINFO_HTTP_CODE );
			curl_close( $ch );

			if ( $http_code === 200 && $content !== false ) {
				return $content;
			}
		}

		return false;
	}

	/**
	 * Helper: Skip test if S3Handler is not initialized
	 *
	 * @return void
	 */
	private function skip_if_not_initialized() {
		if ( ! $this->handler->is_initialized() ) {
			$errors = implode( ', ', $this->handler->get_init_errors() );
			$this->markTestSkipped(
				'S3Handler not initialized. Please configure Hetzner credentials. Errors: ' . $errors
			);
		}
	}
}
