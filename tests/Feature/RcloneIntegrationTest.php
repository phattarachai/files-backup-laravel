<?php

declare(strict_types=1);

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Phattarachai\FilesBackupLaravel\Exceptions\FilesDrillFailed;
use Symfony\Component\Finder\Finder;
use Symfony\Component\Process\ExecutableFinder;

/*
 * The real rclone binary against a `local` remote: proves the flags, exit
 * codes and JSON log the driver relies on, which the faked tests only assume.
 */
beforeEach(function (): void {
    if ((new ExecutableFinder)->find('rclone') === null) {
        $this->markTestSkipped('rclone is not installed.');
    }

    $this->target = dirname($this->source).'/target';
    mkdir($this->target);

    config([
        'files-backup.target.driver' => 'rclone',
        'files-backup.target.path' => $this->target,
        'files-backup.target.rclone.remote' => ['type' => 'local'],
    ]);
});

afterEach(function (): void {
    File::deleteDirectory($this->target ?? '');
});

it('backs up, versions what changed or went, prunes old versions, and drills', function (): void {
    liveFile('1/photo.jpg', 'jpeg', now()->subHours(2)->getTimestamp());
    $gone = liveFile('2/doc.pdf', 'pdf!', now()->subHours(2)->getTimestamp());
    liveFile('cache/skip.tmp', 'never');
    mkdir("{$this->target}/media/versions/2020-01-01_000000", 0o777, true);

    $this->artisan('files:backup')->expectsOutputToContain('[media] ok: 2 files (8 B)')->assertSuccessful();

    expect(file_get_contents("{$this->target}/media/current/public/1/photo.jpg"))->toBe('jpeg');
    expect(file_exists("{$this->target}/media/current/public/cache/skip.tmp"))->toBeFalse();
    expect(json_decode((string) file_get_contents("{$this->target}/media/status.json"), true))->toMatchArray(['files' => 2, 'driver' => 'rclone']);
    expect(is_dir("{$this->target}/media/versions/2020-01-01_000000"))->toBeFalse('the old version folder should have been pruned');

    liveFile('1/photo.jpg', 'jpeg v2', now()->subHour()->getTimestamp());
    unlink($gone);
    $this->travel(1)->second();

    $this->artisan('files:backup')->assertSuccessful();

    $versioned = iterator_to_array(Finder::create()->files()->in("{$this->target}/media/versions"));
    expect(array_map(fn (SplFileInfo $file): string => $file->getRelativePathname(), array_values($versioned)))
        ->toHaveCount(2)
        ->each->toMatch('#^\d{4}-\d{2}-\d{2}_\d{6}/public/(1/photo\.jpg|2/doc\.pdf)$#');
    expect(file_get_contents("{$this->target}/media/current/public/1/photo.jpg"))->toBe('jpeg v2');

    $this->artisan('files:backup-check')->expectsOutputToContain('OK [media]')->assertSuccessful();
    $this->artisan('files:drill', ['--all' => true])->expectsOutputToContain('Drill PASSED.')->assertSuccessful();

    file_put_contents("{$this->target}/media/current/public/1/photo.jpg", 'JPEG V2');
    touch("{$this->target}/media/current/public/1/photo.jpg", now()->subHour()->getTimestamp());

    expect(fn () => $this->artisan('files:drill', ['--all' => true])->run())->toThrow(FilesDrillFailed::class, '1 corrupt download(s)');
});

it('treats a target that was never written as no recorded backup', function (): void {
    $this->artisan('files:backup-check')->expectsOutputToContain('has no recorded backup')->assertFailed();
});

it('encrypts names and contents through the crypt overlay, and still drills', function (): void {
    $obscured = trim(Process::input('drill-password')->run(['rclone', 'obscure', '-'])->throw()->output());
    config(['files-backup.target.rclone.crypt' => ['enabled' => true, 'password' => $obscured]]);

    liveFile('1/secret-photo.jpg', 'plain jpeg body', now()->subHour()->getTimestamp());

    $this->artisan('files:backup')->assertSuccessful();

    $stored = implode("\n", array_map(
        fn (SplFileInfo $file): string => $file->getRelativePathname().file_get_contents($file->getPathname()),
        iterator_to_array(Finder::create()->files()->in($this->target)),
    ));
    expect($stored)->not->toContain('secret-photo')->not->toContain('plain jpeg body')->not->toContain('current');

    $this->artisan('files:backup-check')->assertSuccessful();
    $this->artisan('files:drill', ['--all' => true])->expectsOutputToContain('Drill PASSED.')->assertSuccessful();
});
