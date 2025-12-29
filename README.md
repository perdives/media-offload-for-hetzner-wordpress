# Hetzner Offload

A WordPress plugin that offloads media files to Hetzner's S3-compatible storage.

[![Download](https://img.shields.io/badge/Download-Latest%20Release-brightgreen)](https://github.com/mklasen/hetzner-offload/releases/latest/download/media-offload-for-hetzner.zip)

## Requirements

- PHP 7.4 or higher
- WordPress 5.0 or higher

## Installation for End Users

1.  **Download the Plugin:** Click the download badge above to get the latest `media-offload-for-hetzner.zip` file.
2.  **Install via WordPress Admin:**
    *   In your WordPress admin dashboard, navigate to `Plugins` > `Add New`.
    *   Click the `Upload Plugin` button at the top.
    *   Choose the `media-offload-for-hetzner.zip` file you downloaded and click `Install Now`.
3.  **Activate the Plugin:** Once installed, click `Activate Plugin`.

## Creating a Hetzner Object Storage Bucket

Before you can configure the plugin, you need to create an Object Storage Bucket in your Hetzner Cloud Console.

1.  **Navigate to Object Storage:** Log in to your [Hetzner Cloud Console](https://console.hetzner.cloud/). In your project, select "Object Storage" from the left-hand menu.
2.  **Create a New Bucket:** Click on the "Create Bucket" button.
3.  **Configure Your Bucket:**
    *   **Location:** Choose the data center location for your bucket. For smaller projects, any location is fine. For larger projects, consider the location closest to your users or your server.
    *   **Name/URL:** Enter a unique name for your bucket. This name must be unique across all Hetzner Object Storage, not just your account. It will also be part of the URL to access your files.
    *   **Visibility:** Set this to **Public**. This allows files to be read directly via their public URL by anyone (e.g., for images and other media on your website), which is typically needed for website assets. Write access (e.g., uploading or deleting files by the plugin) will still require S3 keys for security.
4.  **Create & Buy:** Review your settings and click "Create & Buy now".
5.  **Generate Access Keys:**
    *   Go to your newly created bucket
    *   Click "Actions ▼" and click "Generate credentials", enter a name and click "Generate credentials"
    *   You'll receive an Access Key and Secret Key. Store these securely - especially the Secret Key, as it won't be shown again.
    *   Add these keys in your `wp-config.php`, as described below.

## Configuration

Add the following constants to your `wp-config.php` file:

```php
define('PERDIVES_MO_HETZNER_STORAGE_ENDPOINT', '{bucket_name}.fsn1.your-objectstorage.com');
define('PERDIVES_MO_HETZNER_STORAGE_ACCESS_KEY', 'your-access-key');
define('PERDIVES_MO_HETZNER_STORAGE_SECRET_KEY', 'your-secret-key');
define('PERDIVES_MO_HETZNER_STORAGE_BUCKET', 'your-bucket-name');

// If false or not defined, media URLs will not be rewritten, and new uploads/deletions won't be automatically offloaded.
// define('PERDIVES_MO_OFFLOAD_ENABLED', true);

// Optional: Use a CDN URL for serving files.
// define('PERDIVES_MO_HETZNER_STORAGE_CDN_URL', 'https://your-cdn-domain.com');
```

Replace the placeholder values with your Hetzner storage credentials, bucket name, endpoint, and optionally your region and CDN URL.
For the plugin to automatically rewrite media URLs and handle new uploads/deletions, `PERDIVES_MO_OFFLOAD_ENABLED` must be defined and set to `true`.

## WP-CLI Commands

This plugin provides WP-CLI commands for managing your offloaded media. Before running commands that modify files, it's recommended to perform a backup and/or use the `--dry-run` flag where available.

### Synchronize Existing Media

To upload all local media files from your WordPress uploads directory to Hetzner storage that are not already present or to re-upload them:

```bash
# Uploads all local media files to S3, skipping those already present.
wp hetzner-offload sync

# Perform a dry run to see what files would be uploaded without actually uploading.
wp hetzner-offload sync --dry-run

# Force re-upload of all files, even if they seem to exist on S3.
wp hetzner-offload sync --force
```
The `sync` command processes all attachments in your media library.

### Verify Media Integrity

To check for inconsistencies between your local media library and the offloaded files in Hetzner storage, and to perform cleanup actions:

```bash
# Check for inconsistencies and report them.
wp hetzner-offload verify

# Perform a dry run to see what actions would be taken without making changes.
wp hetzner-offload verify --dry-run
```

You can use the following options with `wp hetzner-offload verify`:

*   `--reupload-missing`: If a local media file is found but is missing from S3, this option will attempt to re-upload it.
    ```bash
    wp hetzner-offload verify --reupload-missing
    ```
*   `--delete-s3-orphans`: If an S3 object is found in the `uploads/` prefix that does not correspond to any WordPress media library entry, this option will delete it from S3. **Use with extreme caution.**
    ```bash
    wp hetzner-offload verify --delete-s3-orphans
    ```
*   `--cleanup-local`: If local files exist for attachments that are confirmed to be on S3, this option will delete the local copies. This is useful if local files were not deleted after a previous sync or if you want to free up local server space.
    ```bash
    wp hetzner-offload verify --cleanup-local
    ```

You can combine these options, for example, to re-upload missing files and then clean up local copies of successfully offloaded media:
```bash
wp hetzner-offload verify --reupload-missing --cleanup-local
```

Always refer to the command's help for the most up-to-date options:
```bash
wp help hetzner-offload sync
wp help hetzner-offload verify
```

## Features

- Automatically offloads uploaded media files to Hetzner storage (when `PERDIVES_MO_OFFLOAD_ENABLED` is true)
- Rewrites media URLs to point to Hetzner storage or your CDN (when `PERDIVES_MO_OFFLOAD_ENABLED` is true)
- Maintains WordPress media library integration
- Supports image srcset for responsive images, served from Hetzner or your CDN
- Secure file handling (uploads require keys, public read access for viewing)
- WP-CLI commands for synchronization and verification

## Installation for Developers (or Contributing)

If you want to contribute to the plugin or install it manually from the source:

1. Clone this repository into your WordPress plugins directory:
   ```bash
   cd wp-content/plugins
   git clone https://github.com/your-username/hetzner-offload.git
   ```

2. Install dependencies using Composer:
   ```bash
   cd hetzner-offload
   composer install
   ```

3. Activate the plugin through the WordPress admin interface (`Plugins` > `Installed Plugins`).

## License

This plugin is licensed under the GPL v2 or later. 