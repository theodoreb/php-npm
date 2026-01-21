<?php

declare(strict_types=1);

namespace PhpNpm\Tests\Dependency;

use PHPUnit\Framework\TestCase;
use PhpNpm\Dependency\Edge;
use PhpNpm\Dependency\Node;

/**
 * Tests for the Edge class.
 * Ported from npm's edge.js tests.
 */
class EdgeTest extends TestCase
{
    // ===== Edge Creation Tests =====

    public function testEdgeCreation(): void
    {
        $from = new Node('from-pkg', '1.0.0', []);
        $edge = new Edge($from, 'to-pkg', '^1.0.0', Edge::TYPE_PROD);

        $this->assertSame($from, $edge->getFrom());
        $this->assertEquals('to-pkg', $edge->getName());
        $this->assertEquals('^1.0.0', $edge->getSpec());
        $this->assertEquals(Edge::TYPE_PROD, $edge->getType());
        $this->assertNull($edge->getTo());
    }

    // ===== Edge Type Tests =====

    public function testDevDependencyEdge(): void
    {
        $from = new Node('from-pkg', '1.0.0', []);
        $edge = new Edge($from, 'to-pkg', '^1.0.0', Edge::TYPE_DEV);

        $this->assertTrue($edge->isDev());
        $this->assertFalse($edge->isOptional());
        $this->assertFalse($edge->isPeer());
    }

    public function testOptionalDependencyEdge(): void
    {
        $from = new Node('from-pkg', '1.0.0', []);
        $edge = new Edge($from, 'to-pkg', '^1.0.0', Edge::TYPE_OPTIONAL);

        $this->assertFalse($edge->isDev());
        $this->assertTrue($edge->isOptional());
        $this->assertFalse($edge->isPeer());
    }

    public function testPeerDependencyEdge(): void
    {
        $from = new Node('from-pkg', '1.0.0', []);
        $edge = new Edge($from, 'to-pkg', '^1.0.0', Edge::TYPE_PEER);

        $this->assertFalse($edge->isDev());
        $this->assertFalse($edge->isOptional());
        $this->assertTrue($edge->isPeer());
    }

    public function testPeerOptionalDependencyEdge(): void
    {
        $from = new Node('from-pkg', '1.0.0', []);
        $edge = new Edge($from, 'to-pkg', '^1.0.0', Edge::TYPE_PEER_OPTIONAL);

        $this->assertFalse($edge->isDev());
        $this->assertTrue($edge->isOptional());
        $this->assertTrue($edge->isPeer());
    }

    // ===== Missing Edge Tests =====

    public function testMissingNonOptionalEdge(): void
    {
        $from = new Node('from-pkg', '1.0.0', []);
        $edge = new Edge($from, 'to-pkg', '^1.0.0', Edge::TYPE_PROD);

        // No 'to' node set
        $this->assertTrue($edge->isMissing());
    }

    public function testMissingOptionalEdgeIsNotMissing(): void
    {
        $from = new Node('from-pkg', '1.0.0', []);
        $edge = new Edge($from, 'to-pkg', '^1.0.0', Edge::TYPE_OPTIONAL);

        // Optional deps are not considered "missing" when unresolved
        $this->assertFalse($edge->isMissing());
    }

    // ===== Edge Satisfaction Tests =====

    public function testSatisfiedByMatchingVersion(): void
    {
        $from = new Node('from-pkg', '1.0.0', []);
        $edge = new Edge($from, 'to-pkg', '^1.0.0', Edge::TYPE_PROD);

        $to = new Node('to-pkg', '1.2.3', []);

        $this->assertTrue($edge->satisfiedBy($to));
    }

    public function testNotSatisfiedByNonMatchingVersion(): void
    {
        $from = new Node('from-pkg', '1.0.0', []);
        $edge = new Edge($from, 'to-pkg', '^1.0.0', Edge::TYPE_PROD);

        $to = new Node('to-pkg', '2.0.0', []);

        $this->assertFalse($edge->satisfiedBy($to));
    }

    public function testNotSatisfiedByWrongPackageName(): void
    {
        $from = new Node('from-pkg', '1.0.0', []);
        $edge = new Edge($from, 'to-pkg', '^1.0.0', Edge::TYPE_PROD);

        $to = new Node('other-pkg', '1.0.0', []);

        $this->assertFalse($edge->satisfiedBy($to));
    }

    // ===== Edge To/Validity Tests =====

    public function testSetToUpdatesValidity(): void
    {
        $from = new Node('from-pkg', '1.0.0', []);
        $edge = new Edge($from, 'to-pkg', '^1.0.0', Edge::TYPE_PROD);

        $to = new Node('to-pkg', '1.2.3', []);
        $edge->setTo($to);
        $edge->setValid(true);

        $this->assertSame($to, $edge->getTo());
        $this->assertTrue($edge->isValid());
        $this->assertFalse($edge->isMissing());
    }

    public function testInvalidEdge(): void
    {
        $from = new Node('from-pkg', '1.0.0', []);
        $edge = new Edge($from, 'to-pkg', '^1.0.0', Edge::TYPE_PROD);

        $to = new Node('to-pkg', '2.0.0', []);
        $edge->setTo($to);
        $edge->setValid(false);

        $this->assertTrue($edge->isInvalid());
    }

    // ===== Edge Error Tests =====

    public function testEdgeError(): void
    {
        $from = new Node('from-pkg', '1.0.0', []);
        $edge = new Edge($from, 'to-pkg', '^1.0.0', Edge::TYPE_PROD);

        $edge->setError('MISSING');

        $this->assertEquals('MISSING', $edge->getError());
    }
}
