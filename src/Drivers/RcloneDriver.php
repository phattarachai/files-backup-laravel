<?php

declare(strict_types=1);

namespace Phattarachai\FilesBackupLaravel\Drivers;

use Closure;
use Illuminate\Contracts\Process\ProcessResult;
use Illuminate\Process\Exceptions\ProcessTimedOutException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Process;
use Phattarachai\FilesBackupLaravel\Backup\BackupConfig;
use Phattarachai\FilesBackupLaravel\Backup\BackupSet;
use Phattarachai\FilesBackupLaravel\Backup\RunStamp;
use Phattarachai\FilesBackupLaravel\Backup\SetReport;
use Phattarachai\FilesBackupLaravel\Exceptions\RcloneFailed;
use RuntimeException;

/**
 * Backs a set up with `rclone sync|copy --backup-dir`, one call per source
 * directory, then proves it with `rclone check` (`cryptcheck` under crypt).
 *
 * The remote is configured only through environment variables, so nothing
 * secret is ever on rclone's command line, and every message that leaves this
 * class goes through the remote's scrubber.
 */
final readonly class RcloneDriver implements Driver
{
    /**
     * rclone's exit code for "directory not found".
     */
    public const int EXIT_DIRECTORY_NOT_FOUND = 3;

    public function __construct(private RcloneRemote $remote, private BackupConfig $config) {}

    public function describe(BackupSet $set): string
    {
        return 'rclone '.$this->remote->path($set->name);
    }

    public function backup(BackupSet $set, array $files, string $versionStamp, SetReport $report, Closure $note): void
    {
        $report->uploaded = 0;
        $report->uploadedBytes = 0;

        foreach ($set->sources as $label => $directory) {
            $current = $this->remote->path("{$set->name}/current/{$label}");

            $note("[{$set->name}] {$this->config->mode} {$label} → {$current}");

            $result = $this->run([
                $this->config->mode,
                $directory,
                $current,
                '--backup-dir', $this->remote->path("{$set->name}/versions/{$versionStamp}/{$label}"),
                ...$this->excludes($set),
                '--stats', '1h',
                '--stats-log-level', 'NOTICE',
                ...$this->remote->flags,
            ]);

            $stats = self::stats($result->errorOutput());
            $report->uploaded += (int) ($stats['transfers'] ?? 0);
            $report->uploadedBytes += (int) ($stats['bytes'] ?? 0);

            if ($this->remote->verify) {
                $note("[{$set->name}] ".($this->remote->crypt ? 'cryptcheck' : 'check')." {$label}");

                $this->run([
                    $this->remote->crypt ? 'cryptcheck' : 'check',
                    $directory,
                    $current,
                    ...$this->excludes($set),
                    ...($this->config->mode === BackupConfig::MODE_COPY ? ['--one-way'] : []),
                    ...$this->remote->flags,
                ]);
            }
        }
    }

    public function pruneVersions(BackupSet $set, Carbon $cutoff, Closure $note): array
    {
        $versions = $this->remote->path("{$set->name}/versions");
        $result = $this->run(['lsf', '--dirs-only', $versions], [self::EXIT_DIRECTORY_NOT_FOUND]);

        $pruned = [];

        foreach (preg_split('/\R/u', trim($result->output())) ?: [] as $folder) {
            $folder = trim($folder, '/');
            $stamp = VersionStamp::parse($folder);

            if ($stamp === null || ! $stamp->lt($cutoff)) {
                continue;
            }

            $this->run(['purge', "{$versions}/{$folder}"]);
            $pruned[] = "versions/{$folder}";
            $note("[{$set->name}] pruned versions/{$folder}");
        }

        return $pruned;
    }

    public function writeStatus(BackupSet $set, RunStamp $stamp): void
    {
        $this->run(['rcat', $this->remote->path("{$set->name}/status.json")], input: $stamp->toJson());
    }

    public function readStatus(BackupSet $set): ?RunStamp
    {
        $result = $this->run(['cat', $this->remote->path("{$set->name}/status.json")], [self::EXIT_DIRECTORY_NOT_FOUND]);

        return $result->exitCode() === 0 && trim($result->output()) !== '' ? RunStamp::fromJson($result->output()) : null;
    }

    public function listCurrent(BackupSet $set): array
    {
        $result = $this->run(
            ['lsjson', '-R', '--files-only', '--no-mimetype', '--no-modtime', $this->remote->path("{$set->name}/current")],
            [self::EXIT_DIRECTORY_NOT_FOUND],
        );

        if ($result->exitCode() !== 0) {
            return [];
        }

        $entries = json_decode($result->output(), true);

        if (! is_array($entries)) {
            throw new RcloneFailed($this->remote->scrubber->scrub('rclone lsjson returned something other than a JSON list.'));
        }

        $files = [];

        foreach ($entries as $entry) {
            if (is_array($entry) && isset($entry['Path']) && ! ($entry['IsDir'] ?? false)) {
                $files[(string) $entry['Path']] = (int) ($entry['Size'] ?? 0);
            }
        }

        ksort($files, SORT_STRING);

        return $files;
    }

    public function download(BackupSet $set, ?array $paths, string $directory): void
    {
        $list = null;

        try {
            if ($paths !== null) {
                $list = tempnam(sys_get_temp_dir(), 'files-backup-drill-');

                if ($list === false || file_put_contents($list, implode("\n", $paths)."\n") === false) {
                    throw new RuntimeException('Could not write the drill\'s file list.');
                }
            }

            $this->run([
                'copy',
                $this->remote->path("{$set->name}/current"),
                $directory,
                ...($list === null ? [] : ['--files-from-raw', $list, '--no-traverse']),
                ...$this->remote->flags,
            ]);
        } finally {
            if (is_string($list) && is_file($list)) {
                unlink($list);
            }
        }
    }

    /**
     * @return list<string>
     */
    private function excludes(BackupSet $set): array
    {
        return array_map(fn (string $pattern): string => "--exclude={$pattern}", $set->exclude);
    }

    /**
     * @param  list<string>  $args
     * @param  list<int>  $alsoOk  exit codes besides 0 the caller handles itself
     */
    private function run(array $args, array $alsoOk = [], ?string $input = null): ProcessResult
    {
        $process = Process::env($this->remote->env)->timeout($this->remote->timeout);

        if ($input !== null) {
            $process = $process->input($input);
        }

        try {
            // JSON logs on every call, so an error message keeps rclone's errors and drops its notices.
            $result = $process->run([$this->remote->binary, ...$args, '--use-json-log']);
        } catch (ProcessTimedOutException) {
            throw new RcloneFailed($this->remote->scrubber->scrub("rclone {$args[0]} timed out after {$this->remote->timeout}s."));
        }

        if ($result->exitCode() !== 0 && ! in_array($result->exitCode(), $alsoOk, true)) {
            throw new RcloneFailed($this->remote->scrubber->scrub(sprintf(
                'rclone %s exited %s: %s',
                $args[0],
                $result->exitCode() ?? 'without a code',
                self::errors($result->errorOutput()) ?: trim($result->output()) ?: 'no output',
            )));
        }

        return $result;
    }

    /**
     * The final stats block of a `--use-json-log` run.
     *
     * @return array<string, mixed>
     */
    private static function stats(string $log): array
    {
        $stats = [];

        foreach (preg_split('/\R/u', $log) ?: [] as $line) {
            $entry = json_decode($line, true);

            if (is_array($entry) && is_array($entry['stats'] ?? null)) {
                $stats = $entry['stats'];
            }
        }

        return $stats;
    }

    /**
     * The last few error lines of rclone's log, JSON or plain.
     */
    private static function errors(string $log): string
    {
        $lines = [];

        foreach (preg_split('/\R/u', trim($log)) ?: [] as $line) {
            $entry = json_decode($line, true);

            if (! is_array($entry)) {
                $line = trim($line);
                $line === '' || $lines[] = $line;

                continue;
            }

            if (in_array($entry['level'] ?? '', ['error', 'critical', 'fatal'], true)) {
                $lines[] = trim((string) ($entry['msg'] ?? ''));
            }
        }

        return implode(' | ', array_slice(array_filter($lines), -5));
    }
}
