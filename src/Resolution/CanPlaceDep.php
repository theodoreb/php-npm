<?php

declare(strict_types=1);

namespace PhpNpm\Resolution;

use PhpNpm\Dependency\Node;
use PhpNpm\Dependency\Edge;
use PhpNpm\Semver\ComposerSemverAdapter;

/**
 * Determines if a dependency can be placed at a given location in the tree.
 * Returns a placement decision: OK, KEEP, REPLACE, or CONFLICT.
 */
class CanPlaceDep
{
    public const OK = 'OK';           // Can place the dependency here
    public const KEEP = 'KEEP';       // Existing version satisfies, keep it
    public const REPLACE = 'REPLACE'; // Replace existing with new version
    public const CONFLICT = 'CONFLICT'; // Cannot place here, try parent

    private ComposerSemverAdapter $semver;

    public function __construct()
    {
        $this->semver = new ComposerSemverAdapter();
    }

    /**
     * Check if a node can be placed at the target location.
     *
     * @param Node $target The node where we want to place
     * @param Node $dep The dependency node to place
     * @param Edge $edge The edge that requires this dependency
     * @return PlacementResult The placement decision
     */
    public function canPlaceDep(Node $target, Node $dep, Edge $edge): PlacementResult
    {
        $name = $dep->getName();
        $existing = $target->getChild($name);

        // No existing node at this location
        if ($existing === null) {
            return $this->checkNoConflict($target, $dep, $edge);
        }

        // Same version already exists
        if ($existing->getVersion() === $dep->getVersion()) {
            return new PlacementResult(self::KEEP, $existing);
        }

        // Check if existing satisfies the edge
        if ($edge->satisfiedBy($existing)) {
            // Existing version works, but check if new version is better
            return $this->checkReplaceBetter($existing, $dep, $edge);
        }

        // Existing doesn't satisfy, but can we replace it?
        return $this->checkCanReplace($target, $existing, $dep, $edge);
    }

    /**
     * Check that placing here doesn't conflict with anything.
     *
     * Note: We only check descendants for conflicts, not ancestors.
     * Ancestors will resolve to their own copies first (shadowing).
     */
    private function checkNoConflict(Node $target, Node $dep, Edge $edge): PlacementResult
    {
        $name = $dep->getName();

        // Check if target itself has an edge for this package
        $targetEdge = $target->getEdgeOut($name);
        if ($targetEdge !== null) {
            if (!$this->semver->satisfies($dep->getVersion(), $targetEdge->getRawSpec())) {
                // New version doesn't satisfy target's own edge
                return new PlacementResult(self::CONFLICT, null, $targetEdge);
            }
        }

        // Check children of target for conflicts (descendants who would resolve to this)
        $conflicts = $this->checkChildConflicts($target, $dep);
        if ($conflicts !== null) {
            return new PlacementResult(self::CONFLICT, null, $conflicts);
        }

        return new PlacementResult(self::OK, null);
    }

    /**
     * Check if descendants would have conflicts with new dep.
     */
    private function checkChildConflicts(Node $target, Node $dep): ?Edge
    {
        $name = $dep->getName();
        $version = $dep->getVersion();

        foreach ($target->getChildren() as $child) {
            // Check child's edges
            foreach ($child->getEdgesOut() as $edge) {
                if ($edge->getName() === $name) {
                    // This child depends on the same package
                    // If child has its own copy, that's fine
                    $childCopy = $child->getChild($name);
                    if ($childCopy !== null) {
                        continue; // Child has its own copy
                    }

                    // Child would resolve to target's version
                    if (!$this->semver->satisfies($version, $edge->getSpec())) {
                        return $edge;
                    }
                }
            }

            // Recurse into grandchildren
            $conflict = $this->checkChildConflicts($child, $dep);
            if ($conflict !== null) {
                return $conflict;
            }
        }

        return null;
    }

    /**
     * Check if we should replace the existing with a better version.
     */
    private function checkReplaceBetter(Node $existing, Node $dep, Edge $edge): PlacementResult
    {
        // Both satisfy the edge, prefer higher version
        if ($this->semver->gt($dep->getVersion(), $existing->getVersion())) {
            // New is newer, but make sure it doesn't break other deps
            if ($this->canReplaceWithoutBreaking($existing, $dep)) {
                return new PlacementResult(self::REPLACE, $existing);
            }
        }

        // Keep existing
        return new PlacementResult(self::KEEP, $existing);
    }

    /**
     * Check if existing can be replaced with dep.
     */
    private function checkCanReplace(Node $target, Node $existing, Node $dep, Edge $edge): PlacementResult
    {
        // Check if all nodes depending on existing would accept dep
        if ($this->canReplaceWithoutBreaking($existing, $dep)) {
            return new PlacementResult(self::REPLACE, $existing);
        }

        // Cannot replace, this is a conflict
        return new PlacementResult(self::CONFLICT, $existing);
    }

    /**
     * Check if replacing existing with dep would break any edges.
     */
    private function canReplaceWithoutBreaking(Node $existing, Node $dep): bool
    {
        foreach ($existing->getEdgesIn() as $edgeIn) {
            if (!$edgeIn->satisfiedBy($dep)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Find the best placement location by walking up the tree.
     *
     * @param Node $start Starting node (where the edge is from)
     * @param Node $dep The dependency to place
     * @param Edge $edge The requiring edge
     * @return PlacementTarget|null Best location or null if no valid location
     */
    public function findPlacement(Node $start, Node $dep, Edge $edge): ?PlacementTarget
    {
        $target = $start;
        $best = null;

        while ($target !== null) {
            $result = $this->canPlaceDep($target, $dep, $edge);

            switch ($result->decision) {
                case self::OK:
                case self::REPLACE:
                    // Found a valid placement, but keep looking for shallower
                    $best = new PlacementTarget($target, $result);
                    break;

                case self::KEEP:
                    // Existing version works, use it
                    return new PlacementTarget($target, $result);

                case self::CONFLICT:
                    // Cannot place here, stop if we already found something
                    if ($best !== null) {
                        return $best;
                    }
                    break;
            }

            // Move to parent
            $target = $target->getParent();
        }

        return $best;
    }

    /**
     * Quick check if a version satisfies all edges at a location.
     */
    public function satisfiesAll(string $version, array $edges): bool
    {
        foreach ($edges as $edge) {
            if (!$this->semver->satisfies($version, $edge->getSpec())) {
                return false;
            }
        }
        return true;
    }
}

/**
 * Result of a placement decision.
 */
class PlacementResult
{
    public function __construct(
        public readonly string $decision,
        public readonly ?Node $existing = null,
        public readonly ?Edge $conflictEdge = null,
    ) {
    }

    public function isOk(): bool
    {
        return $this->decision === CanPlaceDep::OK;
    }

    public function isKeep(): bool
    {
        return $this->decision === CanPlaceDep::KEEP;
    }

    public function isReplace(): bool
    {
        return $this->decision === CanPlaceDep::REPLACE;
    }

    public function isConflict(): bool
    {
        return $this->decision === CanPlaceDep::CONFLICT;
    }
}

/**
 * Target location for placing a dependency.
 */
class PlacementTarget
{
    public function __construct(
        public readonly Node $node,
        public readonly PlacementResult $result,
    ) {
    }
}
