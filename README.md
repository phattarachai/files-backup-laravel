# Files Backup

[![Latest Version on Packagist](https://img.shields.io/packagist/v/phattarachai/files-backup-laravel.svg?style=flat-square)](https://packagist.org/packages/phattarachai/files-backup-laravel)
[![Tests](https://img.shields.io/github/actions/workflow/status/phattarachai/files-backup-laravel/run-tests.yml?branch=main&label=tests&style=flat-square)](https://github.com/phattarachai/files-backup-laravel/actions/workflows/run-tests.yml?query=branch%3Amain)
[![Code Style](https://img.shields.io/github/actions/workflow/status/phattarachai/files-backup-laravel/fix-php-code-style-issues.yml?branch=main&label=code%20style&style=flat-square)](https://github.com/phattarachai/files-backup-laravel/actions/workflows/fix-php-code-style-issues.yml?query=branch%3Amain)
[![PHP Version](https://img.shields.io/packagist/dependency-v/phattarachai/files-backup-laravel/php?style=flat-square&label=php&logo=php&logoColor=white)](https://packagist.org/packages/phattarachai/files-backup-laravel)
![Laravel Version](https://img.shields.io/badge/laravel-12%20%7C%2013-FF2D20?style=flat-square&logo=laravel&logoColor=white)
[![Total Downloads](https://img.shields.io/packagist/dt/phattarachai/files-backup-laravel.svg?style=flat-square)](https://packagist.org/packages/phattarachai/files-backup-laravel)

Incremental, versioned off-site backup for a Laravel app's **content files**: uploads, the media
library, anything users put on disk. One nightly command mirrors them to Google Drive, S3/Spaces,
a NAS or any Flysystem disk. Each run uploads only what changed, keeps every replaced or deleted
file for `versions_days`, raises an event when a backup goes stale, and can prove the copy restores.

```
files:backup
  → scan each set's directories (excludes applied)
  → rclone sync --backup-dir …/versions/<stamp>     (or: Flysystem + manifest)
  → rclone check / cryptcheck
  → prune versions/ older than versions_days
  → write status.json                               ← files:backup-check reads this
```

It is the files sibling of
[`phattarachai/db-snapshot-sync-laravel`](https://github.com/phattarachai/db-snapshot-sync-laravel),
which takes the database off-site, and it follows the same conventions: config shape, command names,
a `…Checked` event interface, non-zero exits on failure, jobs that never retry, a configurable queue.
Both can write beside each other on the same target, e.g. `myapp/db` and `myapp/media`.

## Why not spatie/laravel-backup?

[`spatie/laravel-backup`](https://github.com/spatie/laravel-backup) zips everything on every run.
That works for a database dump. For a media library that only grows, it re-uploads the whole library
every night, has no incremental mode, and brings its own retention and alerting model. This package
copies only what changed, and it keeps a file that was overwritten or deleted, so a bad import or an
accidental delete can be undone.

## Install

```bash
composer require phattarachai/files-backup-laravel
php artisan vendor:publish --tag=files-backup-config
```

The default `rclone` driver needs the [rclone](https://rclone.org/install/) binary on the server
(`apt install rclone`, or the static binary). It needs no `rclone.conf`: the package builds the
remote from your config and hands it to rclone as environment variables. The `flysystem` driver
needs nothing besides the disk's adapter.

## Commands

| Command | Job | What it does |
|---|---|---|
| `files:backup {--set=*}` | `BackupFiles` | backs every set (or the named ones) up, versions what changed, prunes old versions |
| `files:backup-check {--set=*}` | `CheckFilesBackup` | dispatches `FilesBackupStale` / `FilesBackupHealthy` per set |
| `files:drill {--set=*} {--sample=} {--all}` | `RunFilesDrill` | compares the target with the live files, then downloads and hashes a sample, or everything |

Every command exits non-zero on failure. `files:backup` and `files:drill` throw
`FilesBackupFailed` / `FilesDrillFailed` once every set has run, so the failure reaches your exception
handler and error tracker (Sentry, Watchtower) as well as the scheduler. `files:backup-check` fails
without throwing, because the stale event is its alert. Every run logs one line per set, at `info` or
`error`, to `files-backup.log_channel` (the default channel when null). Secrets never appear in those
lines.

The jobs (`Phattarachai\FilesBackupLaravel\Jobs\…`) run the same code. They go on `FILES_BACKUP_QUEUE`
when it is set, never retry (`$tries = 1`; the next run catches up), and `BackupFiles` /
`RunFilesDrill` allow an hour. Put them on a queue whose worker `--timeout` covers that instead of the
host's `default` queue, for example a Horizon supervisor:

```php
// config/horizon.php → environments.production
'supervisor-backups' => [
    'connection' => 'redis',
    'queue' => ['backups'],
    'maxProcesses' => 1,
    'timeout' => 3700,
    'tries' => 1,
],
```

## Layout on the target

Each set gets its own folder under `target.path`:

```
{path}/{set}/current/{source}/…                 the newest state, a plain mirror
{path}/{set}/versions/2026-10-10_033000/{source}/…   what that run replaced or deleted
{path}/{set}/status.json                        the last successful run (time, file count, bytes)
{path}/{set}/manifest.json                      flysystem driver only: size, mtime, sha256 per file
```

`{source}` is each path relative to the app's base path, such as `storage/app/public`, so a restore
puts it straight back. A version folder is named after the run that filled it and deleted once it is
older than `versions_days`. Folders that don't match the stamp format are never touched.

## Configure

```php
// config/files-backup.php
'enabled' => env('FILES_BACKUP_ENABLED', true),

'sets' => [
    'media' => [
        'paths' => [
            storage_path('app/public'),              // stored as storage/app/public
            // 'uploads' => public_path('uploads'),   // a key names the folder instead
        ],
        'exclude' => ['.gitignore', '.DS_Store', 'livewire-tmp/**', '*.tmp'],
    ],
],

'target' => [
    'driver' => env('FILES_BACKUP_DRIVER', 'rclone'),   // rclone | flysystem | a Driver class
    'path' => env('FILES_BACKUP_PATH', ''),              // e.g. myapp → myapp/media/…
    'mode' => 'sync',                                     // sync | copy
    'rclone' => [ /* see below */ ],
    'flysystem' => ['disk' => env('FILES_BACKUP_DISK')],
],

'versions_days' => 30,      // keep replaced/deleted files this long
'stale_after_hours' => 26,  // files:backup-check threshold
'drill_sample' => 20,       // files:drill downloads this many per set unless --sample/--all
'queue' => env('FILES_BACKUP_QUEUE'),
'log_channel' => env('FILES_BACKUP_LOG_CHANNEL'),
```

- **Sets.** Projects keep files in different places (`storage/app/public`,
  `storage/app/private/uploads`, `public/uploads`). List them per set, and split sets when they need
  separate checks or drills.
- **`exclude`.** This uses rclone filter globs for both drivers. `*` matches within one path segment,
  `**` matches across segments, and `?`, `[a-z]`, `[!a-z]` and `{jpg,png}` work too. A pattern
  without a leading `/` matches at any depth, and one ending in `/` excludes the whole directory.
  Patterns are matched against the path inside each source directory.
- **`mode`.** `sync` mirrors deletions: the deleted file moves to `versions/` and ages out with it.
  `copy` never removes anything from `current/`.
- **`enabled`.** Off-site file backup is optional per project. While disabled, every command and job
  exits successfully and does nothing, so the schedule lines can stay.

A set whose directories suddenly hold **no files at all**, after a run that backed some up, is
refused rather than mirrored. That situation is far more often an unmounted volume or a wrong path
than a real mass delete. If the files really are gone, delete the set's `status.json` on the target.

### rclone driver

`remote` holds rclone's own backend options. Each one becomes `RCLONE_CONFIG_<NAME>_<OPTION>` in the
environment of the rclone process, so neither an `rclone.conf` nor a secret on the command line is
involved. `name` (default `filesbackup`) is the remote's name. Null and empty values are left out.

Options whose name contains `pass`, `secret`, `token`, `key`, `credentials`, `bearer` or `session`
are treated as secrets. They are masked as `********` in every exception message and log line the
package writes, including rclone's own error output.

**Google Shared Drive with a service account.** Add the service account to the Shared Drive as a
*Content manager*, put its JSON key on the server (readable by the PHP user only), and:

```php
'path' => env('FILES_BACKUP_PATH'),                    // e.g. myapp, a folder per project
'rclone' => [
    'remote' => [
        'type' => 'drive',
        'scope' => 'drive',
        'service_account_file' => env('FILES_BACKUP_RCLONE_SERVICE_ACCOUNT_FILE'),  // /etc/myapp/gdrive-sa.json
        'team_drive' => env('FILES_BACKUP_RCLONE_TEAM_DRIVE'),                       // the Shared Drive id, 0A…
    ],
],
```

A service account has no storage quota of its own, so it can only write to a **Shared** Drive, not to
a folder in someone's My Drive.

**DigitalOcean Spaces / S3:**

```php
'remote' => [
    'type' => 's3',
    'provider' => 'DigitalOcean',                       // or AWS, Cloudflare, Minio, …
    'access_key_id' => env('DO_SPACES_KEY'),
    'secret_access_key' => env('DO_SPACES_SECRET'),
    'endpoint' => 'sgp1.digitaloceanspaces.com',
    'acl' => 'private',
],
// 'path' => 'my-bucket/myapp'                          // the bucket is the first segment
```

**NAS over sftp:**

```php
'remote' => [
    'type' => 'sftp',
    'host' => env('NAS_SFTP_HOST'),
    'port' => env('NAS_SFTP_PORT', 22),
    'user' => env('NAS_SFTP_USERNAME'),
    'key_file' => env('NAS_SFTP_PRIVATE_KEY_PATH'),
    'known_hosts_file' => '/etc/myapp/nas_known_hosts',
],
// 'path' => '/volume1/backups/myapp'                   // a leading / is absolute on the server
```

**Encryption (optional).** Turn on rclone's [crypt](https://rclone.org/crypt/) overlay to encrypt
names and contents before they leave the box. This is worth doing on any target you don't control:

```php
'crypt' => [
    'enabled' => true,
    'password' => env('FILES_BACKUP_RCLONE_CRYPT_PASSWORD'),    // the `rclone obscure` form
    'password2' => env('FILES_BACKUP_RCLONE_CRYPT_PASSWORD2'),  // optional salt, also obscured
    'filename_encryption' => 'standard',
    'directory_name_encryption' => true,
],
```

```bash
rclone obscure 'the-real-passphrase'    # → paste the output into .env
```

Store the **plain** passphrases somewhere other than the server, such as a password manager.
Without them the backup can't be read. Verification switches to `rclone cryptcheck`.

**Other options.** `verify` (default `true`) runs `rclone check` after each source. `flags` are
appended to every transfer, for example `['--transfers=4', '--drive-chunk-size=64M',
'--bwlimit=8M']`. `timeout` caps one rclone call (default 3600 s). `binary` points at a specific
rclone.

### flysystem driver

Back up to any disk in `config/filesystems.php`, for example the same disk db-snapshot-sync uses:

```php
'target' => [
    'driver' => 'flysystem',
    'flysystem' => ['disk' => 'gdrive'],     // its root carries the project, e.g. myapp
],
```

The target's `manifest.json` records each file's size, mtime and sha256. A run compares the live
files with the manifest instead of listing the target, so a nightly run with nothing new moves no
file data at all. Before a changed file is uploaded, its old copy moves to
`versions/<stamp>/` on the target itself, which is server-side on S3 and Drive. Uploads are
streamed and written with **private** visibility whatever the disk's default. Each upload is
checked for size, and a short copy is deleted and fails the set. The manifest is saved every 100
uploads, so an interrupted first run picks up where it stopped.

The manifest is the driver's memory. If someone edits `current/` by hand, delete `manifest.json`
and the next run re-uploads everything.

## Schedule

```php
// routes/console.php
use Illuminate\Support\Facades\Schedule;

Schedule::command('files:backup')->dailyAt('03:50')->withoutOverlapping();
Schedule::command('files:backup-check')->hourly();

// Prove the copy restores, e.g. monthly and off-peak.
Schedule::command('files:drill')->monthlyOn(1, '05:30');
```

Or dispatch the jobs instead, to run on the backups queue:

```php
use Phattarachai\FilesBackupLaravel\Jobs\BackupFiles;

Schedule::job(new BackupFiles)->dailyAt('03:50');
```

## Alerting

`files:backup-check` reads each set's `status.json` and dispatches one event per set. Both events
implement `FilesBackupChecked`, so a listener on that interface receives both. A listener on their
abstract parent class never fires, because Laravel matches an event's interfaces, not its parent
classes.

| Event | When | Properties |
|---|---|---|
| `FilesBackupHealthy` | the last successful run finished within `stale_after_hours` | `set`, `target`, `backedUpAt`, `ageSeconds`, `ageHours()`, `staleAfterHours`, `files`, `bytes`, `describe()` |
| `FilesBackupStale` | longer ago than that, no run recorded, or the target could not be read | the same, with `backedUpAt`/`ageSeconds` null when there is no run, and `error` when unreadable |

```php
use Phattarachai\FilesBackupLaravel\Events\FilesBackupStale;

Event::listen(FilesBackupStale::class, function (FilesBackupStale $event): void {
    Log::critical('Off-site files backup is stale: '.$event->describe(), [
        'set' => $event->set,
        'age_hours' => $event->ageHours(),
        'error' => $event->error,
    ]);
    // …or a Telegram / Slack / mail notification
});
```

Staleness is judged by when the last run **finished**, not by the newest file. A media library that
didn't change all week still has to show it was backed up last night.

## Restore drill

`files:drill` checks the off-site copy, not the local one. For each set it:

1. reads `status.json` (no recorded run is a failure);
2. lists `current/` on the target and compares it with the live files: every live file older than
   the last run must be there at the same size;
3. downloads a random sample (`--sample=N`, default `drill_sample`) or everything (`--all`) to
   `storage/app/files-backup-drill/`, and compares each file's size and sha256 with the live file;
4. deletes that scratch directory in a `finally`, logs the outcome and dispatches
   `FilesDrillCompleted` with the `DrillResult`.

Live files modified since the last run started are expected to differ. They are counted
(`changedSinceBackup`), not failed. Files only on the target (deleted since, or kept by `copy` mode)
are counted too. The drill **fails** on a missing file, a size mismatch, a download that doesn't hash
like the live file, or a target it can't read. It never writes to the target or the live directories.

## Restoring

The target is a plain mirror, so a restore is one copy back. Stop writes first (maintenance mode),
then:

```bash
# rclone: define the remote once in your shell (or `rclone config`), then copy current/ back.
export RCLONE_CONFIG_FILESBACKUP_TYPE=drive RCLONE_CONFIG_FILESBACKUP_SCOPE=drive \
       RCLONE_CONFIG_FILESBACKUP_SERVICE_ACCOUNT_FILE=/etc/myapp/gdrive-sa.json \
       RCLONE_CONFIG_FILESBACKUP_TEAM_DRIVE=0A…
rclone copy filesbackup:myapp/media/current/storage/app/public /var/www/myapp/storage/app/public --progress

# One file as it was before a run replaced or deleted it:
rclone lsf filesbackup:myapp/media/versions/
rclone copyto filesbackup:myapp/media/versions/2026-10-10_033000/storage/app/public/12/photo.jpg ./photo.jpg
```

With crypt, add the crypt remote the same way (`RCLONE_CONFIG_FILESBACKUPCRYPT_TYPE=crypt`,
`…_REMOTE=filesbackup:myapp`, `…_PASSWORD=<obscured>`) and copy from `filesbackupcrypt:media/current/…`.

With the flysystem driver, copy `{path}/{set}/current/…` back with the disk's own tools, such as
`aws s3 sync`, Drive for desktop or `rsync` from the NAS, or with rclone pointed at the same storage.

Restore the database from the same night as the files (`snapshot:drill` /
[db-snapshot-sync](https://github.com/phattarachai/db-snapshot-sync-laravel)), so media rows and files
agree. Fix ownership afterwards (`chown -R www-data: storage/app/public`).

## Custom drivers

`target.driver` also accepts a class implementing `Phattarachai\FilesBackupLaravel\Drivers\Driver`,
resolved from the container. The interface is the layout above: back up, prune versions, read and
write the status, list `current/`, download.

## Testing

```bash
composer test       # Pest; the rclone integration test runs when the binary is on PATH
composer analyse    # PHPStan (Larastan, level 5)
composer format     # Pint
```

## License

MIT. See [LICENSE.md](LICENSE.md).
