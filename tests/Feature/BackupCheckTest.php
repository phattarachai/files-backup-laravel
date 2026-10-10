<?php

declare(strict_types=1);

use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use Phattarachai\FilesBackupLaravel\Events\FilesBackupChecked;
use Phattarachai\FilesBackupLaravel\Events\FilesBackupHealthy;
use Phattarachai\FilesBackupLaravel\Events\FilesBackupStale;

function stampOn(Filesystem $disk, string $set, Carbon $finishedAt, int $files = 107): void
{
    $disk->put("myapp/{$set}/status.json", json_encode([
        'version' => 1,
        'set' => $set,
        'driver' => 'flysystem',
        'started_at' => $finishedAt->copy()->subMinute()->toIso8601String(),
        'finished_at' => $finishedAt->toIso8601String(),
        'files' => $files,
        'bytes' => 47_000_000,
    ]));
}

beforeEach(function (): void {
    $this->travelTo(Carbon::parse('2026-10-10 12:00:00', 'UTC'));
    config(['files-backup.target.driver' => 'flysystem', 'files-backup.stale_after_hours' => 26]);
    $this->backup = Storage::fake('backup');
});

it('is healthy when the last run finished within stale_after_hours', function (): void {
    Event::fake([FilesBackupHealthy::class, FilesBackupStale::class]);
    stampOn($this->backup, 'media', now()->subHours(9));

    $this->artisan('files:backup-check')
        ->expectsOutputToContain('OK [media] disk backup:myapp/media last backed up 9h ago, 107 files (stale after 26h)')
        ->assertSuccessful();

    Event::assertDispatched(FilesBackupHealthy::class, fn (FilesBackupHealthy $event): bool => $event->set === 'media'
        && $event->ageHours() === 9.0
        && $event->files === 107
        && $event->backedUpAt?->eq(now()->subHours(9)) === true);
    Event::assertNotDispatched(FilesBackupStale::class);
});

it('is stale, and fails, when the last run is too old or was never recorded', function (?int $hoursAgo, string $output): void {
    Event::fake([FilesBackupStale::class]);

    if ($hoursAgo !== null) {
        stampOn($this->backup, 'media', now()->subHours($hoursAgo));
    }

    $this->artisan('files:backup-check')->expectsOutputToContain($output)->assertFailed();

    Event::assertDispatched(FilesBackupStale::class, fn (FilesBackupStale $event): bool => $event->error === null);
})->with([
    'too old' => [27, 'STALE [media] disk backup:myapp/media last backed up 27h ago'],
    'never' => [null, 'STALE [media] disk backup:myapp/media has no recorded backup'],
]);

it('is stale with the error when the target cannot be read', function (): void {
    Event::fake([FilesBackupStale::class]);
    config(['files-backup.target.driver' => 'rclone']);
    Process::fake(['*' => Process::result(errorOutput: 'Failed to create file system: couldn\'t find team drive', exitCode: 1)]);

    $this->artisan('files:backup-check')
        ->expectsOutputToContain('unreadable: rclone cat exited 1: Failed to create file system')
        ->assertFailed();

    Event::assertDispatched(FilesBackupStale::class, fn (FilesBackupStale $event): bool => str_contains((string) $event->error, 'team drive'));
});

it('treats rclone\'s "directory not found" as no recorded backup', function (): void {
    Event::fake([FilesBackupStale::class]);
    config(['files-backup.target.driver' => 'rclone']);
    Process::fake(['*' => Process::result(errorOutput: 'directory not found', exitCode: 3)]);

    $this->artisan('files:backup-check')->expectsOutputToContain('has no recorded backup')->assertFailed();

    Event::assertDispatched(FilesBackupStale::class, fn (FilesBackupStale $event): bool => $event->error === null && $event->backedUpAt === null);
});

/*
 * Against the real dispatcher, not Event::fake(): Laravel resolves listeners by
 * the event's class and its interfaces, never its parent class, and this is
 * the contract a host's listener relies on.
 */
it('delivers both outcomes to a listener on the FilesBackupChecked interface', function (): void {
    config(['files-backup.sets.uploads' => ['paths' => ['uploads' => $this->source]]]);
    stampOn($this->backup, 'media', now()->subHour());

    $received = [];
    Event::listen(FilesBackupChecked::class, function (FilesBackupChecked $event) use (&$received): void {
        $received[$event->set] = $event::class;
    });

    $this->artisan('files:backup-check')->assertFailed();

    expect($received)->toBe(['media' => FilesBackupHealthy::class, 'uploads' => FilesBackupStale::class]);
});

it('checks only the named sets, and rejects an unknown one', function (): void {
    config(['files-backup.sets.uploads' => ['paths' => ['uploads' => $this->source]]]);
    stampOn($this->backup, 'media', now()->subHour());

    $this->artisan('files:backup-check', ['--set' => ['media']])->assertSuccessful();

    expect(fn () => $this->artisan('files:backup-check', ['--set' => ['nope']])->run())
        ->toThrow(RuntimeException::class, 'Unknown backup set [nope]; configured: media, uploads.');
});

it('dispatches nothing while disabled', function (): void {
    Event::fake();
    config(['files-backup.enabled' => false]);

    $this->artisan('files:backup-check')->assertSuccessful();

    Event::assertNothingDispatched();
});
