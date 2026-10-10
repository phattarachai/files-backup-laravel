<?php

declare(strict_types=1);

namespace Phattarachai\FilesBackupLaravel\Drivers;

use Phattarachai\FilesBackupLaravel\Support\Scrubber;
use RuntimeException;

/**
 * The rclone remote built from `files-backup.target.rclone`: environment
 * variables instead of an rclone.conf, plus the path every set lives under.
 *
 * With `crypt` on, a second remote (`{name}crypt`) wraps `{name}:{path}`, so
 * set folders are addressed relative to the crypt remote's root.
 */
final readonly class RcloneRemote
{
    /**
     * Option names whose values never reach a log line or exception message.
     */
    private const string SENSITIVE = '/PASS|SECRET|TOKEN|CREDENTIALS|KEY|BEARER|SESSION/';

    /**
     * @param  array<string, string>  $env
     * @param  list<string>  $flags
     */
    public function __construct(
        public string $binary,
        public string $root,
        public array $env,
        public bool $crypt,
        public bool $verify,
        public array $flags,
        public int $timeout,
        public Scrubber $scrubber,
    ) {}

    public static function fromConfig(string $path): self
    {
        $config = (array) config('files-backup.target.rclone', []);
        $name = strtolower((string) ($config['name'] ?? 'filesbackup'));

        if (preg_match('/^[a-z0-9_]+$/', $name) !== 1) {
            throw new RuntimeException("files-backup.target.rclone.name [{$name}] may only contain letters, digits and '_' (it becomes an environment variable name).");
        }

        $env = self::options($name, (array) ($config['remote'] ?? []));

        if (! isset($env[self::variable($name, 'type')])) {
            throw new RuntimeException('files-backup.target.rclone.remote.type is not set (e.g. drive, s3, sftp).');
        }

        $base = "{$name}:{$path}";
        $crypt = (array) ($config['crypt'] ?? []);
        $encrypted = (bool) ($crypt['enabled'] ?? false);

        if ($encrypted) {
            if (! is_string($crypt['password'] ?? null) || $crypt['password'] === '') {
                throw new RuntimeException('files-backup.target.rclone.crypt is enabled but has no password (the `rclone obscure` form).');
            }

            unset($crypt['enabled']);
            $env += self::options("{$name}crypt", ['type' => 'crypt', 'remote' => $base] + $crypt);
        }

        $scrubber = new Scrubber;

        foreach ($env as $variable => $value) {
            if (preg_match(self::SENSITIVE, substr($variable, strlen('RCLONE_CONFIG_'))) === 1) {
                $scrubber->add($value);
            }
        }

        return new self(
            binary: (string) ($config['binary'] ?? 'rclone'),
            root: $encrypted ? "{$name}crypt:" : $base,
            env: $env,
            crypt: $encrypted,
            verify: (bool) ($config['verify'] ?? true),
            flags: array_values(array_map(strval(...), array_filter((array) ($config['flags'] ?? []), is_scalar(...)))),
            timeout: max(1, (int) ($config['timeout'] ?? 3600)),
            scrubber: $scrubber,
        );
    }

    /**
     * A path on the remote, e.g. filesbackup:myapp/media/current.
     */
    public function path(string $relative): string
    {
        $relative = trim($relative, '/');

        if ($relative === '') {
            return $this->root;
        }

        return str_ends_with($this->root, ':') ? $this->root.$relative : "{$this->root}/{$relative}";
    }

    /**
     * @param  array<array-key, mixed>  $options
     * @return array<string, string>
     */
    private static function options(string $remote, array $options): array
    {
        $env = [];

        foreach ($options as $key => $value) {
            if ($value === null || $value === '' || ! is_scalar($value)) {
                continue;
            }

            $env[self::variable($remote, (string) $key)] = is_bool($value) ? ($value ? 'true' : 'false') : (string) $value;
        }

        return $env;
    }

    private static function variable(string $remote, string $option): string
    {
        return 'RCLONE_CONFIG_'.strtoupper($remote).'_'.strtoupper((string) preg_replace('/[^A-Za-z0-9]+/', '_', $option));
    }
}
