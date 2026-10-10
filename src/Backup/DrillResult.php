<?php

declare(strict_types=1);

namespace Phattarachai\FilesBackupLaravel\Backup;

/**
 * What `files:drill` found for one set.
 *
 * Live files are compared with the target as they are *now*, so anything
 * modified since the last run started is expected to differ and is only
 * counted ($changedSinceBackup). The drill fails on what a broken backup
 * looks like: a live file older than the last run missing from the target,
 * a size that differs, or a downloaded file whose hash does not match.
 */
final class DrillResult
{
    public ?string $error = null;

    public ?string $backedUpAt = null;

    public int $liveFiles = 0;

    public int $liveBytes = 0;

    public int $remoteFiles = 0;

    public int $remoteBytes = 0;

    public int $changedSinceBackup = 0;

    /** @var list<string> files on the target that are no longer live (deleted since, or `copy` mode) */
    public array $extra = [];

    /** @var list<string> live files older than the last run that the target does not have */
    public array $missing = [];

    /** @var list<array{path: string, live: int, remote: int}> */
    public array $sizeMismatches = [];

    /** @var list<string> downloaded files whose size or sha256 differs from the live file */
    public array $corrupt = [];

    public int $downloaded = 0;

    public int $downloadedBytes = 0;

    public float $seconds = 0.0;

    public function __construct(public readonly string $set, public readonly string $target) {}

    public function passed(): bool
    {
        return $this->error === null && $this->missing === [] && $this->sizeMismatches === [] && $this->corrupt === [];
    }

    public function failure(): string
    {
        return $this->error ?? sprintf(
            '[%s] %d missing, %d size mismatch(es), %d corrupt download(s)',
            $this->set,
            count($this->missing),
            count($this->sizeMismatches),
            count($this->corrupt),
        );
    }
}
