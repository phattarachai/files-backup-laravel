<?php

declare(strict_types=1);

namespace Phattarachai\FilesBackupLaravel\Support;

/**
 * Replaces secret values with a mask in anything that may reach a log line,
 * an exception message or the console.
 */
final class Scrubber
{
    public const string MASK = '********';

    /** @var list<string> */
    private array $secrets = [];

    /**
     * @param  iterable<string>  $secrets
     */
    public function __construct(iterable $secrets = [])
    {
        foreach ($secrets as $secret) {
            $this->add($secret);
        }
    }

    public function add(string $secret): void
    {
        // Too short to be a credential, and masking it would mangle ordinary words.
        if (strlen($secret) < 4 || in_array($secret, $this->secrets, true)) {
            return;
        }

        $this->secrets[] = $secret;

        // Longest first, so a secret that contains another is masked whole.
        usort($this->secrets, fn (string $a, string $b): int => strlen($b) <=> strlen($a));
    }

    public function scrub(string $text): string
    {
        return $this->secrets === [] ? $text : str_replace($this->secrets, self::MASK, $text);
    }
}
