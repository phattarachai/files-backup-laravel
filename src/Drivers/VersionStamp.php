<?php

declare(strict_types=1);

namespace Phattarachai\FilesBackupLaravel\Drivers;

use Illuminate\Support\Carbon;
use Throwable;

/**
 * The name of a versions/ folder: when the run that filled it started, in the
 * app's timezone, e.g. 2026-10-10_033000.
 */
final class VersionStamp
{
    public const string FORMAT = 'Y-m-d_His';

    public static function for(Carbon $at): string
    {
        return $at->copy()->setTimezone(self::timezone())->format(self::FORMAT);
    }

    /**
     * Null for a folder that is not a version stamp, which retention leaves alone.
     */
    public static function parse(string $folder): ?Carbon
    {
        $folder = trim($folder, '/');

        if (preg_match('/^\d{4}-\d{2}-\d{2}_\d{6}$/', $folder) !== 1) {
            return null;
        }

        try {
            $at = Carbon::createFromFormat(self::FORMAT, $folder, self::timezone());
        } catch (Throwable) {
            return null;
        }

        return $at instanceof Carbon ? $at : null;
    }

    private static function timezone(): string
    {
        return (string) config('app.timezone', 'UTC');
    }
}
