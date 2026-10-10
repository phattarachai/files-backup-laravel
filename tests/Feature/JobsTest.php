<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
use Phattarachai\FilesBackupLaravel\Events\FilesBackupStale;
use Phattarachai\FilesBackupLaravel\Exceptions\FilesBackupFailed;
use Phattarachai\FilesBackupLaravel\Exceptions\FilesDrillFailed;
use Phattarachai\FilesBackupLaravel\Jobs\BackupFiles;
use Phattarachai\FilesBackupLaravel\Jobs\CheckFilesBackup;
use Phattarachai\FilesBackupLaravel\Jobs\RunFilesDrill;

beforeEach(function (): void {
    config(['files-backup.target.driver' => 'flysystem']);
    $this->backup = Storage::fake('backup');
});

it('goes on the configured queue and never retries', function (string $job): void {
    config(['files-backup.queue' => 'backups']);

    $instance = new $job;

    expect($instance->queue)->toBe('backups');
    expect($instance->tries)->toBe(1);
})->with([BackupFiles::class, CheckFilesBackup::class, RunFilesDrill::class]);

it('backs up when run as a job', function (): void {
    liveFile('a.jpg');

    dispatch_sync(new BackupFiles);

    expect($this->backup->exists('myapp/media/current/public/a.jpg'))->toBeTrue();
});

it('fails the backup job when a set failed', function (): void {
    config(['files-backup.sets.media.paths' => ['/nonexistent']]);

    expect(fn () => dispatch_sync(new BackupFiles))->toThrow(FilesBackupFailed::class, '[media]');
});

it('reports a stale set through the event, not by failing the check job', function (): void {
    Event::fake([FilesBackupStale::class]);

    dispatch_sync(new CheckFilesBackup);

    Event::assertDispatched(FilesBackupStale::class);
});

it('fails the drill job when the drill fails', function (): void {
    expect(fn () => dispatch_sync(new RunFilesDrill))->toThrow(FilesDrillFailed::class, 'has no recorded backup');
});

it('does nothing while disabled', function (): void {
    config(['files-backup.enabled' => false, 'files-backup.sets.media.paths' => ['/nonexistent']]);

    dispatch_sync(new BackupFiles);
    dispatch_sync(new RunFilesDrill);

    expect($this->backup->allFiles())->toBe([]);
});
