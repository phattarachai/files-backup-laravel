<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Enabled
    |--------------------------------------------------------------------------
    | Off-site file backup is optional per project. While disabled, every
    | command exits successfully without touching anything (and dispatches no
    | events), so the schedule lines can stay in place.
    */

    'enabled' => (bool) env('FILES_BACKUP_ENABLED', true),

    /*
    |--------------------------------------------------------------------------
    | Backup sets
    |--------------------------------------------------------------------------
    | Each set is backed up to its own folder on the target:
    |
    |   {target.path}/{set}/current/{source}/...        the newest state
    |   {target.path}/{set}/versions/{stamp}/{source}/  files a run replaced or deleted
    |   {target.path}/{set}/status.json                 written after every successful run
    |
    | `paths` lists absolute directories. A plain list stores each under its
    | path relative to the app's base path (storage/app/public), so a restore
    | puts it straight back; a keyed list (['public' => ...]) uses the key.
    |
    | `exclude` takes rclone filter globs, for both drivers: `*` stays within a
    | path segment, `**` crosses them, `?`, `[a-z]` and `{a,b}` work, and a
    | pattern without a leading `/` matches at any depth. Matched against the
    | path inside the source directory.
    */

    'sets' => [
        'media' => [
            'paths' => [
                storage_path('app/public'),
            ],
            'exclude' => [
                '.gitignore',
                '.DS_Store',
            ],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Target
    |--------------------------------------------------------------------------
    | `driver` is `rclone` (default), `flysystem`, or a class implementing
    | Phattarachai\FilesBackupLaravel\Drivers\Driver. `path` is the prefix on the
    | target every set lives under, e.g. a folder per project on a shared
    | Google Drive (`myapp`, so the media set lands in `myapp/media`).
    */

    'target' => [
        'driver' => env('FILES_BACKUP_DRIVER', 'rclone'),
        'path' => env('FILES_BACKUP_PATH', ''),

        // `sync` mirrors deletions (a deleted file moves to versions/ and ages
        // out with them); `copy` never removes anything from current/.
        'mode' => 'sync',

        /*
        | rclone, run through Laravel's Process. The remote is defined entirely
        | by environment variables built from `remote` (RCLONE_CONFIG_<NAME>_<KEY>),
        | so no rclone.conf is needed. Keys are rclone's own backend options:
        |
        |   Google Shared Drive + service account:
        |     'type' => 'drive', 'scope' => 'drive',
        |     'service_account_file' => '/etc/myapp/gdrive-sa.json',
        |     'team_drive' => '0A...'
        |
        |   DigitalOcean Spaces / S3:
        |     'type' => 's3', 'provider' => 'DigitalOcean',
        |     'access_key_id' => ..., 'secret_access_key' => ...,
        |     'endpoint' => 'sgp1.digitaloceanspaces.com', 'acl' => 'private'
        |
        |   NAS over sftp:
        |     'type' => 'sftp', 'host' => ..., 'user' => ..., 'key_file' => ...
        |
        | Null and empty values are left out. Secrets go to rclone only through
        | the environment, never on its command line, and are scrubbed from
        | every log line and exception message.
        |
        | `crypt` overlays rclone's crypt backend: names and contents are
        | encrypted before they leave the box. `password` / `password2` take
        | the *obscured* form (`rclone obscure`), as rclone.conf does. Keep
        | the plain passwords somewhere other than the server: without them
        | the backup cannot be read.
        |
        */

        'rclone' => [
            'binary' => env('FILES_BACKUP_RCLONE_BINARY', 'rclone'),
            'name' => 'filesbackup',
            'remote' => [
                'type' => env('FILES_BACKUP_RCLONE_TYPE', 'drive'),
                'scope' => 'drive',
                'service_account_file' => env('FILES_BACKUP_RCLONE_SERVICE_ACCOUNT_FILE'),
                'team_drive' => env('FILES_BACKUP_RCLONE_TEAM_DRIVE'),
            ],
            'crypt' => [
                'enabled' => (bool) env('FILES_BACKUP_RCLONE_CRYPT', false),
                'password' => env('FILES_BACKUP_RCLONE_CRYPT_PASSWORD'),
                'password2' => env('FILES_BACKUP_RCLONE_CRYPT_PASSWORD2'),
                'filename_encryption' => 'standard',
                'directory_name_encryption' => true,
            ],
            // Run `rclone check` (or `cryptcheck`) after each set's upload.
            'verify' => true,
            // Extra flags for every transfer, e.g. ['--transfers=4', '--drive-chunk-size=64M'].
            'flags' => [],
            // Seconds one rclone call may run.
            'timeout' => 3600,
        ],

        /*
        | Any disk from config/filesystems.php (S3/Spaces, Google Drive, sftp,
        | a local mount). A manifest on the target records what is there, so a
        | run lists nothing remote. Uploads are streamed and written private.
        */

        'flysystem' => [
            'disk' => env('FILES_BACKUP_DISK'),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Retention, staleness and drills
    |--------------------------------------------------------------------------
    | `versions_days`: how long a replaced or deleted file is kept under
    | versions/. `stale_after_hours`: files:backup-check threshold for the age
    | of the last successful run. `drill_sample`: how many files files:drill
    | downloads and hashes when not given --sample or --all.
    */

    'versions_days' => 30,
    'stale_after_hours' => 26,
    'drill_sample' => 20,

    // Queue the package's jobs (BackupFiles, CheckFilesBackup, RunFilesDrill)
    // go on when dispatched; null rides the connection's default queue.
    'queue' => env('FILES_BACKUP_QUEUE'),

    // Log channel every run reports to; null uses the default channel.
    'log_channel' => env('FILES_BACKUP_LOG_CHANNEL'),
];
