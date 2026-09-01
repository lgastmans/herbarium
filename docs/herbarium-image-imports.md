# Herbarium image import operations

## Isolated test database

The PHPUnit configuration connects only to MySQL at `127.0.0.1:3308`, database
`dryherbarium_testing`, with user `root` and password `root`. The test bootstrap
also refuses to run when the resolved database name does not end in `_testing`.

Never point `phpunit.xml` or a test command at development or production data.
The test suite uses destructive database-refresh operations.

Start the checked-in database-only test environment with:

```bash
docker compose -f docker-compose.testing.yml up -d
docker compose -f docker-compose.testing.yml ps
```

Wait for `mysql-testing` to report healthy, then run focused or full tests:

```bash
vendor/bin/phpunit tests/Feature/OperationalHardeningConfigurationTest.php
vendor/bin/phpunit tests/Feature/ImportHerbariumImagesDiscardWarningTest.php
vendor/bin/phpunit --testsuite Unit
vendor/bin/phpunit --testsuite Feature
php artisan test --without-tty
```

Stop the isolated service without deleting its dedicated data volume:

```bash
docker compose -f docker-compose.testing.yml down
```

This Compose project starts only MySQL 8. It does not start the application,
Nginx, or phpMyAdmin, and its `dryherbarium-testing-db` volume is separate from
the development `herbarium-db` volume. If another service already owns port
3308, stop that test-only service deliberately before starting this one; never
replace or reconfigure the development database to resolve the conflict.

## Upload limits

The authoritative application limit is exactly 5 MiB per image. Livewire's
temporary-upload endpoint applies `max:5120` (KiB). The Docker PHP image uses a
6 MiB `upload_max_filesize` and 7 MiB `post_max_size`, and Nginx uses a 7 MiB
`client_max_body_size`. The transport values are slightly larger only to admit
multipart framing around a valid 5 MiB file. They are not batch-size limits:
the browser sends one image per request, never all 100 images in one request.

When deploying without the supplied containers, configure equivalent PHP and
web-server limits. Do not lower either transport layer to 5 MiB or below, and
do not remove the application's MIME/content, size, and checksum validation.
PHP GD must be built with JPEG read support as well as PNG support; the supplied
Docker image installs the Debian JPEG development library and configures GD
with `--with-jpeg` before compiling the extension.

## Web workflow and permissions

The administrator-only **Import Images** page accepts at most 100 staged JPEG
or PNG files. The files are uploaded sequentially and processed synchronously
when the administrator confirms the import. Administrators must be signed in
with a verified email. The single-image uploader remains available to verified
users under its existing authorization behavior; direct save calls still
reject guests and unverified users.

Accepted matching filenames are a numeric collection number or an `F`-prefixed
number, with `.jpg`, `.jpeg`, or `.png`, for example `123.jpg`, `F 00123.PNG`,
or `123_2.jpeg`. A positive `_n` suffix distinguishes additional images but
does not change the collection number used for matching. `_0`, extra suffixes,
paths, and unsupported extensions are invalid filenames. A valid JPEG/PNG with
an invalid filename can still be assigned manually.

- **Automatic** means one unambiguous current filename match was selected.
- **F fallback** means an unprefixed numeric filename matched one F-prefixed
  collection; it remains editable and is visibly identified.
- **Manual** means an administrator selected or overrode the collection.
- **Unmatched** means no collection number matched.
- **Ambiguous** means multiple records matched and none is chosen silently.
- **Invalid** means the filename format could not be parsed.

Permanent imports recompute matching and fetch the selected herbarium again.
An identical checksum already stored for the selected collection is skipped,
without a second file, database record, or activity entry. Other rows continue
independently after an individual skip or failure.

Staged uploads are temporary. Refreshing, closing, or leaving the page discards
the in-memory batch; the page warns while uploads are queued or staged rows
remain. Livewire may retain abandoned temporary files until its normal cleanup
cycle. Successful and duplicate rows leave staging, while retryable failed rows
remain staged and continue to trigger the warning.

## Deployment checklist

1. Back up the database and stored images.
2. Install production PHP dependencies, for example `composer install --no-dev --optimize-autoloader`.
3. Ensure `storage` and `bootstrap/cache` are writable by the PHP process.
4. Ensure the public storage link exists with `php artisan storage:link`.
5. Run reviewed migrations with the environment's normal deployment controls,
   for example `php artisan migrate --force`. Never run production migrations
   as part of test setup.
6. Build frontend assets with `npm ci` and `npm run build`.
7. Refresh application caches as appropriate, including `php artisan config:cache`,
   `php artisan route:cache`, and `php artisan view:cache`.
8. Rebuild/restart PHP and reload Nginx so the checked-in upload settings become
   effective, then verify the effective values.

The safe-import migration expands `herbarium_images.filename` from 32 to 255
characters. Its rollback attempts to restore the legacy 32-character width and
can fail when stored filenames longer than 32 characters exist. Audit and
resolve such records before rolling that migration back.

## Historical command-line workflow

The existing `php artisan herbarium:import-images {path}` command remains useful
for operator-controlled filesystem imports. It is separate from the web batch
workflow described above; the web workflow provides temporary review,
assignment overrides, row outcomes, and confirmation in the browser.
