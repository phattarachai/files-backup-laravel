<?php

declare(strict_types=1);

namespace Phattarachai\FilesBackupLaravel\Drivers;

use Closure;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use JsonException;
use League\Flysystem\FileAttributes;
use Phattarachai\FilesBackupLaravel\Backup\BackupConfig;
use Phattarachai\FilesBackupLaravel\Backup\BackupSet;
use Phattarachai\FilesBackupLaravel\Backup\RunStamp;
use Phattarachai\FilesBackupLaravel\Backup\SetReport;
use Phattarachai\FilesBackupLaravel\Support\LocalFile;
use RuntimeException;

/**
 * Mirrors a set onto any Laravel disk.
 *
 * `{set dir}/manifest.json` records each uploaded file's size, mtime and
 * sha256, so a run compares the live files with it instead of listing the
 * target. A changed file's old copy, and a deleted file (`sync` mode), move
 * to versions/{stamp}/ on the target itself before anything is overwritten.
 * Uploads are streamed, written private whatever the disk's default, and
 * checked for size; a short copy is deleted and fails the set.
 *
 * The manifest is saved every SAVE_EVERY uploads, so a run cut short resumes
 * where it stopped instead of starting over.
 */
final readonly class FlysystemDriver implements Driver
{
    public const int SAVE_EVERY = 100;

    public function __construct(private BackupConfig $config) {}

    public function describe(BackupSet $set): string
    {
        return "disk {$this->diskName()}:{$this->config->setDir($set)}";
    }

    public function backup(BackupSet $set, array $files, string $versionStamp, SetReport $report, Closure $note): void
    {
        $disk = $this->disk();
        $dir = $this->config->setDir($set);
        $manifest = $this->manifest($disk, $dir);
        $versions = "{$dir}/versions/{$versionStamp}";

        $report->uploaded = 0;
        $report->uploadedBytes = 0;
        $report->versioned = 0;
        $unsaved = 0;

        foreach ($files as $path => $file) {
            $known = $manifest[$path] ?? null;

            if ($known !== null && $known['size'] === $file->size && $known['mtime'] === $file->modifiedAt) {
                continue;
            }

            if ($known !== null && $this->moveAside($disk, "{$dir}/current/{$path}", "{$versions}/{$path}")) {
                $report->versioned++;
            }

            $manifest[$path] = $this->upload($disk, $file, "{$dir}/current/{$path}");
            $report->uploaded++;
            $report->uploadedBytes += $file->size;
            $note("[{$set->name}] uploaded {$path}");

            if (++$unsaved >= self::SAVE_EVERY) {
                $this->saveManifest($disk, $dir, $manifest);
                $unsaved = 0;
            }
        }

        if ($this->config->mode === BackupConfig::MODE_SYNC) {
            foreach (array_diff_key($manifest, $files) as $path => $known) {
                if ($this->moveAside($disk, "{$dir}/current/{$path}", "{$versions}/{$path}")) {
                    $report->versioned++;
                    $note("[{$set->name}] deleted {$path} (kept in versions/{$versionStamp})");
                }

                unset($manifest[$path]);
            }
        }

        $this->saveManifest($disk, $dir, $manifest);
    }

    public function pruneVersions(BackupSet $set, Carbon $cutoff, Closure $note): array
    {
        $disk = $this->disk();
        $versions = "{$this->config->setDir($set)}/versions";
        $pruned = [];

        foreach ($disk->directories($versions) as $folder) {
            $stamp = VersionStamp::parse(basename($folder));

            if ($stamp === null || ! $stamp->lt($cutoff)) {
                continue;
            }

            if (! $disk->deleteDirectory($folder)) {
                throw new RuntimeException("Could not delete {$folder} on [{$this->diskName()}].");
            }

            $pruned[] = 'versions/'.basename($folder);
            $note("[{$set->name}] pruned versions/".basename($folder));
        }

        return $pruned;
    }

    public function writeStatus(BackupSet $set, RunStamp $stamp): void
    {
        $this->put($this->disk(), "{$this->config->setDir($set)}/status.json", $stamp->toJson());
    }

    public function readStatus(BackupSet $set): ?RunStamp
    {
        $disk = $this->disk();
        $path = "{$this->config->setDir($set)}/status.json";

        if (! $disk->exists($path)) {
            return null;
        }

        return RunStamp::fromJson((string) $disk->get($path));
    }

    public function listCurrent(BackupSet $set): array
    {
        $disk = $this->disk();
        $current = "{$this->config->setDir($set)}/current";
        $files = [];

        if ($disk instanceof FilesystemAdapter) {
            // One deep listing that carries sizes, instead of a size() call per file.
            foreach ($disk->getDriver()->listContents($current, true) as $item) {
                if ($item instanceof FileAttributes) {
                    $files[substr($item->path(), strlen($current) + 1)] = (int) ($item->fileSize() ?? $disk->size($item->path()));
                }
            }
        } else {
            foreach ($disk->allFiles($current) as $path) {
                $files[substr($path, strlen($current) + 1)] = $disk->size($path);
            }
        }

        ksort($files, SORT_STRING);

        return $files;
    }

    public function download(BackupSet $set, ?array $paths, string $directory): void
    {
        $disk = $this->disk();
        $current = "{$this->config->setDir($set)}/current";

        foreach ($paths ?? array_keys($this->listCurrent($set)) as $path) {
            $to = "{$directory}/{$path}";

            if (! is_dir(dirname($to)) && ! mkdir(dirname($to), 0o700, true) && ! is_dir(dirname($to))) {
                throw new RuntimeException('Could not create '.dirname($to).'.');
            }

            $in = $disk->readStream("{$current}/{$path}");
            $out = fopen($to, 'wb');

            try {
                if (! is_resource($in) || $out === false) {
                    throw new RuntimeException("Could not download {$current}/{$path} from [{$this->diskName()}].");
                }

                stream_copy_to_stream($in, $out);
            } finally {
                is_resource($in) && fclose($in);
                is_resource($out) && fclose($out);
            }
        }
    }

    /**
     * @return array{size: int, mtime: int, sha256: string}
     */
    private function upload(Filesystem $disk, LocalFile $file, string $to): array
    {
        $sha256 = hash_file('sha256', $file->absolutePath);
        $stream = fopen($file->absolutePath, 'rb');

        if ($sha256 === false || $stream === false) {
            throw new RuntimeException("Could not read {$file->absolutePath}.");
        }

        try {
            $written = $disk->writeStream($to, $stream, ['visibility' => Filesystem::VISIBILITY_PRIVATE]);
        } finally {
            // Some adapters (the AWS SDK) close the stream themselves once it is sent.
            if (is_resource($stream)) {
                fclose($stream);
            }
        }

        if ($written === false) {
            throw new RuntimeException("Writing {$to} failed.");
        }

        $actual = $disk->size($to);

        if ($actual !== $file->size) {
            $disk->delete($to);

            throw new RuntimeException("{$to} is {$actual} bytes on the target, expected {$file->size}; the partial copy was deleted.");
        }

        return ['size' => $file->size, 'mtime' => $file->modifiedAt, 'sha256' => $sha256];
    }

    /**
     * Move a target file into the version folder; false when there was nothing
     * to move (a manifest entry whose copy is already gone).
     */
    private function moveAside(Filesystem $disk, string $from, string $to): bool
    {
        if (! $disk->exists($from)) {
            return false;
        }

        if ($disk->exists($to)) {
            $disk->delete($to);
        }

        if (! $disk->move($from, $to)) {
            throw new RuntimeException("Could not move {$from} to {$to} on [{$this->diskName()}].");
        }

        return true;
    }

    /**
     * @return array<string, array{size: int, mtime: int, sha256: string}>
     */
    private function manifest(Filesystem $disk, string $dir): array
    {
        $path = "{$dir}/manifest.json";

        if (! $disk->exists($path)) {
            return [];
        }

        try {
            $data = json_decode((string) $disk->get($path), true, 8, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new RuntimeException("{$path} on [{$this->diskName()}] is not valid JSON ({$e->getMessage()}); delete it to re-upload everything.", previous: $e);
        }

        $files = [];

        foreach ((array) (is_array($data) ? ($data['files'] ?? []) : []) as $file => $entry) {
            if (is_array($entry)) {
                $files[(string) $file] = [
                    'size' => (int) ($entry['size'] ?? -1),
                    'mtime' => (int) ($entry['mtime'] ?? -1),
                    'sha256' => (string) ($entry['sha256'] ?? ''),
                ];
            }
        }

        return $files;
    }

    /**
     * @param  array<string, array{size: int, mtime: int, sha256: string}>  $files
     */
    private function saveManifest(Filesystem $disk, string $dir, array $files): void
    {
        ksort($files, SORT_STRING);

        $this->put($disk, "{$dir}/manifest.json", json_encode(
            ['version' => 1, 'files' => (object) $files],
            JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
        ));
    }

    private function put(Filesystem $disk, string $path, string $contents): void
    {
        if (! $disk->put($path, $contents, ['visibility' => Filesystem::VISIBILITY_PRIVATE])) {
            throw new RuntimeException("Writing {$path} to [{$this->diskName()}] failed.");
        }
    }

    private function disk(): Filesystem
    {
        return Storage::disk($this->diskName());
    }

    private function diskName(): string
    {
        $disk = config('files-backup.target.flysystem.disk');

        if (! is_string($disk) || $disk === '') {
            throw new RuntimeException('No backup disk is configured (files-backup.target.flysystem.disk).');
        }

        return $disk;
    }
}
