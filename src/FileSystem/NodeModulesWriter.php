<?php

declare(strict_types=1);

namespace PhpNpm\FileSystem;

use PhpNpm\Dependency\Node;
use PhpNpm\Dependency\Inventory;

/**
 * Writes the node_modules directory structure.
 */
class NodeModulesWriter
{
    private TarballExtractor $extractor;

    public function __construct()
    {
        $this->extractor = new TarballExtractor();
    }

    /**
     * Write a node to its location on disk.
     *
     * @param Node $node The node to write
     * @param string $tarballContent The tarball content
     */
    public function writeNode(Node $node, string $tarballContent): void
    {
        $destination = $node->getRealpath();

        // Create parent directory if needed
        $parentDir = dirname($destination);
        if (!is_dir($parentDir)) {
            mkdir($parentDir, 0755, true);
        }

        // Remove existing directory
        if (is_dir($destination)) {
            $this->removeDirectory($destination);
        }

        // Extract tarball
        $this->extractor->extractFromContent($tarballContent, $destination);
    }

    /**
     * Remove a node from disk.
     */
    public function removeNode(Node $node): void
    {
        $path = $node->getRealpath();

        if (is_dir($path)) {
            $this->removeDirectory($path);
        }
    }

    /**
     * Ensure the node_modules directory exists.
     */
    public function ensureNodeModulesDir(string $rootPath): void
    {
        $nodeModules = $rootPath . '/node_modules';

        if (!is_dir($nodeModules)) {
            mkdir($nodeModules, 0755, true);
        }
    }

    /**
     * Create the directory structure for a node.
     */
    public function createNodeDirectory(Node $node): void
    {
        $path = $node->getRealpath();

        if (!is_dir($path)) {
            mkdir($path, 0755, true);
        }
    }

    /**
     * Write a .package-lock.json file in node_modules.
     * This is the hidden lockfile npm writes.
     */
    public function writeHiddenLockfile(string $rootPath, array $packages): void
    {
        $lockPath = $rootPath . '/node_modules/.package-lock.json';

        $data = [
            'name' => basename($rootPath),
            'lockfileVersion' => 3,
            'requires' => true,
            'packages' => $packages,
        ];

        $json = json_encode(
            $data,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
        );

        file_put_contents($lockPath, $json . "\n");
    }

    /**
     * Create a .bin directory with executables.
     */
    public function createBinLinks(Node $node): void
    {
        $packageJson = $node->getPackageJson();
        $bin = $packageJson['bin'] ?? null;

        if ($bin === null) {
            return;
        }

        // Normalize bin to array format
        if (is_string($bin)) {
            $name = $node->getName();
            if (str_contains($name, '/')) {
                $name = explode('/', $name)[1];
            }
            $bin = [$name => $bin];
        }

        // Find the root .bin directory
        $root = $node->getRoot();
        if ($root === null) {
            return;
        }

        $binDir = $root->getRealpath() . '/node_modules/.bin';

        if (!is_dir($binDir)) {
            mkdir($binDir, 0755, true);
        }

        // Create symlinks or shims for each binary
        foreach ($bin as $name => $path) {
            $targetPath = $node->getRealpath() . '/' . $path;
            $linkPath = $binDir . '/' . $name;

            if (file_exists($targetPath)) {
                // Remove existing link
                if (file_exists($linkPath) || is_link($linkPath)) {
                    unlink($linkPath);
                }

                // Create symlink
                $relativePath = $this->getRelativePath($binDir, $targetPath);
                symlink($relativePath, $linkPath);

                // Make target executable
                chmod($targetPath, 0755);
            }
        }
    }

    /**
     * Remove .bin links for a node.
     */
    public function removeBinLinks(Node $node): void
    {
        $packageJson = $node->getPackageJson();
        $bin = $packageJson['bin'] ?? null;

        if ($bin === null) {
            return;
        }

        if (is_string($bin)) {
            $name = $node->getName();
            if (str_contains($name, '/')) {
                $name = explode('/', $name)[1];
            }
            $bin = [$name => $bin];
        }

        $root = $node->getRoot();
        if ($root === null) {
            return;
        }

        $binDir = $root->getRealpath() . '/node_modules/.bin';

        foreach ($bin as $name => $path) {
            $linkPath = $binDir . '/' . $name;

            if (file_exists($linkPath) || is_link($linkPath)) {
                unlink($linkPath);
            }
        }
    }

    /**
     * Get relative path from one directory to another.
     */
    private function getRelativePath(string $from, string $to): string
    {
        $from = explode('/', rtrim($from, '/'));
        $to = explode('/', $to);

        // Find common base
        $common = 0;
        while (isset($from[$common]) && isset($to[$common]) && $from[$common] === $to[$common]) {
            $common++;
        }

        // Build relative path
        $up = count($from) - $common;
        $down = array_slice($to, $common);

        $path = str_repeat('../', $up) . implode('/', $down);

        return $path ?: '.';
    }

    /**
     * Recursively remove a directory.
     */
    private function removeDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        $entries = scandir($dir);

        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $path = $dir . '/' . $entry;

            if (is_link($path)) {
                unlink($path);
            } elseif (is_dir($path)) {
                $this->removeDirectory($path);
            } else {
                unlink($path);
            }
        }

        rmdir($dir);
    }

    /**
     * Check if a node exists on disk.
     */
    public function nodeExists(Node $node): bool
    {
        $path = $node->getRealpath();
        return is_dir($path) && file_exists($path . '/package.json');
    }

    /**
     * Get installed version of a node.
     */
    public function getInstalledVersion(Node $node): ?string
    {
        $pkgJsonPath = $node->getRealpath() . '/package.json';

        if (!file_exists($pkgJsonPath)) {
            return null;
        }

        try {
            $content = file_get_contents($pkgJsonPath);
            $data = json_decode($content, true, 512, JSON_THROW_ON_ERROR);
            return $data['version'] ?? null;
        } catch (\JsonException $e) {
            return null;
        }
    }
}
