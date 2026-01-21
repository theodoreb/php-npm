<?php

declare(strict_types=1);

namespace PhpNpm\Arborist;

use PhpNpm\Dependency\Node;
use PhpNpm\Dependency\Inventory;
use PhpNpm\Lockfile\Shrinkwrap;
use PhpNpm\Registry\Pacote;
use PhpNpm\Registry\Client;
use PhpNpm\Exception\ResolveException;

/**
 * Main orchestrator for dependency management.
 * Coordinates tree building, resolution, and reification.
 */
class Arborist
{
    private string $path;
    private Pacote $pacote;
    private Shrinkwrap $shrinkwrap;
    private IdealTreeBuilder $idealTreeBuilder;
    private ?Reifier $reifier = null;

    private ?Node $actualTree = null;
    private ?Node $idealTree = null;

    /** @var callable|null */
    private $onProgress;

    public function __construct(string $path, array $options = [])
    {
        $this->path = rtrim($path, '/');

        // Set up registry client
        $registry = $options['registry'] ?? null;
        $client = new Client($registry);
        $this->pacote = new Pacote($client);

        // Set up shrinkwrap
        $this->shrinkwrap = new Shrinkwrap($this->path);

        // Set up ideal tree builder
        $this->idealTreeBuilder = new IdealTreeBuilder($this->pacote);
    }

    /**
     * Set progress callback.
     * @param callable(string, int, int): void $callback
     */
    public function onProgress(callable $callback): void
    {
        $this->onProgress = $callback;
        $this->idealTreeBuilder->onProgress($callback);
    }

    /**
     * Load the actual tree from disk (current node_modules state).
     */
    public function loadActual(): Node
    {
        $packageJson = $this->loadPackageJson();
        $this->actualTree = Node::createRoot($this->path, $packageJson);

        // Load from existing node_modules
        $this->loadActualTree($this->actualTree);

        return $this->actualTree;
    }

    /**
     * Build the ideal dependency tree.
     *
     * @param array $options
     *   - update: array of packages to update
     *   - add: array of packages to add (name@spec)
     *   - rm: array of packages to remove
     * @throws ResolveException
     */
    public function buildIdealTree(array $options = []): Node
    {
        $this->progress("Building ideal tree...");

        // Load package.json
        $packageJson = $this->loadPackageJson();

        // Handle add/rm operations
        if (!empty($options['add'])) {
            $packageJson = $this->applyAdditions($packageJson, $options['add']);
        }
        if (!empty($options['rm'])) {
            $packageJson = $this->applyRemovals($packageJson, $options['rm']);
        }

        // Create root node
        $root = Node::createRoot($this->path, $packageJson);

        // Load from lockfile if exists and not updating
        if ($this->shrinkwrap->exists() && empty($options['update'])) {
            $this->progress("Loading from lockfile...");
            $this->shrinkwrap->load();
            $this->shrinkwrap->loadVirtualTree($root);
        }

        // Build ideal tree
        $this->idealTree = $this->idealTreeBuilder->buildIdealTree($root, $options);

        // Update shrinkwrap
        $this->shrinkwrap->loadFromTree($this->idealTree);

        return $this->idealTree;
    }

    /**
     * Reify the ideal tree (install to disk).
     */
    public function reify(array $options = []): void
    {
        if ($this->idealTree === null) {
            throw new \RuntimeException("Must build ideal tree before reifying");
        }

        if ($this->reifier === null) {
            $this->reifier = new Reifier($this->path, $this->pacote);
            if ($this->onProgress !== null) {
                $this->reifier->onProgress($this->onProgress);
            }
        }

        // Calculate diff if we have actual tree
        $diff = null;
        if ($this->actualTree !== null) {
            $diff = $this->idealTreeBuilder->calculateDiff(
                $this->actualTree,
                $this->idealTree
            );
        }

        // Perform reification
        $this->reifier->reify($this->idealTree, $diff, $options);

        // Save lockfile
        $this->shrinkwrap->save();

        // Save updated package.json if modified
        if (!empty($options['save'])) {
            $this->savePackageJson($this->idealTree->getPackageJson());
        }
    }

    /**
     * Install dependencies (npm install).
     */
    public function install(array $options = []): void
    {
        $this->loadActual();
        $this->buildIdealTree($options);
        $this->reify($options);
    }

    /**
     * Clean install from lockfile (npm ci).
     */
    public function ci(array $options = []): void
    {
        if (!$this->shrinkwrap->exists()) {
            throw new \RuntimeException(
                "Cannot perform clean install: no lockfile found (package-lock.json, npm-shrinkwrap.json, or yarn.lock)"
            );
        }

        // Remove existing node_modules
        $nodeModules = $this->path . '/node_modules';
        if (is_dir($nodeModules)) {
            $this->progress("Removing node_modules...");
            $this->removeDirectory($nodeModules);
        }

        // Load from lockfile only, no resolution
        $packageJson = $this->loadPackageJson();
        $root = Node::createRoot($this->path, $packageJson);

        $this->shrinkwrap->load();
        $this->shrinkwrap->loadVirtualTree($root);

        // Verify lockfile matches package.json
        $this->verifyLockfileMatchesPackageJson($root, $packageJson);

        $this->idealTree = $root;
        $this->reify($options);
    }

    /**
     * Update packages (npm update).
     *
     * @param array $packages Package names to update (empty = all)
     */
    public function update(array $packages = [], array $options = []): void
    {
        $options['update'] = $packages ?: true;

        $this->loadActual();
        $this->buildIdealTree($options);
        $this->reify($options);
    }

    /**
     * Add packages (npm install <pkg>).
     *
     * @param array $specs Package specs to add (e.g., ["lodash", "express@4.0.0"])
     * @param array $options
     *   - dev: bool - add to devDependencies
     *   - optional: bool - add to optionalDependencies
     *   - peer: bool - add to peerDependencies
     */
    public function add(array $specs, array $options = []): void
    {
        $options['add'] = $specs;
        $options['save'] = true;

        $this->install($options);
    }

    /**
     * Remove packages (npm uninstall <pkg>).
     *
     * @param array $names Package names to remove
     */
    public function remove(array $names, array $options = []): void
    {
        $options['rm'] = $names;
        $options['save'] = true;

        $this->install($options);
    }

    /**
     * Get the actual tree.
     */
    public function getActualTree(): ?Node
    {
        return $this->actualTree;
    }

    /**
     * Get the ideal tree.
     */
    public function getIdealTree(): ?Node
    {
        return $this->idealTree;
    }

    /**
     * Get the shrinkwrap instance.
     */
    public function getShrinkwrap(): Shrinkwrap
    {
        return $this->shrinkwrap;
    }

    /**
     * Load package.json from disk.
     */
    private function loadPackageJson(): array
    {
        $path = $this->path . '/package.json';

        if (!file_exists($path)) {
            return [
                'name' => basename($this->path),
                'version' => '1.0.0',
            ];
        }

        $content = file_get_contents($path);
        $data = json_decode($content, true, 512, JSON_THROW_ON_ERROR);

        if (!is_array($data)) {
            throw new \RuntimeException("Invalid package.json");
        }

        return $data;
    }

    /**
     * Save package.json to disk.
     */
    private function savePackageJson(array $data): void
    {
        $path = $this->path . '/package.json';

        $json = json_encode(
            $data,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
        );

        file_put_contents($path, $json . "\n");
    }

    /**
     * Apply package additions to package.json data.
     */
    private function applyAdditions(array $packageJson, array $specs): array
    {
        foreach ($specs as $spec) {
            $parsed = $this->parseSpec($spec);
            $name = $parsed['name'];
            $version = $parsed['version'] ?? '*';

            // Check if this is an alias
            $isAlias = isset($parsed['registryName']);
            $registryName = $parsed['registryName'] ?? $name;
            $rawSpec = $parsed['rawSpec'] ?? $version;

            // Resolve actual version using the registry name
            try {
                $resolved = $this->pacote->resolve($registryName, $rawSpec);
                if ($isAlias) {
                    // For aliases, preserve the npm:package@version format
                    $version = 'npm:' . $registryName . '@^' . $resolved['version'];
                } else {
                    $version = '^' . $resolved['version'];
                }
            } catch (\Exception $e) {
                // Keep original spec
            }

            // Add to appropriate section
            if (!isset($packageJson['dependencies'])) {
                $packageJson['dependencies'] = [];
            }
            $packageJson['dependencies'][$name] = $version;
        }

        return $packageJson;
    }

    /**
     * Apply package removals to package.json data.
     */
    private function applyRemovals(array $packageJson, array $names): array
    {
        foreach ($names as $name) {
            unset($packageJson['dependencies'][$name]);
            unset($packageJson['devDependencies'][$name]);
            unset($packageJson['optionalDependencies'][$name]);
            unset($packageJson['peerDependencies'][$name]);
        }

        return $packageJson;
    }

    /**
     * Parse a package spec (name@version or alias@npm:package@version).
     *
     * Supports:
     * - "lodash" -> {name: "lodash", version: null}
     * - "lodash@^4.0.0" -> {name: "lodash", version: "^4.0.0"}
     * - "@scope/pkg@^1.0.0" -> {name: "@scope/pkg", version: "^1.0.0"}
     * - "alias@npm:package@^1.0.0" -> {name: "alias", version: "npm:package@^1.0.0", registryName: "package", rawSpec: "^1.0.0"}
     * - "alias@npm:@scope/pkg@^1.0.0" -> {name: "alias", version: "npm:@scope/pkg@^1.0.0", registryName: "@scope/pkg", rawSpec: "^1.0.0"}
     */
    private function parseSpec(string $spec): array
    {
        // Check for alias syntax: alias@npm:package@version
        if (preg_match('/^(.+?)@npm:(.+)$/i', $spec, $matches)) {
            $aliasName = $matches[1];
            $npmSpec = $matches[2];

            // Parse the npm: part to get registryName and version
            $parsed = Node::parseAliasSpec('npm:' . $npmSpec);

            return [
                'name' => $aliasName,
                'version' => 'npm:' . $npmSpec,  // Full spec for package.json
                'registryName' => $parsed['registryName'],
                'rawSpec' => $parsed['rawSpec'],
            ];
        }

        // Handle scoped packages (@scope/name@version)
        if (str_starts_with($spec, '@')) {
            $parts = explode('@', $spec, 3);
            if (count($parts) >= 3) {
                return [
                    'name' => '@' . $parts[1],
                    'version' => $parts[2],
                ];
            }
            return [
                'name' => '@' . $parts[1],
                'version' => null,
            ];
        }

        // Regular packages
        $parts = explode('@', $spec, 2);
        return [
            'name' => $parts[0],
            'version' => $parts[1] ?? null,
        ];
    }

    /**
     * Load actual tree from existing node_modules.
     */
    private function loadActualTree(Node $root): void
    {
        $nodeModules = $this->path . '/node_modules';

        if (!is_dir($nodeModules)) {
            return;
        }

        $this->loadNodeModulesDir($root, $nodeModules);
        $root->buildEdges();
    }

    /**
     * Recursively load packages from a node_modules directory.
     */
    private function loadNodeModulesDir(Node $parent, string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        $entries = scandir($dir);

        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $entryPath = $dir . '/' . $entry;

            // Handle scoped packages
            if (str_starts_with($entry, '@') && is_dir($entryPath)) {
                $scopedEntries = scandir($entryPath);
                foreach ($scopedEntries as $scopedEntry) {
                    if ($scopedEntry === '.' || $scopedEntry === '..') {
                        continue;
                    }
                    $this->loadPackageDir(
                        $parent,
                        $entryPath . '/' . $scopedEntry,
                        '@' . $entry . '/' . $scopedEntry
                    );
                }
                continue;
            }

            if (is_dir($entryPath)) {
                $this->loadPackageDir($parent, $entryPath, $entry);
            }
        }
    }

    /**
     * Load a single package directory.
     */
    private function loadPackageDir(Node $parent, string $dir, string $name): void
    {
        $pkgJsonPath = $dir . '/package.json';

        if (!file_exists($pkgJsonPath)) {
            return;
        }

        try {
            $pkgJson = json_decode(
                file_get_contents($pkgJsonPath),
                true,
                512,
                JSON_THROW_ON_ERROR
            );

            $node = new Node(
                $name,
                $pkgJson['version'] ?? '0.0.0',
                $pkgJson
            );

            $parent->addChild($node);

            // Check for nested node_modules
            $nestedNodeModules = $dir . '/node_modules';
            if (is_dir($nestedNodeModules)) {
                $this->loadNodeModulesDir($node, $nestedNodeModules);
            }

            $node->buildEdges();
        } catch (\JsonException $e) {
            // Skip invalid packages
        }
    }

    /**
     * Verify lockfile matches package.json deps.
     */
    private function verifyLockfileMatchesPackageJson(Node $root, array $packageJson): void
    {
        $deps = array_merge(
            $packageJson['dependencies'] ?? [],
            $packageJson['devDependencies'] ?? []
        );

        foreach ($deps as $name => $spec) {
            $edge = $root->getEdgeOut($name);
            if ($edge === null || $edge->getTo() === null) {
                throw new \RuntimeException(
                    "Lockfile missing dependency: {$name}. " .
                    "Run 'php-npm install' to update lockfile."
                );
            }
        }
    }

    /**
     * Recursively remove a directory.
     */
    private function removeDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        $entries = scandir($dir);
        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $path = $dir . '/' . $entry;

            if (is_dir($path)) {
                $this->removeDirectory($path);
            } else {
                unlink($path);
            }
        }

        rmdir($dir);
    }

    /**
     * Report progress.
     */
    private function progress(string $message): void
    {
        if ($this->onProgress !== null) {
            ($this->onProgress)($message, 0, 0);
        }
    }
}
