<?php

declare(strict_types=1);

namespace Phattarachai\FilesBackupLaravel\Backup;

use Closure;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Phattarachai\FilesBackupLaravel\Drivers\Driver;
use Phattarachai\FilesBackupLaravel\Drivers\DriverFactory;
use Phattarachai\FilesBackupLaravel\Events\FilesDrillCompleted;
use Phattarachai\FilesBackupLaravel\Support\BackupLog;
use Phattarachai\FilesBackupLaravel\Support\LocalFile;
use Phattarachai\FilesBackupLaravel\Support\LocalFiles;
use RuntimeException;
use Throwable;

/**
 * Proves the off-site copy restores: lists the target's current/ state and
 * compares it with the live files (count, presence, size), then downloads a
 * random sample (or everything) into a scratch directory and compares each
 * file's sha256 with the live one. The scratch directory is deleted whatever
 * happened. Nothing on the target or in the live directories is written.
 */
final readonly class RestoreDrill
{
    public function __construct(private DriverFactory $drivers) {}

    /**
     * @param  list<string>  $only
     * @param  ?int  $sample  files to download and hash per set; null uses `drill_sample`
     * @param  (Closure(string): void)|null  $note
     * @return list<DrillResult>
     */
    public function run(array $only = [], ?int $sample = null, bool $all = false, ?Closure $note = null, ?BackupConfig $config = null): array
    {
        $config ??= BackupConfig::fromConfig();
        $note ??= static function (string $line): void {};
        $driver = $this->drivers->make($config);

        return array_map(
            fn (BackupSet $set): DrillResult => $this->drill($driver, $set, $all ? null : max(1, $sample ?? $config->drillSample), $note),
            $config->sets($only),
        );
    }

    /**
     * @param  ?int  $sample  null downloads every file
     * @param  Closure(string): void  $note
     */
    private function drill(Driver $driver, BackupSet $set, ?int $sample, Closure $note): DrillResult
    {
        $result = new DrillResult($set->name, $driver->describe($set));
        $started = hrtime(true);
        $scratch = storage_path('app/files-backup-drill/'.$set->name.'-'.Str::random(8));

        try {
            $stamp = $driver->readStatus($set)
                ?? throw new RuntimeException("[{$set->name}] {$result->target} has no recorded backup to drill.");
            $result->backedUpAt = $stamp->finishedAt->toIso8601String();

            $live = LocalFiles::scan($set);
            $result->liveFiles = count($live);
            $result->liveBytes = LocalFiles::bytes($live);

            $note("[{$set->name}] listing {$result->target}...");
            $remote = $driver->listCurrent($set);
            $result->remoteFiles = count($remote);
            $result->remoteBytes = array_sum($remote);

            $comparable = $this->compare($live, $remote, $stamp->startedAt, $result);

            $paths = $sample === null ? array_keys($comparable) : self::pick(array_keys($comparable), $sample);

            if ($paths !== []) {
                $note("[{$set->name}] downloading ".count($paths).' file(s) to verify...');
                $driver->download($set, $sample === null ? null : $paths, $scratch);
                $this->verify($comparable, $paths, $scratch, $result);
            }
        } catch (Throwable $e) {
            $result->error = $e->getMessage();
        } finally {
            File::deleteDirectory($scratch);
            $result->seconds = round((hrtime(true) - $started) / 1e9, 2);
        }

        $result->passed()
            ? BackupLog::channel()->info("files-backup: drill [{$set->name}] passed", self::context($result))
            : BackupLog::channel()->error("files-backup: drill [{$set->name}] FAILED: {$result->failure()}", self::context($result));

        event(new FilesDrillCompleted($result));

        return $result;
    }

    /**
     * Live files modified since the last run started are expected to differ and
     * are only counted. Of the rest, each must be on the target at its size.
     *
     * @param  array<string, LocalFile>  $live
     * @param  array<string, int>  $remote
     * @return array<string, LocalFile> the live files the target holds unchanged, eligible for hashing
     */
    private function compare(array $live, array $remote, Carbon $since, DrillResult $result): array
    {
        $comparable = [];

        foreach ($live as $path => $file) {
            if ($file->modifiedAt >= $since->getTimestamp()) {
                $result->changedSinceBackup++;

                continue;
            }

            if (! array_key_exists($path, $remote)) {
                $result->missing[] = $path;
            } elseif ($remote[$path] !== $file->size) {
                $result->sizeMismatches[] = ['path' => $path, 'live' => $file->size, 'remote' => $remote[$path]];
            } else {
                $comparable[$path] = $file;
            }
        }

        $result->extra = array_map(strval(...), array_keys(array_diff_key($remote, $live)));

        return $comparable;
    }

    /**
     * @param  array<string, LocalFile>  $comparable
     * @param  list<string>  $paths
     */
    private function verify(array $comparable, array $paths, string $scratch, DrillResult $result): void
    {
        foreach ($paths as $path) {
            $downloaded = "{$scratch}/{$path}";
            $live = $comparable[$path];

            if (! is_file($downloaded)) {
                $result->corrupt[] = "{$path} (not downloaded)";

                continue;
            }

            $result->downloaded++;
            $result->downloadedBytes += (int) filesize($downloaded);

            if (filesize($downloaded) !== $live->size || hash_file('sha256', $downloaded) !== hash_file('sha256', $live->absolutePath)) {
                $result->corrupt[] = $path;
            }
        }
    }

    /**
     * @param  list<string>  $paths
     * @return list<string>
     */
    private static function pick(array $paths, int $count): array
    {
        if (count($paths) <= $count) {
            return $paths;
        }

        $picked = array_rand(array_flip($paths), $count);

        return array_map(strval(...), (array) $picked);
    }

    /**
     * @return array<string, mixed>
     */
    private static function context(DrillResult $result): array
    {
        return [
            'target' => $result->target,
            'live_files' => $result->liveFiles,
            'remote_files' => $result->remoteFiles,
            'downloaded' => $result->downloaded,
            'missing' => array_slice($result->missing, 0, 20),
            'size_mismatches' => array_slice($result->sizeMismatches, 0, 20),
            'corrupt' => array_slice($result->corrupt, 0, 20),
            'seconds' => $result->seconds,
        ];
    }
}
