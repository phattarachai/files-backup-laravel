<?php

declare(strict_types=1);

namespace Phattarachai\FilesBackupLaravel\Drivers;

use Illuminate\Contracts\Container\Container;
use Phattarachai\FilesBackupLaravel\Backup\BackupConfig;
use RuntimeException;

final readonly class DriverFactory
{
    public function __construct(private Container $container) {}

    public function make(BackupConfig $config): Driver
    {
        return match ($config->driver) {
            'rclone' => new RcloneDriver(RcloneRemote::fromConfig($config->path), $config),
            'flysystem' => new FlysystemDriver($config),
            default => $this->custom($config->driver),
        };
    }

    /**
     * A class name implementing Driver, resolved from the container.
     */
    private function custom(string $driver): Driver
    {
        if (! class_exists($driver) || ! is_subclass_of($driver, Driver::class)) {
            throw new RuntimeException("Unknown files-backup driver [{$driver}]; use 'rclone', 'flysystem' or a class implementing ".Driver::class.'.');
        }

        /** @var Driver */
        return $this->container->make($driver);
    }
}
