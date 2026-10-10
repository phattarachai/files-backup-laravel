<?php

declare(strict_types=1);

namespace Phattarachai\FilesBackupLaravel\Exceptions;

use Phattarachai\FilesBackupLaravel\Backup\DrillResult;
use RuntimeException;

final class FilesDrillFailed extends RuntimeException
{
    /**
     * @param  list<DrillResult>  $results  the failed sets
     */
    public static function for(array $results): self
    {
        return new self('Files restore drill failed: '.implode('; ', array_map(
            fn (DrillResult $result): string => $result->failure(),
            $results,
        )));
    }
}
