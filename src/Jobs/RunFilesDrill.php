<?php

declare(strict_types=1);

namespace Phattarachai\FilesBackupLaravel\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Phattarachai\FilesBackupLaravel\Backup\DrillResult;
use Phattarachai\FilesBackupLaravel\Backup\RestoreDrill;
use Phattarachai\FilesBackupLaravel\Exceptions\FilesDrillFailed;

/**
 * `files:drill` as a queued job. Each set's result goes out as
 * FilesDrillCompleted either way; a failed drill also fails the job.
 */
class RunFilesDrill implements ShouldQueue
{
    use Dispatchable;
    use Queueable;

    public int $tries = 1;

    public int $timeout = 3600;

    /**
     * @param  list<string>  $sets  empty drills every set
     */
    public function __construct(public readonly array $sets = [], public readonly ?int $sample = null, public readonly bool $all = false)
    {
        $this->onQueue(config('files-backup.queue'));
    }

    public function handle(RestoreDrill $drill): void
    {
        if (! config('files-backup.enabled')) {
            return;
        }

        $failed = array_values(array_filter($drill->run($this->sets, $this->sample, $this->all), fn (DrillResult $result): bool => ! $result->passed()));

        if ($failed !== []) {
            throw FilesDrillFailed::for($failed);
        }
    }
}
