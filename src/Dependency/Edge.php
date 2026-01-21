<?php

declare(strict_types=1);

namespace PhpNpm\Dependency;

/**
 * Represents a dependency relationship between two nodes.
 * An edge connects a "from" node to a "to" node with a specific dependency type and version spec.
 */
class Edge
{
    public const TYPE_PROD = 'prod';
    public const TYPE_DEV = 'dev';
    public const TYPE_OPTIONAL = 'optional';
    public const TYPE_PEER = 'peer';
    public const TYPE_PEER_OPTIONAL = 'peerOptional';

    private ?Node $to = null;
    private bool $valid = false;
    private ?string $error = null;

    /**
     * The actual package name for registry lookup (different from $name for aliases).
     * For "string-width-cjs": "npm:string-width@^4.2.0", this would be "string-width".
     */
    private readonly ?string $registryName;

    /**
     * The actual version spec without the npm:package@ prefix.
     * For "npm:string-width@^4.2.0", this would be "^4.2.0".
     */
    private readonly string $rawSpec;

    public function __construct(
        private readonly Node $from,
        private readonly string $name,
        private readonly string $spec,
        private readonly string $type = self::TYPE_PROD,
        ?string $registryName = null,
        ?string $rawSpec = null,
    ) {
        $this->registryName = $registryName;
        $this->rawSpec = $rawSpec ?? $spec;
    }

    public function getFrom(): Node
    {
        return $this->from;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getSpec(): string
    {
        return $this->spec;
    }

    /**
     * Get the version spec without the npm:package@ prefix.
     * For aliases like "npm:string-width@^4.2.0", returns "^4.2.0".
     * For regular deps, returns the same as getSpec().
     */
    public function getRawSpec(): string
    {
        return $this->rawSpec;
    }

    /**
     * Get the actual package name for registry lookup.
     * For aliases like "string-width-cjs": "npm:string-width@^4.2.0", returns "string-width".
     * For regular deps, returns the same as getName().
     */
    public function getRegistryName(): string
    {
        return $this->registryName ?? $this->name;
    }

    /**
     * Check if this edge is an alias (package installed under different name).
     */
    public function isAlias(): bool
    {
        return $this->registryName !== null && $this->registryName !== $this->name;
    }

    public function getType(): string
    {
        return $this->type;
    }

    public function getTo(): ?Node
    {
        return $this->to;
    }

    public function setTo(?Node $to): void
    {
        $this->to = $to;
    }

    public function isValid(): bool
    {
        return $this->valid;
    }

    public function setValid(bool $valid): void
    {
        $this->valid = $valid;
    }

    public function getError(): ?string
    {
        return $this->error;
    }

    public function setError(?string $error): void
    {
        $this->error = $error;
    }

    public function isDev(): bool
    {
        return $this->type === self::TYPE_DEV;
    }

    public function isOptional(): bool
    {
        return $this->type === self::TYPE_OPTIONAL || $this->type === self::TYPE_PEER_OPTIONAL;
    }

    public function isPeer(): bool
    {
        return $this->type === self::TYPE_PEER || $this->type === self::TYPE_PEER_OPTIONAL;
    }

    public function isMissing(): bool
    {
        return $this->to === null && !$this->isOptional();
    }

    public function isInvalid(): bool
    {
        return $this->to !== null && !$this->valid;
    }

    /**
     * Reload the edge resolution after tree changes.
     */
    public function reload(): void
    {
        // Remove from old target's edgesIn
        if ($this->to !== null) {
            $this->to->removeEdgeIn($this);
        }

        $resolved = $this->from->resolve($this->name);
        $this->to = $resolved;

        if ($resolved === null) {
            $this->valid = $this->isOptional();
            $this->error = $this->isOptional() ? null : 'MISSING';
        } else {
            // Check if resolved node satisfies the version constraint
            $this->valid = $resolved->satisfies($this->rawSpec);
            $this->error = $this->valid ? null : 'INVALID';

            // Register this edge in the resolved node's edgesIn
            $resolved->addEdgeIn($this);
        }
    }

    public function satisfiedBy(Node $node): bool
    {
        // For aliases, the node's name matches the alias name (folder name)
        // but we validate against the raw spec (actual version constraint)
        if ($node->getName() !== $this->name) {
            return false;
        }

        return $node->satisfies($this->rawSpec);
    }
}
