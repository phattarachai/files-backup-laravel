<?php

declare(strict_types=1);

namespace Phattarachai\FilesBackupLaravel\Exceptions;

use Phattarachai\FilesBackupLaravel\Backup\SetReport;
use RuntimeException;

/**
 * One or more sets failed to back up. Thrown after every set has run, so it
 * reaches the exception handler (and an error tracker) once per run.
 */
final class FilesBackupFailed extends RuntimeException
{
    /**
     * @param  list<SetReport>  $reports  the failed sets
     */
    public static function for(array $reports): self
    {
        return new self('Files backup failed: '.implode('; ', array_map(
            fn (SetReport $report): string => "[{$report->set}] {$report->error}",
            $reports,
        )));
    }
}
