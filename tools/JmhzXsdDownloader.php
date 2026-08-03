<?php

declare(strict_types=1);

namespace MyInvoice\Tooling;

use Closure;
use FilesystemIterator;
use RuntimeException;
use ZipArchive;

final class JmhzXsdDownloader
{
    /** @var array<string,array{target:string,version:string,url:string,sha256:string}> */
    private array $packages;
    private ?Closure $logger;

    /** @param array<mixed> $packages */
    public function __construct(array $packages, ?callable $logger = null)
    {
        $this->packages = $this->validateManifest($packages);
        $this->logger = $logger === null ? null : Closure::fromCallable($logger);
    }

    public function downloadAndInstall(string $targetRoot): void
    {
        $tempRoot = rtrim(sys_get_temp_dir(), '/\\')
            . DIRECTORY_SEPARATOR
            . 'myucto-jmhz-xsd-'
            . bin2hex(random_bytes(8));
        if (!mkdir($tempRoot, 0700, true)) {
            throw new RuntimeException("Cannot create temporary directory {$tempRoot}.");
        }

        try {
            $archives = [];
            foreach ($this->packages as $id => $package) {
                $archive = $tempRoot . DIRECTORY_SEPARATOR . $id . '.zip';
                $this->log("Downloading {$id} {$package['version']}...");
                $this->download($package['url'], $archive);
                $archives[$id] = $archive;
            }

            $this->installFromArchives($archives, $targetRoot);
        } finally {
            $this->removeDirectory($tempRoot);
        }
    }

    /** @param array<string,string> $archives */
    public function installFromArchives(array $archives, string $targetRoot): void
    {
        if (!class_exists(ZipArchive::class)) {
            throw new RuntimeException('The PHP zip extension is required to install JMHZ schemas.');
        }

        foreach ($this->packages as $id => $package) {
            $archive = $archives[$id] ?? null;
            if (!is_string($archive) || !is_file($archive)) {
                throw new RuntimeException("Archive for package {$id} is missing.");
            }
            $actualHash = hash_file('sha256', $archive);
            if (!is_string($actualHash) || !hash_equals($package['sha256'], strtolower($actualHash))) {
                throw new RuntimeException("SHA-256 mismatch for JMHZ package {$id}.");
            }
        }

        $parent = dirname($targetRoot);
        if (!is_dir($parent) && !mkdir($parent, 0777, true)) {
            throw new RuntimeException("Cannot create target parent {$parent}.");
        }
        if (file_exists($targetRoot) && !is_dir($targetRoot)) {
            throw new RuntimeException("JMHZ schema target {$targetRoot} is not a directory.");
        }

        $suffix = bin2hex(random_bytes(8));
        $stage = $parent . DIRECTORY_SEPARATOR . '.' . basename($targetRoot) . '.stage-' . $suffix;
        if (!mkdir($stage, 0777, true)) {
            throw new RuntimeException("Cannot create staging directory {$stage}.");
        }

        try {
            if (is_dir($targetRoot)) {
                $this->copyXsdTree($targetRoot, $stage);
            }

            foreach ($this->packages as $id => $package) {
                $versionTarget = $stage
                    . DIRECTORY_SEPARATOR
                    . $package['target'];
                if (is_dir($versionTarget)) {
                    $this->removeDirectory($versionTarget);
                }
                if (!mkdir($versionTarget, 0777, true)) {
                    throw new RuntimeException("Cannot create package target {$versionTarget}.");
                }

                $count = $this->extractXsd($archives[$id], $versionTarget);
                $this->log("Prepared {$id} {$package['version']}: {$count} XSD file(s).");
            }

            $this->replaceDirectory($stage, $targetRoot);
            $this->log("JMHZ schemas installed in {$targetRoot}.");
        } finally {
            if (is_dir($stage)) {
                $this->removeDirectory($stage);
            }
        }
    }

    /**
     * @param array<mixed> $packages
     * @return array<string,array{target:string,version:string,url:string,sha256:string}>
     */
    private function validateManifest(array $packages): array
    {
        if ($packages === []) {
            throw new RuntimeException('The JMHZ package manifest must not be empty.');
        }

        $targets = [];
        $validated = [];
        foreach ($packages as $id => $package) {
            if (!is_string($id) || !is_array($package)) {
                throw new RuntimeException('Invalid JMHZ package manifest entry.');
            }
            $target = $package['target'] ?? null;
            $version = $package['version'] ?? null;
            $sha256 = $package['sha256'] ?? null;
            $url = $package['url'] ?? null;
            if (
                preg_match('/\A[a-z0-9][a-z0-9-]*\z/D', $id) !== 1
                || !is_string($target)
                || preg_match('/\A[a-z0-9][a-z0-9.-]*\z/D', $target) !== 1
                || !is_string($version)
                || preg_match('/\A[0-9]+(?:\.[0-9]+)*\z/D', $version) !== 1
                || !is_string($sha256)
                || preg_match('/\A[a-f0-9]{64}\z/D', $sha256) !== 1
                || !is_string($url)
                || !str_starts_with($url, 'https://')
            ) {
                throw new RuntimeException("Invalid JMHZ package manifest entry {$id}.");
            }

            if (isset($targets[$target])) {
                throw new RuntimeException("Duplicate JMHZ package target {$target}.");
            }
            $targets[$target] = true;
            $validated[$id] = [
                'target' => $target,
                'version' => $version,
                'url' => $url,
                'sha256' => $sha256,
            ];
        }

        return $validated;
    }

    private function download(string $url, string $target): void
    {
        $context = stream_context_create([
            'http' => [
                'follow_location' => 1,
                'max_redirects' => 5,
                'timeout' => 60,
                'user_agent' => 'MyUcto JMHZ XSD downloader',
            ],
        ]);
        $input = @fopen($url, 'rb', false, $context);
        if ($input === false) {
            throw new RuntimeException("Cannot download {$url}.");
        }
        $output = @fopen($target, 'xb');
        if ($output === false) {
            fclose($input);
            throw new RuntimeException("Cannot create temporary archive {$target}.");
        }

        try {
            if (stream_copy_to_stream($input, $output) === false) {
                throw new RuntimeException("Download failed for {$url}.");
            }
        } finally {
            fclose($input);
            fclose($output);
        }
    }

    private function extractXsd(string $archive, string $target): int
    {
        $zip = new ZipArchive();
        if ($zip->open($archive) !== true) {
            throw new RuntimeException("Cannot open ZIP archive {$archive}.");
        }

        try {
            $entries = [];
            $totalSize = 0;
            for ($index = 0; $index < $zip->numFiles; $index++) {
                $stat = $zip->statIndex($index);
                if ($stat === false) {
                    throw new RuntimeException("Cannot inspect ZIP entry in {$archive}.");
                }
                $name = str_replace('\\', '/', (string) $stat['name']);
                if (str_ends_with($name, '/') || strtolower(pathinfo($name, PATHINFO_EXTENSION)) !== 'xsd') {
                    continue;
                }
                $this->assertSafeArchivePath($name);
                $totalSize += (int) $stat['size'];
                if ($totalSize > 50 * 1024 * 1024 || count($entries) >= 500) {
                    throw new RuntimeException("JMHZ archive {$archive} exceeds safe extraction limits.");
                }
                $entries[] = ['index' => $index, 'name' => $name];
            }

            if ($entries === []) {
                throw new RuntimeException("JMHZ archive {$archive} contains no XSD files.");
            }

            $prefix = $this->commonTopLevelDirectory(array_column($entries, 'name'));
            $destinations = [];
            foreach ($entries as $entry) {
                $relative = $prefix === '' ? $entry['name'] : substr($entry['name'], strlen($prefix) + 1);
                $key = strtolower($relative);
                if ($relative === '' || isset($destinations[$key])) {
                    throw new RuntimeException("Duplicate XSD path {$relative} in {$archive}.");
                }
                $destinations[$key] = true;

                $contents = $zip->getFromIndex($entry['index']);
                if (!is_string($contents) || trim($contents) === '') {
                    throw new RuntimeException("Cannot read XSD {$entry['name']} from {$archive}.");
                }
                if (!$this->isXsdDocument($contents)) {
                    throw new RuntimeException("ZIP entry {$entry['name']} is not an XSD document.");
                }

                $destination = $target . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative);
                $directory = dirname($destination);
                if (!is_dir($directory) && !mkdir($directory, 0777, true)) {
                    throw new RuntimeException("Cannot create XSD directory {$directory}.");
                }
                if (file_put_contents($destination, $contents, LOCK_EX) !== strlen($contents)) {
                    throw new RuntimeException("Cannot write XSD file {$destination}.");
                }
            }

            return count($entries);
        } finally {
            $zip->close();
        }
    }

    private function isXsdDocument(string $contents): bool
    {
        if (str_starts_with($contents, "\xFF\xFE")) {
            $contents = mb_convert_encoding(substr($contents, 2), 'UTF-8', 'UTF-16LE');
        } elseif (str_starts_with($contents, "\xFE\xFF")) {
            $contents = mb_convert_encoding(substr($contents, 2), 'UTF-8', 'UTF-16BE');
        }

        $start = substr(ltrim($contents, "\xEF\xBB\xBF \t\r\n"), 0, 4096);

        return preg_match('/<(?:(?:xs|xsd):)?schema(?:\s|>)/i', $start) === 1;
    }

    private function assertSafeArchivePath(string $path): void
    {
        if (
            str_contains($path, "\0")
            || str_starts_with($path, '/')
            || preg_match('/\A[A-Za-z]:\//', $path) === 1
            || in_array('..', explode('/', $path), true)
        ) {
            throw new RuntimeException("ZIP entry has an unsafe path: {$path}.");
        }
    }

    /** @param list<string> $paths */
    private function commonTopLevelDirectory(array $paths): string
    {
        $first = null;
        foreach ($paths as $path) {
            $parts = explode('/', $path);
            if (count($parts) < 2) {
                return '';
            }
            if ($first === null) {
                $first = $parts[0];
            } elseif ($first !== $parts[0]) {
                return '';
            }
        }

        return $first ?? '';
    }

    private function copyXsdTree(string $source, string $target): void
    {
        $items = new FilesystemIterator($source, FilesystemIterator::SKIP_DOTS);
        foreach ($items as $item) {
            if (!$item instanceof \SplFileInfo) {
                throw new RuntimeException("Cannot inspect JMHZ schema storage {$source}.");
            }
            if ($item->isLink()) {
                throw new RuntimeException("Symlinks are not allowed in JMHZ schema storage: {$item->getPathname()}.");
            }
            $destination = $target . DIRECTORY_SEPARATOR . $item->getFilename();
            if ($item->isDir()) {
                if (!mkdir($destination, 0777, true)) {
                    throw new RuntimeException("Cannot create staging directory {$destination}.");
                }
                $this->copyXsdTree($item->getPathname(), $destination);
            } elseif (
                in_array(strtolower($item->getExtension()), ['xsd', 'md'], true)
                && !copy($item->getPathname(), $destination)
            ) {
                throw new RuntimeException("Cannot copy existing schema metadata {$item->getPathname()}.");
            }
        }
    }

    private function replaceDirectory(string $stage, string $target): void
    {
        $backup = dirname($target)
            . DIRECTORY_SEPARATOR
            . '.'
            . basename($target)
            . '.backup-'
            . bin2hex(random_bytes(8));
        $hadTarget = is_dir($target);

        if ($hadTarget && !@rename($target, $backup)) {
            throw new RuntimeException("Cannot move current JMHZ schemas to {$backup}.");
        }

        if (!@rename($stage, $target)) {
            if ($hadTarget && !@rename($backup, $target)) {
                throw new RuntimeException("Cannot install JMHZ schemas and rollback also failed; backup is {$backup}.");
            }
            throw new RuntimeException("Cannot atomically install JMHZ schemas in {$target}.");
        }

        if ($hadTarget) {
            $this->removeDirectory($backup);
        }
    }

    private function removeDirectory(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }

        $items = new FilesystemIterator($path, FilesystemIterator::SKIP_DOTS);
        foreach ($items as $item) {
            if (!$item instanceof \SplFileInfo) {
                throw new RuntimeException("Cannot inspect temporary directory {$path}.");
            }
            if ($item->isDir() && !$item->isLink()) {
                $this->removeDirectory($item->getPathname());
            } else {
                unlink($item->getPathname());
            }
        }
        rmdir($path);
    }

    private function log(string $message): void
    {
        if ($this->logger !== null) {
            ($this->logger)($message);
        }
    }
}
