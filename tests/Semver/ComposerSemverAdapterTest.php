<?php

declare(strict_types=1);

namespace PhpNpm\Tests\Semver;

use PHPUnit\Framework\TestCase;
use PhpNpm\Semver\ComposerSemverAdapter;

/**
 * Tests for the ComposerSemverAdapter class.
 * Ported from npm's dep-valid.js tests.
 */
class ComposerSemverAdapterTest extends TestCase
{
    private ComposerSemverAdapter $semver;

    protected function setUp(): void
    {
        $this->semver = new ComposerSemverAdapter();
    }

    // ===== Basic Semver Satisfaction Tests =====

    public function testWildcardIsAlwaysSatisfied(): void
    {
        $this->assertTrue($this->semver->satisfies('1.0.0', '*'));
        $this->assertTrue($this->semver->satisfies('2.5.3', '*'));
        $this->assertTrue($this->semver->satisfies('0.0.1', '*'));
    }

    public function testEmptyRangeIsAlwaysSatisfied(): void
    {
        $this->assertTrue($this->semver->satisfies('1.0.0', ''));
        $this->assertTrue($this->semver->satisfies('2.5.3', ''));
    }

    public function testLatestTagIsAlwaysSatisfied(): void
    {
        $this->assertTrue($this->semver->satisfies('1.0.0', 'latest'));
    }

    public function testExactVersionMatch(): void
    {
        $this->assertTrue($this->semver->satisfies('1.2.3', '1.2.3'));
        $this->assertFalse($this->semver->satisfies('1.2.4', '1.2.3'));
    }

    // ===== X-Range Tests (1.x, 1.2.x) =====

    public function testXRangeMajor(): void
    {
        $this->assertTrue($this->semver->satisfies('1.2.3', '1.x'));
        $this->assertTrue($this->semver->satisfies('1.0.0', '1.x'));
        $this->assertTrue($this->semver->satisfies('1.9.9', '1.x'));
        $this->assertFalse($this->semver->satisfies('2.0.0', '1.x'));
        $this->assertFalse($this->semver->satisfies('0.9.9', '1.x'));
    }

    public function testXRangeMinor(): void
    {
        $this->assertTrue($this->semver->satisfies('1.2.0', '1.2.x'));
        $this->assertTrue($this->semver->satisfies('1.2.9', '1.2.x'));
        $this->assertFalse($this->semver->satisfies('1.3.0', '1.2.x'));
        $this->assertFalse($this->semver->satisfies('1.1.9', '1.2.x'));
    }

    // ===== Caret Range Tests (^1.2.3) =====

    public function testCaretRangeMajorVersion(): void
    {
        // ^1.2.3 := >=1.2.3 <2.0.0
        $this->assertTrue($this->semver->satisfies('1.2.3', '^1.2.3'));
        $this->assertTrue($this->semver->satisfies('1.9.9', '^1.2.3'));
        $this->assertFalse($this->semver->satisfies('2.0.0', '^1.2.3'));
        $this->assertFalse($this->semver->satisfies('1.2.2', '^1.2.3'));
    }

    public function testCaretRangeZeroMajor(): void
    {
        // ^0.2.3 := >=0.2.3 <0.3.0
        $this->assertTrue($this->semver->satisfies('0.2.3', '^0.2.3'));
        $this->assertTrue($this->semver->satisfies('0.2.9', '^0.2.3'));
        $this->assertFalse($this->semver->satisfies('0.3.0', '^0.2.3'));
    }

    public function testCaretRangeZeroMinor(): void
    {
        // ^0.0.3 := >=0.0.3 <0.0.4
        $this->assertTrue($this->semver->satisfies('0.0.3', '^0.0.3'));
        $this->assertFalse($this->semver->satisfies('0.0.4', '^0.0.3'));
    }

    // ===== Tilde Range Tests (~1.2.3) =====

    public function testTildeRange(): void
    {
        // ~1.2.3 := >=1.2.3 <1.3.0
        $this->assertTrue($this->semver->satisfies('1.2.3', '~1.2.3'));
        $this->assertTrue($this->semver->satisfies('1.2.9', '~1.2.3'));
        $this->assertFalse($this->semver->satisfies('1.3.0', '~1.2.3'));
        $this->assertFalse($this->semver->satisfies('1.2.2', '~1.2.3'));
    }

    public function testTildeRangeMajorOnly(): void
    {
        // ~1 := >=1.0.0 <2.0.0
        $this->assertTrue($this->semver->satisfies('1.0.0', '~1'));
        $this->assertTrue($this->semver->satisfies('1.9.9', '~1'));
        $this->assertFalse($this->semver->satisfies('2.0.0', '~1'));
    }

    // ===== Comparison Operator Tests =====

    public function testGreaterThanOrEqual(): void
    {
        $this->assertTrue($this->semver->satisfies('1.2.3', '>=1.2.3'));
        $this->assertTrue($this->semver->satisfies('2.0.0', '>=1.2.3'));
        $this->assertFalse($this->semver->satisfies('1.2.2', '>=1.2.3'));
    }

    public function testLessThan(): void
    {
        $this->assertTrue($this->semver->satisfies('1.2.2', '<1.2.3'));
        $this->assertFalse($this->semver->satisfies('1.2.3', '<1.2.3'));
        $this->assertFalse($this->semver->satisfies('1.2.4', '<1.2.3'));
    }

    public function testCompoundRange(): void
    {
        // >=1.0.0 <2.0.0
        $this->assertTrue($this->semver->satisfies('1.0.0', '>=1.0.0 <2.0.0'));
        $this->assertTrue($this->semver->satisfies('1.9.9', '>=1.0.0 <2.0.0'));
        $this->assertFalse($this->semver->satisfies('0.9.9', '>=1.0.0 <2.0.0'));
        $this->assertFalse($this->semver->satisfies('2.0.0', '>=1.0.0 <2.0.0'));
    }

    // ===== OR Range Tests (||) =====

    public function testOrRange(): void
    {
        // 1.x || 2.x
        $this->assertTrue($this->semver->satisfies('1.0.0', '1.x || 2.x'));
        $this->assertTrue($this->semver->satisfies('2.0.0', '1.x || 2.x'));
        $this->assertFalse($this->semver->satisfies('3.0.0', '1.x || 2.x'));
    }

    public function testComplexOrRange(): void
    {
        // ^1.0.0 || ^2.0.0
        $this->assertTrue($this->semver->satisfies('1.5.0', '^1.0.0 || ^2.0.0'));
        $this->assertTrue($this->semver->satisfies('2.5.0', '^1.0.0 || ^2.0.0'));
        $this->assertFalse($this->semver->satisfies('3.0.0', '^1.0.0 || ^2.0.0'));
    }

    // ===== maxSatisfying Tests =====

    public function testMaxSatisfyingBasic(): void
    {
        $versions = ['1.0.0', '1.2.3', '1.5.0', '2.0.0'];

        $this->assertEquals('1.5.0', $this->semver->maxSatisfying($versions, '1.x'));
        $this->assertEquals('2.0.0', $this->semver->maxSatisfying($versions, '*'));
        $this->assertEquals('1.2.3', $this->semver->maxSatisfying($versions, '~1.2.0'));
    }

    public function testMaxSatisfyingNoMatch(): void
    {
        $versions = ['1.0.0', '1.2.3'];

        $this->assertNull($this->semver->maxSatisfying($versions, '2.x'));
    }

    public function testMaxSatisfyingEmptyVersions(): void
    {
        $this->assertNull($this->semver->maxSatisfying([], '*'));
    }

    // ===== Version Comparison Tests =====

    public function testGreaterThan(): void
    {
        $this->assertTrue($this->semver->gt('1.2.4', '1.2.3'));
        $this->assertFalse($this->semver->gt('1.2.3', '1.2.3'));
        $this->assertFalse($this->semver->gt('1.2.2', '1.2.3'));
    }

    public function testGreaterThanOrEqualTo(): void
    {
        $this->assertTrue($this->semver->gte('1.2.4', '1.2.3'));
        $this->assertTrue($this->semver->gte('1.2.3', '1.2.3'));
        $this->assertFalse($this->semver->gte('1.2.2', '1.2.3'));
    }

    public function testLessThanComparison(): void
    {
        $this->assertTrue($this->semver->lt('1.2.2', '1.2.3'));
        $this->assertFalse($this->semver->lt('1.2.3', '1.2.3'));
        $this->assertFalse($this->semver->lt('1.2.4', '1.2.3'));
    }

    public function testLessThanOrEqualTo(): void
    {
        $this->assertTrue($this->semver->lte('1.2.2', '1.2.3'));
        $this->assertTrue($this->semver->lte('1.2.3', '1.2.3'));
        $this->assertFalse($this->semver->lte('1.2.4', '1.2.3'));
    }

    public function testEqual(): void
    {
        $this->assertTrue($this->semver->eq('1.2.3', '1.2.3'));
        $this->assertFalse($this->semver->eq('1.2.3', '1.2.4'));
    }

    // ===== Sort Tests =====

    public function testRsort(): void
    {
        $versions = ['1.0.0', '2.0.0', '1.5.0', '0.9.0'];
        $sorted = $this->semver->rsort($versions);

        $this->assertEquals('2.0.0', $sorted[0]);
        $this->assertEquals('0.9.0', $sorted[count($sorted) - 1]);
    }

    public function testSort(): void
    {
        $versions = ['2.0.0', '1.0.0', '1.5.0', '0.9.0'];
        $sorted = $this->semver->sort($versions);

        $this->assertEquals('0.9.0', $sorted[0]);
        $this->assertEquals('2.0.0', $sorted[count($sorted) - 1]);
    }

    // ===== Valid Version Tests =====

    public function testValidVersion(): void
    {
        $this->assertTrue($this->semver->valid('1.2.3'));
        $this->assertTrue($this->semver->valid('0.0.0'));
        $this->assertTrue($this->semver->valid('1.2.3-alpha.1'));
    }

    // ===== Coerce Tests =====

    public function testCoerceVersion(): void
    {
        $this->assertEquals('1.0.0', $this->semver->coerce('1'));
        $this->assertEquals('1.2.0', $this->semver->coerce('1.2'));
        $this->assertEquals('1.2.3', $this->semver->coerce('1.2.3'));
        $this->assertEquals('1.2.3', $this->semver->coerce('v1.2.3'));
    }

    // ===== Range Conversion Tests =====

    public function testConvertNpmRangeToComposer(): void
    {
        // These test the internal conversion logic
        $this->assertEquals('*', $this->semver->convertNpmRangeToComposer('*'));
        $this->assertEquals('*', $this->semver->convertNpmRangeToComposer(''));
    }

    // ===== npm: Protocol Tests =====

    public function testNpmProtocolPrefix(): void
    {
        // npm:package@version format should extract version
        $this->assertTrue($this->semver->satisfies('1.2.3', 'npm:bar@1.2.3'));
    }

    // ===== Workspace Protocol Tests =====

    public function testWorkspaceProtocol(): void
    {
        // workspace: dependencies are always satisfied locally
        $this->assertTrue($this->semver->satisfies('1.0.0', 'workspace:*'));
    }

    // ===== Prerelease Tests =====

    public function testPrereleaseVersion(): void
    {
        $this->assertTrue($this->semver->satisfies('1.2.3-alpha.1', '1.2.3-alpha.1'));
    }
}
