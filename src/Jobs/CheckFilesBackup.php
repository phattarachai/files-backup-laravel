<?php

declare(strict_types=1);

namespace Phattarachai\FilesBackupLaravel\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Phattarachai\FilesBackupLaravel\Backup\BackupHealth;

/**
 * `files:backup-check` as a queued job. A stale set is reported through the
 * FilesBackupStale event, not by failing the job.
 */
class CheckFilesBackup implements ShouldQueue
{
    use Dispatchable;
    use Queueable;

    public int $tries = 1;

    /**
     * @param  list<string>  $sets  empty checks every set
     */
    public function __construct(public readonly array $sets = [])
    {
        $this->onQueue(config('files-backup.queue'));
    }

    public function handle(BackupHealth $health): void
    {
        if (! config('files-backup.enabled')) {
            return;
        }

        $health->check($this->sets);
    }
}
