<?php

declare(strict_types=1);

namespace Phattarachai\FilesBackupLaravel\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Phattarachai\FilesBackupLaravel\Backup\FilesBackup;
use Phattarachai\FilesBackupLaravel\Backup\SetReport;
use Phattarachai\FilesBackupLaravel\Exceptions\FilesBackupFailed;

/**
 * `files:backup` as a queued job. Fails (once, no retry) when any set failed;
 * the next run catches up whatever this one missed.
 */
class BackupFiles implements ShouldQueue
{
    use Dispatchable;
    use Queueable;

    public int $tries = 1;

    public int $timeout = 3600;

    /**
     * @param  list<string>  $sets  empty backs up every set
     */
    public function __construct(public readonly array $sets = [])
    {
        $this->onQueue(config('files-backup.queue'));
    }

    public function handle(FilesBackup $backup): void
    {
        if (! config('files-backup.enabled')) {
            return;
        }

        $failed = array_values(array_filter($backup->run($this->sets), fn (SetReport $report): bool => $report->failed()));

        if ($failed !== []) {
            throw FilesBackupFailed::for($failed);
        }
    }
}
