# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project Overview

php-npm is a PHP implementation of npm, allowing JavaScript dependency management without Node.js. It mirrors npm's `@npmcli/arborist` architecture and is compatible with npm's `package.json` and `package-lock.json` formats.

## Commands

```bash
# Install dependencies
composer install

# Run tests
composer test              # via PHPUnit
php run-tests.php          # standalone runner (no PHPUnit needed)

# Run single test file
./vendor/bin/phpunit tests/Dependency/NodeTest.php

# Run single test method
./vendor/bin/phpunit --filter testMethodName

# CLI usage (after composer install)
bin/php-npm install
bin/php-npm ci
bin/php-npm update
bin/php-npm ls
```

## Architecture

The codebase follows npm's arborist pattern with these core modules:

- **Arborist/** - Orchestration (`Arborist.php` coordinates, `IdealTreeBuilder.php` resolves, `Reifier.php` installs)
- **Dependency/** - Tree structure (`Node.php` = package, `Edge.php` = relationship, `Inventory.php` = index)
- **Resolution/** - Placement algorithm (`DepsQueue` → `CanPlaceDep` → `PlaceDep`)
- **Registry/** - npm API (`Client.php`, `Pacote.php`, `PackumentCache.php`)
- **Lockfile/** - `Shrinkwrap.php` handles v1/v2/v3 formats, normalized to v3 internally

### Key Data Flow

1. `Arborist.buildIdealTree()` orchestrates resolution
2. `IdealTreeBuilder` queues deps by depth, fetches packuments via `Pacote`
3. `CanPlaceDep` finds valid placement, `PlaceDep` executes
4. `Reifier` downloads tarballs, `NodeModulesWriter` writes to disk
5. `Shrinkwrap` updates `package-lock.json`

### Node and Edge

`Node` represents a package with `name`, `version`, `registryName` (for aliases), `children` (nested deps), `edgesOut` (declared deps), `edgesIn` (dependents).

`Edge` represents a dependency with `from`, `to`, `name`, `spec`, `registryName`, `rawSpec` (version without alias prefix).

## Reference Implementation

**Critical**: When implementing features or fixing bugs, always refer to the official npm CLI source:

- **Arborist**: https://github.com/npm/cli/tree/latest/workspaces/arborist
- **Tests to port**: https://github.com/npm/cli/tree/latest/workspaces/arborist/test

Always port associated tests from npm CLI to ensure compatibility.

## Code Standards

- PHP 8.1+ with `declare(strict_types=1)`
- PSR-4 autoloading under `PhpNpm\` namespace
- No trailing commas in single-line arrays
- Use readonly properties where appropriate
