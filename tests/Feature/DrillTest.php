<?php

declare(strict_types=1);

use Illuminate\Process\PendingProcess;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use Phattarachai\FilesBackupLaravel\Events\FilesDrillCompleted;
use Phattarachai\FilesBackupLaravel\Exceptions\FilesDrillFailed;

beforeEach(function (): void {
    $this->travelTo(Carbon::parse('2026-10-10 03:30:00', 'UTC'));
    config(['files-backup.target.driver' => 'flysystem']);
    $this->backup = Storage::fake('backup');

    liveFile('1/photo.jpg', 'jpeg', now()->subDays(3)->getTimestamp());
    liveFile('2/doc.pdf', 'pdf!', now()->subDays(2)->getTimestamp());
    liveFile('3/clip.mp4', 'mp4mp4', now()->subDay()->getTimestamp());
    $this->artisan('files:backup')->assertSuccessful();

    $this->travel(1)->hour();
});

it('passes when every file is on the target and the downloads hash like the live files', function (): void {
    Event::fake([FilesDrillCompleted::class]);

    $this->artisan('files:drill', ['--all' => true])
        ->expectsOutputToContain('Live 3 files (14 B) · target 3 files (14 B) · 0 changed since the backup · 0 only on the target')
        ->expectsOutputToContain('Downloaded and hashed 3 file(s)')
        ->expectsOutputToContain('[media] Drill PASSED.')
        ->assertSuccessful();

    Event::assertDispatched(FilesDrillCompleted::class, fn (FilesDrillCompleted $event): bool => $event->result->passed() && $event->result->downloaded === 3);
    expect(glob(storage_path('app/files-backup-drill/*')))->toBe([]);
});

it('downloads only a sample by default', function (): void {
    $this->artisan('files:drill', ['--sample' => 2])
        ->expectsOutputToContain('Downloaded and hashed 2 file(s)')
        ->assertSuccessful();
});

it('fails on a file the target lost, a size that differs and a download that does not hash', function (): void {
    $this->backup->delete('myapp/media/current/public/1/photo.jpg');
    $this->backup->put('myapp/media/current/public/2/doc.pdf', 'pdf');
    $this->backup->put('myapp/media/current/public/3/clip.mp4', 'MP4MP4');

    expect(fn () => $this->artisan('files:drill', ['--all' => true])->run())
        ->toThrow(FilesDrillFailed::class, '[media] 1 missing, 1 size mismatch(es), 1 corrupt download(s)');
});

it('reports what changed since the backup without failing on it', function (): void {
    liveFile('2/doc.pdf', 'edited', now()->getTimestamp());
    liveFile('4/new.jpg', 'new', now()->getTimestamp());
    unlink($this->source.'/1/photo.jpg');

    $this->artisan('files:drill', ['--all' => true])
        ->expectsOutputToContain('Live 3 files (15 B) · target 3 files (14 B) · 2 changed since the backup · 1 only on the target')
        ->expectsOutputToContain('Drill PASSED.')
        ->assertSuccessful();
});

it('fails when the target records no backup at all', function (): void {
    Storage::fake('backup');

    expect(fn () => $this->artisan('files:drill')->run())->toThrow(FilesDrillFailed::class, 'has no recorded backup to drill');
});

it('rejects a --sample that is not a positive number', function (): void {
    $this->artisan('files:drill', ['--sample' => '0'])->expectsOutputToContain('--sample must be')->assertFailed();
});

it('drills through rclone: lists with lsjson and downloads only the sampled paths', function (): void {
    config(['files-backup.target.driver' => 'rclone']);
    $stamp = $this->backup->get('myapp/media/status.json');

    Process::fake(function (PendingProcess $process) use ($stamp) {
        return match ($process->command[1]) {
            'cat' => Process::result($stamp),
            'lsjson' => Process::result(json_encode([
                ['Path' => 'public/1/photo.jpg', 'Name' => 'photo.jpg', 'Size' => 4, 'IsDir' => false],
                ['Path' => 'public/2/doc.pdf', 'Name' => 'doc.pdf', 'Size' => 4, 'IsDir' => false],
                ['Path' => 'public/3/clip.mp4', 'Name' => 'clip.mp4', 'Size' => 6, 'IsDir' => false],
            ])),
            'copy' => (function () use ($process) {
                // Act like rclone: copy each listed path out of "the remote" (the live files here).
                $to = $process->command[3];
                $list = $process->command[array_search('--files-from-raw', $process->command, true) + 1];

                foreach (file($list, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $path) {
                    @mkdir(dirname("{$to}/{$path}"), 0o777, true);
                    copy(test()->source.'/'.substr($path, strlen('public/')), "{$to}/{$path}");
                }

                return Process::result();
            })(),
        };
    });

    $this->artisan('files:drill', ['--sample' => 2])
        ->expectsOutputToContain('Downloaded and hashed 2 file(s)')
        ->expectsOutputToContain('Drill PASSED.')
        ->assertSuccessful();

    Process::assertRan(fn (PendingProcess $process): bool => $process->command === [
        'rclone', 'lsjson', '-R', '--files-only', '--no-mimetype', '--no-modtime', 'filesbackup:myapp/media/current', '--use-json-log',
    ]);
    Process::assertRan(fn (PendingProcess $process): bool => array_slice($process->command, 0, 3) === ['rclone', 'copy', 'filesbackup:myapp/media/current']);
});
