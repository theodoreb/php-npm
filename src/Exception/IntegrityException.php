<?php

declare(strict_types=1);

namespace PhpNpm\Exception;

/**
 * Exception thrown when integrity verification fails.
 */
class IntegrityException extends \RuntimeException
{
    private ?string $expectedHash = null;
    private ?string $actualHash = null;
    private ?string $algorithm = null;

    /**
     * Create an integrity mismatch exception.
     */
    public static function mismatch(
        string $context,
        string $algorithm,
        string $expected,
        string $actual
    ): self {
        $exception = new self(sprintf(
            "Integrity check failed for %s.\n" .
            "Expected: %s-%s\n" .
            "Actual: %s-%s\n\n" .
            "This could indicate:\n" .
            "- Network corruption during download\n" .
            "- Tampered package content\n" .
            "- Outdated lockfile\n\n" .
            "Try running 'php-npm cache clean' and reinstalling.",
            $context,
            $algorithm,
            $expected,
            $algorithm,
            $actual
        ));

        $exception->algorithm = $algorithm;
        $exception->expectedHash = $expected;
        $exception->actualHash = $actual;

        return $exception;
    }

    public function getExpectedHash(): ?string
    {
        return $this->expectedHash;
    }

    public function getActualHash(): ?string
    {
        return $this->actualHash;
    }

    public function getAlgorithm(): ?string
    {
        return $this->algorithm;
    }
}
