<?php

declare(strict_types=1);

namespace PhpNpm\Registry;

use GuzzleHttp\Client as HttpClient;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\RequestOptions;
use GuzzleHttp\Pool;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use Psr\Http\Message\ResponseInterface;

/**
 * HTTP client for interacting with the npm registry.
 */
class Client
{
    private const DEFAULT_REGISTRY = 'https://registry.npmjs.org';
    private const ACCEPT_HEADER = 'application/vnd.npm.install-v1+json';

    private HttpClient $http;
    private PackumentCache $cache;
    private string $registry;

    public function __construct(
        ?string $registry = null,
        ?PackumentCache $cache = null,
        ?HttpClient $http = null,
    ) {
        $this->registry = rtrim($registry ?? self::DEFAULT_REGISTRY, '/');
        $this->cache = $cache ?? new PackumentCache();
        $this->http = $http ?? new HttpClient([
            'timeout' => 30,
            'connect_timeout' => 10,
            RequestOptions::HEADERS => [
                'User-Agent' => 'php-npm/1.0.0',
            ],
        ]);
    }

    /**
     * Fetch packument (package manifest) from registry.
     *
     * @throws RegistryException
     */
    public function fetchPackument(string $name): array
    {
        // Check cache first
        $cached = $this->cache->get($name);
        if ($cached !== null) {
            return $cached;
        }

        $url = $this->getPackumentUrl($name);

        try {
            $response = $this->http->get($url, [
                RequestOptions::HEADERS => [
                    'Accept' => self::ACCEPT_HEADER,
                ],
            ]);

            $body = $response->getBody()->getContents();
            $packument = json_decode($body, true, 512, JSON_THROW_ON_ERROR);

            if (!is_array($packument)) {
                throw new RegistryException("Invalid packument response for {$name}");
            }

            // Cache the result
            $this->cache->set($name, $packument);

            return $packument;
        } catch (GuzzleException $e) {
            $statusCode = $e->getCode();

            if ($statusCode === 404) {
                throw new RegistryException("Package not found: {$name}", 404, $e);
            }

            throw new RegistryException(
                "Failed to fetch packument for {$name}: " . $e->getMessage(),
                $statusCode,
                $e
            );
        } catch (\JsonException $e) {
            throw new RegistryException(
                "Invalid JSON response for {$name}: " . $e->getMessage(),
                0,
                $e
            );
        }
    }

    /**
     * Fetch specific version data from a packument.
     *
     * @throws RegistryException
     */
    public function fetchVersion(string $name, string $version): array
    {
        $packument = $this->fetchPackument($name);

        if (!isset($packument['versions'][$version])) {
            throw new RegistryException(
                "Version {$version} not found for package {$name}"
            );
        }

        return $packument['versions'][$version];
    }

    /**
     * Fetch tarball content.
     *
     * @throws RegistryException
     */
    public function fetchTarball(string $url): string
    {
        try {
            $response = $this->http->get($url, [
                RequestOptions::HTTP_ERRORS => true,
            ]);

            return $response->getBody()->getContents();
        } catch (GuzzleException $e) {
            throw new RegistryException(
                "Failed to fetch tarball from {$url}: " . $e->getMessage(),
                $e->getCode(),
                $e
            );
        }
    }

    /**
     * Download tarball to a file.
     *
     * @throws RegistryException
     */
    public function downloadTarball(string $url, string $destination): void
    {
        try {
            $this->http->get($url, [
                RequestOptions::SINK => $destination,
                RequestOptions::HTTP_ERRORS => true,
            ]);
        } catch (GuzzleException $e) {
            throw new RegistryException(
                "Failed to download tarball from {$url}: " . $e->getMessage(),
                $e->getCode(),
                $e
            );
        }
    }

    /**
     * Get all available versions for a package.
     *
     * @return string[]
     * @throws RegistryException
     */
    public function getVersions(string $name): array
    {
        $packument = $this->fetchPackument($name);

        if (!isset($packument['versions'])) {
            return [];
        }

        return array_keys($packument['versions']);
    }

    /**
     * Get dist-tags for a package.
     *
     * @return array<string, string>
     * @throws RegistryException
     */
    public function getDistTags(string $name): array
    {
        $packument = $this->fetchPackument($name);

        return $packument['dist-tags'] ?? [];
    }

    /**
     * Resolve a tag to a version.
     *
     * @throws RegistryException
     */
    public function resolveTag(string $name, string $tag = 'latest'): ?string
    {
        $distTags = $this->getDistTags($name);

        return $distTags[$tag] ?? null;
    }

    /**
     * Get the URL for a packument.
     */
    public function getPackumentUrl(string $name): string
    {
        // Handle scoped packages
        $encodedName = str_replace('/', '%2f', $name);
        return $this->registry . '/' . $encodedName;
    }

    /**
     * Get the tarball URL for a specific version.
     */
    public function getTarballUrl(string $name, string $version): string
    {
        // Handle scoped packages
        $scope = '';
        $packageName = $name;

        if (str_starts_with($name, '@')) {
            [$scope, $packageName] = explode('/', $name, 2);
            $scope .= '/';
        }

        return sprintf(
            '%s/%s%s/-/%s-%s.tgz',
            $this->registry,
            $scope,
            $packageName,
            $packageName,
            $version
        );
    }

    /**
     * Get the registry URL.
     */
    public function getRegistry(): string
    {
        return $this->registry;
    }

    /**
     * Get the cache instance.
     */
    public function getCache(): PackumentCache
    {
        return $this->cache;
    }

    /**
     * Clear the packument cache.
     */
    public function clearCache(): void
    {
        $this->cache->clear();
    }

    /**
     * Fetch multiple packuments in parallel.
     *
     * @param string[] $names Package names to fetch
     * @param int $concurrency Maximum concurrent requests (default: 10)
     * @return array<string, array> Map of package name to packument
     * @throws RegistryException
     */
    public function fetchPackumentsParallel(array $names, int $concurrency = 10): array
    {
        $results = [];
        $errors = [];

        // Filter out cached packages
        $toFetch = [];
        foreach ($names as $name) {
            $cached = $this->cache->get($name);
            if ($cached !== null) {
                $results[$name] = $cached;
            } else {
                $toFetch[] = $name;
            }
        }

        if (empty($toFetch)) {
            return $results;
        }

        // Create request generator
        $requests = function () use ($toFetch) {
            foreach ($toFetch as $name) {
                $url = $this->getPackumentUrl($name);
                yield $name => new Request('GET', $url, [
                    'Accept' => self::ACCEPT_HEADER,
                    'User-Agent' => 'php-npm/1.0.0',
                ]);
            }
        };

        // Execute requests in parallel using Pool
        $pool = new Pool($this->http, $requests(), [
            'concurrency' => $concurrency,
            'fulfilled' => function (ResponseInterface $response, $name) use (&$results) {
                $body = $response->getBody()->getContents();
                $packument = json_decode($body, true, 512, JSON_THROW_ON_ERROR);

                if (is_array($packument)) {
                    $this->cache->set($name, $packument);
                    $results[$name] = $packument;
                }
            },
            'rejected' => function ($reason, $name) use (&$errors) {
                $errors[$name] = $reason instanceof \Exception
                    ? $reason->getMessage()
                    : (string) $reason;
            },
        ]);

        // Wait for all requests to complete
        $pool->promise()->wait();

        // Report errors but don't fail entirely (some packages may be optional)
        if (!empty($errors) && count($errors) === count($toFetch)) {
            throw new RegistryException(
                "Failed to fetch all packuments: " . implode(', ', array_keys($errors))
            );
        }

        return $results;
    }

    /**
     * Fetch multiple tarballs in parallel.
     *
     * @param array<string, string> $urlMap Map of identifier to tarball URL
     * @param int $concurrency Maximum concurrent requests (default: 5)
     * @return array<string, string> Map of identifier to tarball content
     * @throws RegistryException
     */
    public function fetchTarballsParallel(array $urlMap, int $concurrency = 5): array
    {
        $results = [];
        $errors = [];

        if (empty($urlMap)) {
            return $results;
        }

        // Create request generator
        $requests = function () use ($urlMap) {
            foreach ($urlMap as $id => $url) {
                yield $id => new Request('GET', $url, [
                    'User-Agent' => 'php-npm/1.0.0',
                ]);
            }
        };

        // Execute requests in parallel using Pool
        $pool = new Pool($this->http, $requests(), [
            'concurrency' => $concurrency,
            'fulfilled' => function (ResponseInterface $response, $id) use (&$results) {
                $results[$id] = $response->getBody()->getContents();
            },
            'rejected' => function ($reason, $id) use (&$errors) {
                $errors[$id] = $reason instanceof \Exception
                    ? $reason->getMessage()
                    : (string) $reason;
            },
        ]);

        // Wait for all requests to complete
        $pool->promise()->wait();

        if (!empty($errors)) {
            throw new RegistryException(
                "Failed to fetch tarballs: " . implode(', ', array_keys($errors))
            );
        }

        return $results;
    }

    /**
     * Download multiple tarballs to files in parallel.
     *
     * @param array<string, array{url: string, destination: string}> $downloads Map of id to download info
     * @param int $concurrency Maximum concurrent requests (default: 5)
     * @return array<string, bool> Map of identifier to success status
     */
    public function downloadTarballsParallel(array $downloads, int $concurrency = 5): array
    {
        $results = [];
        $errors = [];

        if (empty($downloads)) {
            return $results;
        }

        // Create request generator with sink option
        $requests = function () use ($downloads) {
            foreach ($downloads as $id => $info) {
                yield $id => function () use ($info) {
                    return $this->http->getAsync($info['url'], [
                        RequestOptions::SINK => $info['destination'],
                        RequestOptions::HTTP_ERRORS => true,
                    ]);
                };
            }
        };

        // Execute requests in parallel using Pool
        $pool = new Pool($this->http, $requests(), [
            'concurrency' => $concurrency,
            'fulfilled' => function ($response, $id) use (&$results) {
                $results[$id] = true;
            },
            'rejected' => function ($reason, $id) use (&$errors) {
                $errors[$id] = $reason instanceof \Exception
                    ? $reason->getMessage()
                    : (string) $reason;
            },
        ]);

        // Wait for all requests to complete
        $pool->promise()->wait();

        if (!empty($errors)) {
            throw new RegistryException(
                "Failed to download tarballs: " . implode(', ', array_keys($errors))
            );
        }

        return $results;
    }

    /**
     * Get the underlying HTTP client.
     */
    public function getHttpClient(): HttpClient
    {
        return $this->http;
    }
}

/**
 * Exception for registry-related errors.
 */
class RegistryException extends \Exception
{
}
