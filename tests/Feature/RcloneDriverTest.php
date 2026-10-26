<?php

declare(strict_types=1);

use Illuminate\Log\Events\MessageLogged;
use Illuminate\Process\Exceptions\ProcessTimedOutException;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Process;
use Phattarachai\FilesBackupLaravel\Exceptions\FilesBackupFailed;

/**
 * Fake rclone: each call is recorded, and answered by the handler for its
 * subcommand (a result, or a closure taking the PendingProcess), else success.
 *
 * @param  array<string, mixed>  $handlers
 */
function fakeRclone(array $handlers = []): void
{
    $calls = test()->rclone = new ArrayObject;

    Process::fake(function (PendingProcess $process) use ($handlers, $calls) {
        $calls->append($process);
        $handler = $handlers[$process->command[1]] ?? Process::result();

        return $handler instanceof Closure ? $handler($process) : $handler;
    });
}

/**
 * @return list<PendingProcess>
 */
function rcloneCalls(string $subcommand): array
{
    return array_values(array_filter(test()->rclone->getArrayCopy(), fn (PendingProcess $process): bool => $process->command[1] === $subcommand));
}

function statsLine(int $transfers, int $bytes): string
{
    return json_encode(['level' => 'notice', 'msg' => 'Transferred', 'stats' => ['transfers' => $transfers, 'bytes' => $bytes, 'deletes' => 0]])."\n";
}

beforeEach(function (): void {
    $this->travelTo(Carbon::parse('2026-10-10 03:30:00', 'UTC'));
});

it('syncs each source with --backup-dir and excludes, then checks it and records the run', function (): void {
    liveFile('1/photo.jpg', 'jpeg');
    liveFile('2/doc.pdf', 'pdf!');
    fakeRclone(['sync' => Process::result(errorOutput: '{"level":"info","msg":"x"}'."\n".statsLine(2, 2048))]);

    $this->artisan('files:backup')
        ->expectsOutputToContain('[media] ok: 2 files (8 B) → rclone filesbackup:myapp/media; uploaded 2 (2 KB), pruned 0 old version folder(s)')
        ->assertSuccessful();

    [$sync] = rcloneCalls('sync');
    expect($sync->command)->toBe([
        'rclone', 'sync', $this->source, 'filesbackup:myapp/media/current/public',
        '--backup-dir', 'filesbackup:myapp/media/versions/2026-10-10_033000/public',
        '--exclude=.DS_Store', '--exclude=cache/**',
        '--stats', '1h', '--stats-log-level', 'NOTICE', '--use-json-log',
    ]);
    expect($sync->environment)->toBe([
        'RCLONE_CONFIG_FILESBACKUP_TYPE' => 'drive',
        'RCLONE_CONFIG_FILESBACKUP_SCOPE' => 'drive',
        'RCLONE_CONFIG_FILESBACKUP_SERVICE_ACCOUNT_FILE' => '/etc/myapp/gdrive-sa.json',
        'RCLONE_CONFIG_FILESBACKUP_TEAM_DRIVE' => '0ATEAMDRIVE',
    ]);

    [$check] = rcloneCalls('check');
    expect($check->command)->toBe(['rclone', 'check', $this->source, 'filesbackup:myapp/media/current/public', '--exclude=.DS_Store', '--exclude=cache/**', '--use-json-log']);

    [$rcat] = rcloneCalls('rcat');
    expect($rcat->command)->toBe(['rclone', 'rcat', 'filesbackup:myapp/media/status.json', '--use-json-log']);
    expect(json_decode((string) $rcat->input, true))->toMatchArray(['set' => 'media', 'driver' => 'rclone', 'files' => 2, 'bytes' => 8]);
});

it('copies instead of syncing in copy mode, and checks one way', function (): void {
    config(['files-backup.target.mode' => 'copy']);
    liveFile('a.jpg');
    fakeRclone();

    $this->artisan('files:backup')->assertSuccessful();

    expect(rcloneCalls('copy'))->toHaveCount(1);
    expect(rcloneCalls('sync'))->toBe([]);
    expect(rcloneCalls('check')[0]->command)->toContain('--one-way');
});

it('goes through the crypt remote and verifies with cryptcheck', function (): void {
    config(['files-backup.target.rclone.crypt' => ['enabled' => true, 'password' => 'obscured-pw-xyz']]);
    liveFile('a.jpg');
    fakeRclone();

    $this->artisan('files:backup')->assertSuccessful();

    expect(rcloneCalls('sync')[0]->command)->toContain('filesbackupcrypt:media/current/public', 'filesbackupcrypt:media/versions/2026-10-10_033000/public');
    expect(rcloneCalls('cryptcheck'))->toHaveCount(1);
    expect(rcloneCalls('check'))->toBe([]);
    expect(rcloneCalls('sync')[0]->environment)->toMatchArray(['RCLONE_CONFIG_FILESBACKUPCRYPT_REMOTE' => 'filesbackup:myapp']);
});

it('never puts a secret on the command line, in the exception or in the log', function (): void {
    config(['files-backup.target.rclone.remote' => [
        'type' => 's3',
        'provider' => 'DigitalOcean',
        'access_key_id' => 'DO00ACCESSKEYID',
        'secret_access_key' => 'super-secret-access-key',
    ]]);
    liveFile('a.jpg');
    fakeRclone(['sync' => Process::result(
        errorOutput: json_encode(['level' => 'error', 'msg' => 'SignatureDoesNotMatch for DO00ACCESSKEYID using super-secret-access-key'])."\n",
        exitCode: 1,
    )]);

    $logged = [];
    Event::listen(MessageLogged::class, function (MessageLogged $event) use (&$logged): void {
        $logged[] = $event->message.json_encode($event->context);
    });

    try {
        $this->artisan('files:backup')->run();
        $this->fail('files:backup should have thrown');
    } catch (FilesBackupFailed $e) {
        expect($e->getMessage())->toBe('Files backup failed: [media] rclone sync exited 1: SignatureDoesNotMatch for ******** using ********');
    }

    expect(implode("\n", $logged))->toContain('SignatureDoesNotMatch')->not->toContain('DO00ACCESSKEYID')->not->toContain('super-secret');

    foreach ($this->rclone as $process) {
        expect(implode(' ', $process->command))->not->toContain('DO00ACCESSKEYID')->not->toContain('super-secret');
    }

    expect(rcloneCalls('rcat'))->toBe([]);
});

it('fails the set when rclone check finds a difference', function (): void {
    liveFile('a.jpg');
    fakeRclone(['check' => Process::result(errorOutput: json_encode(['level' => 'error', 'msg' => '1 differences found'])."\n", exitCode: 1)]);

    expect(fn () => $this->artisan('files:backup')->run())->toThrow(FilesBackupFailed::class, 'rclone check exited 1: 1 differences found');
    expect(rcloneCalls('rcat'))->toBe([]);
});

it('purges version folders older than versions_days, and leaves foreign folders alone', function (): void {
    liveFile('a.jpg');
    fakeRclone(['lsf' => Process::result("2026-09-01_033000/\n2026-09-20_033000/\nmanual-copy/\n")]);

    $this->artisan('files:backup')->expectsOutputToContain('pruned 1 old version folder(s)')->assertSuccessful();

    expect(rcloneCalls('lsf')[0]->command)->toBe(['rclone', 'lsf', '--dirs-only', 'filesbackup:myapp/media/versions', '--use-json-log']);
    expect(array_map(fn (PendingProcess $process): array => $process->command, rcloneCalls('purge')))
        ->toBe([['rclone', 'purge', 'filesbackup:myapp/media/versions/2026-09-01_033000', '--use-json-log']]);
});

it('reads rclone output with multibyte names whole', function (): void {
    liveFile('a.jpg');
    $stats = json_encode(['level' => 'notice', 'msg' => 'Transferred: รูปคนงาน ✅.jpg', 'stats' => ['transfers' => 3, 'bytes' => 2048, 'deletes' => 0]], JSON_UNESCAPED_UNICODE);
    fakeRclone([
        'sync' => Process::result(errorOutput: $stats."\n"),
        'lsf' => Process::result("2026-09-01_033000/\nสำรองฅนงาน ✅/\n"),
    ]);

    $this->artisan('files:backup')
        ->expectsOutputToContain('uploaded 3 (2 KB), pruned 1 old version folder(s)')
        ->assertSuccessful();

    expect(array_map(fn (PendingProcess $process): array => $process->command, rcloneCalls('purge')))
        ->toBe([['rclone', 'purge', 'filesbackup:myapp/media/versions/2026-09-01_033000', '--use-json-log']]);
});

it('keeps multibyte characters whole in the rclone error it reports', function (): void {
    liveFile('a.jpg');
    fakeRclone(['check' => Process::result(
        errorOutput: json_encode(['level' => 'error', 'msg' => 'ไม่พบไฟล์ ฅนงาน.jpg ✅'], JSON_UNESCAPED_UNICODE)."\n"."✅ เขียนไม่ได้\n",
        exitCode: 1,
    )]);

    expect(fn () => $this->artisan('files:backup')->run())
        ->toThrow(FilesBackupFailed::class, 'rclone check exited 1: ไม่พบไฟล์ ฅนงาน.jpg ✅ | ✅ เขียนไม่ได้');
});

it('treats a missing versions folder as nothing to prune', function (): void {
    liveFile('a.jpg');
    fakeRclone(['lsf' => Process::result(errorOutput: 'directory not found', exitCode: 3)]);

    $this->artisan('files:backup')->assertSuccessful();

    expect(rcloneCalls('purge'))->toBe([]);
});

it('refuses to sync an empty source over a set that had files', function (): void {
    fakeRclone(['cat' => Process::result(json_encode(['started_at' => '2026-10-09T03:30:00+00:00', 'finished_at' => '2026-10-09T03:31:00+00:00', 'files' => 107]))]);

    expect(fn () => $this->artisan('files:backup')->run())->toThrow(FilesBackupFailed::class, 'the last run backed up 107');
    expect(rcloneCalls('sync'))->toBe([]);
});

it('reports an rclone timeout as a failed set', function (): void {
    config(['files-backup.target.rclone.timeout' => 5]);
    liveFile('a.jpg');
    fakeRclone(['sync' => fn () => throw new ProcessTimedOutException(
        new Symfony\Component\Process\Exception\ProcessTimedOutException(new Symfony\Component\Process\Process(['rclone']), 1),
        Process::result(),
    )]);

    expect(fn () => $this->artisan('files:backup')->run())->toThrow(FilesBackupFailed::class, 'rclone sync timed out after 5s');
});
