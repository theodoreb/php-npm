<?php

declare(strict_types=1);

namespace PhpNpm\Exception;

/**
 * Exception thrown when dependency resolution fails (ERESOLVE).
 */
class ResolveException extends \RuntimeException
{
    private ?string $packageName = null;
    private ?string $requestedVersion = null;
    private ?string $conflictingPackage = null;
    private ?string $conflictingVersion = null;

    /**
     * Create a resolve exception with details.
     */
    public static function conflict(
        string $packageName,
        string $requestedVersion,
        string $conflictingPackage,
        string $conflictingVersion
    ): self {
        $exception = new self(sprintf(
            "Could not resolve dependency tree.\n" .
            "While resolving: %s@%s\n" .
            "Found: %s@%s\n" .
            "node_modules/%s\n" .
            "  %s@\"%s\" from the root project\n\n" .
            "Could not resolve dependency:\n" .
            "peer %s@\"%s\" from %s\n" .
            "node_modules/%s\n\n" .
            "Fix the upstream dependency conflict, or retry\n" .
            "this command with --force, or --legacy-peer-deps\n" .
            "to accept an incorrect (and potentially broken) dependency resolution.",
            $packageName,
            $requestedVersion,
            $conflictingPackage,
            $conflictingVersion,
            $conflictingPackage,
            $conflictingPackage,
            $conflictingVersion,
            $packageName,
            $requestedVersion,
            $conflictingPackage,
            $conflictingPackage
        ));

        $exception->packageName = $packageName;
        $exception->requestedVersion = $requestedVersion;
        $exception->conflictingPackage = $conflictingPackage;
        $exception->conflictingVersion = $conflictingVersion;

        return $exception;
    }

    /**
     * Create a simple not found exception.
     */
    public static function notFound(string $packageName, string $version): self
    {
        $exception = new self(sprintf(
            "No matching version found for %s@%s",
            $packageName,
            $version
        ));

        $exception->packageName = $packageName;
        $exception->requestedVersion = $version;

        return $exception;
    }

    public function getPackageName(): ?string
    {
        return $this->packageName;
    }

    public function getRequestedVersion(): ?string
    {
        return $this->requestedVersion;
    }

    public function getConflictingPackage(): ?string
    {
        return $this->conflictingPackage;
    }

    public function getConflictingVersion(): ?string
    {
        return $this->conflictingVersion;
    }
}
