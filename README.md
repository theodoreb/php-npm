# PHP NPM Client

[![License: MIT](https://img.shields.io/badge/License-MIT-blue.svg)](LICENSE)
[![PHP Version](https://img.shields.io/badge/php-%3E%3D8.1-8892BF.svg)](https://www.php.net/)

A PHP-based npm client that can interact with the npm registry without requiring Node.js/Bun installation.

**Disclaimer:** This project has been created by prompting a Large Language Model (LLM) to generate PHP code from the npm CLI. The code has barely been reviewed.

## Features

- Full recursive dependency resolution with deduplication
- Standard `node_modules/` structure
- Both PHP library and CLI interface
- Lockfile support: `package-lock.json` (v1/v2/v3), `npm-shrinkwrap.json`, and `yarn.lock` (Yarn Berry v2+)
- Parallel fetching for packuments and tarballs
- SRI (sha512) integrity verification
- Semver range matching using composer/semver

## Requirements

- PHP 8.1 or higher
- Composer
- ext-json
- ext-phar (for tarball extraction)

## Installation

```bash
composer require theodoreb/php-npm
```

Or clone and install dependencies:

```bash
git clone <repository>
cd php-npm
composer install
```

## CLI Usage

### Install all dependencies

```bash
php-npm install
# or
php-npm i
```

### Add a package

```bash
php-npm install lodash
php-npm install express@4.0.0
php-npm install -D jest  # as devDependency
```

### Clean install from lockfile

```bash
php-npm ci
```

### Update packages

```bash
php-npm update           # update all
php-npm update lodash    # update specific package
```

### List installed packages

```bash
php-npm ls
php-npm ls --all         # show all nested packages
php-npm ls --json        # output as JSON
php-npm ls --depth=2     # show 2 levels deep
```

## Library Usage

```php
use PhpNpm\Arborist\Arborist;

// Create arborist for a project directory
$arborist = new Arborist('/path/to/project');

// Install all dependencies
$arborist->install();

// Add packages
$arborist->add(['lodash', 'express@^4.0.0']);

// Remove packages
$arborist->remove(['lodash']);

// Clean install from lockfile
$arborist->ci();

// Update packages
$arborist->update();
$arborist->update(['lodash']); // specific packages

// Load and inspect the dependency tree
$tree = $arborist->loadActual();
foreach ($tree->getChildren() as $child) {
    echo $child->getName() . '@' . $child->getVersion() . "\n";
}
```

### Progress Callback

```php
$arborist->onProgress(function (string $message, int $current, int $total) {
    echo "[$current/$total] $message\n";
});
```

### Custom Registry

```php
$arborist = new Arborist('/path/to/project', [
    'registry' => 'https://registry.npmmirror.com',
]);
```

## Architecture

The library is modeled after npm's `@npmcli/arborist` package:

### Core Components

- **Arborist** - Main orchestrator that coordinates tree building and reification
- **IdealTreeBuilder** - Dependency resolution algorithm
- **Reifier** - Applies the ideal tree to disk

### Dependency Tree

- **Node** - Represents a package in the tree
- **Edge** - Dependency relationship between nodes
- **Inventory** - Collection of nodes with indexes for fast lookup

### Resolution

- **CanPlaceDep** - Determines if a dependency can be placed at a location
- **PlaceDep** - Executes dependency placement
- **DepsQueue** - Priority queue for resolution order

### Registry

- **Client** - HTTP client for npm registry
- **Pacote** - Package fetching and resolution
- **PackumentCache** - In-memory manifest cache

### Lockfile

- **Shrinkwrap** - Lockfile handler (package-lock.json, npm-shrinkwrap.json, yarn.lock)
- **LockfileParser** - Parse npm lockfile v1/v2/v3 formats
- **YarnLockParser** - Parse Yarn Berry (v2+) lockfiles

### File System

- **NodeModulesWriter** - Write node_modules structure
- **TarballExtractor** - Extract .tgz files
- **IntegrityChecker** - SRI verification

## Lockfile Compatibility

The library supports multiple lockfile formats:

### npm lockfiles

- **v1** - Nested `dependencies` structure (npm 5-6)
- **v2** - Hybrid format with both `packages` and `dependencies` (npm 7-8)
- **v3** - Flat `packages` only (npm 9+)

Priority: `npm-shrinkwrap.json` > `package-lock.json`

### Yarn lockfiles

- **Yarn Berry (v2+)** - SYML format with descriptor-based keys

When a `yarn.lock` is present (and no npm lockfile), php-npm will use it for dependency resolution while preserving the original format.

Lockfiles are internally normalized to npm v3 format for processing.

## Differences from npm

- Written in PHP, no Node.js required
- Uses composer/semver for version range matching
- Simplified peer dependency handling
- No support for workspaces (yet)
- No scripts execution
- No lifecycle hooks

## Contributing

Contributions are welcome! Please read our [Contributing Guide](CONTRIBUTING.md) for details on:

- Setting up your development environment
- Code standards and testing
- Submitting pull requests

## License

MIT - see [LICENSE](LICENSE) for details.
