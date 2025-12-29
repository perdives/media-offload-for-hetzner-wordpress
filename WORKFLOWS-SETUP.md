# Workflows Setup Summary

Your plugin now has a complete release automation workflow using Perdives GitHub Actions!

## What Was Set Up

### 1. Release Workflows (Using Perdives Actions) ✅

Located in `.github/workflows/`:

- **release-drafter.yml** - Creates draft releases automatically
  - Uses `perdives/wp-plugin-actions/get-plugin-version@v1`
  - Triggers on push to `release` branch

- **build-release.yml** - Builds and packages plugin
  - Uses `perdives/wp-plugin-actions/get-plugin-version@v1`
  - Uses `perdives/wp-plugin-actions/setup-wp-cli@v1`
  - Uses `perdives/wp-plugin-actions/build-plugin@v1`
  - Creates zip file and uploads to GitHub release

- **release-pr-checks.yml** - Quality checks on release PRs
  - Uses `perdives/wp-plugin-actions/phpcs-check@v1`
  - Uses `perdives/wp-plugin-actions/plugin-check@v1`
  - Comments on PR with issues

- **release-pr-changelog.yml** - Reminds about changelog updates
  - Uses `perdives/wp-plugin-actions/get-plugin-version@v1`
  - Posts checklist on release PRs

- **sync-release-to-main.yml** - Syncs release back to main after publish
  - Automatically keeps branches in sync

### 2. Template Files ✅

- `.distignore` - Excludes dev files from distribution
- `.github/release-drafter.yml` - Configures release notes

### 3. Existing Test Workflow ✅

- **tests.yml** - Runs on main/develop branches
  - PHPUnit tests across PHP 7.4-8.3
  - Integration tests with Hetzner credentials
  - PHPCS checks

## How It Works

### The Release Flow:

```
1. Work on main branch
   ↓
2. Create PR from main → release
   ↓
3. Automated checks run (PHPCS, Plugin Check)
   ↓
4. Merge PR to release
   ↓
5. Draft release created with built zip
   ↓
6. Review and publish release
   ↓
7. Release branch auto-syncs to main
```

### Key Features:

✅ **Clean workflows** - Uses Perdives actions instead of inline bash
✅ **Modular** - Same actions used across all Perdives plugins
✅ **Quality checks** - PHPCS and WordPress Plugin Check on PRs
✅ **Automated builds** - WP-CLI dist-archive creates clean zips
✅ **SHA256 checksums** - For distribution verification
✅ **Auto-sync** - Release branch stays in sync with main

## Next Steps

### 1. Push Perdives Actions to GitHub

First, publish the actions repository:

```bash
cd /home/mklasen/repositories/workflows

git init
git add .
git commit -m "Initial commit: WordPress plugin GitHub Actions for Perdives"

# Create in Perdives organization
gh repo create perdives/wp-plugin-actions --public --source=. --remote=origin --push

# Tag for versioning
git tag -a v1.0.0 -m "Initial release"
git push origin v1.0.0
git tag -a v1 -m "v1"
git push origin v1
```

### 2. Create Release Branch

```bash
cd /home/mklasen/repositories/media-offload-for-hetzner-wordpress

git checkout -b release
git push -u origin release
git checkout main
```

### 3. Test the Workflow

```bash
# Make a change (e.g., update version to 1.0.1)
# Edit media-offload-for-hetzner.php: Version: 1.0.1
# Update README.md with changelog

# Create PR to release branch
git checkout -b test-release
# ... make changes ...
git commit -am "Prepare release 1.0.1"
git push -u origin test-release

# Create PR on GitHub: test-release → release
# Watch workflows run in Actions tab
```

### 4. Verify Workflows Work

After pushing actions repo and creating release branch:

1. **PR Checks**: Open PR to release → see PHPCS and Plugin Check run
2. **Build**: Merge PR → see release draft created with zip
3. **Publish**: Publish release → see sync back to main

## Comparison to kiyoh-reviews

### Before (kiyoh-reviews approach):
```yaml
- name: Setup WP-CLI
  run: |
    curl -O https://raw.githubusercontent.com/wp-cli/builds/gh-pages/phar/wp-cli.phar
    chmod +x wp-cli.phar
    sudo mv wp-cli.phar /usr/local/bin/wp
    wp package install wp-cli/dist-archive-command --allow-root
```

### After (hetzner-offload approach):
```yaml
- name: Setup WP-CLI
  uses: perdives/wp-plugin-actions/setup-wp-cli@v1
```

**Benefits:**
- ✅ Cleaner, more readable workflows
- ✅ Reusable across all Perdives plugins
- ✅ Easier to maintain (update once, use everywhere)
- ✅ Consistent behavior across projects

## Updating kiyoh-reviews Later

To update kiyoh-reviews to use Perdives actions:

1. Replace inline bash scripts with Perdives actions
2. Update workflow files similar to hetzner-offload
3. Test thoroughly
4. Deploy

Example changes needed in `kiyoh-reviews/.github/workflows/build-release.yml`:

```yaml
# Replace this:
- name: Get version from plugin file
  run: |
    VERSION=$(grep "Version:" kiyoh-reviews.php | sed 's/.*Version: *//' | tr -d ' ')
    echo "version=$VERSION" >> $GITHUB_OUTPUT

# With this:
- name: Get version from plugin file
  uses: perdives/wp-plugin-actions/get-plugin-version@v1
  with:
    plugin_file: 'kiyoh-reviews.php'
```

## Configuration Files

### composer.json Scripts (Already Configured ✅)

```json
{
  "scripts": {
    "phpcs:check": "phpcs --standard=phpcs.xml.dist",
    "phpcs:fix": "phpcbf --standard=phpcs.xml.dist"
  }
}
```

These match what the workflows expect!

## Troubleshooting

### Actions not found error

Make sure:
1. Perdives actions repo is public
2. Tagged with v1 and v1.0.0
3. Repository name is `perdives/wp-plugin-actions`

### Build fails

Check:
1. `.distignore` exists
2. Composer dependencies install correctly
3. Plugin file has `Version:` header

### Tests fail on PR

Run locally first:
```bash
composer phpcs:check
composer phpcs:fix  # auto-fix issues
```

## Summary

You now have:
- ✅ Modern, modular workflows using Perdives actions
- ✅ Automated release process
- ✅ Quality checks on release PRs
- ✅ Clean, production-ready plugin builds
- ✅ Same setup that can be used across all Perdives plugins

Ready to use once you:
1. Push the `perdives/wp-plugin-actions` repository
2. Create the `release` branch
3. Test with a sample release
