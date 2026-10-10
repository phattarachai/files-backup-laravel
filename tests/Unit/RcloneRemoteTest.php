<?php

declare(strict_types=1);

use Phattarachai\FilesBackupLaravel\Drivers\RcloneRemote;

it('defines the remote through RCLONE_CONFIG_* variables, leaving empty options out', function (): void {
    config(['files-backup.target.rclone.remote' => [
        'type' => 'drive',
        'scope' => 'drive',
        'service_account_file' => '/etc/myapp/gdrive-sa.json',
        'team_drive' => '0ATEAMDRIVE',
        'root_folder_id' => null,
        'acknowledge_abuse' => true,
    ]]);

    $remote = RcloneRemote::fromConfig('myapp');

    expect($remote->env)->toBe([
        'RCLONE_CONFIG_FILESBACKUP_TYPE' => 'drive',
        'RCLONE_CONFIG_FILESBACKUP_SCOPE' => 'drive',
        'RCLONE_CONFIG_FILESBACKUP_SERVICE_ACCOUNT_FILE' => '/etc/myapp/gdrive-sa.json',
        'RCLONE_CONFIG_FILESBACKUP_TEAM_DRIVE' => '0ATEAMDRIVE',
        'RCLONE_CONFIG_FILESBACKUP_ACKNOWLEDGE_ABUSE' => 'true',
    ]);
    expect($remote->path('media/current'))->toBe('filesbackup:myapp/media/current');
    expect(RcloneRemote::fromConfig('')->path('media'))->toBe('filesbackup:media');
});

it('wraps the remote in crypt, addressing sets relative to the crypt root', function (): void {
    config(['files-backup.target.rclone.crypt' => [
        'enabled' => true,
        'password' => 'obscured-password-1',
        'password2' => 'obscured-salt-2',
        'filename_encryption' => 'standard',
        'directory_name_encryption' => true,
    ]]);

    $remote = RcloneRemote::fromConfig('myapp');

    expect($remote->crypt)->toBeTrue();
    expect($remote->path('media/current'))->toBe('filesbackupcrypt:media/current');
    expect($remote->env)->toMatchArray([
        'RCLONE_CONFIG_FILESBACKUPCRYPT_TYPE' => 'crypt',
        'RCLONE_CONFIG_FILESBACKUPCRYPT_REMOTE' => 'filesbackup:myapp',
        'RCLONE_CONFIG_FILESBACKUPCRYPT_PASSWORD' => 'obscured-password-1',
        'RCLONE_CONFIG_FILESBACKUPCRYPT_PASSWORD2' => 'obscured-salt-2',
        'RCLONE_CONFIG_FILESBACKUPCRYPT_DIRECTORY_NAME_ENCRYPTION' => 'true',
    ]);
    expect($remote->scrubber->scrub('pw obscured-password-1 salt obscured-salt-2'))->toBe('pw ******** salt ********');
});

it('scrubs every secret-looking option, and only those', function (): void {
    config(['files-backup.target.rclone.remote' => [
        'type' => 's3',
        'provider' => 'DigitalOcean',
        'access_key_id' => 'DO00ACCESSKEY',
        'secret_access_key' => 'very-secret-key',
        'endpoint' => 'sgp1.digitaloceanspaces.com',
    ]]);

    $scrubbed = RcloneRemote::fromConfig('myapp')->scrubber->scrub('DO00ACCESSKEY very-secret-key at sgp1.digitaloceanspaces.com');

    expect($scrubbed)->toBe('******** ******** at sgp1.digitaloceanspaces.com');
});

it('refuses a crypt overlay without a password, and a remote without a type', function (array $config, string $message): void {
    config($config);

    expect(fn () => RcloneRemote::fromConfig('myapp'))->toThrow(RuntimeException::class, $message);
})->with([
    [['files-backup.target.rclone.crypt' => ['enabled' => true]], 'crypt is enabled but has no password'],
    [['files-backup.target.rclone.remote' => ['team_drive' => 'x']], 'remote.type is not set'],
    [['files-backup.target.rclone.name' => 'my-remote'], 'may only contain letters, digits'],
]);
