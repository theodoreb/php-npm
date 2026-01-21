<?php

declare(strict_types=1);

namespace PhpNpm\Arborist;

use PhpNpm\Dependency\Node;
use PhpNpm\Dependency\Edge;
use PhpNpm\Dependency\Inventory;
use PhpNpm\Resolution\DepsQueue;
use PhpNpm\Resolution\QueueEntry;
use PhpNpm\Resolution\CanPlaceDep;
use PhpNpm\Resolution\PlaceDep;
use PhpNpm\Registry\Pacote;
use PhpNpm\Semver\ComposerSemverAdapter;
use PhpNpm\Exception\ResolveException;

/**
 * Builds the ideal dependency tree by resolving and placing all dependencies.
 * This is the main resolution algorithm.
 */
class IdealTreeBuilder
{
    private Pacote $pacote;
    private CanPlaceDep $canPlace;
    private PlaceDep $placer;
    private DepsQueue $queue;
    private ComposerSemverAdapter $semver;
    private Inventory $inventory;

    /** @var array<string, array> Resolved packuments cache */
    private array $packuments = [];

    /** @var callable|null Progress callback */
    private $onProgress;

    private int $resolvedCount = 0;
    private int $totalToResolve = 0;

    public function __construct(?Pacote $pacote = null)
    {
        $this->pacote = $pacote ?? new Pacote();
        $this->canPlace = new CanPlaceDep();
        $this->placer = new PlaceDep();
        $this->queue = new DepsQueue();
        $this->semver = new ComposerSemverAdapter();
    }

    /**
     * Set progress callback.
     * @param callable(string, int, int): void $callback
     */
    public function onProgress(callable $callback): void
    {
        $this->onProgress = $callback;
    }

    /**
     * Build the ideal tree from a root node.
     *
     * @param Node $root The root node (from package.json)
     * @param array $options Build options
     * @return Node The built tree
     * @throws ResolveException
     */
    public function buildIdealTree(Node $root, array $options = []): Node
    {
        $this->inventory = new Inventory();
        $this->inventory->add($root);
        $this->resolvedCount = 0;

        // Build initial edges from root
        $root->buildEdges();

        // Queue all problem edges from root
        $this->queueNodeDeps($root);

        // Count total deps to resolve
        $this->totalToResolve = $this->queue->count();

        // Process the queue
        while (!$this->queue->isEmpty()) {
            $entry = $this->queue->pop();
            $this->processQueueEntry($entry);
        }

        // Fix dependency flags (dev, optional, peer, extraneous)
        $this->fixDependencyFlags($root);

        return $root;
    }

    /**
     * Process a single queue entry.
     */
    private function processQueueEntry(QueueEntry $entry): void
    {
        $node = $entry->node;
        $edge = $entry->edge;
        $name = $edge->getName();           // Alias name (folder name in node_modules)
        $registryName = $edge->getRegistryName();  // Actual package name for registry lookup
        $rawSpec = $edge->getRawSpec();     // Version spec without npm:package@ prefix

        $this->progress("Resolving {$name}@{$edge->getSpec()}");

        try {
            // Fetch manifest from registry using the actual package name
            $resolved = $this->resolvePackage($registryName, $rawSpec);

            if ($resolved === null) {
                if ($edge->isOptional()) {
                    return; // Optional deps can be missing
                }
                throw new ResolveException(
                    "Could not resolve {$name}@{$edge->getSpec()}"
                );
            }

            // Create node from manifest, using alias name for the folder
            // but tracking the registry name for the package metadata
            $depNode = Node::createFromPackument(
                $name,  // Use alias name for node_modules folder
                $resolved['version'],
                $resolved['manifest']
            );

            // Track the actual package name if this is an alias
            if ($edge->isAlias()) {
                $depNode->setRegistryName($registryName);
            }

            // Find best placement
            $placement = $this->canPlace->findPlacement($node, $depNode, $edge);

            if ($placement === null) {
                if ($edge->isOptional()) {
                    return;
                }
                throw new ResolveException(
                    "Could not place {$name}@{$spec} - no valid location found"
                );
            }

            // Execute placement
            $placed = $this->placer->place(
                $placement->node,
                $depNode,
                $placement->result
            );

            // Add to inventory if new
            if (!$this->inventory->has($placed)) {
                $this->inventory->add($placed);
            }

            // Queue dependencies of placed node
            if (!$placement->result->isKeep()) {
                $this->queueNodeDeps($placed);
            }

            // Reload the edge to point to placed node
            $edge->reload();

            $this->resolvedCount++;

        } catch (\Exception $e) {
            if ($edge->isOptional()) {
                // Optional deps can fail silently
                return;
            }
            throw new ResolveException(
                "Failed to resolve {$name}@{$spec}: " . $e->getMessage(),
                0,
                $e
            );
        }
    }

    /**
     * Resolve a package from the registry.
     *
     * @return array{name: string, version: string, manifest: array}|null
     */
    private function resolvePackage(string $name, string $spec): ?array
    {
        try {
            // Check if we've already fetched this packument
            if (!isset($this->packuments[$name])) {
                $this->packuments[$name] = $this->pacote->packument($name);
            }

            $packument = $this->packuments[$name];
            $version = $this->pacote->resolveVersion($packument, $spec);

            if ($version === null) {
                return null;
            }

            return [
                'name' => $name,
                'version' => $version,
                'manifest' => $packument['versions'][$version] ?? [],
            ];
        } catch (\Exception $e) {
            return null;
        }
    }

    /**
     * Queue all problem edges from a node.
     */
    private function queueNodeDeps(Node $node): void
    {
        $depth = $node->getDepth();

        foreach ($node->getEdgesOut() as $edge) {
            if ($edge->isMissing() || $edge->isInvalid()) {
                if (!$this->queue->hasSeen($node, $edge)) {
                    $this->queue->push($node, $edge, $depth);
                }
            }
        }
    }

    /**
     * Fix dependency flags after resolution.
     */
    private function fixDependencyFlags(Node $root): void
    {
        // Reset all flags
        foreach ($this->inventory as $node) {
            if (!$node->isRoot()) {
                $node->setDev(false);
                $node->setOptional(false);
                $node->setPeer(false);
                $node->setExtraneous(true); // Start as extraneous
            }
        }

        // Walk from root and mark reachable nodes
        $this->markReachable($root, false, false);

        // Walk dev dependencies
        foreach ($root->getEdgesOut() as $edge) {
            if ($edge->isDev() && $edge->getTo() !== null) {
                $this->markReachable($edge->getTo(), true, false);
            }
        }
    }

    /**
     * Mark a node and its dependencies as reachable.
     */
    private function markReachable(Node $node, bool $dev, bool $optional): void
    {
        if ($node->isRoot()) {
            foreach ($node->getEdgesOut() as $edge) {
                $to = $edge->getTo();
                if ($to !== null && !$edge->isDev()) {
                    $this->markReachable($to, false, $edge->isOptional());
                }
            }
            return;
        }

        // Mark as not extraneous
        $node->setExtraneous(false);

        // Propagate flags
        if ($dev) {
            $node->setDev(true);
        }
        if ($optional) {
            $node->setOptional(true);
        }

        // Process dependencies
        foreach ($node->getEdgesOut() as $edge) {
            $to = $edge->getTo();
            if ($to !== null) {
                $edgeOptional = $optional || $edge->isOptional();
                if ($edge->isPeer()) {
                    $node->setPeer(true);
                }
                // Recurse if not already visited with same/lower flags
                if ($to->isExtraneous() || ($dev && !$to->isDev())) {
                    $this->markReachable($to, $dev, $edgeOptional);
                }
            }
        }
    }

    /**
     * Report progress.
     */
    private function progress(string $message): void
    {
        if ($this->onProgress !== null) {
            ($this->onProgress)($message, $this->resolvedCount, $this->totalToResolve);
        }
    }

    /**
     * Get the inventory of all packages in the tree.
     */
    public function getInventory(): Inventory
    {
        return $this->inventory;
    }

    /**
     * Calculate what changes would be needed to reach ideal from actual.
     *
     * @return array{add: Node[], remove: Node[], update: array}
     */
    public function calculateDiff(Node $actual, Node $ideal): array
    {
        $actualInventory = Inventory::fromTree($actual);
        $idealInventory = Inventory::fromTree($ideal);

        $add = [];
        $remove = [];
        $update = [];

        // Find nodes to add or update
        foreach ($idealInventory as $idealNode) {
            if ($idealNode->isRoot()) {
                continue;
            }

            $location = $idealNode->getLocation();
            $actualNode = $actualInventory->getByLocation($location);

            if ($actualNode === null) {
                $add[] = $idealNode;
            } elseif ($actualNode->getVersion() !== $idealNode->getVersion()) {
                $update[] = [
                    'location' => $location,
                    'from' => $actualNode,
                    'to' => $idealNode,
                ];
            }
        }

        // Find nodes to remove
        foreach ($actualInventory as $actualNode) {
            if ($actualNode->isRoot()) {
                continue;
            }

            $location = $actualNode->getLocation();
            $idealNode = $idealInventory->getByLocation($location);

            if ($idealNode === null) {
                $remove[] = $actualNode;
            }
        }

        return [
            'add' => $add,
            'remove' => $remove,
            'update' => $update,
        ];
    }
}
