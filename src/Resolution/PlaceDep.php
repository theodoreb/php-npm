<?php

declare(strict_types=1);

namespace PhpNpm\Resolution;

use PhpNpm\Dependency\Node;
use PhpNpm\Dependency\Edge;

/**
 * Executes dependency placement operations.
 */
class PlaceDep
{
    private CanPlaceDep $canPlace;

    public function __construct()
    {
        $this->canPlace = new CanPlaceDep();
    }

    /**
     * Place a dependency in the tree.
     *
     * @param Node $target Where to place
     * @param Node $dep The dependency to place
     * @param PlacementResult $result The placement decision
     * @return Node The placed node
     */
    public function place(Node $target, Node $dep, PlacementResult $result): Node
    {
        $name = $dep->getName();

        switch ($result->decision) {
            case CanPlaceDep::OK:
                // Simply add as child
                $target->addChild($dep);
                $dep->buildEdges();
                return $dep;

            case CanPlaceDep::REPLACE:
                // Remove existing, add new
                $existing = $result->existing;
                if ($existing !== null) {
                    $this->replaceNode($target, $existing, $dep);
                } else {
                    $target->addChild($dep);
                }
                $dep->buildEdges();
                return $dep;

            case CanPlaceDep::KEEP:
                // Use existing node
                return $result->existing;

            default:
                throw new \RuntimeException(
                    "Cannot place {$name}: " . $result->decision
                );
        }
    }

    /**
     * Replace an existing node with a new one.
     */
    private function replaceNode(Node $target, Node $existing, Node $replacement): void
    {
        // Transfer any children that are still valid
        foreach ($existing->getChildren() as $child) {
            // Check if the child should stay with the new node
            // For now, remove all children - they'll be re-resolved
            $existing->removeChild($child->getName());
        }

        // Remove the existing node
        $target->removeChild($existing->getName());

        // Add the replacement
        $target->addChild($replacement);

        // Update edges pointing to existing
        foreach ($existing->getEdgesIn() as $edgeIn) {
            $edgeIn->reload();
        }
    }

    /**
     * Place a dependency at the best location in the tree.
     *
     * @param Node $start Starting node (where the edge originates)
     * @param Node $dep The dependency to place
     * @param Edge $edge The requiring edge
     * @return Node|null The placed node, or null if placement failed
     */
    public function placeAtBest(Node $start, Node $dep, Edge $edge): ?Node
    {
        $placement = $this->canPlace->findPlacement($start, $dep, $edge);

        if ($placement === null) {
            return null;
        }

        return $this->place($placement->node, $dep, $placement->result);
    }

    /**
     * Remove a node from its parent.
     */
    public function remove(Node $node): void
    {
        $parent = $node->getParent();
        if ($parent !== null) {
            $parent->removeChild($node->getName());
        }
    }

    /**
     * Move a node to a new parent.
     */
    public function move(Node $node, Node $newParent): void
    {
        $node->setParent($newParent);
        $node->reloadEdges();
    }
}
