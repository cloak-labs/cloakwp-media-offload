# Media Offload

Media Offload stores WordPress attachments in object storage. It supports Cloudflare R2 and S3-compatible storage. The package is free and open source.

## Why use object storage?

WordPress usually stores uploads on the web server. A new environment often needs a copy of those files. A server replacement or a deployment can also require a media transfer.

Object storage gives those environments a shared source for media files. WordPress keeps the attachment records and image metadata in its database. The plugin copies the files to object storage and supplies URLs for them.

The plugin does not replace WordPress image processing. WordPress creates the image sizes first. The plugin copies the original image, the registered sizes, and known edit backups. A background job does the copy.

## Status

This is a beta release. The local tests use a simulated S3-compatible service. We have not completed an end-to-end test with R2. Use a test site and a test bucket before production use.

Local files stay on the server by default. The plugin does not delete remote objects. Keep separate backups of the database and the objects.

## Features

- Copy new attachments to object storage with background jobs.
- Copy existing attachments with an admin screen or WP-CLI.
- Keep image sizes, attachment metadata, and WordPress image APIs.
- Serve public files from a custom media domain.
- Store private attachments in a separate private bucket.
- Check the remote bytes before local file removal.
- Restore files for core image editing and size regeneration.
- Export URL maps and change known URLs in selected database records.
- Copy an attachment to a different prefix in the same bucket.
- Add a storage adapter for a different provider.

## Requirements

- WordPress with a writable uploads directory and a working WP-Cron scheduler.
- PHP 8.2 or later.
- Composer 2 for a Composer install or a source ZIP build.
- WP-CLI for command-line operations.
- A supported object store and credentials for its S3-compatible API.
- An HTTPS media domain that serves the public bucket.

The admin scan and upload jobs use WP-Cron. A low-traffic site can need a server scheduler that runs WP-Cron. If the scheduler does not run, jobs stay pending.

## Install with Composer

This package uses the `wordpress-muplugin` Composer type. Set a Composer installer path for must-use plugins in your project.

1. Add the package to your Composer project:

   ```sh
   composer require cloakwp/media-offload:dev-main
   ```

2. Make sure that WordPress loads the plugin file.
3. Add the PHP configuration in the next section.

WordPress does not load a plugin file inside a must-use plugin subdirectory by default. Use [Roots Bedrock Autoloader](https://github.com/roots/bedrock-autoloader), or create a root-level must-use plugin stub:

```php
<?php
require_once WPMU_PLUGIN_DIR . '/media-offload/media-offload.php';
```

Your project must also load the Composer autoloader. The package does not start transfers until you register a storage adapter.

## Install from a ZIP

The GitHub source ZIP does not contain the Composer dependencies. Use a release ZIP that contains `vendor`, or build a ZIP from the source.

To build a ZIP:

1. Open the [repository](https://github.com/cloak-labs/cloakwp-media-offload).
2. Select **Code**, then select **Download ZIP**.
3. Extract the archive and rename the directory to `media-offload`.
4. Run `composer install --no-dev` in the directory.
5. Compress the `media-offload` directory as a ZIP file.
6. In WordPress, select **Plugins**, **Add New Plugin**, then **Upload Plugin**.
7. Upload the ZIP file and activate the plugin.
8. Add the PHP configuration in the next section.

A ZIP install still requires PHP configuration. Do not put storage credentials in the plugin files or the database.

## Configure a public bucket

Create a bucket and an API token with access to that bucket. Connect a custom HTTPS domain to the bucket. Set the cache rules for that domain. The `r2.dev` domain is for development and does not provide the same cache features.

Set these environment variables in the WordPress environment:

| Variable | Value |
| --- | --- |
| `R2_ENDPOINT` | The S3-compatible endpoint for the R2 account. |
| `R2_ACCESS_KEY_ID` | The access key ID for the bucket. |
| `R2_SECRET_ACCESS_KEY` | The secret key for the bucket. |
| `R2_BUCKET` | The public bucket name. |
| `MEDIA_PUBLIC_URL` | The HTTPS media domain, without a trailing object prefix. |
| `MEDIA_WRITE_PREFIX` | A prefix for the site and the environment. |

Add this code to a root-level must-use plugin. It must run before the WordPress `init` action.

```php
<?php
use Aws\S3\S3Client;
use CloakWP\MediaOffload\MediaOffload;
use CloakWP\MediaOffload\Storage\S3Storage;

add_action('plugins_loaded', static function (): void {
    $client = new S3Client([
        'version' => 'latest',
        'region' => 'auto',
        'endpoint' => getenv('R2_ENDPOINT'),
        'credentials' => [
            'key' => getenv('R2_ACCESS_KEY_ID'),
            'secret' => getenv('R2_SECRET_ACCESS_KEY'),
        ],
        'use_path_style_endpoint' => true,
    ]);

    MediaOffload::make()
        ->storage(
            new S3Storage($client, getenv('R2_BUCKET')),
            getenv('MEDIA_PUBLIC_URL'),
            getenv('MEDIA_WRITE_PREFIX'),
        )
        ->register();
});
```

For S3, use the provider endpoint and region in the client configuration. Grant the credentials only the access that the adapter needs. The adapter uses `PutObject`, `HeadObject`, and `GetObject`.

### How public URLs work

The media domain must serve the bucket root. The plugin adds the complete object key to that URL. The key contains the prefix, site ID, attachment ID, and a revision digest.

Each revision has a new key when its files change. The adapter uses conditional writes to prevent replacement of an existing object. It sends a Content-MD5 value and stores SHA-256 metadata.

The plugin reads the attachment state from WordPress metadata. It does not make object storage requests for each thumbnail in the media library.

The adapter sends one Content-MD5 checksum per upload. It tells recent AWS SDK versions not to add a second automatic checksum. Cloudflare R2 rejects a request that has both.

## Use multiple environments

Give local, staging, and production different write prefixes. For example, use `client/local`, `client/staging`, and `client/production`. Give each client a separate bucket when the access policy requires it.

On multisite, `->siteIds([2, 5])` restricts transfers and saved manifest access to those site IDs. The check also applies after `switch_to_blog()`. Use a separate allowlist for each environment.

A database copy keeps the URLs and object keys of existing attachments. New attachments use the write prefix of the current environment. The configured adapter must have read access to the source objects for restore operations.

The plugin does not copy the database. It does not move objects between buckets. It does not delete objects when a user deletes an attachment. Keep an independent backup and a restore procedure for each environment.

## Copy existing attachments

In WordPress, open **Media**, then **Offload Media**. Start the scan. The scan queues attachments in batches of 50. You can cancel the scan and resume it later.

The scan and the attachment jobs are separate. A finished scan does not mean that all attachment jobs are complete. The screen shows the scan counts and recent errors. Canceling the scan does not cancel jobs that are already in the queue.

You can also use WP-CLI:

```sh
wp media offload run --all --dry-run --limit=100
wp media offload run --all --limit=100
wp media offload run --all --limit=100 --after=123
wp media offload run 123 124
wp media offload status 123
wp media offload report --fail-on-errors
```

The command prints the last attachment ID. Use that value with `--after` for the next batch. The dry run checks the local attachment files. It does not upload files or check the media domain.

The command returns an error if an attachment fails. Correct the error, then retry the affected ID. An attachment is ready for cloud delivery after the upload and metadata checks succeed.

The report shows attachment records with manifests, stored errors, queued jobs, and whether WordPress cron is disabled. A manifest count is not proof that every attachment is current or readable. Check individual files and the job runner as well. If you disable WP-Cron, run the WordPress cron events from a reliable external scheduler.

To install the plugin without scheduling new uploads, add `->automatic(false)` before `->register()`. The explicit WP-CLI and scan operations remain available.

## Local file removal

Local file removal is off by default. Keep that default until you complete a restore and edit test with your provider. To enable removal, add `->removeLocalFiles()` before `->register()`.

The plugin schedules a separate cleanup job after a successful upload. The job checks the attachment metadata and the local file hashes. It checks for possible references from other attachment records. It downloads each remote object and checks its SHA-256 hash. Only then does it remove the local files.

You can also run cleanup for one attachment:

```sh
wp media offload cleanup 123
```

If cleanup cannot complete, some local files can remain. If a filesystem error occurs during removal, some files can already be gone. The plugin does not delete the remote objects in either case.

## Restore and edit files

To restore the files for one attachment, run:

```sh
wp media offload restore 123
```

The plugin checks the downloaded bytes. It does not replace a different local file. WordPress core image editing and image size regeneration can also request a restore.

Some third-party plugins need a local file in other code paths. Call `Offloader::restore($id)` before those operations. You can also use the `cloakwp/media-offload/needs-local-file` filter. Do not restore files for every media-library request. That can cause many remote requests and slow the page.

## Private attachments

The plugin treats attachment posts with the WordPress `private` status as private. It excludes them unless you configure a separate private adapter. The `cloakwp/media-offload/private` filter can mark other attachments as private. The `cloakwp/media-offload/eligible` filter can exclude attachments from all transfers.

WordPress and other plugins can store sensitive uploads as regular attachments. Their post status alone does not make their files private. Add a site-specific privacy filter for those files before you enable offload.

Private storage is not a complete private upload system. Before you use it for sensitive files, make sure the upload path is protected before WordPress receives a file. Also protect image edits, restores, temporary files, and old copies. Test direct requests to each path without a login. A private URL from this plugin does not block direct requests to a local file.

To add an R2 private bucket, create a second bucket without public access. Use separate credentials with access to the private bucket. Add a private adapter before `register()`:

```php
->privateStorage(new S3Storage($privateClient, getenv('R2_PRIVATE_BUCKET'), true))
->privateDirectory(getenv('WP_PRIVATE_UPLOAD_DIR'))
```

`WP_PRIVATE_UPLOAD_DIR` must name an existing directory outside the web root. It must be writable by PHP and the job runner. The plugin uses it for private restore and verification files. It rejects private local files outside that directory when this setting is present. Configure PHP's upload temporary directory outside the web root too. Make private storage persistent across deployments and include it in backups.

If the application stores a private attachment under a portable path marker, use `cloakwp/media-offload/local-path` to resolve that marker to a path inside the private directory. The application must protect the upload before it creates the attachment. This plugin does not know which arbitrary uploads are sensitive and does not move them to the private directory on its own.

The constructor rejects the same S3 bucket for public and private storage. It cannot check the bucket access policy or public domains. The private endpoint checks the user's WordPress login and edit capability. It sends private, no-store cache headers. It sends non-image documents as downloads. Before an attachment has a valid private manifest, the plugin does not return its local attachment URL or original image URL. This does not protect the local file from a direct request.

**Warning:** Protect sensitive local files before you use private storage. WordPress can write uploads into a web-accessible directory. The plugin also restores files there for editing. A private bucket does not protect a public local file. An old public object or cached URL can remain accessible after a privacy change.

The plugin does not move an attachment between public and private storage automatically. For an explicit change, use `wp media offload run <id> --allow-visibility-change`. A move to private storage does not revoke the old public object. A move to public storage can expose the file. A configured private directory may require a separate application-specific file staging step before a visibility change.

## Change old URLs

Old URLs can remain in post content, custom fields, and site options. The plugin does not change them during offload. Back up the database before you change those URLs.

1. Export a map for the old uploads base URL:

   ```sh
   wp media offload map --source-base=https://old.example.com/wp-content/uploads --out=/tmp/media-map.json --limit=1000
   ```

2. Review the JSON map.
3. Run the commands without `--apply` to see the proposed changes:

   ```sh
   wp media offload rewrite-posts --map=/tmp/media-map.json --limit=100
   wp media offload rewrite-meta --map=/tmp/media-map.json --limit=100
   wp media offload rewrite-options --map=/tmp/media-map.json --limit=100
   ```

4. Add `--apply` to each command to save the changes.
5. Use the printed `--after` cursor for each subsequent batch.
6. Check the affected content and the old URLs.

Map export is also paginated. Save each page to a different file. Check for conflicting mappings before you combine the files. The rewriter changes complete mapped URLs and JSON-escaped URLs. It handles strings and serialized arrays.

The rewriter skips object values and protected names that start with an underscore. It does not change term metadata, user metadata, external documents, CSS files, or CDN caches. Keep old URLs available until you check those sources.

## Promote an attachment

The promotion command copies an attachment to another prefix in the same configured bucket:

```sh
wp media offload promote 123 --prefix=client/production
wp media offload promote 123 --prefix=client/production --apply
```

The first command is a dry run. The second command copies the files and changes the manifest in the current database. Move the applicable database content to the target environment with your normal deployment procedure. The command does not delete the source objects.

## Storage adapters

Implement `CloakWP\MediaOffload\Contracts\Storage` for another provider. The adapter must provide these operations:

- `put`: copy a local file to a key without replacement of different bytes.
- `inspect`: return the object size and SHA-256 metadata, or `null` if the object is absent.
- `download`: copy an object to a local path and report failures with an exception.

Use a physically separate private bucket for private files. The restore and cleanup operations hash the downloaded bytes. Test an adapter's conditional writes, metadata, error responses, and download behavior before use.

## Recovery and limits

A crashed worker can leave an attachment lock in the site options table. Its name is `_cloakwp_offload_lock_<id>`. A crashed scan can leave `_cloakwp_media_offload_batch_lock`. Make sure that no worker is active before you delete a lock.

The plugin inventories files in WordPress attachment metadata. It includes the original, image sizes, and known edit backups. It does not copy unrelated files that other plugins put in uploads. It does not find orphan files or change external documents. Audit those files separately.

The plugin does not configure a Next.js frontend. Add the media domain to `remotePatterns` when your frontend uses Next.js image optimization. Check local-build URL rules and the frontend image loader for your site.

## Development tests

Run the package tests:

```sh
composer install
composer test
composer test:http
```

The HTTP test requires Node.js. It starts a temporary local server. The WordPress integration scripts in `tests/` create and delete test records. Run them only against a disposable WordPress site. Load each script with WP-CLI `eval` and its absolute path.

The local tests do not replace a test against the real provider, bucket policy, and CDN configuration.

## License

The package uses the GNU Lesser General Public License, version 3 or later. See [LICENSE](LICENSE) and [LICENSE-GPL](LICENSE-GPL).
