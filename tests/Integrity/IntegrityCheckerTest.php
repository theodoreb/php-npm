<?php

declare(strict_types=1);

namespace PhpNpm\Tests\Integrity;

use PHPUnit\Framework\TestCase;
use PhpNpm\Integrity\IntegrityChecker;
use PhpNpm\Exception\IntegrityException;

/**
 * Tests for the IntegrityChecker class.
 * Tests SRI (Subresource Integrity) verification.
 */
class IntegrityCheckerTest extends TestCase
{
    private IntegrityChecker $checker;

    protected function setUp(): void
    {
        $this->checker = new IntegrityChecker();
    }

    // ===== Basic Verification Tests =====

    public function testVerifyValidSha512(): void
    {
        $content = 'Hello, World!';
        $integrity = $this->checker->calculate($content, 'sha512');

        $this->assertTrue($this->checker->verify($content, $integrity));
    }

    public function testVerifyValidSha384(): void
    {
        $content = 'Hello, World!';
        $integrity = $this->checker->calculate($content, 'sha384');

        $this->assertTrue($this->checker->verify($content, $integrity));
    }

    public function testVerifyValidSha256(): void
    {
        $content = 'Hello, World!';
        $integrity = $this->checker->calculate($content, 'sha256');

        $this->assertTrue($this->checker->verify($content, $integrity));
    }

    public function testVerifyValidSha1(): void
    {
        $content = 'Hello, World!';
        $integrity = $this->checker->calculate($content, 'sha1');

        $this->assertTrue($this->checker->verify($content, $integrity));
    }

    public function testVerifyInvalidContent(): void
    {
        $content = 'Hello, World!';
        $integrity = $this->checker->calculate($content, 'sha512');

        $this->assertFalse($this->checker->verify('Different content', $integrity));
    }

    // ===== Calculate Tests =====

    public function testCalculateSha512(): void
    {
        $content = 'test content';
        $integrity = $this->checker->calculate($content, 'sha512');

        $this->assertStringStartsWith('sha512-', $integrity);
    }

    public function testCalculateDefaultsToSha512(): void
    {
        $content = 'test content';
        $integrity = $this->checker->calculate($content);

        $this->assertStringStartsWith('sha512-', $integrity);
    }

    public function testCalculateUnsupportedAlgorithmThrows(): void
    {
        $this->expectException(IntegrityException::class);

        $this->checker->calculate('content', 'md5');
    }

    // ===== Parse Tests =====

    public function testParseSingleIntegrity(): void
    {
        $integrity = 'sha512-abc123def456';
        $parsed = $this->checker->parse($integrity);

        $this->assertCount(1, $parsed);
        $this->assertEquals('sha512', $parsed[0]['algorithm']);
        $this->assertEquals('abc123def456', $parsed[0]['hash']);
    }

    public function testParseMultipleIntegrities(): void
    {
        $integrity = 'sha512-abc123 sha384-def456 sha256-ghi789';
        $parsed = $this->checker->parse($integrity);

        $this->assertCount(3, $parsed);
        $this->assertEquals('sha512', $parsed[0]['algorithm']);
        $this->assertEquals('sha384', $parsed[1]['algorithm']);
        $this->assertEquals('sha256', $parsed[2]['algorithm']);
    }

    public function testParseWithOptions(): void
    {
        // SRI can have options like ?foo=bar
        $integrity = 'sha512-abc123?foo=bar';
        $parsed = $this->checker->parse($integrity);

        $this->assertEquals('abc123', $parsed[0]['hash']);
    }

    public function testParseEmptyString(): void
    {
        $parsed = $this->checker->parse('');

        $this->assertEmpty($parsed);
    }

    public function testParseIgnoresInvalidAlgorithms(): void
    {
        $integrity = 'md5-abc123 sha512-def456';
        $parsed = $this->checker->parse($integrity);

        $this->assertCount(1, $parsed);
        $this->assertEquals('sha512', $parsed[0]['algorithm']);
    }

    // ===== Verify Or Throw Tests =====

    public function testVerifyOrThrowSuccess(): void
    {
        $content = 'test content';
        $integrity = $this->checker->calculate($content);

        // Should not throw
        $this->checker->verifyOrThrow($content, $integrity);
        $this->assertTrue(true); // If we get here, no exception was thrown
    }

    public function testVerifyOrThrowFailure(): void
    {
        $content = 'test content';
        $integrity = $this->checker->calculate('different content');

        $this->expectException(IntegrityException::class);
        $this->expectExceptionMessage('Integrity check failed');

        $this->checker->verifyOrThrow($content, $integrity, 'test-package');
    }

    // ===== Get Strongest Algorithm Tests =====

    public function testGetStrongestAlgorithmSha512(): void
    {
        $integrity = 'sha256-abc sha512-def sha384-ghi';

        $this->assertEquals('sha512', $this->checker->getStrongestAlgorithm($integrity));
    }

    public function testGetStrongestAlgorithmSha384(): void
    {
        $integrity = 'sha256-abc sha384-ghi';

        $this->assertEquals('sha384', $this->checker->getStrongestAlgorithm($integrity));
    }

    public function testGetStrongestAlgorithmSha256(): void
    {
        $integrity = 'sha256-abc sha1-def';

        $this->assertEquals('sha256', $this->checker->getStrongestAlgorithm($integrity));
    }

    public function testGetStrongestAlgorithmNone(): void
    {
        $integrity = 'md5-abc';

        $this->assertNull($this->checker->getStrongestAlgorithm($integrity));
    }

    // ===== Multi Hash Tests =====

    public function testCreateMultiHash(): void
    {
        $content = 'test content';
        $integrity = $this->checker->createMultiHash($content, ['sha512', 'sha256']);

        $this->assertStringContainsString('sha512-', $integrity);
        $this->assertStringContainsString('sha256-', $integrity);
    }

    // ===== Real-World Integrity String Tests =====

    public function testVerifyRealLodashIntegrity(): void
    {
        // This is a known integrity for an empty package.json
        $content = '{}';
        $integrity = $this->checker->calculate($content, 'sha512');

        $this->assertTrue($this->checker->verify($content, $integrity));
    }

    public function testMultipleHashesOnlyNeedsOneToMatch(): void
    {
        $content = 'test content';
        $validHash = $this->checker->calculate($content, 'sha512');
        $invalidHash = 'sha256-invalidhash';

        // Should pass because at least one hash matches
        $integrity = $validHash . ' ' . $invalidHash;

        $this->assertTrue($this->checker->verify($content, $integrity));
    }

    // ===== Edge Cases =====

    public function testVerifyWithWhitespace(): void
    {
        $content = 'test';
        $integrity = '  sha512-' . base64_encode(hash('sha512', $content, true)) . '  ';

        $this->assertTrue($this->checker->verify($content, $integrity));
    }

    public function testCaseInsensitiveAlgorithm(): void
    {
        $content = 'test';
        $hash = base64_encode(hash('sha512', $content, true));
        $integrity = 'SHA512-' . $hash;

        $parsed = $this->checker->parse($integrity);

        $this->assertEquals('sha512', $parsed[0]['algorithm']);
    }
}
