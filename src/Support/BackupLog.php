<?php

declare(strict_types=1);

namespace Phattarachai\FilesBackupLaravel\Support;

use Illuminate\Support\Facades\Log;
use Psr\Log\LoggerInterface;

/**
 * The log channel every run reports to (`files-backup.log_channel`, or the
 * default one).
 */
final class BackupLog
{
    public static function channel(): LoggerInterface
    {
        $channel = config('files-backup.log_channel');

        return Log::channel(is_string($channel) && $channel !== '' ? $channel : null);
    }
}
