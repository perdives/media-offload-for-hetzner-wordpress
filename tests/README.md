# Hetzner Offload - Testing Guide

This directory contains the test suite for the Hetzner Offload WordPress plugin.

## Test Types

### Integration Tests

Located in `tests/Integration/`, these tests interact with real Hetzner Object Storage to verify:

- **Connection Tests**: Validates S3Handler initialization
- **Upload Tests**: Verifies file uploads to Hetzner storage
- **Retrieval Tests**: Checks if files exist on S3
- **Deletion Tests**: Ensures files can be deleted from S3
- **Listing Tests**: Validates object listing functionality
- **Full Workflow**: End-to-end test of upload → verify → delete

## Prerequisites

1. **PHP 7.4 or higher**
2. **Composer dependencies installed**:
   ```bash
   composer install
   ```

3. **Hetzner Object Storage credentials** (for integration tests)

## Quick Start

### Install Dependencies

```bash
composer install
```

### Run All Tests

```bash
composer test
```

### Run Only Integration Tests

```bash
composer test:integration
```

### Run Tests with Coverage

```bash
composer test:coverage
```

Coverage reports will be generated in `tests/_output/coverage/`.

## Configuration

### Setting Up Hetzner Credentials for Tests

Integration tests require valid Hetzner Object Storage credentials. You can configure them in two ways:

#### Method 1: Environment Variables (Recommended)

Set the following environment variables before running tests:

```bash
export HETZNER_TEST_ENDPOINT="bucket-name.fsn1.your-objectstorage.com"
export HETZNER_TEST_ACCESS_KEY="your-access-key"
export HETZNER_TEST_SECRET_KEY="your-secret-key"
export HETZNER_TEST_BUCKET="your-bucket-name"
export HETZNER_TEST_CDN_URL="https://cdn.example.com"  # Optional

composer test:integration
```

#### Method 2: Create phpunit.xml (Local Override)

Copy the distribution file and edit it:

```bash
cp phpunit.xml.dist phpunit.xml
```

Edit `phpunit.xml` and set your credentials in the `<php>` section:

```xml
<php>
    <env name="HETZNER_TEST_ENDPOINT" value="bucket-name.fsn1.your-objectstorage.com"/>
    <env name="HETZNER_TEST_ACCESS_KEY" value="your-access-key"/>
    <env name="HETZNER_TEST_SECRET_KEY" value="your-secret-key"/>
    <env name="HETZNER_TEST_BUCKET" value="your-bucket-name"/>
    <env name="HETZNER_TEST_CDN_URL" value="https://cdn.example.com"/>
</php>
```

**Note**: `phpunit.xml` is git-ignored, so your credentials stay local.

### Using a Test Bucket

For safety, create a dedicated test bucket for running integration tests:

1. Log in to Hetzner Cloud Console
2. Navigate to Object Storage
3. Create a new bucket (e.g., `my-plugin-tests`)
4. Generate access credentials for this bucket
5. Use these credentials in your test configuration

## Running Tests Locally

### Run All Tests

```bash
vendor/bin/phpunit
```

### Run Specific Test Class

```bash
vendor/bin/phpunit tests/Integration/S3HandlerTest.php
```

### Run Specific Test Method

```bash
vendor/bin/phpunit --filter test_upload_file tests/Integration/S3HandlerTest.php
```

### Run with Verbose Output

```bash
vendor/bin/phpunit --verbose
```

### Run Without Integration Tests (Unit Tests Only)

```bash
vendor/bin/phpunit --exclude-group integration
```

## CI/CD Integration

### GitHub Actions

Tests run automatically on push and pull requests via GitHub Actions. See `.github/workflows/tests.yml`.

To enable integration tests in GitHub Actions:

1. Go to your repository Settings → Secrets and variables → Actions
2. Add the following repository secrets:
   - `HETZNER_TEST_ENDPOINT`
   - `HETZNER_TEST_ACCESS_KEY`
   - `HETZNER_TEST_SECRET_KEY`
   - `HETZNER_TEST_BUCKET`
   - `HETZNER_TEST_CDN_URL` (optional)

## Test Coverage

Generate HTML coverage reports:

```bash
composer test:coverage
```

Open `tests/_output/coverage/index.html` in your browser to view the report.

## Writing New Tests

### Integration Test Template

```php
<?php
namespace HetznerOffload\Tests\Integration;

use HetznerOffload\Storage\S3Handler;
use Yoast\PHPUnitPolyfills\TestCases\TestCase;

/**
 * @group integration
 */
class MyFeatureTest extends TestCase {

    private $handler;

    protected function set_up() {
        parent::set_up();
        $this->handler = new S3Handler();
    }

    public function test_my_feature() {
        if (!$this->handler->is_initialized()) {
            $this->markTestSkipped('S3Handler not initialized');
        }

        // Your test code here
    }
}
```

## Troubleshooting

### Tests Skip with "S3Handler not initialized"

This means your Hetzner credentials are not configured. See the Configuration section above.

### Connection Errors

- Verify your endpoint format: `bucket-name.fsn1.your-objectstorage.com`
- Check that your access key and secret key are correct
- Ensure your bucket exists and is accessible
- Check your firewall/network allows HTTPS connections to Hetzner

### Slow Tests

Integration tests interact with real S3 storage and include sleep delays for eventual consistency. This is expected behavior.

### Permission Errors

Ensure your Hetzner access credentials have the following permissions:
- `s3:PutObject` (upload files)
- `s3:GetObject` (check if files exist)
- `s3:DeleteObject` (delete files)
- `s3:ListBucket` (list objects)

## Helper Classes

### TestHelpers

Located in `tests/Helpers/TestHelpers.php`, provides utility methods:

```php
use HetznerOffload\Tests\Helpers\TestHelpers;

// Create temporary test file
$file = TestHelpers::create_temp_file('content', 'txt');

// Create test image
$image = TestHelpers::create_temp_image(800, 600, 'jpg');

// Generate unique S3 key
$key = TestHelpers::generate_s3_key('uploads/tests/', 'jpg');

// Clean up files
TestHelpers::cleanup_temp_files([$file, $image]);

// Check credentials
if (TestHelpers::has_hetzner_credentials()) {
    // Run tests
}
```

## Best Practices

1. **Always clean up**: Tests should clean up S3 objects they create
2. **Use unique keys**: Generate unique S3 keys with `uniqid()` to avoid conflicts
3. **Skip gracefully**: Use `markTestSkipped()` when credentials are missing
4. **Wait for consistency**: Use `sleep(1)` after S3 operations for eventual consistency
5. **Test isolation**: Each test should be independent and not rely on other tests

## Questions?

For issues or questions about testing, please open an issue on GitHub.
