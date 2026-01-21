# Agent Instructions

This file provides guidance for AI agents working with the php-npm codebase.

## Project Overview

php-npm is a PHP implementation of the npm package manager, designed to be compatible with npm's package.json and package-lock.json formats. It can install JavaScript dependencies from the npm registry.

## Reference Implementation

**IMPORTANT**: When implementing new features or fixing bugs:
1. **Always refer to the official npm CLI source code** to understand the expected behavior
2. **Always port the associated tests** from the npm CLI to ensure compatibility

- **Repository**: https://github.com/npm/cli
- **Arborist** (dependency management): https://github.com/npm/cli/tree/latest/workspaces/arborist
- **Arborist tests**: https://github.com/npm/cli/tree/latest/workspaces/arborist/test

Key reference locations in the npm CLI:
- `workspaces/arborist/lib/` - Dependency tree management (equivalent to our `src/Arborist/`)
- `workspaces/arborist/lib/node.js` - Node class implementation
- `workspaces/arborist/lib/edge.js` - Edge class implementation
- `workspaces/arborist/lib/shrinkwrap.js` - Lockfile handling
- `workspaces/arborist/lib/dep-valid.js` - Dependency validation
- `workspaces/arborist/test/` - **Tests to port** when implementing features

Related npm packages (with their tests):
- **npm-package-arg**: https://github.com/npm/npm-package-arg - Package spec parsing
- **pacote**: https://github.com/npm/pacote - Package fetching
- **npm-registry-fetch**: https://github.com/npm/npm-registry-fetch - Registry API client

## Architecture

The codebase follows npm's arborist architecture:

```
src/
├── Arborist/           # Core orchestration
│   ├── Arborist.php    # Main entry point, coordinates tree building and reification
│   ├── IdealTreeBuilder.php  # Builds the ideal dependency tree
│   └── Reifier.php     # Installs packages to disk
├── Dependency/         # Tree structure
│   ├── Node.php        # Package node in the tree (name, version, deps)
│   ├── Edge.php        # Dependency relationship between nodes
│   └── Inventory.php   # Index of all nodes in tree
├── Resolution/         # Dependency resolution algorithm
│   ├── DepsQueue.php   # Priority queue for resolution order
│   ├── CanPlaceDep.php # Determines valid placement locations
│   └── PlaceDep.php    # Executes package placement
├── Registry/           # npm registry interaction
│   ├── Client.php      # HTTP client for registry API
│   ├── Pacote.php      # Package fetching and version resolution
│   └── PackumentCache.php # Caches registry responses
├── Lockfile/           # package-lock.json handling
│   ├── Shrinkwrap.php  # Reads/writes lockfiles
│   └── LockfileParser.php # Parses lockfile formats
├── FileSystem/         # Disk operations
│   ├── TarballExtractor.php # Extracts .tgz packages
│   └── NodeModulesWriter.php # Writes to node_modules
├── Semver/             # Version handling
│   └── ComposerSemverAdapter.php # Adapts composer/semver for npm ranges
├── Integrity/          # Package verification
│   └── IntegrityChecker.php # Verifies SHA integrity
├── Exception/          # Custom exceptions
└── CLI/                # Command-line interface
    ├── Application.php
    └── Command/        # Symfony Console commands
```

## Key Concepts

### Node
A `Node` represents a package in the dependency tree. Key properties:
- `name` - Package name (or alias name for aliased packages)
- `version` - Package version
- `registryName` - Actual package name for aliases (e.g., for `string-width-cjs` aliased to `string-width`)
- `children` - Packages in this node's node_modules
- `edgesOut` - Dependencies this package declares
- `edgesIn` - Packages that depend on this node

### Edge
An `Edge` represents a dependency relationship. Key properties:
- `from` - The node declaring the dependency
- `to` - The resolved node (or null if unresolved)
- `name` - Dependency name as declared
- `spec` - Version specification (e.g., `^4.0.0` or `npm:package@^1.0.0`)
- `registryName` - Actual package name for aliases
- `rawSpec` - Version spec without npm:package@ prefix

### Resolution Flow
1. `Arborist.buildIdealTree()` orchestrates resolution
2. `IdealTreeBuilder` queues dependencies by depth
3. For each dependency:
   - Fetch packument from registry via `Pacote`
   - Find valid placement via `CanPlaceDep`
   - Place node in tree via `PlaceDep`
4. `Reifier` downloads and extracts packages to disk

## Development Guidelines

### Coding Standards
- PHP 8.1+ with strict types
- PSR-4 autoloading
- Follow existing code style (no trailing commas in single-line arrays)
- Use readonly properties where appropriate

### Testing
- Run tests with: `composer test` (if configured)
- Test with real package.json files

### When Adding Features
1. First study how npm implements the feature in the reference CLI
2. Understand the data flow through Node, Edge, and resolution
3. Update lockfile handling if the feature affects persistence
4. Test with both fresh installs and lockfile-based installs

### Key Files to Understand
- `Arborist/Arborist.php` - Start here for the main flow
- `Dependency/Node.php` - Central to everything
- `Arborist/IdealTreeBuilder.php` - Resolution algorithm
- `Lockfile/Shrinkwrap.php` - Lockfile format handling

## Common Tasks

### Adding a new dependency spec type
1. Update `Node::parseAliasSpec()` to parse the new syntax
2. Update `Edge` to store any new properties
3. Update `IdealTreeBuilder::processQueueEntry()` to use new properties
4. Update `Node::toLockEntry()` and `createFromLockEntry()` for persistence

### Adding a new CLI command
1. Create new command class in `src/CLI/Command/`
2. Register in `src/CLI/Application.php`
3. Follow existing command patterns (InstallCommand is a good reference)

## Debugging Tips
- Enable verbose output in CLI commands
- Check packument cache in `PackumentCache`
- Trace resolution through `DepsQueue` entries
- Verify lockfile format matches npm's output
