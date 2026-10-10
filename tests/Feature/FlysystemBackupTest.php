<?php

declare(strict_types=1);

use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Phattarachai\FilesBackupLaravel\Exceptions\FilesBackupFailed;

beforeEach(function (): void {
    $this->travelTo(Carbon::parse('2026-10-10 03:30:00', 'UTC'));

    config(['files-backup.target.driver' => 'flysystem']);
    // The target's own default is public: the backup must not inherit it.
    $this->backup = Storage::fake('backup', ['visibility' => 'public']);
});

it('uploads every live file privately under current/, skipping excludes, and records the run', function (): void {
    liveFile('1/photo.jpg', 'jpeg');
    liveFile('2/conversions/thumb.webp', 'webp');
    liveFile('.DS_Store', 'junk');
    liveFile('cache/x/tmp.bin', 'junk');

    $this->artisan('files:backup')
        ->expectsOutputToContain('[media] ok: 2 files (8 B) → disk backup:myapp/media; uploaded 2 (8 B)')
        ->assertSuccessful();

    expect($this->backup->allFiles('myapp/media/current'))->toEqualCanonicalizing([
        'myapp/media/current/public/1/photo.jpg',
        'myapp/media/current/public/2/conversions/thumb.webp',
    ]);
    expect($this->backup->get('myapp/media/current/public/1/photo.jpg'))->toBe('jpeg');
    expect($this->backup->getVisibility('myapp/media/current/public/1/photo.jpg'))->toBe('private');
    expect($this->backup->getVisibility('myapp/media/manifest.json'))->toBe('private');

    $status = json_decode((string) $this->backup->get('myapp/media/status.json'), true);
    expect($status)->toMatchArray(['set' => 'media', 'driver' => 'flysystem', 'files' => 2, 'bytes' => 8, 'finished_at' => '2026-10-10T03:30:00+00:00']);

    $manifest = json_decode((string) $this->backup->get('myapp/media/manifest.json'), true);
    expect($manifest['files']['public/1/photo.jpg']['sha256'])->toBe(hash('sha256', 'jpeg'));
});

it('uploads nothing the manifest already records unchanged', function (): void {
    liveFile('1/photo.jpg', 'jpeg');
    $this->artisan('files:backup')->assertSuccessful();

    $this->artisan('files:backup')
        ->expectsOutputToContain('uploaded 0 (0 B), moved 0 to versions')
        ->assertSuccessful();
});

it('moves the replaced copy of a changed file into versions/ before uploading the new one', function (): void {
    liveFile('1/photo.jpg', 'old');
    $this->artisan('files:backup')->assertSuccessful();

    $this->travel(1)->day();
    liveFile('1/photo.jpg', 'new!', now()->getTimestamp());

    $this->artisan('files:backup')
        ->expectsOutputToContain('uploaded 1 (4 B), moved 1 to versions')
        ->assertSuccessful();

    expect($this->backup->get('myapp/media/current/public/1/photo.jpg'))->toBe('new!');
    expect($this->backup->get('myapp/media/versions/2026-10-11_033000/public/1/photo.jpg'))->toBe('old');
});

it('keeps a deleted file in versions/ in sync mode, and in current/ in copy mode', function (string $mode, string $kept): void {
    config(['files-backup.target.mode' => $mode]);
    liveFile('keep.jpg');
    $gone = liveFile('gone.jpg', 'bye');
    $this->artisan('files:backup')->assertSuccessful();

    unlink($gone);
    $this->travel(1)->hour();
    $this->artisan('files:backup')->assertSuccessful();

    expect($this->backup->get($kept))->toBe('bye');
    expect($this->backup->allFiles('myapp/media'))->not->toContain($kept === 'myapp/media/current/public/gone.jpg' ? 'myapp/media/versions/2026-10-10_043000/public/gone.jpg' : 'myapp/media/current/public/gone.jpg');
})->with([
    'sync' => ['sync', 'myapp/media/versions/2026-10-10_043000/public/gone.jpg'],
    'copy' => ['copy', 'myapp/media/current/public/gone.jpg'],
]);

it('deletes version folders older than versions_days, and nothing else', function (): void {
    config(['files-backup.versions_days' => 30]);
    liveFile('a.jpg');
    $this->backup->put('myapp/media/versions/2026-09-01_033000/public/old.jpg', 'x');
    $this->backup->put('myapp/media/versions/2026-09-20_033000/public/recent.jpg', 'x');
    $this->backup->put('myapp/media/versions/keep-me/notes.txt', 'x');

    $this->artisan('files:backup')
        ->expectsOutputToContain('pruned 1 old version folder(s)')
        ->assertSuccessful();

    expect($this->backup->directories('myapp/media/versions'))->toEqualCanonicalizing([
        'myapp/media/versions/2026-09-20_033000',
        'myapp/media/versions/keep-me',
    ]);
});

it('refuses to mirror a set that suddenly has no files, and fails loudly', function (): void {
    $only = liveFile('only.jpg');
    $this->artisan('files:backup')->assertSuccessful();
    unlink($only);

    expect(fn () => $this->artisan('files:backup')->run())
        ->toThrow(FilesBackupFailed::class, 'has no files, but the last run backed up 1; refusing to mirror an empty source');

    expect($this->backup->exists('myapp/media/current/public/only.jpg'))->toBeTrue();
});

it('fails a set whose source directory does not exist instead of backing up nothing', function (): void {
    config(['files-backup.sets.media.paths' => ['/nonexistent/uploads']]);

    expect(fn () => $this->artisan('files:backup')->run())
        ->toThrow(FilesBackupFailed::class, 'source directory /nonexistent/uploads does not exist');
});

it('deletes a copy whose size does not match after upload and fails the set', function (): void {
    liveFile('a.jpg', '12345');

    $short = Mockery::mock(Filesystem::class);
    $short->shouldReceive('exists')->andReturnFalse();
    $short->shouldReceive('writeStream')->once()->with('myapp/media/current/public/a.jpg', Mockery::type('resource'), ['visibility' => 'private'])->andReturnTrue();
    $short->shouldReceive('size')->with('myapp/media/current/public/a.jpg')->andReturn(3);
    $short->shouldReceive('delete')->once()->with('myapp/media/current/public/a.jpg')->andReturnTrue();
    Storage::set('backup', $short);

    expect(fn () => $this->artisan('files:backup')->run())
        ->toThrow(FilesBackupFailed::class, 'is 3 bytes on the target, expected 5; the partial copy was deleted');
});

it('runs every set even when one fails, and only the named ones with --set', function (): void {
    $uploads = dirname($this->source).'/uploads';
    mkdir($uploads);
    file_put_contents("{$uploads}/doc.pdf", 'pdf');
    config(['files-backup.sets.uploads' => ['paths' => ['uploads' => $uploads]]]);
    config(['files-backup.sets.broken' => ['paths' => ['/nonexistent']]]);

    liveFile('a.jpg');

    expect(fn () => $this->artisan('files:backup')->run())->toThrow(FilesBackupFailed::class, '[broken]');
    expect($this->backup->exists('myapp/media/current/public/a.jpg'))->toBeTrue();
    expect($this->backup->exists('myapp/uploads/current/uploads/doc.pdf'))->toBeTrue();

    Storage::fake('backup');
    $this->artisan('files:backup', ['--set' => ['uploads']])->assertSuccessful();
    expect(Storage::disk('backup')->exists('myapp/media/status.json'))->toBeFalse();
    expect(Storage::disk('backup')->exists('myapp/uploads/status.json'))->toBeTrue();
});

it('does nothing while disabled', function (): void {
    config(['files-backup.enabled' => false]);
    liveFile('a.jpg');

    $this->artisan('files:backup')->expectsOutputToContain('disabled')->assertSuccessful();

    expect($this->backup->allFiles())->toBe([]);
});
