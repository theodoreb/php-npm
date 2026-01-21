<?php

declare(strict_types=1);

namespace PhpNpm\Resolution;

use PhpNpm\Dependency\Node;
use PhpNpm\Dependency\Edge;

/**
 * Priority queue for dependency resolution.
 * Orders nodes by depth (shallowest first) then alphabetically.
 */
class DepsQueue
{
    /** @var array<string, QueueEntry> */
    private array $entries = [];

    /** @var array<string, bool> Set of node IDs already processed */
    private array $seen = [];

    /**
     * Add a node to the queue for processing.
     */
    public function push(Node $node, Edge $edge, int $depth): void
    {
        $key = $this->getKey($node, $edge);

        if (isset($this->seen[$key])) {
            return; // Already processed
        }

        $this->entries[$key] = new QueueEntry($node, $edge, $depth);
    }

    /**
     * Get and remove the highest priority entry.
     */
    public function pop(): ?QueueEntry
    {
        if (empty($this->entries)) {
            return null;
        }

        // Sort by priority (depth first, then name)
        uasort($this->entries, function (QueueEntry $a, QueueEntry $b) {
            // Lower depth = higher priority
            if ($a->depth !== $b->depth) {
                return $a->depth <=> $b->depth;
            }

            // Alphabetical order for same depth
            return $a->edge->getName() <=> $b->edge->getName();
        });

        // Get first entry
        $key = array_key_first($this->entries);
        $entry = $this->entries[$key];

        unset($this->entries[$key]);
        $this->seen[$key] = true;

        return $entry;
    }

    /**
     * Check if the queue is empty.
     */
    public function isEmpty(): bool
    {
        return empty($this->entries);
    }

    /**
     * Get the number of entries in the queue.
     */
    public function count(): int
    {
        return count($this->entries);
    }

    /**
     * Clear the queue.
     */
    public function clear(): void
    {
        $this->entries = [];
        $this->seen = [];
    }

    /**
     * Check if a node/edge combination has been seen.
     */
    public function hasSeen(Node $node, Edge $edge): bool
    {
        return isset($this->seen[$this->getKey($node, $edge)]);
    }

    /**
     * Mark a node/edge combination as seen without adding to queue.
     */
    public function markSeen(Node $node, Edge $edge): void
    {
        $this->seen[$this->getKey($node, $edge)] = true;
    }

    /**
     * Get unique key for node/edge combination.
     */
    private function getKey(Node $node, Edge $edge): string
    {
        return $node->getLocation() . ':' . $edge->getName() . ':' . $edge->getSpec();
    }

    /**
     * Get all pending entries (for debugging).
     * @return QueueEntry[]
     */
    public function getPending(): array
    {
        return array_values($this->entries);
    }
}

/**
 * Entry in the dependency queue.
 */
class QueueEntry
{
    public function __construct(
        public readonly Node $node,
        public readonly Edge $edge,
        public readonly int $depth,
    ) {
    }
}
