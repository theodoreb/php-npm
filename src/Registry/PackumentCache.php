<?php

declare(strict_types=1);

namespace PhpNpm\Registry;

/**
 * In-memory cache for package manifests (packuments).
 */
class PackumentCache
{
    /** @var array<string, array> Cached packuments by package name */
    private array $cache = [];

    /** @var array<string, int> Cache timestamps for TTL tracking */
    private array $timestamps = [];

    /** Time-to-live for cache entries in seconds */
    private int $ttl;

    public function __construct(int $ttl = 300)
    {
        $this->ttl = $ttl;
    }

    /**
     * Get a cached packument.
     */
    public function get(string $name): ?array
    {
        if (!isset($this->cache[$name])) {
            return null;
        }

        // Check TTL
        if ($this->ttl > 0) {
            $age = time() - $this->timestamps[$name];
            if ($age > $this->ttl) {
                $this->delete($name);
                return null;
            }
        }

        return $this->cache[$name];
    }

    /**
     * Store a packument in the cache.
     */
    public function set(string $name, array $packument): void
    {
        $this->cache[$name] = $packument;
        $this->timestamps[$name] = time();
    }

    /**
     * Check if a packument is cached.
     */
    public function has(string $name): bool
    {
        return $this->get($name) !== null;
    }

    /**
     * Delete a packument from cache.
     */
    public function delete(string $name): void
    {
        unset($this->cache[$name], $this->timestamps[$name]);
    }

    /**
     * Clear all cached packuments.
     */
    public function clear(): void
    {
        $this->cache = [];
        $this->timestamps = [];
    }

    /**
     * Get the number of cached packuments.
     */
    public function count(): int
    {
        return count($this->cache);
    }

    /**
     * Get all cached package names.
     * @return string[]
     */
    public function getNames(): array
    {
        return array_keys($this->cache);
    }
}
