<?php

declare(strict_types=1);

namespace Phattarachai\FilesBackupLaravel\Backup;

/**
 * What one `files:backup` run did for one set. Counts a driver cannot tell
 * (rclone reports transfers, not which of them replaced a file) stay null.
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

        return sprintf(
            '[%s] ok: %d files (%s) → %s; uploaded %s, moved %s to versions, pruned %d old version folder(s), %.1fs.',
            $this->set,
            $this->files,
            self::humanBytes($this->bytes),
            $this->target,
            $this->uploaded === null ? '?' : $this->uploaded.($this->uploadedBytes === null ? '' : ' ('.self::humanBytes($this->uploadedBytes).')'),
            $this->versioned === null ? '?' : (string) $this->versioned,
            count($this->prunedVersions),
            $this->seconds,
        );
    }

    public static function humanBytes(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $power = $bytes > 0 ? min((int) floor(log($bytes, 1024)), count($units) - 1) : 0;

        return round($bytes / (1024 ** $power), 1).' '.$units[$power];
    }
}
