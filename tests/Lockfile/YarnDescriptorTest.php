<?php

declare(strict_types=1);

namespace PhpNpm\Tests\Lockfile;

use PHPUnit\Framework\TestCase;
use PhpNpm\Lockfile\YarnDescriptor;

/**
 * Tests for the YarnDescriptor value object.
 */
class YarnDescriptorTest extends TestCase
{
    // ===== Basic Parsing Tests =====

    public function testParseSimplePackage(): void
    {
        $descriptor = YarnDescriptor::parse('lodash@npm:^4.17.0');

        $this->assertNotNull($descriptor);
        $this->assertEquals('lodash', $descriptor->getName());
        $this->assertNull($descriptor->getScope());
        $this->assertEquals('npm', $descriptor->getProtocol());
        $this->assertEquals('^4.17.0', $descriptor->getRange());
        $this->assertFalse($descriptor->isScoped());
        $this->assertTrue($descriptor->isNpm());
    }

    public function testParseScopedPackage(): void
    {
        $descriptor = YarnDescriptor::parse('@babel/core@npm:^7.0.0');

        $this->assertNotNull($descriptor);
        $this->assertEquals('@babel/core', $descriptor->getName());
        $this->assertEquals('babel', $descriptor->getScope());
        $this->assertEquals('npm', $descriptor->getProtocol());
        $this->assertEquals('^7.0.0', $descriptor->getRange());
        $this->assertTrue($descriptor->isScoped());
        $this->assertTrue($descriptor->isNpm());
    }

    public function testParseExactVersion(): void
    {
        $descriptor = YarnDescriptor::parse('react@npm:18.2.0');

        $this->assertNotNull($descriptor);
        $this->assertEquals('react', $descriptor->getName());
        $this->assertEquals('18.2.0', $descriptor->getRange());
    }

    public function testParseRangeWithTilde(): void
    {
        $descriptor = YarnDescriptor::parse('express@npm:~4.18.0');

        $this->assertNotNull($descriptor);
        $this->assertEquals('~4.18.0', $descriptor->getRange());
    }

    // ===== Protocol Tests =====

    public function testParseWorkspaceProtocol(): void
    {
        $descriptor = YarnDescriptor::parse('@yarnpkg/core@workspace:^');

        $this->assertNotNull($descriptor);
        $this->assertEquals('@yarnpkg/core', $descriptor->getName());
        $this->assertEquals('workspace', $descriptor->getProtocol());
        $this->assertEquals('^', $descriptor->getRange());
        $this->assertFalse($descriptor->isNpm());
    }

    public function testParsePatchProtocol(): void
    {
        $descriptor = YarnDescriptor::parse('ink@patch:ink@npm%3A3.2.0#./patches/ink.patch');

        $this->assertNotNull($descriptor);
        $this->assertEquals('ink', $descriptor->getName());
        $this->assertEquals('patch', $descriptor->getProtocol());
    }

    // ===== Resolution Parsing Tests =====

    public function testParseResolutionSimple(): void
    {
        $descriptor = YarnDescriptor::parseResolution('lodash@npm:4.17.21');

        $this->assertNotNull($descriptor);
        $this->assertEquals('lodash', $descriptor->getName());
        $this->assertEquals('4.17.21', $descriptor->getRange());
    }

    public function testParseResolutionScoped(): void
    {
        $descriptor = YarnDescriptor::parseResolution('@babel/core@npm:7.26.0');

        $this->assertNotNull($descriptor);
        $this->assertEquals('@babel/core', $descriptor->getName());
        $this->assertEquals('7.26.0', $descriptor->getRange());
    }

    public function testExtractVersionFromResolution(): void
    {
        $version = YarnDescriptor::extractVersionFromResolution('@types/node@npm:18.19.124');

        $this->assertEquals('18.19.124', $version);
    }

    // ===== toNpmRange Tests =====

    public function testToNpmRange(): void
    {
        $descriptor = YarnDescriptor::parse('lodash@npm:^4.17.0');

        $this->assertEquals('^4.17.0', $descriptor->toNpmRange());
    }

    public function testToNpmRangeScopedPackage(): void
    {
        $descriptor = YarnDescriptor::parse('@types/react@npm:>=16.0.0');

        $this->assertEquals('>=16.0.0', $descriptor->toNpmRange());
    }

    // ===== Comparison Tests =====

    public function testSamePackage(): void
    {
        $desc1 = YarnDescriptor::parse('lodash@npm:^4.0.0');
        $desc2 = YarnDescriptor::parse('lodash@npm:^4.17.0');

        $this->assertTrue($desc1->samePackage($desc2));
    }

    public function testDifferentPackages(): void
    {
        $desc1 = YarnDescriptor::parse('lodash@npm:^4.0.0');
        $desc2 = YarnDescriptor::parse('underscore@npm:^1.0.0');

        $this->assertFalse($desc1->samePackage($desc2));
    }

    public function testSamePackageDifferentProtocol(): void
    {
        $desc1 = YarnDescriptor::parse('pkg@npm:^1.0.0');
        $desc2 = YarnDescriptor::parse('pkg@workspace:^');

        $this->assertFalse($desc1->samePackage($desc2));
    }

    // ===== getBaseName Tests =====

    public function testGetBaseNameUnscoped(): void
    {
        $descriptor = YarnDescriptor::parse('lodash@npm:^4.0.0');

        $this->assertEquals('lodash', $descriptor->getBaseName());
    }

    public function testGetBaseNameScoped(): void
    {
        $descriptor = YarnDescriptor::parse('@babel/core@npm:^7.0.0');

        $this->assertEquals('core', $descriptor->getBaseName());
    }

    // ===== toString Tests =====

    public function testToString(): void
    {
        $descriptor = YarnDescriptor::parse('lodash@npm:^4.17.0');

        $this->assertEquals('lodash@npm:^4.17.0', $descriptor->toString());
    }

    public function testToStringScoped(): void
    {
        $descriptor = YarnDescriptor::parse('@babel/core@npm:7.26.0');

        $this->assertEquals('@babel/core@npm:7.26.0', $descriptor->toString());
    }

    // ===== Edge Cases =====

    public function testParseInvalidDescriptor(): void
    {
        $descriptor = YarnDescriptor::parse('invalid');

        $this->assertNull($descriptor);
    }

    public function testParseEmptyString(): void
    {
        $descriptor = YarnDescriptor::parse('');

        $this->assertNull($descriptor);
    }

    public function testParseMissingScopeSlash(): void
    {
        // Invalid scoped package format (missing slash)
        $descriptor = YarnDescriptor::parse('@invalid@npm:^1.0.0');

        $this->assertNull($descriptor);
    }

    public function testGetOriginal(): void
    {
        $original = '@babel/core@npm:^7.0.0';
        $descriptor = YarnDescriptor::parse($original);

        $this->assertEquals($original, $descriptor->getOriginal());
    }

    // ===== Complex Range Tests =====

    public function testParseComplexRange(): void
    {
        $descriptor = YarnDescriptor::parse('semver@npm:>=5.0.0 <8.0.0');

        $this->assertNotNull($descriptor);
        $this->assertEquals('>=5.0.0 <8.0.0', $descriptor->getRange());
    }

    public function testParseRangeWithOr(): void
    {
        $descriptor = YarnDescriptor::parse('node@npm:^14.0.0 || ^16.0.0 || ^18.0.0');

        $this->assertNotNull($descriptor);
        $this->assertEquals('^14.0.0 || ^16.0.0 || ^18.0.0', $descriptor->getRange());
    }

    public function testParseStarRange(): void
    {
        $descriptor = YarnDescriptor::parse('any-pkg@npm:*');

        $this->assertNotNull($descriptor);
        $this->assertEquals('*', $descriptor->getRange());
    }
}
