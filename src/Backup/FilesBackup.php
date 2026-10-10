<?php

declare(strict_types=1);

namespace Phattarachai\FilesBackupLaravel\Backup;

use Closure;
use Illuminate\Support\Carbon;
use Phattarachai\FilesBackupLaravel\Drivers\DriverFactory;
use Phattarachai\FilesBackupLaravel\Drivers\VersionStamp;
use Phattarachai\FilesBackupLaravel\Support\BackupLog;
use Phattarachai\FilesBackupLaravel\Support\LocalFiles;
use RuntimeException;
use Throwable;

/**
 * Runs every set (or the named ones) through the configured driver: scan the
 * live files, back them up, age out old versions, then record the run in
 * status.json. One set failing does not stop the others; the caller reads the
 * reports. Every set's outcome is logged.
 */
final readonly class FilesBackup
{
    public function __construct(private DriverFactory $drivers) {}

    /**
     * @param  list<string>  $only  set names; empty runs them all
     * @param  (Closure(string): void)|null  $note  progress lines, e.g. for console output
     * @return list<SetReport>
     */
    public function run(array $only = [], ?BackupConfig $config = null, ?Closure $note = null): array
    {
        $config ??= BackupConfig::fromConfig();
        $note ??= static function (string $line): void {};
        $driver = $this->drivers->make($config);

        return array_map(function (BackupSet $set) use ($config, $driver, $note): SetReport {
            $report = new SetReport($set->name, $driver->describe($set));
            $started = Carbon::now();

            try {
                $files = LocalFiles::scan($set);
                $report->files = count($files);
                $report->bytes = LocalFiles::bytes($files);

                $this->guardAgainstEmptying($files, $driver->readStatus($set), $set);

                $report->versionStamp = VersionStamp::for($started);
                $driver->backup($set, $files, $report->versionStamp, $report, $note);
                $report->prunedVersions = $driver->pruneVersions($set, Carbon::now()->subDays($config->versionsDays), $note);

                $driver->writeStatus($set, new RunStamp($set->name, $config->driver, $started, Carbon::now(), $report->files, $report->bytes));
            } catch (Throwable $e) {
                $report->error = $e->getMessage();
            }

            $report->seconds = round(Carbon::now()->diffInMilliseconds($started, true) / 1000, 1);

            $report->failed()
                ? BackupLog::channel()->error('files-backup: '.$report->summary(), ['set' => $set->name, 'target' => $report->target])
                : BackupLog::channel()->info('files-backup: '.$report->summary(), ['set' => $set->name, 'target' => $report->target]);

            return $report;
        }, $config->sets($only));
    }

    /**
     * A set that suddenly has no files at all is far more likely an unmounted
     * volume or a wrong path than a real deletion of everything. Mirroring it
     * would move the whole backup into versions/ and start its countdown.
     *
     * @param  array<string, mixed>  $files
     */
    private function guardAgainstEmptying(array $files, ?RunStamp $previous, BackupSet $set): void
    {
        if ($files === [] && $previous !== null && $previous->files > 0) {
            throw new RuntimeException("Set [{$set->name}] has no files, but the last run backed up {$previous->files}; refusing to mirror an empty source. Check the paths, or delete status.json on the target if the files really are gone.");
        }
    }
}
