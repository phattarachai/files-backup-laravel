<?php

declare(strict_types=1);

namespace Phattarachai\FilesBackupLaravel\Support;

/**
 * One live file of a backup set.
 *
 * $path is where it lives on the target, below current/: the source's label
 * followed by the path inside the source, e.g. storage/app/public/1/photo.jpg.
 */
final readonly class LocalFile
{
    public function __construct(
        public string $path,
        public string $absolutePath,
        public int $size,
        public int $modifiedAt,
    ) {}
}
