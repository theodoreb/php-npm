<?php

declare(strict_types=1);

namespace PhpNpm\Lockfile;

/**
 * Value object for parsing Yarn Berry descriptor strings.
 *
 * Yarn uses descriptors like:
 * - "lodash@npm:^4.17.0" (regular package)
 * - "@scope/name@npm:^1.0.0" (scoped package)
 * - "alias@npm:real-package@^1.0.0" (aliased package)
 *
 * The descriptor format is: [name]@[protocol]:[range]
 * For scoped packages: @[scope]/[name]@[protocol]:[range]
 */
final class YarnDescriptor
{
    private function __construct(
        private readonly string $name,
        private readonly ?string $scope,
        private readonly string $protocol,
        private readonly string $range,
        private readonly string $original,
    ) {
    }

    /**
     * Parse a Yarn descriptor string.
     *
     * @param string $descriptor The descriptor string (e.g., "lodash@npm:^4.17.0")
     * @return self|null Returns null if the descriptor cannot be parsed
     */
    public static function parse(string $descriptor): ?self
    {
        // Handle scoped packages: @scope/name@protocol:range
        if (str_starts_with($descriptor, '@')) {
            // Find the second @ which separates scope/name from protocol
            $firstSlash = strpos($descriptor, '/');
            if ($firstSlash === false) {
                return null;
            }

            $afterScope = strpos($descriptor, '@', $firstSlash);
            if ($afterScope === false) {
                return null;
            }

            $fullName = substr($descriptor, 0, $afterScope);
            $scope = substr($descriptor, 1, $firstSlash - 1);
            $name = $fullName;
            $rest = substr($descriptor, $afterScope + 1);
        } else {
            // Unscoped package: name@protocol:range
            $atPos = strpos($descriptor, '@');
            if ($atPos === false) {
                return null;
            }

            $name = substr($descriptor, 0, $atPos);
            $scope = null;
            $rest = substr($descriptor, $atPos + 1);
        }

        // Parse protocol:range
        $colonPos = strpos($rest, ':');
        if ($colonPos === false) {
            // No protocol, treat as npm with version
            return new self($name, $scope, 'npm', $rest, $descriptor);
        }

        $protocol = substr($rest, 0, $colonPos);
        $range = substr($rest, $colonPos + 1);

        return new self($name, $scope, $protocol, $range, $descriptor);
    }

    /**
     * Get the full package name (including scope if present).
     */
    public function getName(): string
    {
        return $this->name;
    }

    /**
     * Get the scope without the @ prefix, or null if unscoped.
     */
    public function getScope(): ?string
    {
        return $this->scope;
    }

    /**
     * Get the protocol (e.g., "npm", "workspace", "patch").
     */
    public function getProtocol(): string
    {
        return $this->protocol;
    }

    /**
     * Get the version range.
     */
    public function getRange(): string
    {
        return $this->range;
    }

    /**
     * Get the original descriptor string.
     */
    public function getOriginal(): string
    {
        return $this->original;
    }

    /**
     * Check if this is a scoped package.
     */
    public function isScoped(): bool
    {
        return $this->scope !== null;
    }

    /**
     * Check if this is an npm protocol descriptor.
     */
    public function isNpm(): bool
    {
        return $this->protocol === 'npm';
    }

    /**
     * Convert to npm-style range (strips protocol prefix).
     *
     * For "lodash@npm:^4.17.0", returns "^4.17.0"
     */
    public function toNpmRange(): string
    {
        return $this->range;
    }

    /**
     * Create a descriptor string.
     */
    public function toString(): string
    {
        return $this->name . '@' . $this->protocol . ':' . $this->range;
    }

    /**
     * Parse a resolution string which has a different format.
     *
     * Resolution format: "name@npm:version" (exact version, no range)
     * Example: "@scope/name@npm:1.2.3"
     *
     * @param string $resolution The resolution string
     * @return self|null Returns null if cannot be parsed
     */
    public static function parseResolution(string $resolution): ?self
    {
        return self::parse($resolution);
    }

    /**
     * Extract version from a resolution string.
     *
     * @param string $resolution The resolution string (e.g., "@scope/name@npm:1.2.3")
     * @return string|null The version or null if cannot be parsed
     */
    public static function extractVersionFromResolution(string $resolution): ?string
    {
        $parsed = self::parse($resolution);
        if ($parsed === null) {
            return null;
        }

        // The range in a resolution is actually the exact version
        return $parsed->getRange();
    }

    /**
     * Check if two descriptors refer to the same package.
     */
    public function samePackage(self $other): bool
    {
        return $this->name === $other->name && $this->protocol === $other->protocol;
    }

    /**
     * Get the base name without scope.
     */
    public function getBaseName(): string
    {
        if ($this->scope === null) {
            return $this->name;
        }

        $slashPos = strpos($this->name, '/');
        if ($slashPos === false) {
            return $this->name;
        }

        return substr($this->name, $slashPos + 1);
    }
}
