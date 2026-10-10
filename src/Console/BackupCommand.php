<?php

declare(strict_types=1);

namespace Phattarachai\FilesBackupLaravel\Console;

use Illuminate\Console\Command;
use Phattarachai\FilesBackupLaravel\Backup\FilesBackup;
use Phattarachai\FilesBackupLaravel\Backup\SetReport;
use Phattarachai\FilesBackupLaravel\Exceptions\FilesBackupFailed;

class BackupCommand extends Command
{
    protected $signature = 'files:backup
        {--set=* : Back up only these sets (default: every set in files-backup.sets)}';

    protected $description = 'Back the configured file sets up off-site, keeping replaced and deleted files under versions/, and prune old versions.';

    public function handle(FilesBackup $backup): int
    {
        if (! config('files-backup.enabled')) {
            $this->line('Files backup is disabled (files-backup.enabled).');

            return self::SUCCESS;
        }

        $reports = $backup->run($this->sets(), note: fn (string $line) => $this->line($line, verbosity: 'v'));

        foreach ($reports as $report) {
            $report->failed() ? $this->error($report->summary()) : $this->info($report->summary());
        }

        $failed = array_values(array_filter($reports, fn (SetReport $report): bool => $report->failed()));

        if ($failed !== []) {
            throw FilesBackupFailed::for($failed);
        }

        return self::SUCCESS;
    }

    /**
     * @return list<string>
     */
    private function sets(): array
    {
        return array_values(array_filter(array_map(strval(...), (array) $this->option('set'))));
    }
}
