<?php

declare(strict_types=1);

namespace Phattarachai\FilesBackupLaravel\Console;

use Illuminate\Console\Command;
use Phattarachai\FilesBackupLaravel\Backup\BackupHealth;
use Phattarachai\FilesBackupLaravel\Events\FilesBackupStale;
use Phattarachai\FilesBackupLaravel\Events\FilesBackupStatus;

class BackupCheckCommand extends Command
{
    protected $signature = 'files:backup-check
        {--set=* : Check only these sets}';

    protected $description = 'Check each set\'s last successful off-site run; dispatch FilesBackupStale / FilesBackupHealthy and fail when stale.';

    public function handle(BackupHealth $health): int
    {
        if (! config('files-backup.enabled')) {
            $this->line('Files backup is disabled (files-backup.enabled).');

            return self::SUCCESS;
        }

        $statuses = $health->check(array_values(array_filter(array_map(strval(...), (array) $this->option('set')))));

        foreach ($statuses as $status) {
            $status instanceof FilesBackupStale
                ? $this->error('STALE '.$status->describe())
                : $this->info('OK '.$status->describe());
        }

        return array_any($statuses, fn (FilesBackupStatus $status): bool => $status instanceof FilesBackupStale)
            ? self::FAILURE
            : self::SUCCESS;
    }
}
