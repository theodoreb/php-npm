# Contributing to php-npm

Thank you for your interest in contributing to php-npm! This document provides guidelines and information for contributors.

## Getting Started

1. Fork the repository
2. Clone your fork: `git clone https://github.com/YOUR_USERNAME/php-npm.git`
3. Install dependencies: `composer install` (this also installs git hooks)
4. Run tests to verify setup: `composer test`

## Development Workflow

### Before You Code

**Important**: php-npm is modeled after npm's official `@npmcli/arborist` package. When implementing features or fixing bugs:

1. **Study the npm CLI source** to understand expected behavior:
   - Arborist: https://github.com/npm/cli/tree/latest/workspaces/arborist
   - Tests: https://github.com/npm/cli/tree/latest/workspaces/arborist/test

2. **Port associated tests** from the npm CLI to ensure compatibility

### Making Changes

1. Create a branch: `git checkout -b feature/your-feature-name`
2. Make your changes following the code standards below
3. Add or update tests as needed
4. Run the test suite: `composer test`
5. Commit using conventional commit format (see below)

### Commit Message Format

This project uses [Conventional Commits](https://www.conventionalcommits.org/). Commit messages are validated automatically via git hooks.

```
<type>[optional scope]: <description>

[optional body]

[optional footer(s)]
```

**Types:**
- `feat` - New feature
- `fix` - Bug fix
- `docs` - Documentation changes
- `style` - Code style changes (formatting, no logic change)
- `refactor` - Code refactoring (no feature or fix)
- `perf` - Performance improvement
- `test` - Adding or updating tests
- `build` - Build system or dependencies
- `ci` - CI configuration
- `task` - Other changes (e.g., tooling)

**Examples:**
```
feat: add support for npm workspaces
fix(lockfile): handle v1 format edge case
docs: update installation instructions
refactor(arborist): simplify tree building logic
```

### Code Standards

- **PHP 8.1+** with `declare(strict_types=1)` in all files
- **PSR-4** autoloading under the `PhpNpm\` namespace
- **No trailing commas** in single-line arrays
- Use **readonly properties** where appropriate
- Follow the existing code style in the repository

### Running Tests

```bash
# Run all tests
composer test

# Run a specific test file
./vendor/bin/phpunit tests/Dependency/NodeTest.php

# Run a specific test method
./vendor/bin/phpunit --filter testMethodName

# Alternative: standalone runner (no PHPUnit required)
php run-tests.php
```

## Pull Request Process

1. Ensure all tests pass
2. Update documentation if you're changing behavior
3. Keep PRs focused—one feature or fix per PR
4. Write a clear PR description explaining what and why

## Reporting Issues

When reporting bugs, please include:

- PHP version (`php -v`)
- Steps to reproduce the issue
- Expected vs actual behavior
- Relevant `package.json` and `package-lock.json` contents (if applicable)
- Any error messages or stack traces

## Architecture Overview

Understanding the codebase structure helps with contributions:

- **Arborist/** - Main orchestration (start here for the flow)
- **Dependency/** - Tree structure (Node, Edge, Inventory)
- **Resolution/** - Placement algorithm (DepsQueue, CanPlaceDep, PlaceDep)
- **Registry/** - npm API interaction
- **Lockfile/** - package-lock.json handling

See [AGENTS.md](AGENTS.md) for detailed architecture documentation.

## Questions?

Open an issue for questions about contributing. We're happy to help!

## License

By contributing, you agree that your contributions will be licensed under the MIT License.
