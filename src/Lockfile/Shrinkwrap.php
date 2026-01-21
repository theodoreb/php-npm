<?php

declare(strict_types=1);

namespace PhpNpm\Lockfile;

use PhpNpm\Dependency\Node;
use PhpNpm\Dependency\Inventory;

/**
 * Manages package-lock.json files.
 * Named after npm's Shrinkwrap class in arborist.
 */
class Shrinkwrap
{
    private const LOCKFILE_NAME = 'package-lock.json';
    private const SHRINKWRAP_NAME = 'npm-shrinkwrap.json';

    private LockfileParser $parser;
    private string $path;
    private ?string $lockfilePath = null;
    private array $data = [];
    private bool $loaded = false;

    public function __construct(string $path)
    {
        $this->path = rtrim($path, '/');
        $this->parser = new LockfileParser();
    }

    /**
     * Load lockfile from disk.
     */
    public function load(): bool
    {
        // Try shrinkwrap first, then package-lock
        $shrinkwrap = $this->path . '/' . self::SHRINKWRAP_NAME;
        $lockfile = $this->path . '/' . self::LOCKFILE_NAME;

        if (file_exists($shrinkwrap)) {
            $this->lockfilePath = $shrinkwrap;
        } elseif (file_exists($lockfile)) {
            $this->lockfilePath = $lockfile;
        } else {
            $this->lockfilePath = $lockfile;
            $this->data = $this->createEmpty();
            $this->loaded = true;
            return false;
        }

        try {
            $content = file_get_contents($this->lockfilePath);
            $raw = json_decode($content, true, 512, JSON_THROW_ON_ERROR);
            $this->data = $this->parser->parse($raw);
            $this->loaded = true;
            return true;
        } catch (\JsonException $e) {
            throw new LockfileException(
                "Failed to parse lockfile: " . $e->getMessage()
            );
        }
    }

    /**
     * Create an empty lockfile structure.
     */
    private function createEmpty(): array
    {
        return [
            'name' => '',
            'version' => '0.0.0',
            'lockfileVersion' => 3,
            'packages' => [
                '' => [
                    'name' => '',
                    'version' => '0.0.0',
                ],
            ],
        ];
    }

    /**
     * Check if a lockfile exists.
     */
    public function exists(): bool
    {
        return file_exists($this->path . '/' . self::SHRINKWRAP_NAME)
            || file_exists($this->path . '/' . self::LOCKFILE_NAME);
    }

    /**
     * Build a virtual tree from the lockfile.
     */
    public function loadVirtualTree(Node $root): void
    {
        if (!$this->loaded) {
            $this->load();
        }

        $packages = $this->data['packages'] ?? [];

        // Update root from lockfile
        if (isset($packages[''])) {
            $rootPkg = $packages[''];
            if (isset($rootPkg['dependencies'])) {
                // Root deps already loaded from package.json
            }
        }

        // Create nodes for all packages
        $nodes = [];
        foreach ($packages as $location => $entry) {
            if ($location === '') {
                continue; // Skip root
            }

            $name = $this->getNameFromLocation($location);
            $node = Node::createFromLockEntry($name, $entry, $root);
            $node->setLocation($location);
            $nodes[$location] = $node;
        }

        // Build tree structure by placing nodes
        foreach ($nodes as $location => $node) {
            $parentLocation = $this->getParentLocation($location);
            $parent = $parentLocation === '' ? $root : ($nodes[$parentLocation] ?? $root);
            $node->setParent($parent);
        }

        // Build edges for all nodes
        $root->buildEdges();
        foreach ($nodes as $node) {
            $node->buildEdges();
        }
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
     * Get parent location from a package location.
     */
    private function getParentLocation(string $location): string
    {
        // node_modules/foo -> ''
        // node_modules/foo/node_modules/bar -> node_modules/foo
        // node_modules/@scope/foo/node_modules/bar -> node_modules/@scope/foo

        $parts = explode('/node_modules/', $location);

        if (count($parts) <= 1) {
            return '';
        }

        array_pop($parts);
        $parentPath = implode('/node_modules/', $parts);

        if ($parentPath === 'node_modules') {
            return '';
        }

        return $parentPath;
    }

    /**
     * Update lockfile data from a tree.
     */
    public function loadFromTree(Node $root): void
    {
        $packages = [];

        // Root package
        $packages[''] = [
            'name' => $root->getName(),
            'version' => $root->getVersion(),
            'dependencies' => $root->getDependencies(),
            'devDependencies' => $root->getDevDependencies(),
            'optionalDependencies' => $root->getOptionalDependencies(),
        ];

        // Add all descendants
        $this->collectPackages($root, $packages);

        $this->data = [
            'name' => $root->getName(),
            'version' => $root->getVersion(),
            'lockfileVersion' => 3,
            'packages' => $packages,
        ];
    }

    /**
     * Recursively collect packages from tree.
     */
    private function collectPackages(Node $node, array &$packages): void
    {
        foreach ($node->getChildren() as $child) {
            $location = $child->getLocation();
            if ($location === '') {
                $location = 'node_modules/' . $child->getName();
            }

            $packages[$location] = $child->toLockEntry();
            $this->collectPackages($child, $packages);
        }
    }

    /**
     * Save lockfile to disk.
     */
    public function save(int $version = 3): void
    {
        $output = $this->parser->serialize($this->data, $version);

        $path = $this->lockfilePath ?? ($this->path . '/' . self::LOCKFILE_NAME);

        $json = json_encode(
            $output,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
        );

        if ($json === false) {
            throw new LockfileException("Failed to encode lockfile as JSON");
        }

        // Ensure consistent line endings
        $json .= "\n";

        if (file_put_contents($path, $json) === false) {
            throw new LockfileException("Failed to write lockfile to {$path}");
        }
    }

    /**
     * Get the lockfile data.
     */
    public function getData(): array
    {
        return $this->data;
    }

    /**
     * Get package entry from lockfile.
     */
    public function getPackage(string $location): ?array
    {
        return $this->data['packages'][$location] ?? null;
    }

    /**
     * Set package entry in lockfile.
     */
    public function setPackage(string $location, array $entry): void
    {
        $this->data['packages'][$location] = $entry;
    }

    /**
     * Check if a package is in the lockfile.
     */
    public function hasPackage(string $location): bool
    {
        return isset($this->data['packages'][$location]);
    }

    /**
     * Get all package locations.
     * @return string[]
     */
    public function getLocations(): array
    {
        return array_keys($this->data['packages'] ?? []);
    }

    /**
     * Get the lockfile path.
     */
    public function getPath(): string
    {
        return $this->lockfilePath ?? ($this->path . '/' . self::LOCKFILE_NAME);
    }

    /**
     * Get the detected lockfile version.
     */
    public function getVersion(): int
    {
        return $this->data['lockfileVersion'] ?? 3;
    }

    /**
     * Calculate diff between current tree and lockfile.
     *
     * @return array{add: array, remove: array, update: array}
     */
    public function diff(Node $root): array
    {
        $currentPackages = [];
        $this->collectPackages($root, $currentPackages);

        $lockPackages = $this->data['packages'] ?? [];

        $add = [];
        $remove = [];
        $update = [];

        // Find packages to add or update
        foreach ($currentPackages as $location => $entry) {
            if (!isset($lockPackages[$location])) {
                $add[$location] = $entry;
            } elseif ($lockPackages[$location]['version'] !== $entry['version']) {
                $update[$location] = [
                    'from' => $lockPackages[$location],
                    'to' => $entry,
                ];
            }
        }

        // Find packages to remove
        foreach ($lockPackages as $location => $entry) {
            if ($location !== '' && !isset($currentPackages[$location])) {
                $remove[$location] = $entry;
            }
        }

        return [
            'add' => $add,
            'remove' => $remove,
            'update' => $update,
        ];
    }

    /**
     * Verify that current node_modules matches lockfile.
     *
     * @return array List of mismatches
     */
    public function verify(): array
    {
        $issues = [];
        $packages = $this->data['packages'] ?? [];

        foreach ($packages as $location => $entry) {
            if ($location === '') {
                continue;
            }

            $pkgPath = $this->path . '/' . $location;
            $pkgJsonPath = $pkgPath . '/package.json';

            if (!is_dir($pkgPath)) {
                $issues[] = [
                    'type' => 'missing',
                    'location' => $location,
                    'expected' => $entry['version'] ?? 'unknown',
                ];
                continue;
            }

            if (!file_exists($pkgJsonPath)) {
                $issues[] = [
                    'type' => 'missing_package_json',
                    'location' => $location,
                ];
                continue;
            }

            try {
                $pkgJson = json_decode(
                    file_get_contents($pkgJsonPath),
                    true,
                    512,
                    JSON_THROW_ON_ERROR
                );

                $installedVersion = $pkgJson['version'] ?? '0.0.0';
                $expectedVersion = $entry['version'] ?? '0.0.0';

                if ($installedVersion !== $expectedVersion) {
                    $issues[] = [
                        'type' => 'version_mismatch',
                        'location' => $location,
                        'expected' => $expectedVersion,
                        'installed' => $installedVersion,
                    ];
                }
            } catch (\JsonException $e) {
                $issues[] = [
                    'type' => 'invalid_package_json',
                    'location' => $location,
                    'error' => $e->getMessage(),
                ];
            }
        }

        return $issues;
    }
}
