<?php

declare(strict_types=1);

namespace PhpNpm\Arborist;

use PhpNpm\Dependency\Node;
use PhpNpm\Dependency\Inventory;
use PhpNpm\Registry\Pacote;
use PhpNpm\FileSystem\NodeModulesWriter;
use PhpNpm\Integrity\IntegrityChecker;
use PhpNpm\Exception\IntegrityException;

/**
 * Reifies the ideal tree to disk.
 * Downloads packages and writes them to node_modules.
 */
class Reifier
{
    private string $rootPath;
    private Pacote $pacote;
    private NodeModulesWriter $writer;
    private IntegrityChecker $integrity;

    /** @var callable|null */
    private $onProgress;

    private int $processed = 0;
    private int $total = 0;

    public function __construct(string $rootPath, ?Pacote $pacote = null)
    {
        $this->rootPath = rtrim($rootPath, '/');
        $this->pacote = $pacote ?? new Pacote();
        $this->writer = new NodeModulesWriter();
        $this->integrity = new IntegrityChecker();
    }

    /**
     * Set progress callback.
     * @param callable(string, int, int): void $callback
     */
    public function onProgress(callable $callback): void
    {
        $this->onProgress = $callback;
    }

    /**
     * Reify the ideal tree to disk.
     *
     * @param Node $idealTree The ideal tree to reify
     * @param array|null $diff Pre-calculated diff (optional)
     * @param array $options Reification options
     */
    public function reify(Node $idealTree, ?array $diff = null, array $options = []): void
    {
        // Ensure node_modules directory exists
        $this->writer->ensureNodeModulesDir($this->rootPath);

        // Calculate what needs to be done
        if ($diff === null) {
            $diff = $this->calculateFullDiff($idealTree);
        }

        $toRemove = $diff['remove'] ?? [];
        $toAdd = $diff['add'] ?? [];
        $toUpdate = $diff['update'] ?? [];

        // Extract nodes to install from updates
        $nodesToInstall = array_merge(
            $toAdd,
            array_map(fn($u) => $u['to'], $toUpdate)
        );

        $this->total = count($toRemove) + count($toAdd) + count($toUpdate);
        $this->processed = 0;

        // Phase 1: Remove packages
        foreach ($toRemove as $node) {
            $this->progress("Removing {$node->getName()}@{$node->getVersion()}");
            $this->removePackage($node);
            $this->processed++;
        }

        // Phase 1b: Remove old versions for updates
        foreach ($toUpdate as $update) {
            $from = $update['from'];
            $this->removePackage($from);
        }

        // Phase 2: Download all tarballs in parallel
        if (!empty($nodesToInstall)) {
            $this->progress("Downloading " . count($nodesToInstall) . " packages in parallel...");
            $tarballs = $this->downloadTarballsParallel($nodesToInstall);

            // Phase 3: Install packages (write to disk + verify)
            foreach ($nodesToInstall as $node) {
                $nodeId = $this->getNodeId($node);
                $this->progress("Installing {$node->getName()}@{$node->getVersion()}");

                if (!isset($tarballs[$nodeId])) {
                    throw new \RuntimeException(
                        "Missing tarball for {$node->getName()}@{$node->getVersion()}"
                    );
                }

                $this->installPackageFromTarball($node, $tarballs[$nodeId]);
                $this->processed++;
            }
        }

        // Phase 4: Create bin links
        $this->createAllBinLinks($idealTree);

        $this->progress("Done");
    }

    /**
     * Download tarballs for multiple nodes in parallel.
     *
     * @param Node[] $nodes Nodes to download
     * @return array<string, string> Map of node ID to tarball content
     */
    private function downloadTarballsParallel(array $nodes): array
    {
        $urlMap = [];

        foreach ($nodes as $node) {
            $resolved = $node->getResolved();

            // Get tarball URL if not set
            if ($resolved === null) {
                $resolved = $this->pacote->tarball($node->getName(), $node->getVersion());
            }

            $nodeId = $this->getNodeId($node);
            $urlMap[$nodeId] = $resolved;
        }

        // Fetch all tarballs in parallel
        return $this->pacote->getClient()->fetchTarballsParallel($urlMap);
    }

    /**
     * Get unique identifier for a node.
     */
    private function getNodeId(Node $node): string
    {
        return $node->getName() . '@' . $node->getVersion() . '@' . $node->getLocation();
    }

    /**
     * Install a package from already-downloaded tarball content.
     */
    private function installPackageFromTarball(Node $node, string $tarballContent): void
    {
        $name = $node->getName();
        $version = $node->getVersion();
        $integrity = $node->getIntegrity();

        // Verify integrity if available
        if ($integrity !== null) {
            try {
                $this->integrity->verifyOrThrow(
                    $tarballContent,
                    $integrity,
                    "{$name}@{$version}"
                );
            } catch (IntegrityException $e) {
                throw new \RuntimeException(
                    "Integrity check failed for {$name}@{$version}: " . $e->getMessage()
                );
            }
        }

        // Write to disk
        $this->writer->writeNode($node, $tarballContent);
    }

    /**
     * Calculate full diff (for clean install).
     *
     * @return array{add: Node[], remove: Node[], update: array}
     */
    private function calculateFullDiff(Node $idealTree): array
    {
        $add = [];

        $this->collectNodesToInstall($idealTree, $add);

        return [
            'add' => $add,
            'remove' => [],
            'update' => [],
        ];
    }

    /**
     * Collect all nodes that need to be installed.
     *
     * @param Node[] $add
     */
    private function collectNodesToInstall(Node $node, array &$add): void
    {
        foreach ($node->getChildren() as $child) {
            // Only add if not already on disk with correct version
            if (!$this->writer->nodeExists($child) ||
                $this->writer->getInstalledVersion($child) !== $child->getVersion()) {
                $add[] = $child;
            }

            $this->collectNodesToInstall($child, $add);
        }
    }

    /**
     * Install a single package.
     */
    private function installPackage(Node $node): void
    {
        $name = $node->getName();
        $version = $node->getVersion();
        $resolved = $node->getResolved();
        $integrity = $node->getIntegrity();

        // Get tarball URL
        if ($resolved === null) {
            $resolved = $this->pacote->tarball($name, $version);
        }

        // Download tarball
        $tarballContent = $this->pacote->getClient()->fetchTarball($resolved);

        // Verify integrity if available
        if ($integrity !== null) {
            try {
                $this->integrity->verifyOrThrow(
                    $tarballContent,
                    $integrity,
                    "{$name}@{$version}"
                );
            } catch (IntegrityException $e) {
                throw new \RuntimeException(
                    "Integrity check failed for {$name}@{$version}: " . $e->getMessage()
                );
            }
        }

        // Write to disk
        $this->writer->writeNode($node, $tarballContent);
    }

    /**
     * Remove a package from disk.
     */
    private function removePackage(Node $node): void
    {
        // Remove bin links first
        $this->writer->removeBinLinks($node);

        // Remove the package directory
        $this->writer->removeNode($node);
    }

    /**
     * Create bin links for all packages.
     */
    private function createAllBinLinks(Node $node): void
    {
        foreach ($node->getChildren() as $child) {
            $this->writer->createBinLinks($child);
            $this->createAllBinLinks($child);
        }
    }

    /**
     * Verify integrity of installed packages.
     *
     * @return array List of packages with integrity issues
     */
    public function verify(Node $tree): array
    {
        $issues = [];

        $this->verifyNode($tree, $issues);

        return $issues;
    }

    /**
     * Recursively verify nodes.
     */
    private function verifyNode(Node $node, array &$issues): void
    {
        foreach ($node->getChildren() as $child) {
            if (!$this->writer->nodeExists($child)) {
                $issues[] = [
                    'type' => 'missing',
                    'name' => $child->getName(),
                    'version' => $child->getVersion(),
                    'location' => $child->getLocation(),
                ];
                continue;
            }

            $installedVersion = $this->writer->getInstalledVersion($child);
            if ($installedVersion !== $child->getVersion()) {
                $issues[] = [
                    'type' => 'version_mismatch',
                    'name' => $child->getName(),
                    'expected' => $child->getVersion(),
                    'installed' => $installedVersion,
                    'location' => $child->getLocation(),
                ];
            }

            $this->verifyNode($child, $issues);
        }
    }

    /**
     * Report progress.
     */
    private function progress(string $message): void
    {
        if ($this->onProgress !== null) {
            ($this->onProgress)($message, $this->processed, $this->total);
        }
    }

    /**
     * Clean up empty directories in node_modules.
     */
    public function cleanup(): void
    {
        $nodeModules = $this->rootPath . '/node_modules';

        if (!is_dir($nodeModules)) {
            return;
        }

        $this->cleanEmptyDirs($nodeModules);
    }

    /**
     * Recursively clean empty directories.
     */
    private function cleanEmptyDirs(string $dir): bool
    {
        if (!is_dir($dir)) {
            return false;
        }

        $entries = scandir($dir);
        $empty = true;

        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $path = $dir . '/' . $entry;

            if (is_dir($path)) {
                if (!$this->cleanEmptyDirs($path)) {
                    $empty = false;
                }
            } else {
                $empty = false;
            }
        }

        if ($empty && $dir !== $this->rootPath . '/node_modules') {
            rmdir($dir);
            return true;
        }

        return $empty;
    }
}
