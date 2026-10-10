<?php

declare(strict_types=1);

use Phattarachai\FilesBackupLaravel\Tests\TestCase;

uses(TestCase::class)->in('Feature', 'Unit');

/**
 * Write a live file under the test's source directory and date it.
 */
function liveFile(string $relative, string $body = 'content', ?int $modifiedAt = null): string
{
    $path = test()->source.'/'.$relative;

    if (! is_dir(dirname($path))) {
        mkdir(dirname($path), 0o777, true);
    }

    file_put_contents($path, $body);
    touch($path, $modifiedAt ?? now()->subHour()->getTimestamp());

    return $path;
}
