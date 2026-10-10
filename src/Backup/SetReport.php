<?php

declare(strict_types=1);

namespace Phattarachai\FilesBackupLaravel\Backup;

/**
 * What one `files:backup` run did for one set. Counts a driver cannot tell
 * (rclone reports transfers, not which of them replaced a file) stay null and
 * are left out of the summary.
 */
final class SetReport
{
    public int $files = 0;

    public int $bytes = 0;

    public ?int $uploaded = null;

    public ?int $uploadedBytes = null;

    public ?int $versioned = null;

    /** @var list<string> version folders deleted by retention */
    public array $prunedVersions = [];

    public ?string $versionStamp = null;

    public ?string $error = null;

    public float $seconds = 0.0;

    public function __construct(public readonly string $set, public readonly string $target) {}

    public function failed(): bool
    {
        return $this->error !== null;
    }

    public function summary(): string
    {
        if ($this->failed()) {
            return "[{$this->set}] FAILED: {$this->error}";
        }

        $parts = array_filter([
            $this->uploaded === null ? null : "uploaded {$this->uploaded}".($this->uploadedBytes === null ? '' : ' ('.self::humanBytes($this->uploadedBytes).')'),
            $this->versioned === null ? null : "moved {$this->versioned} to versions",
            sprintf('pruned %d old version folder(s)', count($this->prunedVersions)),
            sprintf('%.1fs', $this->seconds),
        ]);

        return sprintf('[%s] ok: %d files (%s) → %s; %s.', $this->set, $this->files, self::humanBytes($this->bytes), $this->target, implode(', ', $parts));
    }

    public static function humanBytes(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $power = $bytes > 0 ? min((int) floor(log($bytes, 1024)), count($units) - 1) : 0;

        return round($bytes / (1024 ** $power), 1).' '.$units[$power];
    }
}
