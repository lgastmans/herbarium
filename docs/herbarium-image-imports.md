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

## Signed temporary previews behind a reverse proxy

Livewire previews remain private files served by its signed
`livewire.preview-file` route. Do not expose `storage/app/livewire-tmp`, remove
signature checks, or add a public link for that directory.

The production-style preview failure was caused by a proxy configuration gap:
the application had no trusted proxy addresses, so Laravel ignored the
forwarded HTTPS protocol. Livewire signs an absolute preview URL, including its
scheme and host. An HTTP/HTTPS or host difference between URL generation and
the later preview request can therefore produce a mixed-content failure or an
invalid signature even though the temporary file is readable.

There are two proxy layers in the supplied topology:

```text
public TLS/edge proxy -> herbarium-nginx -> herbarium-app PHP-FPM
```

The edge proxy terminates public TLS and must set or sanitize the forwarded
protocol and host. The immediate peer Laravel must trust is the internal
`herbarium-nginx` service. Configuring only the external edge proxy address is
insufficient when PHP-FPM receives the request from internal Nginx. Individual
container addresses are dynamic and must not be copied into production
configuration.

Set `APP_URL` to the canonical public HTTPS origin. Prefer trusting the narrow
Docker network CIDR that contains `herbarium-nginx`:

```dotenv
APP_URL=https://herbarium.example.org
TRUSTED_PROXIES=172.30.0.0/16
```

The CIDR above is illustrative, not a repository default. Identify the actual
Compose network name and its configured subnet with read-only commands:

```bash
docker inspect herbarium-nginx --format '{{range $network, $_ := .NetworkSettings.Networks}}{{$network}}{{println}}{{end}}'
docker inspect herbarium-nginx --format '{{range .NetworkSettings.Networks}}{{.IPAddress}}{{println}}{{end}}'
docker network inspect <network-name> --format '{{range .IPAM.Config}}{{.Subnet}}{{println}}{{end}}'
```

`TRUSTED_PROXIES` accepts one IP, a comma-separated list of IPs/CIDRs, or an
intentional standalone `*`/`**`. A wildcard cannot be mixed into a list. As a
second supported choice, `TRUSTED_PROXIES=*` tells Laravel to trust only the
immediate caller it observes for that request. Use this only when PHP-FPM is
not published externally and is guaranteed to receive web traffic exclusively
from the controlled internal Nginx service. If PHP-FPM is reachable directly,
wildcard mode would make that direct caller trusted and allow it to forge
forwarded origin data.

The outer edge must overwrite or sanitize `X-Forwarded-Proto`,
`X-Forwarded-Host`, and related forwarding headers before passing requests
inward. Restrict direct public access to the internal Nginx HTTP port so users
cannot bypass that edge policy. After changing `APP_URL` or `TRUSTED_PROXIES`,
refresh Laravel's cached configuration:

```bash
docker compose exec app php artisan config:clear
docker compose exec app php artisan config:cache
```

If production injects environment variables when the container is created
rather than reading the bind-mounted `.env`, recreate only PHP after reviewing
the change:

```bash
docker compose up -d --no-deps --force-recreate app
```

Confirm that `storage/app/livewire-tmp` is writable by PHP and staged files are
readable by the PHP worker. A stale configuration cache must not retain an old
HTTP `APP_URL` or trusted-proxy list.

## Legacy image metadata backfill

`herbarium:backfill-image-metadata` scans image records in ascending ID order,
using ID-based chunks. It hashes the bytes already stored on the configured
public disk and uses the stored filename as the best available historical
`original_filename`. It never renames, copies, or deletes image files, never
creates or deletes historical image rows, and does not create activities.

The default is a dry run and cannot change the database:

```bash
docker compose exec app php artisan herbarium:backfill-image-metadata
```

Database writes require `--apply`. `--limit` provides a deterministic,
ascending-ID first run:

```bash
docker compose exec app php artisan herbarium:backfill-image-metadata --apply --limit=100
docker compose exec app php artisan herbarium:backfill-image-metadata --apply
```

Existing non-null metadata is preserved. Unsafe stored filenames, missing or
unreadable files, and hashing errors are reported and skipped. When identical
bytes occur more than once for one herbarium, the lowest eligible image-record
ID owns the checksum; later records keep a null checksum but may still receive
their missing original filename. A checksum already owned by another record is
not moved or overwritten. Identical bytes for different herbaria are allowed.
Unique-constraint races are reported and processing continues.

Take and verify a database backup before any apply run. This repair is
idempotent, but it has no automatic rollback: restoring prior null metadata
requires that backup or a separately recorded list of modified rows.

### Safe production runbook

Do not run these steps until the operator has approved the backup and target
environment.

1. Verify a current database backup using the deployment's established backup
   procedure, and record its location and restore test.
2. Verify the public disk and permissions without modifying images:
   `docker compose exec app php artisan tinker --execute="dump(config('filesystems.disks.public.root'));"`
   and inspect the mounted `storage/app/public/herbarium` directory as the PHP
   worker.
3. Run `docker compose exec app php artisan herbarium:backfill-image-metadata`
   without `--apply`.
4. Review every missing file, unsafe filename, unreadable/hash failure, and
   same-herbarium checksum conflict in the report.
5. Optionally apply a controlled first segment with
   `docker compose exec app php artisan herbarium:backfill-image-metadata --apply --limit=100`.
6. Run the complete apply with
   `docker compose exec app php artisan herbarium:backfill-image-metadata --apply`.
7. Repeat the dry run and verify that only intentional unresolved rows remain.
8. In the administrator web importer, stage a known historical image for the
   same herbarium and verify that the pre-confirmation duplicate advisory is
   shown.
9. Confirm the batch import and verify it is reported as skipped, with no new
   image row, stored file, or import activity.

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
