<?php

declare(strict_types=1);

namespace Phattarachai\FilesBackupLaravel\Drivers;

use Closure;
use Illuminate\Support\Carbon;
use Phattarachai\FilesBackupLaravel\Backup\BackupSet;
use Phattarachai\FilesBackupLaravel\Backup\RunStamp;
use Phattarachai\FilesBackupLaravel\Backup\SetReport;
use Phattarachai\FilesBackupLaravel\Support\LocalFile;

/**
 * Where a set goes. Every target holds, per set:
 *
 *   {set dir}/current/{label}/...           the newest state
 *   {set dir}/versions/{stamp}/{label}/...  what a run replaced or deleted
 *   {set dir}/status.json                   the last successful run
 *
 * Messages a driver throws are already scrubbed of secrets.
 */
interface Driver
{
    /**
     * A short, secret-free description of the target, for logs and events.
     */
    public function describe(BackupSet $set): string;

    /**
     * Bring current/ in line with the live files; whatever it replaces or
     * deletes moves to versions/{$versionStamp}/.
     *
     * @param  array<string, LocalFile>  $files  the live files, from LocalFiles::scan()
     * @param  Closure(string): void  $note
     */
    public function backup(BackupSet $set, array $files, string $versionStamp, SetReport $report, Closure $note): void;

    /**
     * Delete version folders whose stamp is older than $cutoff.
     *
     * @param  Closure(string): void  $note
     * @return list<string> the folders deleted
     */
    public function pruneVersions(BackupSet $set, Carbon $cutoff, Closure $note): array;

    public function writeStatus(BackupSet $set, RunStamp $stamp): void;

    /**
     * The last successful run, or null when the target has no record of one.
     * Throws when the target cannot be read.
     */
    public function readStatus(BackupSet $set): ?RunStamp;

    /**
     * Every file under current/, as path => size.
     *
     * @return array<string, int>
     */
    public function listCurrent(BackupSet $set): array;

    /**
     * Download the given current/ paths (all of them when null) into $directory,
     * keeping their relative paths.
     *
     * @param  list<string>|null  $paths
     */
    public function download(BackupSet $set, ?array $paths, string $directory): void;
}
