<?php

declare(strict_types=1);

namespace PhpNpm\Tests\Resolution;

use PHPUnit\Framework\TestCase;
use PhpNpm\Resolution\CanPlaceDep;
use PhpNpm\Dependency\Node;
use PhpNpm\Dependency\Edge;

/**
 * Tests for the CanPlaceDep class.
 * Ported from npm's can-place-dep.js tests.
 */
class CanPlaceDepTest extends TestCase
{
    private CanPlaceDep $canPlace;

    protected function setUp(): void
    {
        $this->canPlace = new CanPlaceDep();
    }

    /**
     * Helper to create a tree and test placement.
     */
    private function createTree(array $rootPkg, array $children = []): Node
    {
        $root = Node::createRoot('/project', $rootPkg);

        foreach ($children as $childPkg) {
            $child = new Node(
                $childPkg['name'],
                $childPkg['version'],
                $childPkg
            );
            $root->addChild($child);

            // Handle nested children
            if (isset($childPkg['children'])) {
                foreach ($childPkg['children'] as $nestedPkg) {
                    $nested = new Node(
                        $nestedPkg['name'],
                        $nestedPkg['version'],
                        $nestedPkg
                    );
                    $child->addChild($nested);
                }
            }
        }

        $root->buildEdges();
        foreach ($root->getChildren() as $child) {
            $child->buildEdges();
        }

        return $root;
    }

    // ===== Basic Placement Tests =====

    /**
     * @test Basic placement of a dep, no conflicts or issues
     */
    public function testBasicPlacementNoConflicts(): void
    {
        $root = $this->createTree([
            'name' => 'project',
            'version' => '1.2.3',
            'dependencies' => ['a' => '1.x'],
        ]);

        $dep = new Node('a', '1.2.3', ['name' => 'a', 'version' => '1.2.3']);
        $edge = $root->getEdgeOut('a');

        $result = $this->canPlace->canPlaceDep($root, $dep, $edge);

        $this->assertTrue($result->isOk());
        $this->assertEquals(CanPlaceDep::OK, $result->decision);
    }

    /**
     * @test Replace an existing dep with newer version
     */
    public function testReplaceExistingDep(): void
    {
        $root = $this->createTree(
            [
                'name' => 'project',
                'version' => '1.2.3',
                'dependencies' => ['a' => '1.x'],
            ],
            [
                ['name' => 'a', 'version' => '1.0.0'],
            ]
        );

        $dep = new Node('a', '1.2.3', ['name' => 'a', 'version' => '1.2.3']);
        $edge = $root->getEdgeOut('a');

        $result = $this->canPlace->canPlaceDep($root, $dep, $edge);

        $this->assertTrue($result->isReplace());
        $this->assertEquals(CanPlaceDep::REPLACE, $result->decision);
        $this->assertNotNull($result->existing);
        $this->assertEquals('1.0.0', $result->existing->getVersion());
    }

    /**
     * @test Keep an existing dep that matches exactly
     */
    public function testKeepExistingDepThatMatches(): void
    {
        $root = $this->createTree(
            [
                'name' => 'project',
                'version' => '1.2.3',
                'dependencies' => ['a' => '1'],
            ],
            [
                ['name' => 'a', 'version' => '1.2.3'],
            ]
        );

        $dep = new Node('a', '1.2.3', ['name' => 'a', 'version' => '1.2.3']);
        $edge = $root->getEdgeOut('a');

        $result = $this->canPlace->canPlaceDep($root, $dep, $edge);

        $this->assertTrue($result->isKeep());
        $this->assertEquals(CanPlaceDep::KEEP, $result->decision);
    }

    /**
     * @test Conflict in root for nested dep
     */
    public function testConflictInRootForNestedDep(): void
    {
        $root = $this->createTree(
            [
                'name' => 'project',
                'version' => '1.2.3',
                'dependencies' => ['a' => '1.x', 'b' => '1.x'],
            ],
            [
                ['name' => 'a', 'version' => '1.0.0'],
                [
                    'name' => 'b',
                    'version' => '1.0.0',
                    'dependencies' => ['a' => '2'],
                ],
            ]
        );

        $b = $root->getChild('b');
        $b->buildEdges();

        $dep = new Node('a', '2.3.4', ['name' => 'a', 'version' => '2.3.4']);
        $edge = $b->getEdgeOut('a');

        // Trying to place a@2.3.4 at root level should conflict
        // because root already depends on a@1.x
        $result = $this->canPlace->canPlaceDep($root, $dep, $edge);

        $this->assertTrue($result->isConflict());
        $this->assertEquals(CanPlaceDep::CONFLICT, $result->decision);
    }

    /**
     * @test Place nested dependency at child level
     */
    public function testPlaceNested(): void
    {
        $root = $this->createTree(
            [
                'name' => 'project',
                'version' => '1.2.3',
                'dependencies' => ['a' => '1.x', 'b' => '1.x'],
            ],
            [
                ['name' => 'a', 'version' => '1.0.0'],
                [
                    'name' => 'b',
                    'version' => '1.0.0',
                    'dependencies' => ['a' => '2.x'],
                ],
            ]
        );

        $b = $root->getChild('b');
        $b->buildEdges();

        $dep = new Node('a', '2.3.4', ['name' => 'a', 'version' => '2.3.4']);
        $edge = $b->getEdgeOut('a');

        // Placing at b's level should be OK
        $result = $this->canPlace->canPlaceDep($b, $dep, $edge);

        $this->assertTrue($result->isOk());
    }

    // ===== Find Placement Tests =====

    /**
     * @test Find best placement location
     */
    public function testFindPlacementBasic(): void
    {
        $root = $this->createTree([
            'name' => 'project',
            'version' => '1.0.0',
            'dependencies' => ['a' => '^1.0.0'],
        ]);

        $dep = new Node('a', '1.2.3', ['name' => 'a', 'version' => '1.2.3']);
        $edge = $root->getEdgeOut('a');

        $placement = $this->canPlace->findPlacement($root, $dep, $edge);

        $this->assertNotNull($placement);
        $this->assertSame($root, $placement->node);
        $this->assertTrue($placement->result->isOk());
    }

    /**
     * @test Find placement walks up tree to find valid location
     */
    public function testFindPlacementWalksUpTree(): void
    {
        $root = $this->createTree(
            [
                'name' => 'project',
                'version' => '1.0.0',
                'dependencies' => ['b' => '1.x'],
            ],
            [
                [
                    'name' => 'b',
                    'version' => '1.0.0',
                    'dependencies' => ['c' => '^1.0.0'],
                ],
            ]
        );

        $b = $root->getChild('b');
        $b->buildEdges();

        $dep = new Node('c', '1.2.3', ['name' => 'c', 'version' => '1.2.3']);
        $edge = $b->getEdgeOut('c');

        // Starting from b, should find placement at root (hoisting)
        $placement = $this->canPlace->findPlacement($b, $dep, $edge);

        $this->assertNotNull($placement);
        // Should hoist to root since no conflicts
        $this->assertSame($root, $placement->node);
    }

    /**
     * @test Keep existing when looking for placement
     */
    public function testFindPlacementKeepsExisting(): void
    {
        $root = $this->createTree(
            [
                'name' => 'project',
                'version' => '1.0.0',
                'dependencies' => ['a' => '^1.0.0', 'b' => '1.x'],
            ],
            [
                ['name' => 'a', 'version' => '1.5.0'],
                [
                    'name' => 'b',
                    'version' => '1.0.0',
                    'dependencies' => ['a' => '^1.0.0'],
                ],
            ]
        );

        $b = $root->getChild('b');
        $b->buildEdges();

        $dep = new Node('a', '1.2.0', ['name' => 'a', 'version' => '1.2.0']);
        $edge = $b->getEdgeOut('a');

        $placement = $this->canPlace->findPlacement($b, $dep, $edge);

        $this->assertNotNull($placement);
        // Should KEEP the existing a@1.5.0 since it satisfies ^1.0.0
        $this->assertTrue($placement->result->isKeep());
    }

    // ===== Satisfies All Tests =====

    /**
     * @test SatisfiesAll with multiple edges
     */
    public function testSatisfiesAll(): void
    {
        $root = Node::createRoot('/project', [
            'name' => 'project',
            'version' => '1.0.0',
            'dependencies' => ['a' => '^1.0.0'],
        ]);
        $root->buildEdges();

        $child = new Node('b', '1.0.0', [
            'name' => 'b',
            'version' => '1.0.0',
            'dependencies' => ['a' => '^1.2.0'],
        ]);
        $root->addChild($child);
        $child->buildEdges();

        $edges = [
            $root->getEdgeOut('a'),
            $child->getEdgeOut('a'),
        ];

        // 1.5.0 satisfies both ^1.0.0 and ^1.2.0
        $this->assertTrue($this->canPlace->satisfiesAll('1.5.0', $edges));

        // 1.1.0 satisfies ^1.0.0 but not ^1.2.0
        $this->assertFalse($this->canPlace->satisfiesAll('1.1.0', $edges));
    }

    // ===== PlacementResult Tests =====

    public function testPlacementResultMethods(): void
    {
        $okResult = new \PhpNpm\Resolution\PlacementResult(CanPlaceDep::OK);
        $this->assertTrue($okResult->isOk());
        $this->assertFalse($okResult->isKeep());
        $this->assertFalse($okResult->isReplace());
        $this->assertFalse($okResult->isConflict());

        $keepResult = new \PhpNpm\Resolution\PlacementResult(CanPlaceDep::KEEP);
        $this->assertTrue($keepResult->isKeep());

        $replaceResult = new \PhpNpm\Resolution\PlacementResult(CanPlaceDep::REPLACE);
        $this->assertTrue($replaceResult->isReplace());

        $conflictResult = new \PhpNpm\Resolution\PlacementResult(CanPlaceDep::CONFLICT);
        $this->assertTrue($conflictResult->isConflict());
    }
}
