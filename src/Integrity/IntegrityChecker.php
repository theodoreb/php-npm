<?php

declare(strict_types=1);

namespace PhpNpm\Integrity;

use PhpNpm\Exception\IntegrityException;

/**
 * Verifies package integrity using Subresource Integrity (SRI) format.
 * Supports sha512, sha384, sha256, and sha1 algorithms.
 */
class IntegrityChecker
{
    /**
     * Supported hash algorithms in order of preference.
     */
    private const ALGORITHMS = ['sha512', 'sha384', 'sha256', 'sha1'];

    /**
     * Verify content against an SRI integrity string.
     *
     * @param string $content The content to verify
     * @param string $integrity SRI format: "algorithm-base64hash"
     * @return bool True if integrity matches
     * @throws IntegrityException If integrity format is invalid
     */
    public function verify(string $content, string $integrity): bool
    {
        $parsed = $this->parse($integrity);

        foreach ($parsed as $item) {
            if ($this->checkHash($content, $item['algorithm'], $item['hash'])) {
                return true;
            }
        }

        return false;
    }

    /**
     * Verify a file against an SRI integrity string.
     *
     * @param string $path Path to the file
     * @param string $integrity SRI format integrity string
     * @return bool True if integrity matches
     * @throws IntegrityException
     */
    public function verifyFile(string $path, string $integrity): bool
    {
        if (!file_exists($path)) {
            throw new IntegrityException("File not found: {$path}");
        }

        $content = file_get_contents($path);
        if ($content === false) {
            throw new IntegrityException("Could not read file: {$path}");
        }

        return $this->verify($content, $integrity);
    }

    /**
     * Verify content and throw if it doesn't match.
     *
     * @throws IntegrityException
     */
    public function verifyOrThrow(string $content, string $integrity, string $context = ''): void
    {
        if (!$this->verify($content, $integrity)) {
            $actual = $this->calculate($content, 'sha512');
            $message = "Integrity check failed";
            if ($context) {
                $message .= " for {$context}";
            }
            $message .= ". Expected: {$integrity}, Got: {$actual}";
            throw new IntegrityException($message);
        }
    }

    /**
     * Calculate the SRI hash for content.
     *
     * @param string $content The content to hash
     * @param string $algorithm Hash algorithm (sha512, sha384, sha256, sha1)
     * @return string SRI format hash string
     */
    public function calculate(string $content, string $algorithm = 'sha512'): string
    {
        $algorithm = strtolower($algorithm);

        if (!in_array($algorithm, self::ALGORITHMS, true)) {
            throw new IntegrityException("Unsupported algorithm: {$algorithm}");
        }

        $hash = hash($algorithm, $content, true);
        $base64 = base64_encode($hash);

        return "{$algorithm}-{$base64}";
    }

    /**
     * Calculate the SRI hash for a file.
     *
     * @param string $path Path to the file
     * @param string $algorithm Hash algorithm
     * @return string SRI format hash string
     */
    public function calculateFile(string $path, string $algorithm = 'sha512'): string
    {
        if (!file_exists($path)) {
            throw new IntegrityException("File not found: {$path}");
        }

        $content = file_get_contents($path);
        if ($content === false) {
            throw new IntegrityException("Could not read file: {$path}");
        }

        return $this->calculate($content, $algorithm);
    }

    /**
     * Parse an SRI integrity string.
     *
     * @param string $integrity SRI string, possibly with multiple hashes
     * @return array Array of ['algorithm' => string, 'hash' => string]
     */
    public function parse(string $integrity): array
    {
        $integrity = trim($integrity);

        if ($integrity === '') {
            return [];
        }

        $results = [];
        $parts = preg_split('/\s+/', $integrity);

        foreach ($parts as $part) {
            if (!str_contains($part, '-')) {
                continue;
            }

            [$algorithm, $hash] = explode('-', $part, 2);
            $algorithm = strtolower($algorithm);

            // Remove any options suffix (e.g., "sha512-abc123?foo=bar")
            if (str_contains($hash, '?')) {
                $hash = explode('?', $hash, 2)[0];
            }

            if (in_array($algorithm, self::ALGORITHMS, true)) {
                $results[] = [
                    'algorithm' => $algorithm,
                    'hash' => $hash,
                ];
            }
        }

        return $results;
    }

    /**
     * Check if content matches a specific hash.
     */
    private function checkHash(string $content, string $algorithm, string $expectedHash): bool
    {
        $actualHash = hash($algorithm, $content, true);
        $actualBase64 = base64_encode($actualHash);

        return hash_equals($expectedHash, $actualBase64);
    }

    /**
     * Get the strongest algorithm from an integrity string.
     */
    public function getStrongestAlgorithm(string $integrity): ?string
    {
        $parsed = $this->parse($integrity);

        foreach (self::ALGORITHMS as $algo) {
            foreach ($parsed as $item) {
                if ($item['algorithm'] === $algo) {
                    return $algo;
                }
            }
        }

        return null;
    }

    /**
     * Create a combined integrity string from multiple algorithms.
     */
    public function createMultiHash(string $content, array $algorithms = ['sha512']): string
    {
        $hashes = [];

        foreach ($algorithms as $algorithm) {
            $hashes[] = $this->calculate($content, $algorithm);
        }

        return implode(' ', $hashes);
    }
}
