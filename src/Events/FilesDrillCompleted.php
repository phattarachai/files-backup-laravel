<?php

declare(strict_types=1);

namespace Phattarachai\FilesBackupLaravel\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Phattarachai\FilesBackupLaravel\Backup\DrillResult;

/**
 * A restore drill finished for one set, passed or not — check $result->passed().
 */
final class FilesDrillCompleted
{
    use Dispatchable;

    public function __construct(public readonly DrillResult $result) {}
}
