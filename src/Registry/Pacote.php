<?php

declare(strict_types=1);

namespace PhpNpm\Registry;

use PhpNpm\Dependency\Node;
use PhpNpm\Semver\ComposerSemverAdapter;

/**
 * Package fetching and resolution utility.
 * Named after npm's "pacote" package.
 */
class Pacote
{
    private Client $client;
    private ComposerSemverAdapter $semver;

    public function __construct(?Client $client = null)
    {
        $this->client = $client ?? new Client();
        $this->semver = new ComposerSemverAdapter();
    }

    /**
     * Resolve a package spec to a specific version.
     *
     * @param string $name Package name
     * @param string $spec Version spec (e.g., "^1.0.0", "latest", "1.2.3")
     * @return array{name: string, version: string, manifest: array}
     * @throws RegistryException
     */
    public function resolve(string $name, string $spec = 'latest'): array
    {
        $packument = $this->client->fetchPackument($name);

        // Resolve the version
        $version = $this->resolveVersion($packument, $spec);

        if ($version === null) {
            throw new RegistryException(
                "No version found for {$name}@{$spec}"
            );
        }

        $manifest = $packument['versions'][$version] ?? [];

        return [
            'name' => $name,
            'version' => $version,
            'manifest' => $manifest,
        ];
    }

    /**
     * Resolve a version spec against a packument.
     */
    public function resolveVersion(array $packument, string $spec): ?string
    {
        $distTags = $packument['dist-tags'] ?? [];
        $versions = array_keys($packument['versions'] ?? []);

        if (empty($versions)) {
            return null;
        }

        // Handle dist-tags (latest, next, beta, etc.)
        if (isset($distTags[$spec])) {
            return $distTags[$spec];
        }

        // Handle empty spec or wildcard
        if ($spec === '' || $spec === '*' || $spec === 'latest') {
            return $distTags['latest'] ?? $this->semver->maxSatisfying($versions, '*');
        }

        // Handle exact version
        if (in_array($spec, $versions, true)) {
            return $spec;
        }

        // Handle range
        return $this->semver->maxSatisfying($versions, $spec);
    }

    /**
     * Create a Node from a package spec.
     *
     * @throws RegistryException
     */
    public function manifest(string $name, string $spec = 'latest'): Node
    {
        $resolved = $this->resolve($name, $spec);

        return Node::createFromPackument(
            $resolved['name'],
            $resolved['version'],
            $resolved['manifest']
        );
    }

    /**
     * Get the tarball URL for a package.
     *
     * @throws RegistryException
     */
    public function tarball(string $name, string $spec = 'latest'): string
    {
        $resolved = $this->resolve($name, $spec);
        $manifest = $resolved['manifest'];

        if (isset($manifest['dist']['tarball'])) {
            return $manifest['dist']['tarball'];
        }

        // Construct URL if not in manifest
        return $this->client->getTarballUrl($name, $resolved['version']);
    }

    /**
     * Fetch tarball content.
     *
     * @throws RegistryException
     */
    public function fetchTarball(string $name, string $spec = 'latest'): string
    {
        $url = $this->tarball($name, $spec);
        return $this->client->fetchTarball($url);
    }

    /**
     * Download tarball to a file.
     *
     * @throws RegistryException
     */
    public function downloadTarball(string $name, string $spec, string $destination): void
    {
        $url = $this->tarball($name, $spec);
        $this->client->downloadTarball($url, $destination);
    }

    /**
     * Get the integrity hash for a package version.
     *
     * @throws RegistryException
     */
    public function getIntegrity(string $name, string $spec = 'latest'): ?string
    {
        $resolved = $this->resolve($name, $spec);
        return $resolved['manifest']['dist']['integrity'] ?? null;
    }

    /**
     * Get all available versions for a package.
     *
     * @return string[]
     * @throws RegistryException
     */
    public function versions(string $name): array
    {
        return $this->client->getVersions($name);
    }

    /**
     * Get packument for a package.
     *
     * @throws RegistryException
     */
    public function packument(string $name): array
    {
        return $this->client->fetchPackument($name);
    }

    /**
     * Extract package data needed for lockfile.
     *
     * @throws RegistryException
     */
    public function extractLockData(string $name, string $spec = 'latest'): array
    {
        $resolved = $this->resolve($name, $spec);
        $manifest = $resolved['manifest'];

        $lockData = [
            'version' => $resolved['version'],
        ];

        if (isset($manifest['dist']['tarball'])) {
            $lockData['resolved'] = $manifest['dist']['tarball'];
        }

        if (isset($manifest['dist']['integrity'])) {
            $lockData['integrity'] = $manifest['dist']['integrity'];
        }

        // Include dependencies
        if (!empty($manifest['dependencies'])) {
            $lockData['dependencies'] = $manifest['dependencies'];
        }

        if (!empty($manifest['optionalDependencies'])) {
            $lockData['optionalDependencies'] = $manifest['optionalDependencies'];
        }

        if (!empty($manifest['peerDependencies'])) {
            $lockData['peerDependencies'] = $manifest['peerDependencies'];
        }

        if (!empty($manifest['peerDependenciesMeta'])) {
            $lockData['peerDependenciesMeta'] = $manifest['peerDependenciesMeta'];
        }

        // Engines
        if (!empty($manifest['engines'])) {
            $lockData['engines'] = $manifest['engines'];
        }

        return $lockData;
    }

    /**
     * Get the underlying registry client.
     */
    public function getClient(): Client
    {
        return $this->client;
    }

    /**
     * Resolve multiple packages in parallel.
     *
     * @param array<string, string> $specs Map of package name to version spec
     * @param int $concurrency Maximum concurrent requests (default: 10)
     * @return array<string, array{name: string, version: string, manifest: array}> Resolved packages
     */
    public function resolveParallel(array $specs, int $concurrency = 10): array
    {
        // Fetch all packuments in parallel
        $names = array_keys($specs);
        $packuments = $this->client->fetchPackumentsParallel($names, $concurrency);

        // Resolve versions from packuments
        $results = [];
        foreach ($specs as $name => $spec) {
            if (!isset($packuments[$name])) {
                continue; // Skip if packument fetch failed
            }

            $packument = $packuments[$name];
            $version = $this->resolveVersion($packument, $spec);

            if ($version !== null) {
                $results[$name] = [
                    'name' => $name,
                    'version' => $version,
                    'manifest' => $packument['versions'][$version] ?? [],
                ];
            }
        }

        return $results;
    }

    /**
     * Fetch multiple packuments in parallel.
     *
     * @param string[] $names Package names
     * @param int $concurrency Maximum concurrent requests (default: 10)
     * @return array<string, array> Map of name to packument
     */
    public function packumentsParallel(array $names, int $concurrency = 10): array
    {
        return $this->client->fetchPackumentsParallel($names, $concurrency);
    }

    /**
     * Extract lock data for multiple packages in parallel.
     *
     * @param array<string, string> $specs Map of package name to version spec
     * @param int $concurrency Maximum concurrent requests (default: 10)
     * @return array<string, array> Map of name to lock data
     */
    public function extractLockDataParallel(array $specs, int $concurrency = 10): array
    {
        $resolved = $this->resolveParallel($specs, $concurrency);

        $results = [];
        foreach ($resolved as $name => $data) {
            $manifest = $data['manifest'];

            $lockData = [
                'version' => $data['version'],
            ];

            if (isset($manifest['dist']['tarball'])) {
                $lockData['resolved'] = $manifest['dist']['tarball'];
            }

            if (isset($manifest['dist']['integrity'])) {
                $lockData['integrity'] = $manifest['dist']['integrity'];
            }

            if (!empty($manifest['dependencies'])) {
                $lockData['dependencies'] = $manifest['dependencies'];
            }

            if (!empty($manifest['optionalDependencies'])) {
                $lockData['optionalDependencies'] = $manifest['optionalDependencies'];
            }

            if (!empty($manifest['peerDependencies'])) {
                $lockData['peerDependencies'] = $manifest['peerDependencies'];
            }

            if (!empty($manifest['peerDependenciesMeta'])) {
                $lockData['peerDependenciesMeta'] = $manifest['peerDependenciesMeta'];
            }

            if (!empty($manifest['engines'])) {
                $lockData['engines'] = $manifest['engines'];
            }

            $results[$name] = $lockData;
        }

        return $results;
    }
}
