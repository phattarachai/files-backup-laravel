<?php

declare(strict_types=1);

namespace Phattarachai\FilesBackupLaravel\Events;

use Illuminate\Support\Carbon;

/**
 * Both outcomes of `files:backup-check`. Laravel resolves listeners by the
 * event's own class and the interfaces it implements, never its parent class,
 * so listen on this interface to receive FilesBackupStale and
 * FilesBackupHealthy alike.
 */
interface FilesBackupChecked
{
    public string $set { get; }

    public string $target { get; }

    public ?Carbon $backedUpAt { get; }

    public ?int $ageSeconds { get; }

    public int $staleAfterHours { get; }

    public ?int $files { get; }

    public ?int $bytes { get; }

    public ?string $error { get; }

    public function ageHours(): ?float;

    public function describe(): string;
}
