<?php

declare(strict_types=1);

namespace PhpNpm\Tests\Dependency;

use PHPUnit\Framework\TestCase;
use PhpNpm\Dependency\Inventory;
use PhpNpm\Dependency\Node;

/**
 * Tests for the Inventory class.
 */
class InventoryTest extends TestCase
{
    // ===== Basic Operations =====

    public function testAddAndRetrieve(): void
    {
        $inventory = new Inventory();
        $node = new Node('lodash', '4.17.21', []);
        $node->setLocation('node_modules/lodash');

        $inventory->add($node);

        $this->assertTrue($inventory->has($node));
        $this->assertSame($node, $inventory->getByLocation('node_modules/lodash'));
    }

    public function testCount(): void
    {
        $inventory = new Inventory();

        $this->assertEquals(0, $inventory->count());

        $inventory->add(new Node('a', '1.0.0', []));
        $this->assertEquals(1, $inventory->count());

        $inventory->add(new Node('b', '1.0.0', []));
        $this->assertEquals(2, $inventory->count());
    }

    public function testDelete(): void
    {
        $inventory = new Inventory();
        $node = new Node('lodash', '4.17.21', []);
        $node->setLocation('node_modules/lodash');

        $inventory->add($node);
        $this->assertTrue($inventory->has($node));

        $inventory->delete($node);
        $this->assertFalse($inventory->has($node));
    }

    // ===== Index Tests =====

    public function testGetByName(): void
    {
        $inventory = new Inventory();

        $lodash1 = new Node('lodash', '4.17.21', []);
        $lodash1->setLocation('node_modules/lodash');

        $lodash2 = new Node('lodash', '3.10.0', []);
        $lodash2->setLocation('node_modules/express/node_modules/lodash');

        $express = new Node('express', '4.18.0', []);
        $express->setLocation('node_modules/express');

        $inventory->add($lodash1);
        $inventory->add($lodash2);
        $inventory->add($express);

        $lodashNodes = $inventory->getByName('lodash');

        $this->assertCount(2, $lodashNodes);
    }

    public function testGetBySpec(): void
    {
        $inventory = new Inventory();

        $node = new Node('lodash', '4.17.21', []);
        $node->setLocation('node_modules/lodash');

        $inventory->add($node);

        $found = $inventory->getBySpec('lodash@4.17.21');

        $this->assertSame($node, $found);
    }

    // ===== Query Tests =====

    public function testQueryByNameOnly(): void
    {
        $inventory = new Inventory();

        $v1 = new Node('lodash', '3.10.0', []);
        $v1->setLocation('node_modules/a/node_modules/lodash');

        $v2 = new Node('lodash', '4.17.21', []);
        $v2->setLocation('node_modules/lodash');

        $inventory->add($v1);
        $inventory->add($v2);

        $results = $inventory->query('lodash');

        $this->assertCount(2, $results);
    }

    public function testQueryWithSpec(): void
    {
        $inventory = new Inventory();

        $v1 = new Node('lodash', '3.10.0', []);
        $v1->setLocation('node_modules/a/node_modules/lodash');

        $v2 = new Node('lodash', '4.17.21', []);
        $v2->setLocation('node_modules/lodash');

        $inventory->add($v1);
        $inventory->add($v2);

        $results = $inventory->query('lodash', '^4.0.0');

        $this->assertCount(1, $results);
        $this->assertEquals('4.17.21', $results[0]->getVersion());
    }

    public function testQueryWithWildcard(): void
    {
        $inventory = new Inventory();

        $v1 = new Node('lodash', '3.10.0', []);
        $v1->setLocation('loc1');
        $v2 = new Node('lodash', '4.17.21', []);
        $v2->setLocation('loc2');

        $inventory->add($v1);
        $inventory->add($v2);

        $results = $inventory->query('lodash', '*');

        $this->assertCount(2, $results);
    }

    // ===== Iterator Tests =====

    public function testIterable(): void
    {
        $inventory = new Inventory();

        $inventory->add(new Node('a', '1.0.0', []));
        $inventory->add(new Node('b', '1.0.0', []));
        $inventory->add(new Node('c', '1.0.0', []));

        $names = [];
        foreach ($inventory as $node) {
            $names[] = $node->getName();
        }

        $this->assertCount(3, $names);
        $this->assertContains('a', $names);
        $this->assertContains('b', $names);
        $this->assertContains('c', $names);
    }

    // ===== Utility Methods =====

    public function testGetNames(): void
    {
        $inventory = new Inventory();

        $inventory->add(new Node('lodash', '4.17.21', []));
        $inventory->add(new Node('express', '4.18.0', []));

        $names = $inventory->getNames();

        $this->assertCount(2, $names);
        $this->assertContains('lodash', $names);
        $this->assertContains('express', $names);
    }

    public function testToArray(): void
    {
        $inventory = new Inventory();

        $a = new Node('a', '1.0.0', []);
        $b = new Node('b', '1.0.0', []);

        $inventory->add($a);
        $inventory->add($b);

        $array = $inventory->toArray();

        $this->assertCount(2, $array);
        $this->assertContains($a, $array);
        $this->assertContains($b, $array);
    }

    public function testFilter(): void
    {
        $inventory = new Inventory();

        $a = new Node('a', '1.0.0', []);
        $a->setDev(true);

        $b = new Node('b', '1.0.0', []);
        $b->setDev(false);

        $c = new Node('c', '1.0.0', []);
        $c->setDev(true);

        $inventory->add($a);
        $inventory->add($b);
        $inventory->add($c);

        $devNodes = $inventory->filter(fn(Node $n) => $n->isDev());

        $this->assertCount(2, $devNodes);
    }

    public function testClear(): void
    {
        $inventory = new Inventory();

        $inventory->add(new Node('a', '1.0.0', []));
        $inventory->add(new Node('b', '1.0.0', []));

        $this->assertEquals(2, $inventory->count());

        $inventory->clear();

        $this->assertEquals(0, $inventory->count());
    }

    // ===== From Tree Tests =====

    public function testFromTree(): void
    {
        $root = Node::createRoot('/project', [
            'name' => 'project',
            'version' => '1.0.0',
        ]);

        $a = new Node('a', '1.0.0', []);
        $root->addChild($a);

        $b = new Node('b', '1.0.0', []);
        $a->addChild($b);

        $c = new Node('c', '1.0.0', []);
        $root->addChild($c);

        $inventory = Inventory::fromTree($root);

        // root + a + b + c = 4 nodes
        $this->assertEquals(4, $inventory->count());
        $this->assertNotEmpty($inventory->getByName('a'));
        $this->assertNotEmpty($inventory->getByName('b'));
        $this->assertNotEmpty($inventory->getByName('c'));
    }
}
