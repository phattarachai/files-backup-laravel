<?php

declare(strict_types=1);

use Phattarachai\FilesBackupLaravel\Support\Glob;

it('matches like rclone filter globs', function (string $pattern, string $path, bool $matches): void {
    expect(Glob::matchesAny([$pattern], $path))->toBe($matches);
})->with([
    'unanchored name at the root' => ['.DS_Store', '.DS_Store', true],
    'unanchored name at depth' => ['.DS_Store', '1/2/.DS_Store', true],
    'unanchored name is a whole segment' => ['.DS_Store', '1/x.DS_Store', false],
    '* stays within a segment' => ['*.tmp', 'a/b.tmp', true],
    '* does not cross /' => ['a*.tmp', 'a/b.tmp', false],
    '** crosses segments' => ['cache/**', 'x/cache/a/b.jpg', true],
    'anchored only at the root' => ['/cache/**', 'x/cache/a.jpg', false],
    'anchored at the root' => ['/cache/**', 'cache/a.jpg', true],
    'trailing slash means everything under' => ['tmp/', 'a/tmp/b/c', true],
    '? is one character' => ['file?.jpg', 'file1.jpg', true],
    'character class' => ['[0-9].jpg', '7.jpg', true],
    'negated character class' => ['[!0-9].jpg', '7.jpg', false],
    'alternatives' => ['*.{jpg,png}', 'a/b.png', true],
    'alternatives miss' => ['*.{jpg,png}', 'a/b.gif', false],
    'regex characters are literal' => ['a+b(1).jpg', 'a+b(1).jpg', true],
    'escaped star' => ['a\*.jpg', 'a*.jpg', true],
]);
