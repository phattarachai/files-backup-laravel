<?php

declare(strict_types=1);

namespace Phattarachai\FilesBackupLaravel\Support;

/**
 * rclone filter globs as regular expressions, so the flysystem driver and the
 * drill exclude exactly what `rclone --exclude` does: `*` within a segment,
 * `**` across segments, `?`, `[...]`, `{a,b}`, `\` escapes. A pattern without
 * a leading `/` matches the end of the path at any depth; one ending in `/`
 * matches everything under that directory.
 */
final class Glob
{
    public static function toRegex(string $pattern): string
    {
        $anchored = str_starts_with($pattern, '/');
        $body = $anchored ? substr($pattern, 1) : $pattern;

        if (str_ends_with($body, '/')) {
            $body .= '**';
        }

        return '#'.($anchored ? '^' : '(?:^|/)').self::translate($body).'$#';
    }

    /**
     * @param  list<string>  $patterns
     */
    public static function matchesAny(array $patterns, string $path): bool
    {
        return array_any($patterns, fn (string $pattern): bool => preg_match(self::toRegex($pattern), $path) === 1);
    }

    private static function translate(string $glob): string
    {
        $regex = '';
        $length = strlen($glob);

        for ($i = 0; $i < $length; $i++) {
            $char = $glob[$i];

            switch ($char) {
                case '*':
                    if (($glob[$i + 1] ?? '') === '*') {
                        $regex .= '.*';
                        $i++;
                    } else {
                        $regex .= '[^/]*';
                    }
                    break;
                case '?':
                    $regex .= '[^/]';
                    break;
                case '\\':
                    $regex .= preg_quote($glob[++$i] ?? '\\', '#');
                    break;
                case '[':
                    $end = strpos($glob, ']', $i + 1);

                    if ($end === false) {
                        $regex .= '\\[';
                        break;
                    }

                    $class = substr($glob, $i + 1, $end - $i - 1);
                    $regex .= '['.(str_starts_with($class, '!') ? '^'.substr($class, 1) : $class).']';
                    $i = $end;
                    break;
                case '{':
                    $end = strpos($glob, '}', $i + 1);

                    if ($end === false) {
                        $regex .= '\\{';
                        break;
                    }

                    $alternatives = explode(',', substr($glob, $i + 1, $end - $i - 1));
                    $regex .= '(?:'.implode('|', array_map(self::translate(...), $alternatives)).')';
                    $i = $end;
                    break;
                default:
                    $regex .= preg_quote($char, '#');
            }
        }

        return $regex;
    }
}
