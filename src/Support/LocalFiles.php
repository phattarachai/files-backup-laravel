<?php

declare(strict_types=1);

namespace Phattarachai\FilesBackupLaravel\Support;

use Phattarachai\FilesBackupLaravel\Backup\BackupSet;
use RuntimeException;
use Symfony\Component\Finder\Finder;

/**
 * Lists a set's live files the way rclone sees them: regular files, hidden
 * ones included, symlinks skipped, the set's excludes applied.
 */
final class LocalFiles
{
    /**
     * @return array<string, LocalFile> keyed by LocalFile::$path, sorted
     */
    public static function scan(BackupSet $set): array
    {
        $files = [];

        foreach ($set->sources as $label => $directory) {
            if (! is_dir($directory)) {
                throw new RuntimeException("Set [{$set->name}]: source directory {$directory} does not exist.");
            }

            $finder = Finder::create()->files()->in($directory)->ignoreDotFiles(false)->ignoreVCS(false);

            foreach ($finder as $file) {
                $inner = str_replace('\\', '/', $file->getRelativePathname());

                if ($file->isLink() || Glob::matchesAny($set->exclude, $inner)) {
                    continue;
                }

                $path = "{$label}/{$inner}";
                $files[$path] = new LocalFile($path, $file->getPathname(), (int) $file->getSize(), (int) $file->getMTime());
            }
        }

        ksort($files, SORT_STRING);

        return $files;
    }

    /**
     * @param  array<string, LocalFile>  $files
     */
    public static function bytes(array $files): int
    {
        return array_sum(array_map(fn (LocalFile $file): int => $file->size, $files));
    }
}
