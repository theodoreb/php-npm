<?php

declare(strict_types=1);

namespace PhpNpm\Semver;

use Composer\Semver\Semver;
use Composer\Semver\VersionParser;
use Composer\Semver\Comparator;

/**
 * Adapter that wraps composer/semver and handles npm-specific syntax.
 * Converts npm range syntax to Composer-compatible format.
 */
class ComposerSemverAdapter
{
    private VersionParser $parser;

    public function __construct()
    {
        $this->parser = new VersionParser();
    }

    /**
     * Check if a version satisfies a semver range specification.
     */
    public function satisfies(string $version, string $range): bool
    {
        // Handle special cases
        if ($range === '' || $range === '*' || $range === 'latest') {
            return true;
        }

        // Handle npm: protocol prefix
        if (str_starts_with($range, 'npm:')) {
            // npm:package@version format - extract version part
            $range = preg_replace('/^npm:[^@]+@/', '', $range);
        }

        // Handle workspace protocol
        if (str_starts_with($range, 'workspace:')) {
            return true; // Workspace dependencies are always satisfied locally
        }

        // Handle git/url dependencies
        if ($this->isUrlSpec($range)) {
            return true; // Can't version match URLs
        }

        // Handle tag-based ranges (like "latest", "next", "beta")
        if ($this->isTag($range)) {
            return true; // Tags are resolved separately
        }

        try {
            $composerRange = $this->convertNpmRangeToComposer($range);
            return Semver::satisfies($version, $composerRange);
        } catch (\Exception $e) {
            // If conversion fails, try direct comparison
            return $version === $range;
        }
    }

    /**
     * Find the best version from a list that satisfies a range.
     * @param string[] $versions
     */
    public function maxSatisfying(array $versions, string $range): ?string
    {
        if (empty($versions)) {
            return null;
        }

        // Filter out invalid version strings that would cause Composer\Semver to throw
        $validVersions = array_filter($versions, function ($v) {
            return $this->isValidVersion($v);
        });

        if (empty($validVersions)) {
            return null;
        }

        if ($range === '' || $range === '*' || $range === 'latest') {
            return $this->findMaxVersion($validVersions);
        }

        try {
            $composerRange = $this->convertNpmRangeToComposer($range);
            $satisfying = Semver::satisfiedBy($validVersions, $composerRange);

            if (empty($satisfying)) {
                return null;
            }

            return $this->findMaxVersion($satisfying);
        } catch (\Exception $e) {
            // Direct match fallback
            return in_array($range, $validVersions, true) ? $range : null;
        }
    }

    /**
     * Check if a version string is valid for Composer\Semver.
     */
    private function isValidVersion(string $version): bool
    {
        // Quick regex check for valid semver-like versions
        // Accepts: 1.2.3, 1.2.3-alpha.1, 1.2.3-beta, 1.2.3+build, etc.
        if (!preg_match('/^\d+\.\d+\.\d+(-[a-zA-Z0-9.]+)?(\+[a-zA-Z0-9.]+)?$/', $version)) {
            return false;
        }

        try {
            $this->parser->normalize($version);
            return true;
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * Sort versions in descending order.
     * @param string[] $versions
     * @return string[]
     */
    public function rsort(array $versions): array
    {
        return Semver::rsort($versions);
    }

    /**
     * Sort versions in ascending order.
     * @param string[] $versions
     * @return string[]
     */
    public function sort(array $versions): array
    {
        return Semver::sort($versions);
    }

    /**
     * Compare two versions.
     * @return int -1, 0, or 1
     */
    public function compare(string $v1, string $v2): int
    {
        return Comparator::compare($v1, '<=>', $v2) ? -1 :
               (Comparator::compare($v1, '>', $v2) ? 1 : 0);
    }

    /**
     * Check if v1 > v2.
     */
    public function gt(string $v1, string $v2): bool
    {
        return Comparator::greaterThan($v1, $v2);
    }

    /**
     * Check if v1 >= v2.
     */
    public function gte(string $v1, string $v2): bool
    {
        return Comparator::greaterThanOrEqualTo($v1, $v2);
    }

    /**
     * Check if v1 < v2.
     */
    public function lt(string $v1, string $v2): bool
    {
        return Comparator::lessThan($v1, $v2);
    }

    /**
     * Check if v1 <= v2.
     */
    public function lte(string $v1, string $v2): bool
    {
        return Comparator::lessThanOrEqualTo($v1, $v2);
    }

    /**
     * Check if two versions are equal.
     */
    public function eq(string $v1, string $v2): bool
    {
        return Comparator::equalTo($v1, $v2);
    }

    /**
     * Check if a string is a valid semver version.
     */
    public function valid(string $version): bool
    {
        try {
            $this->parser->normalize($version);
            return true;
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * Coerce a string to a valid semver version.
     */
    public function coerce(string $version): ?string
    {
        // Strip leading 'v'
        $version = ltrim($version, 'v');

        // Try to extract major.minor.patch
        if (preg_match('/^(\d+)(?:\.(\d+))?(?:\.(\d+))?/', $version, $matches)) {
            $major = $matches[1];
            $minor = $matches[2] ?? '0';
            $patch = $matches[3] ?? '0';
            return "$major.$minor.$patch";
        }

        return null;
    }

    /**
     * Convert npm range syntax to Composer-compatible format.
     */
    public function convertNpmRangeToComposer(string $range): string
    {
        $range = trim($range);

        // Handle empty or wildcard
        if ($range === '' || $range === '*') {
            return '*';
        }

        // Handle || (or) operator - split and process each part
        if (str_contains($range, '||')) {
            $parts = array_map('trim', explode('||', $range));
            $converted = array_map([$this, 'convertNpmRangeToComposer'], $parts);
            return implode(' || ', $converted);
        }

        // Handle space-separated AND conditions
        if (preg_match('/\s+/', $range) && !preg_match('/^[<>=~^]/', $range)) {
            $parts = preg_split('/\s+/', $range);
            $converted = array_map([$this, 'convertSingleRange'], $parts);
            return implode(' ', $converted);
        }

        return $this->convertSingleRange($range);
    }

    /**
     * Convert a single npm range expression to Composer format.
     */
    private function convertSingleRange(string $range): string
    {
        $range = trim($range);

        // Handle x-range (1.x, 1.2.x, *)
        if (preg_match('/^(\d+)\.x(?:\.x)?$/i', $range, $m)) {
            return '^' . $m[1] . '.0.0';
        }
        if (preg_match('/^(\d+)\.(\d+)\.x$/i', $range, $m)) {
            return '~' . $m[1] . '.' . $m[2] . '.0';
        }
        if ($range === 'x' || $range === 'X') {
            return '*';
        }

        // Handle hyphen range (1.0.0 - 2.0.0)
        if (preg_match('/^([\d.]+)\s*-\s*([\d.]+)$/', $range, $m)) {
            return '>=' . $m[1] . ' <=' . $m[2];
        }

        // Handle tilde-range (~1.2.3)
        if (preg_match('/^~([\d.]+)/', $range, $m)) {
            // Tilde in npm: allows patch-level changes
            return '~' . $m[1];
        }

        // Handle caret-range (^1.2.3)
        if (preg_match('/^\\^([\d.]+)/', $range, $m)) {
            // Caret in npm: allows minor-level changes (for major >= 1)
            return '^' . $m[1];
        }

        // Handle comparison operators
        if (preg_match('/^([<>=]+)\s*([\d.]+.*)$/', $range, $m)) {
            return $m[1] . $m[2];
        }

        // Handle bare version (exact match)
        if (preg_match('/^[\d.]+(?:-[\w.]+)?(?:\+[\w.]+)?$/', $range)) {
            return $range;
        }

        // Handle partial versions
        if (preg_match('/^(\d+)$/', $range)) {
            return '^' . $range . '.0.0';
        }
        if (preg_match('/^(\d+)\.(\d+)$/', $range)) {
            return '~' . $range . '.0';
        }

        // Return as-is if we can't parse it
        return $range;
    }

    /**
     * Check if a spec is a URL-based dependency.
     */
    private function isUrlSpec(string $spec): bool
    {
        return (bool) preg_match('/^(https?:|git:|git\+|github:|file:|\/|\.\.?\/)/', $spec);
    }

    /**
     * Check if a spec appears to be a tag name.
     */
    private function isTag(string $spec): bool
    {
        // Tags are non-numeric strings that aren't ranges
        if (preg_match('/^[a-zA-Z][\w-]*$/', $spec)) {
            return true;
        }
        return false;
    }

    /**
     * Find the maximum version from a list.
     * @param string[] $versions
     */
    private function findMaxVersion(array $versions): string
    {
        $sorted = Semver::rsort($versions);
        return $sorted[0];
    }

    /**
     * Parse a version range and return the minimum version boundary.
     */
    public function minVersion(string $range): ?string
    {
        $composerRange = $this->convertNpmRangeToComposer($range);

        // Extract the lower bound from >= constraints
        if (preg_match('/>=\s*([\d.]+)/', $composerRange, $m)) {
            return $m[1];
        }

        // For caret/tilde ranges, return the base version
        if (preg_match('/^[~^]([\d.]+)/', $composerRange, $m)) {
            return $m[1];
        }

        // For exact versions, return as-is
        if (preg_match('/^[\d.]+/', $composerRange, $m)) {
            return $m[0];
        }

        return null;
    }

    /**
     * Intersect two ranges, returning a range that satisfies both.
     */
    public function intersect(string $r1, string $r2): ?string
    {
        // Simple implementation: just AND them together
        if ($r1 === '*' || $r1 === '') {
            return $r2;
        }
        if ($r2 === '*' || $r2 === '') {
            return $r1;
        }

        // If they're identical, return one
        if ($r1 === $r2) {
            return $r1;
        }

        // Otherwise return both as an AND constraint
        return $r1 . ' ' . $r2;
    }

    /**
     * Check if a range is a subset of another range.
     */
    public function subset(string $subset, string $superset): bool
    {
        // A range is a subset if everything that satisfies subset also satisfies superset
        // For simplicity, we'll check if the minimum of subset satisfies superset
        $minVersion = $this->minVersion($subset);
        if ($minVersion === null) {
            return false;
        }

        return $this->satisfies($minVersion, $superset);
    }
}
