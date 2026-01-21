<?php

declare(strict_types=1);

namespace PhpNpm\Tests\Lockfile;

use PHPUnit\Framework\TestCase;
use PhpNpm\Lockfile\LockfileParser;
use PhpNpm\Lockfile\LockfileException;

/**
 * Tests for the LockfileParser class.
 * Ported from npm's shrinkwrap.js tests.
 */
class LockfileParserTest extends TestCase
{
    private LockfileParser $parser;

    protected function setUp(): void
    {
        $this->parser = new LockfileParser();
    }

    // ===== Version Detection Tests =====

    public function testDetectVersionExplicitV1(): void
    {
        $data = [
            'lockfileVersion' => 1,
            'dependencies' => [],
        ];

        $this->assertEquals(1, $this->parser->detectVersion($data));
    }

    public function testDetectVersionExplicitV2(): void
    {
        $data = [
            'lockfileVersion' => 2,
            'packages' => [],
            'dependencies' => [],
        ];

        $this->assertEquals(2, $this->parser->detectVersion($data));
    }

    public function testDetectVersionExplicitV3(): void
    {
        $data = [
            'lockfileVersion' => 3,
            'packages' => [],
        ];

        $this->assertEquals(3, $this->parser->detectVersion($data));
    }

    public function testDetectVersionImplicitV1(): void
    {
        // No lockfileVersion, only dependencies = v1
        $data = [
            'dependencies' => ['lodash' => ['version' => '4.17.21']],
        ];

        $this->assertEquals(1, $this->parser->detectVersion($data));
    }

    public function testDetectVersionImplicitV2(): void
    {
        // Has both packages and dependencies = v2
        $data = [
            'packages' => [],
            'dependencies' => [],
        ];

        $this->assertEquals(2, $this->parser->detectVersion($data));
    }

    public function testDetectVersionImplicitV3(): void
    {
        // Only packages = v3
        $data = [
            'packages' => [],
        ];

        $this->assertEquals(3, $this->parser->detectVersion($data));
    }

    // ===== V1 Parsing Tests =====

    public function testParseV1Basic(): void
    {
        $v1Data = [
            'name' => 'my-project',
            'version' => '1.0.0',
            'lockfileVersion' => 1,
            'dependencies' => [
                'lodash' => [
                    'version' => '4.17.21',
                    'resolved' => 'https://registry.npmjs.org/lodash/-/lodash-4.17.21.tgz',
                    'integrity' => 'sha512-abc123',
                ],
            ],
        ];

        $normalized = $this->parser->parse($v1Data);

        $this->assertEquals(3, $normalized['lockfileVersion']);
        $this->assertEquals('my-project', $normalized['name']);
        $this->assertArrayHasKey('packages', $normalized);
        $this->assertArrayHasKey('node_modules/lodash', $normalized['packages']);

        $lodash = $normalized['packages']['node_modules/lodash'];
        $this->assertEquals('4.17.21', $lodash['version']);
        $this->assertEquals('sha512-abc123', $lodash['integrity']);
    }

    public function testParseV1NestedDependencies(): void
    {
        $v1Data = [
            'name' => 'project',
            'version' => '1.0.0',
            'lockfileVersion' => 1,
            'dependencies' => [
                'a' => [
                    'version' => '1.0.0',
                    'dependencies' => [
                        'b' => [
                            'version' => '2.0.0',
                        ],
                    ],
                ],
            ],
        ];

        $normalized = $this->parser->parse($v1Data);

        $this->assertArrayHasKey('node_modules/a', $normalized['packages']);
        $this->assertArrayHasKey('node_modules/a/node_modules/b', $normalized['packages']);

        $this->assertEquals('1.0.0', $normalized['packages']['node_modules/a']['version']);
        $this->assertEquals('2.0.0', $normalized['packages']['node_modules/a/node_modules/b']['version']);
    }

    public function testParseV1WithRequires(): void
    {
        $v1Data = [
            'name' => 'project',
            'version' => '1.0.0',
            'lockfileVersion' => 1,
            'dependencies' => [
                'express' => [
                    'version' => '4.18.0',
                    'requires' => [
                        'debug' => '^4.0.0',
                    ],
                ],
                'debug' => [
                    'version' => '4.3.0',
                ],
            ],
        ];

        $normalized = $this->parser->parse($v1Data);

        $express = $normalized['packages']['node_modules/express'];
        $this->assertEquals(['debug' => '^4.0.0'], $express['dependencies']);
    }

    // ===== V2 Parsing Tests =====

    public function testParseV2(): void
    {
        $v2Data = [
            'name' => 'project',
            'version' => '1.0.0',
            'lockfileVersion' => 2,
            'packages' => [
                '' => [
                    'name' => 'project',
                    'version' => '1.0.0',
                    'dependencies' => ['lodash' => '^4.0.0'],
                ],
                'node_modules/lodash' => [
                    'version' => '4.17.21',
                    'resolved' => 'https://registry.npmjs.org/lodash/-/lodash-4.17.21.tgz',
                    'integrity' => 'sha512-xyz',
                ],
            ],
            'dependencies' => [
                'lodash' => [
                    'version' => '4.17.21',
                ],
            ],
        ];

        $normalized = $this->parser->parse($v2Data);

        $this->assertEquals(3, $normalized['lockfileVersion']);
        $this->assertArrayHasKey('node_modules/lodash', $normalized['packages']);
        $this->assertEquals('4.17.21', $normalized['packages']['node_modules/lodash']['version']);
    }

    // ===== V3 Parsing Tests =====

    public function testParseV3(): void
    {
        $v3Data = [
            'name' => 'project',
            'version' => '1.0.0',
            'lockfileVersion' => 3,
            'packages' => [
                '' => [
                    'name' => 'project',
                    'version' => '1.0.0',
                ],
                'node_modules/lodash' => [
                    'version' => '4.17.21',
                    'resolved' => 'https://registry.npmjs.org/lodash/-/lodash-4.17.21.tgz',
                    'integrity' => 'sha512-xyz',
                ],
            ],
        ];

        $normalized = $this->parser->parse($v3Data);

        $this->assertEquals(3, $normalized['lockfileVersion']);
        $this->assertEquals($v3Data['packages'], $normalized['packages']);
    }

    // ===== Serialization Tests =====

    public function testSerializeToV3(): void
    {
        $normalized = [
            'name' => 'project',
            'version' => '1.0.0',
            'lockfileVersion' => 3,
            'packages' => [
                '' => ['name' => 'project', 'version' => '1.0.0'],
                'node_modules/lodash' => [
                    'version' => '4.17.21',
                    'resolved' => 'https://registry.npmjs.org/lodash/-/lodash-4.17.21.tgz',
                ],
            ],
        ];

        $serialized = $this->parser->serialize($normalized, 3);

        $this->assertEquals(3, $serialized['lockfileVersion']);
        $this->assertArrayHasKey('packages', $serialized);
        $this->assertArrayNotHasKey('dependencies', $serialized);
    }

    public function testSerializeToV1(): void
    {
        $normalized = [
            'name' => 'project',
            'version' => '1.0.0',
            'lockfileVersion' => 3,
            'packages' => [
                '' => ['name' => 'project', 'version' => '1.0.0'],
                'node_modules/lodash' => [
                    'version' => '4.17.21',
                    'resolved' => 'https://registry.npmjs.org/lodash/-/lodash-4.17.21.tgz',
                ],
            ],
        ];

        $serialized = $this->parser->serialize($normalized, 1);

        $this->assertEquals(1, $serialized['lockfileVersion']);
        $this->assertArrayHasKey('dependencies', $serialized);
        $this->assertArrayNotHasKey('packages', $serialized);
        $this->assertEquals('4.17.21', $serialized['dependencies']['lodash']['version']);
    }

    public function testSerializeToV2(): void
    {
        $normalized = [
            'name' => 'project',
            'version' => '1.0.0',
            'lockfileVersion' => 3,
            'packages' => [
                '' => ['name' => 'project', 'version' => '1.0.0'],
                'node_modules/lodash' => [
                    'version' => '4.17.21',
                ],
            ],
        ];

        $serialized = $this->parser->serialize($normalized, 2);

        $this->assertEquals(2, $serialized['lockfileVersion']);
        $this->assertArrayHasKey('packages', $serialized);
        $this->assertArrayHasKey('dependencies', $serialized);
    }

    // ===== Round-trip Tests =====

    public function testV1RoundTrip(): void
    {
        $original = [
            'name' => 'project',
            'version' => '1.0.0',
            'lockfileVersion' => 1,
            'requires' => true,
            'dependencies' => [
                'lodash' => [
                    'version' => '4.17.21',
                    'resolved' => 'https://registry.npmjs.org/lodash/-/lodash-4.17.21.tgz',
                ],
            ],
        ];

        $normalized = $this->parser->parse($original);
        $serialized = $this->parser->serialize($normalized, 1);

        $this->assertEquals('4.17.21', $serialized['dependencies']['lodash']['version']);
    }

    // ===== Edge Case Tests =====

    public function testParseEmptyLockfile(): void
    {
        $data = [
            'lockfileVersion' => 3,
            'packages' => [],
        ];

        $normalized = $this->parser->parse($data);

        $this->assertEquals(3, $normalized['lockfileVersion']);
        $this->assertEmpty($normalized['packages']);
    }

    public function testParseLockfileWithDevFlags(): void
    {
        $data = [
            'lockfileVersion' => 3,
            'packages' => [
                '' => ['name' => 'project', 'version' => '1.0.0'],
                'node_modules/jest' => [
                    'version' => '27.0.0',
                    'dev' => true,
                ],
                'node_modules/lodash' => [
                    'version' => '4.17.21',
                ],
            ],
        ];

        $normalized = $this->parser->parse($data);

        $this->assertTrue($normalized['packages']['node_modules/jest']['dev']);
        $this->assertArrayNotHasKey('dev', $normalized['packages']['node_modules/lodash']);
    }

    public function testParseLockfileWithOptionalFlags(): void
    {
        $data = [
            'lockfileVersion' => 3,
            'packages' => [
                '' => ['name' => 'project', 'version' => '1.0.0'],
                'node_modules/fsevents' => [
                    'version' => '2.3.0',
                    'optional' => true,
                ],
            ],
        ];

        $normalized = $this->parser->parse($data);

        $this->assertTrue($normalized['packages']['node_modules/fsevents']['optional']);
    }

    public function testParseScopedPackages(): void
    {
        $data = [
            'lockfileVersion' => 1,
            'dependencies' => [
                '@scope/package' => [
                    'version' => '1.0.0',
                ],
            ],
        ];

        $normalized = $this->parser->parse($data);

        $this->assertArrayHasKey('node_modules/@scope/package', $normalized['packages']);
    }
}
