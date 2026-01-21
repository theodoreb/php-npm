<?php

declare(strict_types=1);

namespace PhpNpm\Lockfile;

use Symfony\Component\Yaml\Yaml;

/**
 * Parser for Yarn Berry (v2+) lockfiles.
 *
 * Yarn uses SYML format (Structured YAML) with descriptor-based keys.
 * This parser converts between Yarn format and normalized v3 npm format.
 */
class YarnLockParser
{
    /**
     * Parse a yarn.lock file content into normalized v3 format.
     *
     * @param string $content Raw yarn.lock file content
     * @param array $rootPackageJson The root package.json data for resolving locations
     * @return array Normalized lockfile data in v3 format
     */
    public function parse(string $content, array $rootPackageJson = []): array
    {
        // Parse YAML content
        $data = Yaml::parse($content);

        if (!is_array($data)) {
            throw new LockfileException('Invalid yarn.lock format');
        }

        // Extract metadata
        $metadata = $data['__metadata'] ?? [];
        unset($data['__metadata']);

        // Build a resolution map: descriptor -> entry data
        $resolutionMap = $this->buildResolutionMap($data);

        // Build packages with location paths
        $packages = $this->buildPackagesFromDescriptors($data, $resolutionMap, $rootPackageJson);

        // Extract root package info
        $rootName = $rootPackageJson['name'] ?? '';
        $rootVersion = $rootPackageJson['version'] ?? '0.0.0';

        return [
            'name' => $rootName,
            'version' => $rootVersion,
            'lockfileVersion' => 3,
            'packages' => $packages,
            '_yarn' => [
                'metadata' => $metadata,
                'originalFormat' => 'yarn',
            ],
        ];
    }

    /**
     * Build a map from resolution string to entry data.
     *
     * @param array $data Parsed yarn.lock data
     * @return array<string, array> Map of resolution -> entry
     */
    private function buildResolutionMap(array $data): array
    {
        $map = [];

        foreach ($data as $descriptorKey => $entry) {
            if (!is_array($entry) || !isset($entry['resolution'])) {
                continue;
            }

            // Store by resolution string
            $map[$entry['resolution']] = $entry;

            // Also store by each descriptor variant
            $descriptors = $this->parseDescriptorKey($descriptorKey);
            foreach ($descriptors as $descriptor) {
                $map[$descriptor] = $entry;
            }
        }

        return $map;
    }

    /**
     * Parse a descriptor key which may contain multiple comma-separated descriptors.
     *
     * @param string $key The descriptor key
     * @return string[] Array of individual descriptors
     */
    private function parseDescriptorKey(string $key): array
    {
        // Keys can be comma-separated: "@babel/core@npm:^7.0.0, @babel/core@npm:^7.1.0"
        $descriptors = preg_split('/,\s*/', $key);

        return array_filter(array_map('trim', $descriptors));
    }

    /**
     * Build packages array with computed locations.
     *
     * @param array $data Parsed yarn.lock entries
     * @param array $resolutionMap Resolution to entry map
     * @param array $rootPackageJson Root package.json
     * @return array<string, array> Packages indexed by location
     */
    private function buildPackagesFromDescriptors(
        array $data,
        array $resolutionMap,
        array $rootPackageJson
    ): array {
        $packages = [];
        $placed = []; // Track which packages are placed at which locations

        // Root package entry
        $packages[''] = [
            'name' => $rootPackageJson['name'] ?? '',
            'version' => $rootPackageJson['version'] ?? '0.0.0',
            'dependencies' => $rootPackageJson['dependencies'] ?? [],
            'devDependencies' => $rootPackageJson['devDependencies'] ?? [],
            'optionalDependencies' => $rootPackageJson['optionalDependencies'] ?? [],
        ];

        // Build a map of package name+version to yarn entries
        $packageVersionMap = [];
        foreach ($data as $descriptorKey => $entry) {
            if (!is_array($entry) || !isset($entry['resolution'])) {
                continue;
            }

            $resolution = YarnDescriptor::parseResolution($entry['resolution']);
            if ($resolution === null) {
                continue;
            }

            $name = $resolution->getName();
            $version = $entry['version'] ?? '0.0.0';

            $key = $name . '@' . $version;
            if (!isset($packageVersionMap[$key])) {
                $packageVersionMap[$key] = [
                    'name' => $name,
                    'version' => $version,
                    'entry' => $entry,
                    'descriptors' => [],
                ];
            }

            // Collect all descriptors that resolve to this version
            $descriptors = $this->parseDescriptorKey($descriptorKey);
            $packageVersionMap[$key]['descriptors'] = array_merge(
                $packageVersionMap[$key]['descriptors'],
                $descriptors
            );
        }

        // Place packages using hoisting algorithm
        $this->placePackagesWithHoisting($packages, $packageVersionMap, $rootPackageJson, $placed);

        return $packages;
    }

    /**
     * Place packages using npm-style hoisting algorithm.
     *
     * @param array &$packages Packages array to populate
     * @param array $packageVersionMap Map of name@version to entry data
     * @param array $rootPackageJson Root package.json
     * @param array &$placed Track placed packages
     */
    private function placePackagesWithHoisting(
        array &$packages,
        array $packageVersionMap,
        array $rootPackageJson,
        array &$placed
    ): void {
        // First pass: collect all dependencies that need to be placed
        $depsToPlace = [];

        // Start with root dependencies
        $allDeps = array_merge(
            $rootPackageJson['dependencies'] ?? [],
            $rootPackageJson['devDependencies'] ?? [],
            $rootPackageJson['optionalDependencies'] ?? []
        );

        foreach ($allDeps as $depName => $depRange) {
            $this->collectDependency($depName, $depRange, '', $depsToPlace, $packageVersionMap);
        }

        // Process all dependencies using BFS for proper hoisting
        $queue = $depsToPlace;
        $processed = [];

        while (!empty($queue)) {
            $item = array_shift($queue);
            $depName = $item['name'];
            $depVersion = $item['version'];
            $parentLocation = $item['parent'];
            $entry = $item['entry'];
            $key = $depName . '@' . $depVersion;

            if (isset($processed[$key . '@' . $parentLocation])) {
                continue;
            }
            $processed[$key . '@' . $parentLocation] = true;

            // Try to hoist to node_modules root first
            $hoistedLocation = 'node_modules/' . $depName;

            if (!isset($placed[$depName])) {
                // Can hoist to root
                $location = $hoistedLocation;
            } elseif ($placed[$depName] === $depVersion) {
                // Already placed with same version, skip
                continue;
            } else {
                // Conflict - need to nest under parent
                if ($parentLocation === '') {
                    $location = 'node_modules/' . $depName;
                } else {
                    $location = $parentLocation . '/node_modules/' . $depName;
                }
            }

            if (isset($packages[$location])) {
                continue; // Already placed here
            }

            // Place the package
            $packages[$location] = $this->convertYarnEntryToNpmEntry($entry, $depName);
            $placed[$depName] = $depVersion;

            // Queue sub-dependencies
            $subDeps = $entry['dependencies'] ?? [];
            foreach ($subDeps as $subName => $subRange) {
                $this->collectDependency($subName, $subRange, $location, $queue, $packageVersionMap);
            }
        }
    }

    /**
     * Collect a dependency for placement.
     */
    private function collectDependency(
        string $name,
        string $range,
        string $parentLocation,
        array &$queue,
        array $packageVersionMap
    ): void {
        // Parse the range to handle npm: prefix
        $effectiveName = $name;
        $effectiveRange = $range;

        // Handle npm:package@range format
        if (str_starts_with($range, 'npm:')) {
            $rest = substr($range, 4);
            if (str_starts_with($rest, '@')) {
                // Scoped: npm:@scope/pkg@range
                $parts = explode('@', $rest, 3);
                if (count($parts) >= 3) {
                    $effectiveName = '@' . $parts[1];
                    $effectiveRange = $parts[2];
                }
            } else {
                // Unscoped: npm:pkg@range or just a version
                $atPos = strpos($rest, '@');
                if ($atPos !== false) {
                    $effectiveName = substr($rest, 0, $atPos);
                    $effectiveRange = substr($rest, $atPos + 1);
                } else {
                    // Just a version like npm:1.2.3
                    $effectiveRange = $rest;
                }
            }
        }

        // Skip non-npm protocols
        if (str_starts_with($range, 'workspace:') || str_starts_with($range, 'portal:') || str_starts_with($range, 'patch:')) {
            return;
        }

        // Find matching package version
        foreach ($packageVersionMap as $key => $data) {
            if ($data['name'] === $effectiveName || $data['name'] === $name) {
                $queue[] = [
                    'name' => $name,
                    'version' => $data['version'],
                    'parent' => $parentLocation,
                    'entry' => $data['entry'],
                ];
                return;
            }
        }
    }

    /**
     * Convert a Yarn entry to npm lockfile entry format.
     *
     * @param array $yarnEntry The Yarn entry
     * @param string $name Package name
     * @return array npm-style entry
     */
    private function convertYarnEntryToNpmEntry(array $yarnEntry, string $name): array
    {
        $entry = [
            'version' => $yarnEntry['version'] ?? '0.0.0',
        ];

        // Convert resolution to resolved URL if it's an npm package
        $resolution = $yarnEntry['resolution'] ?? null;
        if ($resolution !== null) {
            $parsed = YarnDescriptor::parseResolution($resolution);
            if ($parsed !== null && $parsed->isNpm()) {
                $packageName = $parsed->getName();
                $version = $parsed->getRange();
                // Construct npm registry URL
                $encodedName = str_replace('/', '%2f', $packageName);
                $entry['resolved'] = "https://registry.npmjs.org/{$packageName}/-/{$this->getBaseName($packageName)}-{$version}.tgz";
            }
        }

        // Note: Yarn checksums cannot be converted to npm integrity format
        // They use different algorithms. Preserve for roundtrip.
        if (isset($yarnEntry['checksum'])) {
            $entry['_yarn'] = [
                'checksum' => $yarnEntry['checksum'],
                'resolution' => $yarnEntry['resolution'] ?? null,
            ];
        }

        // Convert dependencies (strip npm: prefix)
        if (!empty($yarnEntry['dependencies'])) {
            $entry['dependencies'] = $this->convertDependencies($yarnEntry['dependencies']);
        }

        if (!empty($yarnEntry['optionalDependencies'])) {
            $entry['optionalDependencies'] = $this->convertDependencies($yarnEntry['optionalDependencies']);
        }

        if (!empty($yarnEntry['peerDependencies'])) {
            $entry['peerDependencies'] = $this->convertDependencies($yarnEntry['peerDependencies']);
        }

        if (!empty($yarnEntry['peerDependenciesMeta'])) {
            $entry['peerDependenciesMeta'] = $yarnEntry['peerDependenciesMeta'];
        }

        return $entry;
    }

    /**
     * Convert Yarn-style dependencies to npm style.
     *
     * @param array $deps Yarn dependencies
     * @return array npm-style dependencies
     */
    private function convertDependencies(array $deps): array
    {
        $result = [];

        foreach ($deps as $name => $range) {
            // Strip npm: prefix
            if (str_starts_with($range, 'npm:')) {
                $range = substr($range, 4);
            }
            $result[$name] = $range;
        }

        return $result;
    }

    /**
     * Get the base package name without scope.
     */
    private function getBaseName(string $name): string
    {
        if (!str_starts_with($name, '@')) {
            return $name;
        }

        $slashPos = strpos($name, '/');
        if ($slashPos === false) {
            return $name;
        }

        return substr($name, $slashPos + 1);
    }

    /**
     * Serialize normalized format back to yarn.lock format.
     *
     * @param array $normalized Normalized lockfile data
     * @param array $originalYarnData Original yarn metadata for roundtrip
     * @return string yarn.lock file content
     */
    public function serialize(array $normalized, array $originalYarnData = []): string
    {
        $output = [];

        // Header comment
        $output[] = '# This file is generated by running "yarn install" inside your project.';
        $output[] = '# Manual changes might be lost - proceed with caution!';
        $output[] = '';

        // Metadata
        $metadata = $originalYarnData['_yarn']['metadata'] ?? ['version' => 8, 'cacheKey' => 10];
        $output[] = '__metadata:';
        $output[] = '  version: ' . ($metadata['version'] ?? 8);
        $output[] = '  cacheKey: ' . ($metadata['cacheKey'] ?? 10);
        $output[] = '';

        // Collect entries grouped by resolution
        $entries = $this->collectEntriesForSerialization($normalized, $originalYarnData);

        // Sort entries alphabetically by first descriptor
        ksort($entries, SORT_STRING);

        foreach ($entries as $descriptorKey => $entry) {
            $output[] = $this->serializeEntry($descriptorKey, $entry);
        }

        return implode("\n", $output);
    }

    /**
     * Collect entries for serialization, grouped by resolution.
     */
    private function collectEntriesForSerialization(array $normalized, array $originalYarnData): array
    {
        $entries = [];
        $packages = $normalized['packages'] ?? [];

        foreach ($packages as $location => $entry) {
            if ($location === '') {
                continue; // Skip root
            }

            $name = $this->getNameFromLocation($location);
            $version = $entry['version'] ?? '0.0.0';

            // Build descriptor key
            $descriptorKey = '"' . $name . '@npm:' . $version . '"';

            // Build yarn entry
            $yarnEntry = [
                'version' => $version,
                'resolution' => '"' . $name . '@npm:' . $version . '"',
            ];

            // Restore original yarn metadata if available
            if (isset($entry['_yarn'])) {
                if (isset($entry['_yarn']['checksum'])) {
                    $yarnEntry['checksum'] = $entry['_yarn']['checksum'];
                }
            }

            // Dependencies
            if (!empty($entry['dependencies'])) {
                $yarnEntry['dependencies'] = $this->serializeDependencies($entry['dependencies']);
            }

            if (!empty($entry['peerDependencies'])) {
                $yarnEntry['peerDependencies'] = $this->serializeDependencies($entry['peerDependencies']);
            }

            $yarnEntry['languageName'] = 'node';
            $yarnEntry['linkType'] = 'hard';

            $entries[$descriptorKey] = $yarnEntry;
        }

        return $entries;
    }

    /**
     * Serialize dependencies back to yarn format.
     */
    private function serializeDependencies(array $deps): array
    {
        $result = [];

        foreach ($deps as $name => $range) {
            // Add npm: prefix
            $result[$name] = 'npm:' . $range;
        }

        return $result;
    }

    /**
     * Serialize a single entry to SYML format.
     */
    private function serializeEntry(string $descriptorKey, array $entry): string
    {
        $lines = [];
        $lines[] = $descriptorKey . ':';

        foreach ($entry as $key => $value) {
            if (is_array($value)) {
                $lines[] = '  ' . $key . ':';
                foreach ($value as $subKey => $subValue) {
                    $lines[] = '    ' . $this->quoteIfNeeded($subKey) . ': ' . $this->quoteValue($subValue);
                }
            } else {
                $lines[] = '  ' . $key . ': ' . $this->formatValue($value);
            }
        }

        $lines[] = '';
        return implode("\n", $lines);
    }

    /**
     * Format a value for YAML output.
     */
    private function formatValue(mixed $value): string
    {
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        if (is_int($value)) {
            return (string) $value;
        }

        if (is_string($value)) {
            // Already quoted strings
            if (str_starts_with($value, '"')) {
                return $value;
            }

            // Check if needs quoting
            if ($this->needsQuoting($value)) {
                return '"' . addslashes($value) . '"';
            }
        }

        return (string) $value;
    }

    /**
     * Quote a value if needed.
     */
    private function quoteValue(mixed $value): string
    {
        if (is_string($value) && !str_starts_with($value, '"')) {
            return '"' . $value . '"';
        }
        return (string) $value;
    }

    /**
     * Quote a key if it contains special characters.
     */
    private function quoteIfNeeded(string $key): string
    {
        if (str_contains($key, '@') || str_contains($key, '/') || str_contains($key, ':')) {
            return '"' . $key . '"';
        }
        return $key;
    }

    /**
     * Check if a string value needs quoting.
     */
    private function needsQuoting(string $value): bool
    {
        return preg_match('/[:#@\[\]{}|>*&!%\'"]/', $value) === 1
            || str_starts_with($value, '-')
            || is_numeric($value);
    }

    /**
     * Extract package name from location path.
     */
    private function getNameFromLocation(string $location): string
    {
        // Handle scoped packages
        if (preg_match('#/(@[^/]+/[^/]+)$#', $location, $m)) {
            return $m[1];
        }

        // Regular package
        return basename($location);
    }

    /**
     * Check if content is a yarn.lock file.
     *
     * @param string $content File content to check
     * @return bool True if this looks like a yarn.lock file
     */
    public static function isYarnLock(string $content): bool
    {
        // Yarn lockfiles start with a comment and have __metadata
        return str_contains($content, '__metadata:')
            || str_starts_with(trim($content), '# This file is generated by running "yarn');
    }
}
