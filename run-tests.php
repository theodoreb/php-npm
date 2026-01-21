<?php
/**
 * Simple test runner that doesn't require PHPUnit.
 * Tests core functionality for compliance with npm behavior.
 */

require_once __DIR__ . '/vendor/autoload.php';

use PhpNpm\Semver\ComposerSemverAdapter;
use PhpNpm\Dependency\Node;
use PhpNpm\Dependency\Edge;
use PhpNpm\Dependency\Inventory;
use PhpNpm\Resolution\CanPlaceDep;
use PhpNpm\Resolution\PlacementResult;
use PhpNpm\Lockfile\LockfileParser;
use PhpNpm\Integrity\IntegrityChecker;

$passed = 0;
$failed = 0;
$errors = [];

function test(string $name, callable $fn): void {
    global $passed, $failed, $errors;

    try {
        $fn();
        echo "✓ {$name}\n";
        $passed++;
    } catch (Throwable $e) {
        echo "✗ {$name}\n";
        echo "  Error: {$e->getMessage()}\n";
        $failed++;
        $errors[] = [$name, $e];
    }
}

function assertEquals($expected, $actual, string $message = ''): void {
    if ($expected !== $actual) {
        throw new Exception(
            $message ?: "Expected " . json_encode($expected) . " but got " . json_encode($actual)
        );
    }
}

function assertTrue($value, string $message = ''): void {
    if ($value !== true) {
        throw new Exception($message ?: "Expected true but got " . json_encode($value));
    }
}

function assertFalse($value, string $message = ''): void {
    if ($value !== false) {
        throw new Exception($message ?: "Expected false but got " . json_encode($value));
    }
}

function assertNull($value, string $message = ''): void {
    if ($value !== null) {
        throw new Exception($message ?: "Expected null but got " . json_encode($value));
    }
}

function assertNotNull($value, string $message = ''): void {
    if ($value === null) {
        throw new Exception($message ?: "Expected non-null value");
    }
}

function assertCount(int $expected, $array, string $message = ''): void {
    $actual = count($array);
    if ($expected !== $actual) {
        throw new Exception($message ?: "Expected count {$expected} but got {$actual}");
    }
}

echo "\n=== Semver Adapter Tests ===\n\n";

$semver = new ComposerSemverAdapter();

test('wildcard is always satisfied', function() use ($semver) {
    assertTrue($semver->satisfies('1.0.0', '*'));
    assertTrue($semver->satisfies('2.5.3', '*'));
});

test('empty range is always satisfied', function() use ($semver) {
    assertTrue($semver->satisfies('1.0.0', ''));
});

test('exact version match', function() use ($semver) {
    assertTrue($semver->satisfies('1.2.3', '1.2.3'));
    assertFalse($semver->satisfies('1.2.4', '1.2.3'));
});

test('x-range major (1.x)', function() use ($semver) {
    assertTrue($semver->satisfies('1.2.3', '1.x'));
    assertTrue($semver->satisfies('1.9.9', '1.x'));
    assertFalse($semver->satisfies('2.0.0', '1.x'));
});

test('x-range minor (1.2.x)', function() use ($semver) {
    assertTrue($semver->satisfies('1.2.0', '1.2.x'));
    assertTrue($semver->satisfies('1.2.9', '1.2.x'));
    assertFalse($semver->satisfies('1.3.0', '1.2.x'));
});

test('caret range (^1.2.3)', function() use ($semver) {
    assertTrue($semver->satisfies('1.2.3', '^1.2.3'));
    assertTrue($semver->satisfies('1.9.9', '^1.2.3'));
    assertFalse($semver->satisfies('2.0.0', '^1.2.3'));
    assertFalse($semver->satisfies('1.2.2', '^1.2.3'));
});

test('tilde range (~1.2.3)', function() use ($semver) {
    assertTrue($semver->satisfies('1.2.3', '~1.2.3'));
    assertTrue($semver->satisfies('1.2.9', '~1.2.3'));
    assertFalse($semver->satisfies('1.3.0', '~1.2.3'));
});

test('comparison operators (>=, <)', function() use ($semver) {
    assertTrue($semver->satisfies('1.2.3', '>=1.2.3'));
    assertTrue($semver->satisfies('2.0.0', '>=1.2.3'));
    assertFalse($semver->satisfies('1.2.2', '>=1.2.3'));
    assertTrue($semver->satisfies('1.2.2', '<1.2.3'));
});

test('OR range (||)', function() use ($semver) {
    assertTrue($semver->satisfies('1.0.0', '1.x || 2.x'));
    assertTrue($semver->satisfies('2.0.0', '1.x || 2.x'));
    assertFalse($semver->satisfies('3.0.0', '1.x || 2.x'));
});

test('maxSatisfying', function() use ($semver) {
    $versions = ['1.0.0', '1.2.3', '1.5.0', '2.0.0'];
    assertEquals('1.5.0', $semver->maxSatisfying($versions, '1.x'));
    assertEquals('2.0.0', $semver->maxSatisfying($versions, '*'));
});

test('version comparison (gt, lt)', function() use ($semver) {
    assertTrue($semver->gt('1.2.4', '1.2.3'));
    assertFalse($semver->gt('1.2.3', '1.2.3'));
    assertTrue($semver->lt('1.2.2', '1.2.3'));
});

echo "\n=== Node Tests ===\n\n";

test('basic node creation', function() {
    $node = new Node('test-pkg', '1.2.3', []);
    assertEquals('test-pkg', $node->getName());
    assertEquals('1.2.3', $node->getVersion());
    assertFalse($node->isRoot());
});

test('root node creation', function() {
    $root = Node::createRoot('/project', [
        'name' => 'my-project',
        'version' => '1.0.0',
    ]);
    assertTrue($root->isRoot());
    assertEquals('/project', $root->getPath());
});

test('parent-child relationship', function() {
    $root = Node::createRoot('/project', ['name' => 'project', 'version' => '1.0.0']);
    $child = new Node('lodash', '4.17.21', []);
    $root->addChild($child);

    assertEquals($root, $child->getParent());
    assertEquals($child, $root->getChild('lodash'));
});

test('resolve walks up tree', function() {
    $root = Node::createRoot('/project', ['name' => 'project', 'version' => '1.0.0']);
    $lodash = new Node('lodash', '4.17.21', []);
    $root->addChild($lodash);
    $express = new Node('express', '4.18.0', []);
    $root->addChild($express);

    $resolved = $express->resolve('lodash');
    assertEquals($lodash, $resolved);
});

test('satisfies range', function() {
    $node = new Node('lodash', '4.17.21', []);
    assertTrue($node->satisfies('^4.0.0'));
    assertTrue($node->satisfies('4.x'));
    assertFalse($node->satisfies('^5.0.0'));
});

test('build edges from dependencies', function() {
    $root = Node::createRoot('/project', [
        'name' => 'project',
        'version' => '1.0.0',
        'dependencies' => ['lodash' => '^4.0.0'],
    ]);
    $root->buildEdges();

    $edge = $root->getEdgeOut('lodash');
    assertNotNull($edge);
    assertEquals('^4.0.0', $edge->getSpec());
});

echo "\n=== Edge Tests ===\n\n";

test('edge creation', function() {
    $from = new Node('from-pkg', '1.0.0', []);
    $edge = new Edge($from, 'to-pkg', '^1.0.0', Edge::TYPE_PROD);

    assertEquals('to-pkg', $edge->getName());
    assertEquals('^1.0.0', $edge->getSpec());
    assertFalse($edge->isDev());
});

test('edge type detection', function() {
    $from = new Node('pkg', '1.0.0', []);

    $devEdge = new Edge($from, 'dep', '*', Edge::TYPE_DEV);
    assertTrue($devEdge->isDev());

    $optEdge = new Edge($from, 'dep', '*', Edge::TYPE_OPTIONAL);
    assertTrue($optEdge->isOptional());

    $peerEdge = new Edge($from, 'dep', '*', Edge::TYPE_PEER);
    assertTrue($peerEdge->isPeer());
});

test('edge satisfied by', function() {
    $from = new Node('from', '1.0.0', []);
    $edge = new Edge($from, 'to', '^1.0.0', Edge::TYPE_PROD);

    $good = new Node('to', '1.5.0', []);
    $bad = new Node('to', '2.0.0', []);

    assertTrue($edge->satisfiedBy($good));
    assertFalse($edge->satisfiedBy($bad));
});

echo "\n=== CanPlaceDep Tests ===\n\n";

$canPlace = new CanPlaceDep();

test('basic placement OK', function() use ($canPlace) {
    $root = Node::createRoot('/project', [
        'name' => 'project',
        'version' => '1.0.0',
        'dependencies' => ['a' => '1.x'],
    ]);
    $root->buildEdges();

    $dep = new Node('a', '1.2.3', []);
    $edge = $root->getEdgeOut('a');

    $result = $canPlace->canPlaceDep($root, $dep, $edge);
    assertEquals(CanPlaceDep::OK, $result->decision);
});

test('keep existing matching dep', function() use ($canPlace) {
    $root = Node::createRoot('/project', [
        'name' => 'project',
        'version' => '1.0.0',
        'dependencies' => ['a' => '1.x'],
    ]);
    $existing = new Node('a', '1.2.3', []);
    $root->addChild($existing);
    $root->buildEdges();

    $dep = new Node('a', '1.2.3', []);
    $edge = $root->getEdgeOut('a');

    $result = $canPlace->canPlaceDep($root, $dep, $edge);
    assertEquals(CanPlaceDep::KEEP, $result->decision);
});

test('replace older version', function() use ($canPlace) {
    $root = Node::createRoot('/project', [
        'name' => 'project',
        'version' => '1.0.0',
        'dependencies' => ['a' => '1.x'],
    ]);
    $existing = new Node('a', '1.0.0', []);
    $root->addChild($existing);
    $root->buildEdges();

    $dep = new Node('a', '1.5.0', []);
    $edge = $root->getEdgeOut('a');

    $result = $canPlace->canPlaceDep($root, $dep, $edge);
    assertEquals(CanPlaceDep::REPLACE, $result->decision);
});

echo "\n=== Lockfile Parser Tests ===\n\n";

$parser = new LockfileParser();

test('detect v1 lockfile', function() use ($parser) {
    $data = ['lockfileVersion' => 1, 'dependencies' => []];
    assertEquals(1, $parser->detectVersion($data));
});

test('detect v2 lockfile', function() use ($parser) {
    $data = ['lockfileVersion' => 2, 'packages' => [], 'dependencies' => []];
    assertEquals(2, $parser->detectVersion($data));
});

test('detect v3 lockfile', function() use ($parser) {
    $data = ['lockfileVersion' => 3, 'packages' => []];
    assertEquals(3, $parser->detectVersion($data));
});

test('parse v1 to normalized', function() use ($parser) {
    $v1 = [
        'name' => 'project',
        'version' => '1.0.0',
        'lockfileVersion' => 1,
        'dependencies' => [
            'lodash' => ['version' => '4.17.21'],
        ],
    ];

    $normalized = $parser->parse($v1);
    assertEquals(3, $normalized['lockfileVersion']);
    assertTrue(isset($normalized['packages']['node_modules/lodash']));
});

test('parse v3 passthrough', function() use ($parser) {
    $v3 = [
        'name' => 'project',
        'version' => '1.0.0',
        'lockfileVersion' => 3,
        'packages' => [
            '' => ['name' => 'project'],
            'node_modules/lodash' => ['version' => '4.17.21'],
        ],
    ];

    $normalized = $parser->parse($v3);
    assertEquals($v3['packages'], $normalized['packages']);
});

test('serialize to v1', function() use ($parser) {
    $normalized = [
        'name' => 'project',
        'version' => '1.0.0',
        'lockfileVersion' => 3,
        'packages' => [
            '' => ['name' => 'project'],
            'node_modules/lodash' => ['version' => '4.17.21'],
        ],
    ];

    $v1 = $parser->serialize($normalized, 1);
    assertEquals(1, $v1['lockfileVersion']);
    assertTrue(isset($v1['dependencies']['lodash']));
});

echo "\n=== Integrity Checker Tests ===\n\n";

$integrity = new IntegrityChecker();

test('calculate sha512', function() use ($integrity) {
    $hash = $integrity->calculate('test content', 'sha512');
    assertTrue(str_starts_with($hash, 'sha512-'));
});

test('verify valid integrity', function() use ($integrity) {
    $content = 'Hello, World!';
    $hash = $integrity->calculate($content);
    assertTrue($integrity->verify($content, $hash));
});

test('verify invalid content fails', function() use ($integrity) {
    $content = 'Hello, World!';
    $hash = $integrity->calculate($content);
    assertFalse($integrity->verify('Different', $hash));
});

test('parse integrity string', function() use ($integrity) {
    $parsed = $integrity->parse('sha512-abc123');
    assertCount(1, $parsed);
    assertEquals('sha512', $parsed[0]['algorithm']);
    assertEquals('abc123', $parsed[0]['hash']);
});

test('parse multiple hashes', function() use ($integrity) {
    $parsed = $integrity->parse('sha512-abc sha256-def');
    assertCount(2, $parsed);
});

test('get strongest algorithm', function() use ($integrity) {
    assertEquals('sha512', $integrity->getStrongestAlgorithm('sha256-a sha512-b'));
    assertEquals('sha384', $integrity->getStrongestAlgorithm('sha256-a sha384-b'));
});

echo "\n=== Inventory Tests ===\n\n";

test('add and retrieve', function() {
    $inventory = new Inventory();
    $node = new Node('lodash', '4.17.21', []);
    $node->setLocation('node_modules/lodash');

    $inventory->add($node);
    assertTrue($inventory->has($node));
    assertEquals($node, $inventory->getByLocation('node_modules/lodash'));
});

test('query by name and spec', function() {
    $inventory = new Inventory();

    $v1 = new Node('lodash', '3.10.0', []);
    $v1->setLocation('loc1');
    $v2 = new Node('lodash', '4.17.21', []);
    $v2->setLocation('loc2');

    $inventory->add($v1);
    $inventory->add($v2);

    $all = $inventory->query('lodash');
    assertCount(2, $all);

    $v4Only = $inventory->query('lodash', '^4.0.0');
    assertCount(1, $v4Only);
});

test('from tree', function() {
    $root = Node::createRoot('/project', ['name' => 'project', 'version' => '1.0.0']);
    $a = new Node('a', '1.0.0', []);
    $root->addChild($a);
    $b = new Node('b', '1.0.0', []);
    $a->addChild($b);

    $inventory = Inventory::fromTree($root);
    assertEquals(3, $inventory->count()); // root + a + b
});

// Summary
echo "\n" . str_repeat('=', 50) . "\n";
echo "Tests: " . ($passed + $failed) . " | ";
echo "Passed: {$passed} | ";
echo "Failed: {$failed}\n";
echo str_repeat('=', 50) . "\n";

if ($failed > 0) {
    echo "\nFailed tests:\n";
    foreach ($errors as [$name, $e]) {
        echo "  - {$name}: {$e->getMessage()}\n";
    }
    exit(1);
}

exit(0);
