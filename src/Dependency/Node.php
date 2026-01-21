<?php

declare(strict_types=1);

namespace PhpNpm\Dependency;

use PhpNpm\Semver\ComposerSemverAdapter;

/**
 * Represents a package node in the dependency tree.
 * Can be root, a direct dependency, or a transitive dependency.
 */
class Node
{
    private ?Node $parent = null;
    private ?Node $root = null;
    private string $path = '';
    private string $location = '';
    private string $realpath = '';

    /** @var array<string, Node> Children nodes (packages in this node's node_modules) */
    private array $children = [];

    /** @var array<string, Edge> Edges OUT from this node (dependencies this node requires) */
    private array $edgesOut = [];

    /** @var array<string, Edge> Edges IN to this node (packages that depend on this node) */
    private array $edgesIn = [];

    /** @var array<string, string> Raw dependencies from package.json */
    private array $dependencies = [];

    /** @var array<string, string> Raw devDependencies from package.json */
    private array $devDependencies = [];

    /** @var array<string, string> Raw optionalDependencies from package.json */
    private array $optionalDependencies = [];

    /** @var array<string, string> Raw peerDependencies from package.json */
    private array $peerDependencies = [];

    /** @var array<string, bool> Raw peerDependenciesMeta from package.json */
    private array $peerDependenciesMeta = [];

    private bool $isRoot = false;
    private bool $dev = false;
    private bool $optional = false;
    private bool $peer = false;
    private bool $extraneous = false;
    private bool $isLink = false;
    private bool $hasShrinkwrap = false;

    /** @var array<string, mixed> Full package.json data */
    private array $packageJson = [];

    private ?string $resolved = null;
    private ?string $integrity = null;

    /**
     * The actual package name from the registry (different from $name for aliases).
     * When a package is aliased (e.g., "string-width-cjs": "npm:string-width@^4.2.0"),
     * $name is "string-width-cjs" (folder name) and $registryName is "string-width".
     */
    private ?string $registryName = null;

    private static ?ComposerSemverAdapter $semver = null;

    public function __construct(
        private readonly string $name,
        private readonly string $version,
        array $packageJson = [],
    ) {
        $this->packageJson = $packageJson;
        $this->loadDependenciesFromPackageJson($packageJson);
    }

    private function loadDependenciesFromPackageJson(array $pkg): void
    {
        $this->dependencies = $pkg['dependencies'] ?? [];
        $this->devDependencies = $pkg['devDependencies'] ?? [];
        $this->optionalDependencies = $pkg['optionalDependencies'] ?? [];
        $this->peerDependencies = $pkg['peerDependencies'] ?? [];

        $meta = $pkg['peerDependenciesMeta'] ?? [];
        foreach ($meta as $name => $data) {
            $this->peerDependenciesMeta[$name] = $data['optional'] ?? false;
        }
    }

    public static function createRoot(string $path, array $packageJson): self
    {
        $name = $packageJson['name'] ?? basename($path);
        $version = $packageJson['version'] ?? '0.0.0';

        $node = new self($name, $version, $packageJson);
        $node->isRoot = true;
        $node->root = $node;
        $node->path = $path;
        $node->realpath = realpath($path) ?: $path;
        $node->location = '';

        return $node;
    }

    public static function createFromLockEntry(string $name, array $entry, Node $root): self
    {
        $version = $entry['version'] ?? '0.0.0';

        // The 'name' field in the lockfile entry is the actual package name
        // (different from $name which is the folder/alias name for aliases)
        $packageName = $entry['name'] ?? $name;

        $packageJson = [
            'name' => $packageName,
            'version' => $version,
            'dependencies' => $entry['dependencies'] ?? [],
            'devDependencies' => $entry['devDependencies'] ?? [],
            'optionalDependencies' => $entry['optionalDependencies'] ?? [],
            'peerDependencies' => $entry['peerDependencies'] ?? [],
            'peerDependenciesMeta' => $entry['peerDependenciesMeta'] ?? [],
        ];

        $node = new self($name, $version, $packageJson);
        $node->root = $root;
        $node->resolved = $entry['resolved'] ?? null;
        $node->integrity = $entry['integrity'] ?? null;
        $node->dev = $entry['dev'] ?? false;
        $node->optional = $entry['optional'] ?? false;
        $node->peer = $entry['peer'] ?? false;

        // Set registry name if this is an alias (name field differs from folder name)
        if ($packageName !== $name) {
            $node->registryName = $packageName;
        }

        return $node;
    }

    public static function createFromPackument(string $name, string $version, array $versionData): self
    {
        $packageJson = array_merge(['name' => $name, 'version' => $version], $versionData);

        $node = new self($name, $version, $packageJson);
        $node->resolved = $versionData['dist']['tarball'] ?? null;
        $node->integrity = $versionData['dist']['integrity'] ?? null;

        return $node;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getVersion(): string
    {
        return $this->version;
    }

    public function getPackageJson(): array
    {
        return $this->packageJson;
    }

    public function isRoot(): bool
    {
        return $this->isRoot;
    }

    public function getRoot(): ?Node
    {
        return $this->root;
    }

    public function setRoot(?Node $root): void
    {
        $this->root = $root;
    }

    public function getParent(): ?Node
    {
        return $this->parent;
    }

    public function setParent(?Node $parent): void
    {
        if ($this->parent === $parent) {
            return;
        }

        // Remove from old parent
        if ($this->parent !== null) {
            unset($this->parent->children[$this->name]);
        }

        $this->parent = $parent;

        // Add to new parent
        if ($parent !== null) {
            $parent->children[$this->name] = $this;
            $this->root = $parent->root ?? $parent;
            $this->updatePaths();
        }
    }

    private function updatePaths(): void
    {
        if ($this->parent === null) {
            return;
        }

        $parentPath = $this->parent->isRoot ? $this->parent->path : $this->parent->realpath;
        $this->realpath = $parentPath . '/node_modules/' . $this->name;
        $this->path = $this->realpath;

        // Location is relative path from root
        if ($this->root !== null) {
            $rootPath = $this->root->path;
            if (str_starts_with($this->realpath, $rootPath)) {
                $this->location = substr($this->realpath, strlen($rootPath) + 1);
            }
        }

        // Update children paths recursively
        foreach ($this->children as $child) {
            $child->updatePaths();
        }
    }

    public function getPath(): string
    {
        return $this->path;
    }

    public function setPath(string $path): void
    {
        $this->path = $path;
        $this->realpath = realpath($path) ?: $path;
    }

    public function getRealpath(): string
    {
        return $this->realpath;
    }

    public function getLocation(): string
    {
        return $this->location;
    }

    public function setLocation(string $location): void
    {
        $this->location = $location;
    }

    /** @return array<string, Node> */
    public function getChildren(): array
    {
        return $this->children;
    }

    public function getChild(string $name): ?Node
    {
        return $this->children[$name] ?? null;
    }

    public function addChild(Node $child): void
    {
        $child->setParent($this);
    }

    public function removeChild(string $name): void
    {
        if (isset($this->children[$name])) {
            $this->children[$name]->parent = null;
            unset($this->children[$name]);
        }
    }

    /** @return array<string, Edge> */
    public function getEdgesOut(): array
    {
        return $this->edgesOut;
    }

    public function getEdgeOut(string $name): ?Edge
    {
        return $this->edgesOut[$name] ?? null;
    }

    /** @return array<string, Edge> */
    public function getEdgesIn(): array
    {
        return $this->edgesIn;
    }

    public function addEdgeIn(Edge $edge): void
    {
        $key = $edge->getFrom()->getName() . ':' . $edge->getType();
        $this->edgesIn[$key] = $edge;
    }

    public function removeEdgeIn(Edge $edge): void
    {
        $key = $edge->getFrom()->getName() . ':' . $edge->getType();
        unset($this->edgesIn[$key]);
    }

    /**
     * Build edges from this node's declared dependencies.
     */
    public function buildEdges(): void
    {
        // Clear existing edges
        $this->edgesOut = [];

        // Production dependencies
        foreach ($this->dependencies as $name => $spec) {
            $this->addEdge($name, $spec, Edge::TYPE_PROD);
        }

        // Dev dependencies (only for root)
        if ($this->isRoot) {
            foreach ($this->devDependencies as $name => $spec) {
                if (!isset($this->edgesOut[$name])) {
                    $this->addEdge($name, $spec, Edge::TYPE_DEV);
                }
            }
        }

        // Optional dependencies
        foreach ($this->optionalDependencies as $name => $spec) {
            if (!isset($this->edgesOut[$name])) {
                $this->addEdge($name, $spec, Edge::TYPE_OPTIONAL);
            }
        }

        // Peer dependencies
        foreach ($this->peerDependencies as $name => $spec) {
            if (!isset($this->edgesOut[$name])) {
                $isOptional = $this->peerDependenciesMeta[$name] ?? false;
                $type = $isOptional ? Edge::TYPE_PEER_OPTIONAL : Edge::TYPE_PEER;
                $this->addEdge($name, $spec, $type);
            }
        }
    }

    private function addEdge(string $name, string $spec, string $type): void
    {
        // Parse npm: alias syntax (e.g., "npm:string-width@^4.2.0")
        $parsed = self::parseAliasSpec($spec);

        $edge = new Edge(
            $this,
            $name,
            $spec,
            $type,
            $parsed['registryName'],
            $parsed['rawSpec']
        );
        $this->edgesOut[$name] = $edge;
        $edge->reload();
    }

    /**
     * Parse an npm alias spec like "npm:package@version".
     *
     * @param string $spec The dependency spec (e.g., "^4.2.0" or "npm:string-width@^4.2.0")
     * @return array{registryName: ?string, rawSpec: string}
     */
    public static function parseAliasSpec(string $spec): array
    {
        // Check for npm: prefix indicating an alias
        if (!str_starts_with(strtolower($spec), 'npm:')) {
            return [
                'registryName' => null,
                'rawSpec' => $spec,
            ];
        }

        // Extract the part after "npm:"
        $aliasSpec = substr($spec, 4);

        // Handle scoped packages: npm:@scope/package@version
        if (str_starts_with($aliasSpec, '@')) {
            // @scope/name@version - need to find the version separator
            $parts = explode('@', $aliasSpec, 3);
            if (count($parts) >= 3) {
                // @scope/name@version
                return [
                    'registryName' => '@' . $parts[1],
                    'rawSpec' => $parts[2],
                ];
            }
            // @scope/name without version
            return [
                'registryName' => '@' . $parts[1],
                'rawSpec' => '*',
            ];
        }

        // Regular package: npm:package@version
        $parts = explode('@', $aliasSpec, 2);
        if (count($parts) === 2) {
            return [
                'registryName' => $parts[0],
                'rawSpec' => $parts[1],
            ];
        }

        // npm:package without version
        return [
            'registryName' => $parts[0],
            'rawSpec' => '*',
        ];
    }

    /**
     * Resolve a package name starting from this node, walking up the tree.
     */
    public function resolve(string $name): ?Node
    {
        // Check own children
        if (isset($this->children[$name])) {
            return $this->children[$name];
        }

        // Walk up to parent
        if ($this->parent !== null) {
            return $this->parent->resolve($name);
        }

        return null;
    }

    /**
     * Check if this node's version satisfies a semver spec.
     */
    public function satisfies(string $spec): bool
    {
        if (self::$semver === null) {
            self::$semver = new ComposerSemverAdapter();
        }

        return self::$semver->satisfies($this->version, $spec);
    }

    public function getResolved(): ?string
    {
        return $this->resolved;
    }

    public function setResolved(?string $resolved): void
    {
        $this->resolved = $resolved;
    }

    public function getIntegrity(): ?string
    {
        return $this->integrity;
    }

    public function setIntegrity(?string $integrity): void
    {
        $this->integrity = $integrity;
    }

    public function isDev(): bool
    {
        return $this->dev;
    }

    public function setDev(bool $dev): void
    {
        $this->dev = $dev;
    }

    public function isOptional(): bool
    {
        return $this->optional;
    }

    public function setOptional(bool $optional): void
    {
        $this->optional = $optional;
    }

    public function isPeer(): bool
    {
        return $this->peer;
    }

    public function setPeer(bool $peer): void
    {
        $this->peer = $peer;
    }

    public function isExtraneous(): bool
    {
        return $this->extraneous;
    }

    public function setExtraneous(bool $extraneous): void
    {
        $this->extraneous = $extraneous;
    }

    public function isLink(): bool
    {
        return $this->isLink;
    }

    public function setLink(bool $isLink): void
    {
        $this->isLink = $isLink;
    }

    public function hasShrinkwrap(): bool
    {
        return $this->hasShrinkwrap;
    }

    public function setHasShrinkwrap(bool $hasShrinkwrap): void
    {
        $this->hasShrinkwrap = $hasShrinkwrap;
    }

    /**
     * Get the actual package name from the registry.
     * For aliases, this differs from getName().
     */
    public function getRegistryName(): ?string
    {
        return $this->registryName;
    }

    /**
     * Set the actual package name from the registry.
     */
    public function setRegistryName(?string $registryName): void
    {
        $this->registryName = $registryName;
    }

    /**
     * Check if this node is installed under an alias.
     */
    public function isAlias(): bool
    {
        return $this->registryName !== null && $this->registryName !== $this->name;
    }

    /**
     * Get the effective package name (registry name if set, otherwise name).
     */
    public function getPackageName(): string
    {
        return $this->registryName ?? $this->name;
    }

    /** @return array<string, string> */
    public function getDependencies(): array
    {
        return $this->dependencies;
    }

    /** @return array<string, string> */
    public function getDevDependencies(): array
    {
        return $this->devDependencies;
    }

    /** @return array<string, string> */
    public function getOptionalDependencies(): array
    {
        return $this->optionalDependencies;
    }

    /** @return array<string, string> */
    public function getPeerDependencies(): array
    {
        return $this->peerDependencies;
    }

    /**
     * Get the depth of this node in the tree (root = 0).
     */
    public function getDepth(): int
    {
        $depth = 0;
        $node = $this;
        while ($node->parent !== null) {
            $depth++;
            $node = $node->parent;
        }
        return $depth;
    }

    /**
     * Check if this node has any invalid or missing edges.
     */
    public function hasProblems(): bool
    {
        foreach ($this->edgesOut as $edge) {
            if ($edge->isMissing() || $edge->isInvalid()) {
                return true;
            }
        }
        return false;
    }

    /**
     * Get all problem edges (missing or invalid).
     * @return Edge[]
     */
    public function getProblemEdges(): array
    {
        $problems = [];
        foreach ($this->edgesOut as $edge) {
            if ($edge->isMissing() || $edge->isInvalid()) {
                $problems[] = $edge;
            }
        }
        return $problems;
    }

    /**
     * Reload all edges after tree modifications.
     */
    public function reloadEdges(): void
    {
        foreach ($this->edgesOut as $edge) {
            $edge->reload();
        }
    }

    /**
     * Get package identifier for lockfile.
     */
    public function getPackageId(): string
    {
        return $this->name . '@' . $this->version;
    }

    /**
     * Convert node to lockfile entry format.
     */
    public function toLockEntry(): array
    {
        $entry = [
            'version' => $this->version,
        ];

        // Include 'name' field if this is an alias (actual package name differs from folder name)
        if ($this->isAlias()) {
            $entry['name'] = $this->registryName;
        }

        if ($this->resolved !== null) {
            $entry['resolved'] = $this->resolved;
        }

        if ($this->integrity !== null) {
            $entry['integrity'] = $this->integrity;
        }

        if ($this->dev) {
            $entry['dev'] = true;
        }

        if ($this->optional) {
            $entry['optional'] = true;
        }

        if ($this->peer) {
            $entry['peer'] = true;
        }

        if (!empty($this->dependencies)) {
            $entry['dependencies'] = $this->dependencies;
        }

        if (!empty($this->optionalDependencies)) {
            $entry['optionalDependencies'] = $this->optionalDependencies;
        }

        if (!empty($this->peerDependencies)) {
            $entry['peerDependencies'] = $this->peerDependencies;
        }

        return $entry;
    }
}
