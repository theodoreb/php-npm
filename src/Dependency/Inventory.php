<?php

declare(strict_types=1);

namespace PhpNpm\Dependency;

use ArrayIterator;
use Countable;
use IteratorAggregate;
use Traversable;

/**
 * Collection of package nodes with multiple indexes for fast lookup.
 */
class Inventory implements Countable, IteratorAggregate
{
    /** @var array<string, Node> All nodes by location (primary index) */
    private array $byLocation = [];

    /** @var array<string, array<string, Node>> Nodes by name (secondary index) */
    private array $byName = [];

    /** @var array<string, Node> Nodes by name@version (secondary index) */
    private array $bySpec = [];

    public function add(Node $node): void
    {
        $location = $node->getLocation() ?: $node->getRealpath();

        // Use object ID as fallback when no location is set (for tests/virtual nodes)
        if ($location === '') {
            $location = '__node_' . spl_object_id($node);
        }

        // Add to primary index
        $this->byLocation[$location] = $node;

        // Add to name index
        $name = $node->getName();
        if (!isset($this->byName[$name])) {
            $this->byName[$name] = [];
        }
        $this->byName[$name][$location] = $node;

        // Add to spec index
        $spec = $node->getPackageId();
        $this->bySpec[$spec] = $node;
    }

    public function delete(Node $node): void
    {
        $location = $node->getLocation() ?: $node->getRealpath();

        // Use object ID as fallback when no location is set
        if ($location === '') {
            $location = '__node_' . spl_object_id($node);
        }

        // Remove from primary index
        unset($this->byLocation[$location]);

        // Remove from name index
        $name = $node->getName();
        if (isset($this->byName[$name][$location])) {
            unset($this->byName[$name][$location]);
            if (empty($this->byName[$name])) {
                unset($this->byName[$name]);
            }
        }

        // Remove from spec index
        $spec = $node->getPackageId();
        if (isset($this->bySpec[$spec]) && $this->bySpec[$spec] === $node) {
            unset($this->bySpec[$spec]);
        }
    }

    public function has(Node $node): bool
    {
        $location = $node->getLocation() ?: $node->getRealpath();

        // Use object ID as fallback when no location is set
        if ($location === '') {
            $location = '__node_' . spl_object_id($node);
        }

        return isset($this->byLocation[$location]);
    }

    public function getByLocation(string $location): ?Node
    {
        return $this->byLocation[$location] ?? null;
    }

    /**
     * Get all nodes with a given package name.
     * @return Node[]
     */
    public function getByName(string $name): array
    {
        return array_values($this->byName[$name] ?? []);
    }

    /**
     * Get a node by name@version specification.
     */
    public function getBySpec(string $spec): ?Node
    {
        return $this->bySpec[$spec] ?? null;
    }

    /**
     * Query nodes by name that satisfy a version spec.
     * @return Node[]
     */
    public function query(string $name, ?string $spec = null): array
    {
        $nodes = $this->getByName($name);

        if ($spec === null || $spec === '*' || $spec === '') {
            return $nodes;
        }

        return array_values(array_filter($nodes, fn(Node $node) => $node->satisfies($spec)));
    }

    /**
     * Get all unique package names in the inventory.
     * @return string[]
     */
    public function getNames(): array
    {
        return array_keys($this->byName);
    }

    public function count(): int
    {
        return count($this->byLocation);
    }

    public function getIterator(): Traversable
    {
        return new ArrayIterator(array_values($this->byLocation));
    }

    /**
     * Get all nodes as array.
     * @return Node[]
     */
    public function toArray(): array
    {
        return array_values($this->byLocation);
    }

    /**
     * Filter inventory by a callback.
     * @param callable(Node): bool $callback
     * @return Node[]
     */
    public function filter(callable $callback): array
    {
        return array_filter($this->byLocation, $callback);
    }

    /**
     * Clear all nodes from inventory.
     */
    public function clear(): void
    {
        $this->byLocation = [];
        $this->byName = [];
        $this->bySpec = [];
    }

    /**
     * Build an inventory from a tree, starting from root.
     */
    public static function fromTree(Node $root): self
    {
        $inventory = new self();
        self::addNodeRecursive($inventory, $root);
        return $inventory;
    }

    private static function addNodeRecursive(self $inventory, Node $node): void
    {
        $inventory->add($node);

        foreach ($node->getChildren() as $child) {
            self::addNodeRecursive($inventory, $child);
        }
    }
}
