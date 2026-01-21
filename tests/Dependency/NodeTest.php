<?php

declare(strict_types=1);

namespace PhpNpm\Tests\Dependency;

use PHPUnit\Framework\TestCase;
use PhpNpm\Dependency\Node;
use PhpNpm\Dependency\Edge;

/**
 * Tests for the Node class.
 * Ported from npm's node.js tests.
 */
class NodeTest extends TestCase
{
    // ===== Node Creation Tests =====

    public function testBasicNodeCreation(): void
    {
        $node = new Node('test-pkg', '1.2.3', []);

        $this->assertEquals('test-pkg', $node->getName());
        $this->assertEquals('1.2.3', $node->getVersion());
        $this->assertFalse($node->isRoot());
    }

    public function testNodeWithPackageJson(): void
    {
        $pkg = [
            'name' => 'my-pkg',
            'version' => '2.0.0',
            'dependencies' => ['lodash' => '^4.0.0'],
        ];

        $node = new Node('my-pkg', '2.0.0', $pkg);

        $this->assertEquals(['lodash' => '^4.0.0'], $node->getDependencies());
    }

    // ===== Root Node Tests =====

    public function testCreateRoot(): void
    {
        $pkg = [
            'name' => 'root-project',
            'version' => '1.0.0',
            'dependencies' => ['express' => '^4.0.0'],
            'devDependencies' => ['jest' => '^27.0.0'],
        ];

        $root = Node::createRoot('/path/to/project', $pkg);

        $this->assertTrue($root->isRoot());
        $this->assertEquals('/path/to/project', $root->getPath());
        $this->assertSame($root, $root->getRoot());
        $this->assertEquals('', $root->getLocation());
    }

    // ===== Parent/Child Relationship Tests =====

    public function testParentChildRelationship(): void
    {
        $root = Node::createRoot('/project', [
            'name' => 'project',
            'version' => '1.0.0',
        ]);

        $child = new Node('child-pkg', '1.0.0', []);
        $root->addChild($child);

        $this->assertSame($root, $child->getParent());
        $this->assertSame($child, $root->getChild('child-pkg'));
        $this->assertSame($root, $child->getRoot());
    }

    public function testChildLocationPath(): void
    {
        $root = Node::createRoot('/project', [
            'name' => 'project',
            'version' => '1.0.0',
        ]);

        $child = new Node('lodash', '4.17.21', []);
        $root->addChild($child);

        $this->assertEquals('/project/node_modules/lodash', $child->getRealpath());
        $this->assertEquals('node_modules/lodash', $child->getLocation());
    }

    public function testNestedChildPath(): void
    {
        $root = Node::createRoot('/project', [
            'name' => 'project',
            'version' => '1.0.0',
        ]);

        $child1 = new Node('express', '4.18.0', []);
        $root->addChild($child1);

        $child2 = new Node('debug', '4.3.0', []);
        $child1->addChild($child2);

        $this->assertEquals('/project/node_modules/express/node_modules/debug', $child2->getRealpath());
        $this->assertEquals('node_modules/express/node_modules/debug', $child2->getLocation());
    }

    public function testRemoveChild(): void
    {
        $root = Node::createRoot('/project', ['name' => 'project', 'version' => '1.0.0']);

        $child = new Node('to-remove', '1.0.0', []);
        $root->addChild($child);

        $this->assertNotNull($root->getChild('to-remove'));

        $root->removeChild('to-remove');

        $this->assertNull($root->getChild('to-remove'));
        $this->assertNull($child->getParent());
    }

    // ===== Edge Building Tests =====

    public function testBuildEdgesFromDependencies(): void
    {
        $pkg = [
            'name' => 'project',
            'version' => '1.0.0',
            'dependencies' => [
                'lodash' => '^4.0.0',
                'express' => '^4.18.0',
            ],
        ];

        $root = Node::createRoot('/project', $pkg);
        $root->buildEdges();

        $edges = $root->getEdgesOut();

        $this->assertCount(2, $edges);
        $this->assertArrayHasKey('lodash', $edges);
        $this->assertArrayHasKey('express', $edges);

        $this->assertEquals('^4.0.0', $edges['lodash']->getSpec());
        $this->assertEquals(Edge::TYPE_PROD, $edges['lodash']->getType());
    }

    public function testBuildEdgesWithDevDependencies(): void
    {
        $pkg = [
            'name' => 'project',
            'version' => '1.0.0',
            'dependencies' => ['lodash' => '^4.0.0'],
            'devDependencies' => ['jest' => '^27.0.0'],
        ];

        $root = Node::createRoot('/project', $pkg);
        $root->buildEdges();

        $jestEdge = $root->getEdgeOut('jest');

        $this->assertNotNull($jestEdge);
        $this->assertTrue($jestEdge->isDev());
    }

    public function testBuildEdgesWithOptionalDependencies(): void
    {
        $pkg = [
            'name' => 'project',
            'version' => '1.0.0',
            'optionalDependencies' => ['fsevents' => '^2.0.0'],
        ];

        $root = Node::createRoot('/project', $pkg);
        $root->buildEdges();

        $edge = $root->getEdgeOut('fsevents');

        $this->assertNotNull($edge);
        $this->assertTrue($edge->isOptional());
    }

    public function testBuildEdgesWithPeerDependencies(): void
    {
        $pkg = [
            'name' => 'react-component',
            'version' => '1.0.0',
            'peerDependencies' => ['react' => '^17.0.0 || ^18.0.0'],
        ];

        $node = new Node('react-component', '1.0.0', $pkg);
        $node->buildEdges();

        $edge = $node->getEdgeOut('react');

        $this->assertNotNull($edge);
        $this->assertTrue($edge->isPeer());
    }

    public function testBuildEdgesWithOptionalPeerDependencies(): void
    {
        $pkg = [
            'name' => 'component',
            'version' => '1.0.0',
            'peerDependencies' => ['typescript' => '^4.0.0'],
            'peerDependenciesMeta' => [
                'typescript' => ['optional' => true],
            ],
        ];

        $node = new Node('component', '1.0.0', $pkg);
        $node->buildEdges();

        $edge = $node->getEdgeOut('typescript');

        $this->assertNotNull($edge);
        $this->assertTrue($edge->isPeer());
        $this->assertTrue($edge->isOptional());
    }

    // ===== Resolve Tests =====

    public function testResolveFromDirectChild(): void
    {
        $root = Node::createRoot('/project', ['name' => 'project', 'version' => '1.0.0']);

        $lodash = new Node('lodash', '4.17.21', []);
        $root->addChild($lodash);

        $resolved = $root->resolve('lodash');

        $this->assertSame($lodash, $resolved);
    }

    public function testResolveWalksUpTree(): void
    {
        $root = Node::createRoot('/project', ['name' => 'project', 'version' => '1.0.0']);

        $lodash = new Node('lodash', '4.17.21', []);
        $root->addChild($lodash);

        $express = new Node('express', '4.18.0', []);
        $root->addChild($express);

        // Express looks for lodash, finds it in root
        $resolved = $express->resolve('lodash');

        $this->assertSame($lodash, $resolved);
    }

    public function testResolveNestedShadows(): void
    {
        $root = Node::createRoot('/project', ['name' => 'project', 'version' => '1.0.0']);

        $lodashOld = new Node('lodash', '3.10.0', []);
        $root->addChild($lodashOld);

        $express = new Node('express', '4.18.0', []);
        $root->addChild($express);

        $lodashNew = new Node('lodash', '4.17.21', []);
        $express->addChild($lodashNew);

        // Express finds its own lodash
        $resolvedFromExpress = $express->resolve('lodash');
        $this->assertSame($lodashNew, $resolvedFromExpress);

        // Root finds old lodash
        $resolvedFromRoot = $root->resolve('lodash');
        $this->assertSame($lodashOld, $resolvedFromRoot);
    }

    public function testResolveNotFound(): void
    {
        $root = Node::createRoot('/project', ['name' => 'project', 'version' => '1.0.0']);

        $resolved = $root->resolve('nonexistent');

        $this->assertNull($resolved);
    }

    // ===== Version Satisfaction Tests =====

    public function testSatisfiesRange(): void
    {
        $node = new Node('lodash', '4.17.21', []);

        $this->assertTrue($node->satisfies('^4.0.0'));
        $this->assertTrue($node->satisfies('4.x'));
        $this->assertTrue($node->satisfies('>=4.0.0'));
        $this->assertFalse($node->satisfies('^5.0.0'));
        $this->assertFalse($node->satisfies('3.x'));
    }

    // ===== Depth Tests =====

    public function testDepth(): void
    {
        $root = Node::createRoot('/project', ['name' => 'project', 'version' => '1.0.0']);

        $child1 = new Node('a', '1.0.0', []);
        $root->addChild($child1);

        $child2 = new Node('b', '1.0.0', []);
        $child1->addChild($child2);

        $child3 = new Node('c', '1.0.0', []);
        $child2->addChild($child3);

        $this->assertEquals(0, $root->getDepth());
        $this->assertEquals(1, $child1->getDepth());
        $this->assertEquals(2, $child2->getDepth());
        $this->assertEquals(3, $child3->getDepth());
    }

    // ===== Flag Tests =====

    public function testNodeFlags(): void
    {
        $node = new Node('pkg', '1.0.0', []);

        $node->setDev(true);
        $this->assertTrue($node->isDev());

        $node->setOptional(true);
        $this->assertTrue($node->isOptional());

        $node->setPeer(true);
        $this->assertTrue($node->isPeer());

        $node->setExtraneous(true);
        $this->assertTrue($node->isExtraneous());
    }

    // ===== Package ID Tests =====

    public function testPackageId(): void
    {
        $node = new Node('lodash', '4.17.21', []);

        $this->assertEquals('lodash@4.17.21', $node->getPackageId());
    }

    // ===== Problem Edges Tests =====

    public function testHasProblemsWithMissingEdge(): void
    {
        $pkg = [
            'name' => 'project',
            'version' => '1.0.0',
            'dependencies' => ['missing-pkg' => '^1.0.0'],
        ];

        $root = Node::createRoot('/project', $pkg);
        $root->buildEdges();

        $this->assertTrue($root->hasProblems());

        $problems = $root->getProblemEdges();
        $this->assertCount(1, $problems);
        $this->assertEquals('missing-pkg', $problems[0]->getName());
    }

    public function testNoProblemsWhenAllResolved(): void
    {
        $pkg = [
            'name' => 'project',
            'version' => '1.0.0',
            'dependencies' => ['lodash' => '^4.0.0'],
        ];

        $root = Node::createRoot('/project', $pkg);

        $lodash = new Node('lodash', '4.17.21', []);
        $root->addChild($lodash);

        $root->buildEdges();

        // Need to reload edges after child was added
        $root->reloadEdges();

        $this->assertFalse($root->hasProblems());
    }

    // ===== Lock Entry Tests =====

    public function testToLockEntry(): void
    {
        $node = new Node('lodash', '4.17.21', [
            'dependencies' => ['foo' => '^1.0.0'],
        ]);
        $node->setResolved('https://registry.npmjs.org/lodash/-/lodash-4.17.21.tgz');
        $node->setIntegrity('sha512-abc123');
        $node->setDev(true);

        $entry = $node->toLockEntry();

        $this->assertEquals('4.17.21', $entry['version']);
        $this->assertEquals('https://registry.npmjs.org/lodash/-/lodash-4.17.21.tgz', $entry['resolved']);
        $this->assertEquals('sha512-abc123', $entry['integrity']);
        $this->assertTrue($entry['dev']);
        $this->assertEquals(['foo' => '^1.0.0'], $entry['dependencies']);
    }

    // ===== Create From Packument Tests =====

    public function testCreateFromPackument(): void
    {
        $manifest = [
            'dependencies' => ['debug' => '^4.0.0'],
            'dist' => [
                'tarball' => 'https://registry.npmjs.org/express/-/express-4.18.0.tgz',
                'integrity' => 'sha512-xyz',
            ],
        ];

        $node = Node::createFromPackument('express', '4.18.0', $manifest);

        $this->assertEquals('express', $node->getName());
        $this->assertEquals('4.18.0', $node->getVersion());
        $this->assertEquals('https://registry.npmjs.org/express/-/express-4.18.0.tgz', $node->getResolved());
        $this->assertEquals('sha512-xyz', $node->getIntegrity());
        $this->assertEquals(['debug' => '^4.0.0'], $node->getDependencies());
    }

    // ===== Create From Lock Entry Tests =====

    public function testCreateFromLockEntry(): void
    {
        $root = Node::createRoot('/project', ['name' => 'project', 'version' => '1.0.0']);

        $entry = [
            'version' => '4.17.21',
            'resolved' => 'https://registry.npmjs.org/lodash/-/lodash-4.17.21.tgz',
            'integrity' => 'sha512-v2kDEe57',
            'dev' => true,
            'dependencies' => ['foo' => '^1.0.0'],
        ];

        $node = Node::createFromLockEntry('lodash', $entry, $root);

        $this->assertEquals('lodash', $node->getName());
        $this->assertEquals('4.17.21', $node->getVersion());
        $this->assertEquals('https://registry.npmjs.org/lodash/-/lodash-4.17.21.tgz', $node->getResolved());
        $this->assertTrue($node->isDev());
        $this->assertSame($root, $node->getRoot());
    }
}
