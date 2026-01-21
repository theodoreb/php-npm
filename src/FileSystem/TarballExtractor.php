<?php

declare(strict_types=1);

namespace PhpNpm\FileSystem;

use PharData;

/**
 * Extracts npm tarballs (.tgz files) to the filesystem.
 */
class TarballExtractor
{
    private const DIR_MODE = 0755;
    private const FILE_MODE = 0644;

    /**
     * Extract a tarball to a destination directory.
     *
     * @param string $tarballPath Path to the .tgz file
     * @param string $destination Destination directory
     * @param bool $stripPackageDir Strip the leading "package/" directory
     * @throws \RuntimeException If extraction fails
     */
    public function extract(string $tarballPath, string $destination, bool $stripPackageDir = true): void
    {
        if (!file_exists($tarballPath)) {
            throw new \RuntimeException("Tarball not found: {$tarballPath}");
        }

        // Create destination directory
        if (!is_dir($destination)) {
            if (!mkdir($destination, self::DIR_MODE, true)) {
                throw new \RuntimeException("Could not create directory: {$destination}");
            }
        }

        // Extract using PharData
        try {
            // Create temporary directory for extraction
            $tempDir = sys_get_temp_dir() . '/php-npm-extract-' . uniqid();
            mkdir($tempDir, self::DIR_MODE, true);

            // PharData expects .tar.gz or .tgz extension
            $phar = new PharData($tarballPath);
            $phar->extractTo($tempDir, null, true);

            // Find the package directory (usually "package/")
            $extractedDir = $tempDir;
            if ($stripPackageDir) {
                $packageDir = $this->findPackageDir($tempDir);
                if ($packageDir !== null) {
                    $extractedDir = $packageDir;
                }
            }

            // Move contents to destination
            $this->moveContents($extractedDir, $destination);

            // Clean up temp directory
            $this->removeDirectory($tempDir);

        } catch (\Exception $e) {
            throw new \RuntimeException(
                "Failed to extract tarball: " . $e->getMessage(),
                0,
                $e
            );
        }
    }

    /**
     * Extract tarball from content (in-memory).
     *
     * @param string $content Tarball content
     * @param string $destination Destination directory
     * @param bool $stripPackageDir Strip the leading "package/" directory
     */
    public function extractFromContent(string $content, string $destination, bool $stripPackageDir = true): void
    {
        // Write to temp file
        $tempFile = sys_get_temp_dir() . '/php-npm-tarball-' . uniqid() . '.tgz';

        try {
            if (file_put_contents($tempFile, $content) === false) {
                throw new \RuntimeException("Could not write temporary tarball file");
            }

            $this->extract($tempFile, $destination, $stripPackageDir);
        } finally {
            if (file_exists($tempFile)) {
                unlink($tempFile);
            }
        }
    }

    /**
     * Find the package directory inside extracted tarball.
     * npm tarballs typically have a "package/" root directory.
     */
    private function findPackageDir(string $dir): ?string
    {
        $entries = scandir($dir);

        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $path = $dir . '/' . $entry;

            if (is_dir($path)) {
                // Check if this looks like the package root
                if ($entry === 'package' || file_exists($path . '/package.json')) {
                    return $path;
                }
            }
        }

        // If only one directory exists, assume it's the package root
        $dirs = array_filter($entries, function ($e) use ($dir) {
            return $e !== '.' && $e !== '..' && is_dir($dir . '/' . $e);
        });

        if (count($dirs) === 1) {
            return $dir . '/' . reset($dirs);
        }

        return null;
    }

    /**
     * Move contents from source to destination.
     */
    private function moveContents(string $source, string $destination): void
    {
        $entries = scandir($source);

        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $srcPath = $source . '/' . $entry;
            $dstPath = $destination . '/' . $entry;

            if (is_dir($srcPath)) {
                if (!is_dir($dstPath)) {
                    mkdir($dstPath, self::DIR_MODE, true);
                }
                $this->moveContents($srcPath, $dstPath);
            } else {
                // Copy file and set permissions
                copy($srcPath, $dstPath);
                chmod($dstPath, self::FILE_MODE);
            }
        }
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

            if (is_dir($path)) {
                $this->removeDirectory($path);
            } else {
                unlink($path);
            }
        }

        rmdir($dir);
    }

    /**
     * List contents of a tarball without extracting.
     *
     * @return string[] List of file paths
     */
    public function listContents(string $tarballPath): array
    {
        if (!file_exists($tarballPath)) {
            throw new \RuntimeException("Tarball not found: {$tarballPath}");
        }

        $contents = [];
        $phar = new PharData($tarballPath);

        foreach (new \RecursiveIteratorIterator($phar) as $file) {
            $contents[] = $file->getPathname();
        }

        return $contents;
    }

    /**
     * Get package.json from tarball without fully extracting.
     */
    public function getPackageJson(string $tarballPath): ?array
    {
        if (!file_exists($tarballPath)) {
            throw new \RuntimeException("Tarball not found: {$tarballPath}");
        }

        try {
            $phar = new PharData($tarballPath);

            // Try common paths
            $paths = ['package/package.json', 'package.json'];

            foreach ($paths as $path) {
                if (isset($phar[$path])) {
                    $content = $phar[$path]->getContent();
                    return json_decode($content, true, 512, JSON_THROW_ON_ERROR);
                }
            }

            // Search for package.json
            foreach (new \RecursiveIteratorIterator($phar) as $file) {
                if (basename($file->getPathname()) === 'package.json') {
                    $content = $file->getContent();
                    return json_decode($content, true, 512, JSON_THROW_ON_ERROR);
                }
            }

            return null;

        } catch (\Exception $e) {
            throw new \RuntimeException(
                "Failed to read package.json from tarball: " . $e->getMessage()
            );
        }
    }

    /**
     * Validate that a tarball is properly formed.
     */
    public function validate(string $tarballPath): bool
    {
        try {
            $phar = new PharData($tarballPath);

            // Check for package.json
            $hasPackageJson = false;
            foreach (new \RecursiveIteratorIterator($phar) as $file) {
                if (basename($file->getPathname()) === 'package.json') {
                    $hasPackageJson = true;
                    break;
                }
            }

            return $hasPackageJson;
        } catch (\Exception $e) {
            return false;
        }
    }
}
