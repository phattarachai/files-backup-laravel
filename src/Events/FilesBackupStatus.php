<?php

declare(strict_types=1);

namespace Phattarachai\FilesBackupLaravel\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Support\Carbon;

/**
 * The state of one set's last successful run, as `files:backup-check` found
 * it. Listen for FilesBackupStale / FilesBackupHealthy, or for the
 * FilesBackupChecked interface to get both (a listener on this abstract class
 * never fires: Laravel matches interfaces, not parent classes).
 */
abstract class FilesBackupStatus implements FilesBackupChecked
{
    use Dispatchable;

    /**
     * @param  string  $set  the backup set's name
     * @param  string  $target  where it is backed up, e.g. "rclone filesbackup:myapp/media"
     * @param  ?Carbon  $backedUpAt  when the last successful run finished, null when none is recorded
     * @param  ?int  $ageSeconds  how long ago that was, null when none is recorded
     * @param  int  $staleAfterHours  the threshold it was judged against
     * @param  ?int  $files  files that run backed up
     * @param  ?int  $bytes  their total size
     * @param  ?string  $error  why the target could not be read, if it could not
     */
    public function __construct(
        public readonly string $set,
        public readonly string $target,
        public readonly ?Carbon $backedUpAt,
        public readonly ?int $ageSeconds,
        public readonly int $staleAfterHours,
        public readonly ?int $files = null,
        public readonly ?int $bytes = null,
        public readonly ?string $error = null,
    ) {}

    public function ageHours(): ?float
    {
        return $this->ageSeconds === null ? null : round($this->ageSeconds / 3600, 2);
    }

    public function describe(): string
    {
        return match (true) {
            $this->error !== null => "[{$this->set}] {$this->target} unreadable: {$this->error}",
            $this->backedUpAt === null => "[{$this->set}] {$this->target} has no recorded backup",
            default => "[{$this->set}] {$this->target} last backed up {$this->ageHours()}h ago, {$this->files} files (stale after {$this->staleAfterHours}h)",
        };
    }
}
