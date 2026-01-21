<?php

declare(strict_types=1);

namespace PhpNpm\Lockfile;

/**
 * Parser for package-lock.json files.
 * Handles v1, v2, and v3 lockfile formats.
 */
class LockfileParser
{
    /**
     * Parse a lockfile and normalize to v3 format internally.
     *
     * @param array $data Raw lockfile data
     * @return array Normalized lockfile data in v3 format
     */
    public function parse(array $data): array
    {
        $version = $this->detectVersion($data);

        return match ($version) {
            1 => $this->parseV1($data),
            2 => $this->parseV2($data),
            3 => $this->parseV3($data),
            default => throw new LockfileException("Unknown lockfile version: {$version}"),
        };
    }

    /**
     * Detect the lockfile version.
     */
    public function detectVersion(array $data): int
    {
        // Explicit version field
        if (isset($data['lockfileVersion'])) {
            return (int) $data['lockfileVersion'];
        }

        // v3 uses only "packages" object
        if (isset($data['packages']) && !isset($data['dependencies'])) {
            return 3;
        }

        // v2 has both "packages" and "dependencies"
        if (isset($data['packages']) && isset($data['dependencies'])) {
            return 2;
        }

        // v1 has only "dependencies"
        if (isset($data['dependencies'])) {
            return 1;
        }

        // Default to v3 if no indicators
        return 3;
    }

    /**
     * Parse v1 lockfile format.
     * v1 has nested "dependencies" structure.
     */
    private function parseV1(array $data): array
    {
        $packages = [];

        // Root package
        $packages[''] = [
            'name' => $data['name'] ?? '',
            'version' => $data['version'] ?? '0.0.0',
            'dependencies' => $this->extractRootDeps($data),
        ];

        // Convert nested dependencies to flat packages
        if (isset($data['dependencies'])) {
            $this->flattenDependencies(
                $data['dependencies'],
                'node_modules',
                $packages
            );
        }

        return [
            'name' => $data['name'] ?? '',
            'version' => $data['version'] ?? '0.0.0',
            'lockfileVersion' => 3,
            'packages' => $packages,
        ];
    }

    /**
     * Recursively flatten v1 nested dependencies.
     */
    private function flattenDependencies(
        array $deps,
        string $prefix,
        array &$packages
    ): void {
        foreach ($deps as $name => $entry) {
            $location = $prefix . '/' . $name;

            $packages[$location] = [
                'version' => $entry['version'] ?? '0.0.0',
                'resolved' => $entry['resolved'] ?? null,
                'integrity' => $entry['integrity'] ?? null,
                'dev' => $entry['dev'] ?? false,
                'optional' => $entry['optional'] ?? false,
                'dependencies' => $entry['requires'] ?? [],
            ];

            // Handle nested dependencies
            if (isset($entry['dependencies'])) {
                $this->flattenDependencies(
                    $entry['dependencies'],
                    $location . '/node_modules',
                    $packages
                );
            }
        }
    }

    /**
     * Parse v2 lockfile format.
     * v2 has both "packages" (flat) and "dependencies" (nested for backwards compat).
     */
    private function parseV2(array $data): array
    {
        // v2 already has packages in the right format
        // Just ensure the structure is consistent
        $packages = $data['packages'] ?? [];

        // Ensure root package has required fields
        if (!isset($packages[''])) {
            $packages[''] = [
                'name' => $data['name'] ?? '',
                'version' => $data['version'] ?? '0.0.0',
            ];
        }

        return [
            'name' => $data['name'] ?? '',
            'version' => $data['version'] ?? '0.0.0',
            'lockfileVersion' => 3,
            'packages' => $packages,
        ];
    }

    /**
     * Parse v3 lockfile format.
     * v3 has only "packages" (flat structure).
     */
    private function parseV3(array $data): array
    {
        return [
            'name' => $data['name'] ?? '',
            'version' => $data['version'] ?? '0.0.0',
            'lockfileVersion' => 3,
            'packages' => $data['packages'] ?? [],
        ];
    }

    /**
     * Extract root dependencies from lockfile.
     */
    private function extractRootDeps(array $data): array
    {
        $deps = [];

        if (isset($data['dependencies']) && is_array($data['dependencies'])) {
            foreach ($data['dependencies'] as $name => $entry) {
                if (is_array($entry) && isset($entry['version'])) {
                    $deps[$name] = $entry['version'];
                }
            }
        }

        return $deps;
    }

    /**
     * Convert normalized format back to specified version for writing.
     */
    public function serialize(array $normalized, int $version = 3): array
    {
        return match ($version) {
            1 => $this->serializeV1($normalized),
            2 => $this->serializeV2($normalized),
            3 => $this->serializeV3($normalized),
            default => throw new LockfileException("Cannot serialize to lockfile version: {$version}"),
        };
    }

    /**
     * Serialize to v1 format (nested dependencies).
     */
    private function serializeV1(array $normalized): array
    {
        $result = [
            'name' => $normalized['name'] ?? '',
            'version' => $normalized['version'] ?? '0.0.0',
            'lockfileVersion' => 1,
            'requires' => true,
            'dependencies' => [],
        ];

        $packages = $normalized['packages'] ?? [];

        // Build nested structure from flat packages
        foreach ($packages as $location => $entry) {
            if ($location === '') {
                continue; // Skip root
            }

            // Parse location to determine nesting
            $this->insertNestedDep($result['dependencies'], $location, $entry);
        }

        return $result;
    }

    /**
     * Insert a dependency into nested structure based on its location.
     */
    private function insertNestedDep(array &$deps, string $location, array $entry): void
    {
        // Remove node_modules prefix
        $path = preg_replace('#^node_modules/#', '', $location);
        $parts = explode('/node_modules/', $path);

        $name = array_pop($parts);
        $current = &$deps;

        foreach ($parts as $parent) {
            if (!isset($current[$parent])) {
                $current[$parent] = ['dependencies' => []];
            }
            if (!isset($current[$parent]['dependencies'])) {
                $current[$parent]['dependencies'] = [];
            }
            $current = &$current[$parent]['dependencies'];
        }

        $current[$name] = [
            'version' => $entry['version'] ?? '0.0.0',
        ];

        if (isset($entry['resolved'])) {
            $current[$name]['resolved'] = $entry['resolved'];
        }
        if (isset($entry['integrity'])) {
            $current[$name]['integrity'] = $entry['integrity'];
        }
        if (!empty($entry['dev'])) {
            $current[$name]['dev'] = true;
        }
        if (!empty($entry['optional'])) {
            $current[$name]['optional'] = true;
        }
        if (!empty($entry['dependencies'])) {
            $current[$name]['requires'] = $entry['dependencies'];
        }
    }

    /**
     * Serialize to v2 format (both packages and dependencies).
     */
    private function serializeV2(array $normalized): array
    {
        $v3 = $this->serializeV3($normalized);
        $v1 = $this->serializeV1($normalized);

        return [
            'name' => $normalized['name'] ?? '',
            'version' => $normalized['version'] ?? '0.0.0',
            'lockfileVersion' => 2,
            'requires' => true,
            'packages' => $v3['packages'],
            'dependencies' => $v1['dependencies'],
        ];
    }

    /**
     * Serialize to v3 format (flat packages only).
     */
    private function serializeV3(array $normalized): array
    {
        $packages = [];

        foreach ($normalized['packages'] ?? [] as $location => $entry) {
            $packages[$location] = $this->cleanPackageEntry($entry);
        }

        return [
            'name' => $normalized['name'] ?? '',
            'version' => $normalized['version'] ?? '0.0.0',
            'lockfileVersion' => 3,
            'requires' => true,
            'packages' => $packages,
        ];
    }

    /**
     * Clean up a package entry for serialization.
     */
    private function cleanPackageEntry(array $entry): array
    {
        $cleaned = [];

        // Order fields consistently
        $fields = [
            'version', 'resolved', 'integrity', 'dev', 'optional', 'peer',
            'dependencies', 'devDependencies', 'optionalDependencies',
            'peerDependencies', 'peerDependenciesMeta', 'engines',
            'bin', 'license', 'funding',
        ];

        foreach ($fields as $field) {
            if (isset($entry[$field]) && !$this->isEmpty($entry[$field])) {
                $cleaned[$field] = $entry[$field];
            }
        }

        // Add any remaining fields
        foreach ($entry as $key => $value) {
            if (!isset($cleaned[$key]) && !$this->isEmpty($value)) {
                $cleaned[$key] = $value;
            }
        }

        return $cleaned;
    }

    /**
     * Check if a value is "empty" for lockfile purposes.
     */
    private function isEmpty(mixed $value): bool
    {
        if ($value === null || $value === '' || $value === []) {
            return true;
        }
        if ($value === false) {
            return true; // Don't write false booleans
        }
        return false;
    }
}

/**
 * Exception for lockfile-related errors.
 */
class LockfileException extends \Exception
{
}
