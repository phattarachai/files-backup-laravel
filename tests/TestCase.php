<?php

declare(strict_types=1);

namespace Phattarachai\FilesBackupLaravel\Tests;

use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\File;
use Orchestra\Testbench\TestCase as BaseTestCase;
use Override;
use Phattarachai\FilesBackupLaravel\FilesBackupServiceProvider;

abstract class TestCase extends BaseTestCase
{
    /**
     * A fresh live directory per test, configured as the `media` set's only path.
     */
    public string $source;

    #[Override]
    protected function setUp(): void
    {
        parent::setUp();

        $this->source = sys_get_temp_dir().'/files-backup-test-'.bin2hex(random_bytes(6)).'/public';
        mkdir($this->source, 0o777, true);

        config(['files-backup.sets' => [
            'media' => ['paths' => ['public' => $this->source], 'exclude' => ['.DS_Store', 'cache/**']],
        ]]);
    }

    #[Override]
    protected function tearDown(): void
    {
        File::deleteDirectory(dirname($this->source));

        parent::tearDown();
    }

    /**
     * @param  Application  $app
     * @return array<int, class-string>
     */
    protected function getPackageProviders($app): array
    {
        return [FilesBackupServiceProvider::class];
    }

    /**
     * @param  Application  $app
     */
    protected function defineEnvironment($app): void
    {
        $app['config']->set('app.timezone', 'UTC');
        $app['config']->set('files-backup.target.path', 'myapp');
        $app['config']->set('files-backup.target.rclone.remote', [
            'type' => 'drive',
            'scope' => 'drive',
            'service_account_file' => '/etc/myapp/gdrive-sa.json',
            'team_drive' => '0ATEAMDRIVE',
        ]);
        $app['config']->set('files-backup.target.flysystem.disk', 'backup');
    }
}
