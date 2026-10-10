<?php

declare(strict_types=1);

namespace Phattarachai\FilesBackupLaravel\Backup;

use RuntimeException;

/**
 * One entry of `files-backup.sets`: the directories it backs up, each under a
 * label (its folder below current/ on the target), and its exclude globs.
 */
final readonly class BackupSet
{
    /**
     * @param  array<string, string>  $sources  label => absolute directory
     * @param  list<string>  $exclude
     */
    public function __construct(
        public string $name,
        public array $sources,
        public array $exclude,
    ) {}

    /**
     * @param  array<array-key, mixed>  $config
     */
    public static function fromConfig(string $name, array $config): self
    {
        if (preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]*$/', $name) !== 1) {
            throw new RuntimeException("Backup set name [{$name}] may only contain letters, digits, '.', '_' and '-'.");
        }

        $sources = [];

        foreach ((array) ($config['paths'] ?? []) as $key => $directory) {
            if (! is_string($directory) || $directory === '') {
                continue;
            }

            $directory = rtrim($directory, '/');
            $label = trim(is_string($key) ? $key : self::labelFor($directory), '/');

            if ($label === '' || in_array('..', explode('/', $label), true)) {
                throw new RuntimeException("Set [{$name}]: cannot derive a target folder for {$directory}; give it a key.");
            }

            if (isset($sources[$label])) {
                throw new RuntimeException("Set [{$name}]: two paths share the target folder [{$label}].");
            }

            $sources[$label] = $directory;
        }

        if ($sources === []) {
            throw new RuntimeException("Set [{$name}] lists no paths.");
        }

        $exclude = array_values(array_filter(
            array_map(strval(...), array_filter((array) ($config['exclude'] ?? []), is_scalar(...))),
            fn (string $pattern): bool => $pattern !== '',
        ));

        return new self($name, $sources, $exclude);
    }

    /**
     * A directory inside the app is stored under its path relative to the base
     * path (storage/app/public); one outside it under its absolute path.
     */
    private static function labelFor(string $directory): string
    {
        $base = rtrim(base_path(), '/').'/';

        return str_starts_with($directory, $base) ? substr($directory, strlen($base)) : ltrim($directory, '/');
    }
}
