<?php

declare(strict_types=1);

namespace Phattarachai\FilesBackupLaravel;

use Illuminate\Support\ServiceProvider;
use Override;
use Phattarachai\FilesBackupLaravel\Console\BackupCheckCommand;
use Phattarachai\FilesBackupLaravel\Console\BackupCommand;
use Phattarachai\FilesBackupLaravel\Console\DrillCommand;

class FilesBackupServiceProvider extends ServiceProvider
{
    #[Override]
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/files-backup.php', 'files-backup');
    }

    public function boot(): void
    {
        $this->publishes([
            __DIR__.'/../config/files-backup.php' => config_path('files-backup.php'),
        ], 'files-backup-config');

        if ($this->app->runningInConsole()) {
            $this->commands([
                BackupCheckCommand::class,
                BackupCommand::class,
                DrillCommand::class,
            ]);
        }
    }
}
