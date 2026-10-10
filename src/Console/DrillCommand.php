<?php

declare(strict_types=1);

namespace Phattarachai\FilesBackupLaravel\Console;

use Illuminate\Console\Command;
use Phattarachai\FilesBackupLaravel\Backup\DrillResult;
use Phattarachai\FilesBackupLaravel\Backup\RestoreDrill;
use Phattarachai\FilesBackupLaravel\Backup\SetReport;
use Phattarachai\FilesBackupLaravel\Exceptions\FilesDrillFailed;

class DrillCommand extends Command
{
    protected $signature = 'files:drill
        {--set=* : Drill only these sets}
        {--sample= : Files to download and hash per set (default: files-backup.drill_sample)}
        {--all : Download and hash every file}';

    protected $description = 'Compare the off-site copy with the live files, download a sample (or all) to a scratch directory and verify sizes and hashes.';

    public function handle(RestoreDrill $drill): int
    {
        if (! config('files-backup.enabled')) {
            $this->line('Files backup is disabled (files-backup.enabled).');

            return self::SUCCESS;
        }

        $sample = $this->option('sample');

        if ($sample !== null && (! ctype_digit((string) $sample) || (int) $sample < 1)) {
            $this->error('--sample must be a whole number of at least 1.');

            return self::FAILURE;
        }

        $results = $drill->run(
            only: array_values(array_filter(array_map(strval(...), (array) $this->option('set')))),
            sample: $sample === null ? null : (int) $sample,
            all: (bool) $this->option('all'),
            note: fn (string $line) => $this->line($line),
        );

        array_walk($results, $this->report(...));

        $failed = array_values(array_filter($results, fn (DrillResult $result): bool => ! $result->passed()));

        if ($failed !== []) {
            throw FilesDrillFailed::for($failed);
        }

        return self::SUCCESS;
    }

    private function report(DrillResult $result): void
    {
        $this->newLine();
        $this->line("[{$result->set}] {$result->target}, last backed up ".($result->backedUpAt ?? '—'));
        $this->line(sprintf(
            'Live %d files (%s) · target %d files (%s) · %d changed since the backup · %d only on the target',
            $result->liveFiles,
            SetReport::humanBytes($result->liveBytes),
            $result->remoteFiles,
            SetReport::humanBytes($result->remoteBytes),
            $result->changedSinceBackup,
            count($result->extra),
        ));
        $this->line(sprintf('Downloaded and hashed %d file(s) (%s) in %.2fs.', $result->downloaded, SetReport::humanBytes($result->downloadedBytes), $result->seconds));

        foreach (array_slice($result->missing, 0, 20) as $path) {
            $this->error("Missing from the target: {$path}");
        }

        foreach (array_slice($result->sizeMismatches, 0, 20) as $row) {
            $this->error("Size differs: {$row['path']} (live {$row['live']}, target {$row['remote']})");
        }

        foreach (array_slice($result->corrupt, 0, 20) as $path) {
            $this->error("Download does not match the live file: {$path}");
        }

        if ($result->error !== null) {
            $this->error($result->error);
        }

        $result->passed()
            ? $this->info("[{$result->set}] Drill PASSED.")
            : $this->error("[{$result->set}] Drill FAILED.");
    }
}
