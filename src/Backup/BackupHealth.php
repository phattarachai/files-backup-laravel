<?php

declare(strict_types=1);

namespace Phattarachai\FilesBackupLaravel\Backup;

use Illuminate\Support\Carbon;
use Phattarachai\FilesBackupLaravel\Drivers\DriverFactory;
use Phattarachai\FilesBackupLaravel\Events\FilesBackupHealthy;
use Phattarachai\FilesBackupLaravel\Events\FilesBackupStale;
use Phattarachai\FilesBackupLaravel\Events\FilesBackupStatus;
use Phattarachai\FilesBackupLaravel\Support\BackupLog;
use Throwable;

/**
 * Reads each set's status.json on the target and dispatches FilesBackupHealthy
 * or FilesBackupStale for it. A set with no recorded run, or whose target
 * cannot be read, is stale.
 */
final readonly class BackupHealth
{
    public function __construct(private DriverFactory $drivers) {}

    /**
     * @param  list<string>  $only
     * @return list<FilesBackupStatus> one per set, each already dispatched
     */
    public function check(array $only = [], ?BackupConfig $config = null): array
    {
        $config ??= BackupConfig::fromConfig();
        $driver = $this->drivers->make($config);

        return array_map(function (BackupSet $set) use ($config, $driver): FilesBackupStatus {
            $target = $driver->describe($set);

            try {
                $stamp = $driver->readStatus($set);
            } catch (Throwable $e) {
                $stamp = null;
                $error = $e->getMessage();
            }

            if ($stamp === null) {
                $status = new FilesBackupStale($set->name, $target, null, null, $config->staleAfterHours, null, null, $error ?? null);
            } else {
                $age = max(0, Carbon::now()->getTimestamp() - $stamp->finishedAt->getTimestamp());
                $class = $age > $config->staleAfterHours * 3600 ? FilesBackupStale::class : FilesBackupHealthy::class;
                $status = new $class($set->name, $target, $stamp->finishedAt, $age, $config->staleAfterHours, $stamp->files, $stamp->bytes);
            }

            $status instanceof FilesBackupStale
                ? BackupLog::channel()->warning('files-backup: STALE '.$status->describe(), ['set' => $set->name])
                : BackupLog::channel()->info('files-backup: OK '.$status->describe(), ['set' => $set->name]);

            event($status);

            return $status;
        }, $config->sets($only));
    }
}
