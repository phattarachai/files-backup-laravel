<?php

declare(strict_types=1);

namespace Phattarachai\FilesBackupLaravel\Backup;

use RuntimeException;

/**
 * The `files-backup` config, read once and typed.
 */
final readonly class BackupConfig
{
    public const string MODE_SYNC = 'sync';

    public const string MODE_COPY = 'copy';

    /**
     * @param  array<string, BackupSet>  $sets
     */
    public function __construct(
        public bool $enabled,
        public array $sets,
        public string $driver,
        public string $path,
        public string $mode,
        public int $versionsDays,
        public int $staleAfterHours,
        public int $drillSample,
    ) {}

    public static function fromConfig(): self
    {
        $sets = [];

        foreach ((array) config('files-backup.sets', []) as $name => $set) {
            $sets[(string) $name] = BackupSet::fromConfig((string) $name, (array) $set);
        }

        $mode = (string) config('files-backup.target.mode', self::MODE_SYNC);

        if (! in_array($mode, [self::MODE_SYNC, self::MODE_COPY], true)) {
            throw new RuntimeException("files-backup.target.mode must be 'sync' or 'copy', not [{$mode}].");
        }

        return new self(
            enabled: (bool) config('files-backup.enabled', true),
            sets: $sets,
            driver: (string) config('files-backup.target.driver', 'rclone'),
            // A leading slash stays: on an sftp or local rclone remote it means an absolute path.
            path: rtrim((string) config('files-backup.target.path', ''), '/'),
            mode: $mode,
            versionsDays: max(1, (int) config('files-backup.versions_days', 30)),
            staleAfterHours: max(1, (int) config('files-backup.stale_after_hours', 26)),
            drillSample: max(1, (int) config('files-backup.drill_sample', 20)),
        );
    }

    /**
     * The sets to run: all of them, or the named ones (an unknown name throws).
     *
     * @param  list<string>  $only
     * @return list<BackupSet>
     */
    public function sets(array $only = []): array
    {
        if ($this->sets === []) {
            throw new RuntimeException('No backup sets are configured (files-backup.sets).');
        }

        if ($only === []) {
            return array_values($this->sets);
        }

        return array_map(
            fn (string $name): BackupSet => $this->sets[$name] ?? throw new RuntimeException("Unknown backup set [{$name}]; configured: ".implode(', ', array_keys($this->sets)).'.'),
            $only,
        );
    }

    /**
     * The set's folder on a Flysystem disk, relative to the disk's root.
     */
    public function setDir(BackupSet $set): string
    {
        $path = ltrim($this->path, '/');

        return $path === '' ? $set->name : "{$path}/{$set->name}";
    }
}
