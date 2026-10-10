<?php

declare(strict_types=1);

namespace Phattarachai\FilesBackupLaravel\Backup;

use Illuminate\Support\Carbon;
use JsonException;
use RuntimeException;

/**
 * The `status.json` a successful run leaves beside a set's current/ folder.
 * files:backup-check judges staleness by it, because a set whose files have
 * not changed for a week still has to prove it was backed up last night.
 */
final readonly class RunStamp
{
    public const int VERSION = 1;

    public function __construct(
        public string $set,
        public string $driver,
        public Carbon $startedAt,
        public Carbon $finishedAt,
        public int $files,
        public int $bytes,
    ) {}

    public function toJson(): string
    {
        return json_encode([
            'version' => self::VERSION,
            'set' => $this->set,
            'driver' => $this->driver,
            'started_at' => $this->startedAt->toIso8601String(),
            'finished_at' => $this->finishedAt->toIso8601String(),
            'files' => $this->files,
            'bytes' => $this->bytes,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n";
    }

    public static function fromJson(string $json): self
    {
        try {
            $data = json_decode($json, true, 8, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new RuntimeException("status.json is not valid JSON: {$e->getMessage()}", previous: $e);
        }

        if (! is_array($data) || ! isset($data['started_at'], $data['finished_at'])) {
            throw new RuntimeException('status.json is missing started_at / finished_at.');
        }

        return new self(
            set: (string) ($data['set'] ?? ''),
            driver: (string) ($data['driver'] ?? ''),
            startedAt: Carbon::parse((string) $data['started_at']),
            finishedAt: Carbon::parse((string) $data['finished_at']),
            files: (int) ($data['files'] ?? 0),
            bytes: (int) ($data['bytes'] ?? 0),
        );
    }
}
