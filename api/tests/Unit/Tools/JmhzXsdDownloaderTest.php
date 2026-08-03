<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Tools;

use MyInvoice\Tooling\JmhzXsdDownloader;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use ZipArchive;

require_once dirname(__DIR__, 4) . '/tools/JmhzXsdDownloader.php';

final class JmhzXsdDownloaderTest extends TestCase
{
    private string $tempDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tempDir = sys_get_temp_dir() . '/myucto-jmhz-test-' . bin2hex(random_bytes(8));
        self::assertTrue(mkdir($this->tempDir, 0777, true));
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->tempDir);
        parent::tearDown();
    }

    public function testOfficialPackageManifestIsCompleteAndPinned(): void
    {
        $packages = $this->officialPackages();

        self::assertSame(
            [
                'jmhz' => ['1.4.3.4', 'f189885a'],
                'regzec' => ['1.4.0.4', '0d0396fd'],
                'prezec' => ['1.2', 'dda370c1'],
                'regzeldopl' => ['1.2', '6f0eb190'],
                'dzmh' => ['1.1', '1e89ec55'],
                'orezam-zrezam' => ['1.0', '9a153012'],
            ],
            array_map(
                static fn (array $package): array => [
                    $package['version'],
                    substr($package['sha256'], 0, 8),
                ],
                $packages,
            ),
        );

        foreach ($packages as $id => $package) {
            self::assertSame($id . '-' . $package['version'], $package['target']);
            self::assertMatchesRegularExpression('/\A[a-f0-9]{64}\z/', $package['sha256']);
            self::assertStringStartsWith('https://developers.mpsv.cz/assets/documents/', $package['url']);
        }
    }

    public function testCheckedInPackageTreeContainsOnlyWellFormedXsdFiles(): void
    {
        $root = dirname(__DIR__, 4) . '/api/xsd/jmhz';
        $expectedCounts = [
            'jmhz-1.4.3.4' => 14,
            'regzec-1.4.0.4' => 2,
            'prezec-1.2' => 2,
            'regzeldopl-1.2' => 2,
            'dzmh-1.1' => 2,
            'orezam-zrezam-1.0' => 3,
        ];

        foreach ($expectedCounts as $relative => $expectedCount) {
            $files = glob($root . '/' . $relative . '/*.xsd') ?: [];
            self::assertCount($expectedCount, $files, "Unexpected XSD count in {$relative}.");
        }

        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS),
        );
        foreach ($items as $item) {
            self::assertInstanceOf(\SplFileInfo::class, $item);
            if (strtolower($item->getExtension()) === 'md') {
                continue;
            }
            self::assertSame('xsd', strtolower($item->getExtension()), $item->getPathname());
            $document = new \DOMDocument();
            self::assertTrue($document->load($item->getPathname(), LIBXML_NONET), $item->getPathname());
        }
    }

    public function testInstallExtractsOnlyXsdAndPreservesOlderVersions(): void
    {
        $archive = $this->createArchive([
            'official-package/schema/main.xsd' => $this->schema('main'),
            'official-package/schema/base.xsd' => $this->schema('base'),
            'official-package/schema/utf16.xsd' => "\xFF\xFE"
                . mb_convert_encoding($this->schema('utf16'), 'UTF-16LE', 'UTF-8'),
            'official-package/sample.xml' => '<real-data-must-not-be-installed/>',
            'official-package/readme.txt' => 'documentation',
        ]);
        $target = $this->tempDir . '/jmhz';
        self::assertTrue(mkdir($target . '/legacy/1.0', 0777, true));
        self::assertSame(6, file_put_contents($target . '/legacy/1.0/old.xsd', 'legacy'));
        self::assertSame(8, file_put_contents($target . '/README.md', 'metadata'));

        $downloader = new JmhzXsdDownloader([
            'sample' => [
                'target' => 'sample-9.9',
                'version' => '9.9',
                'url' => 'https://example.test/sample.zip',
                'sha256' => $this->hash($archive),
            ],
        ]);
        $downloader->installFromArchives(['sample' => $archive], $target);

        self::assertFileExists($target . '/sample-9.9/schema/main.xsd');
        self::assertFileExists($target . '/sample-9.9/schema/base.xsd');
        self::assertFileExists($target . '/sample-9.9/schema/utf16.xsd');
        self::assertFileDoesNotExist($target . '/sample-9.9/sample.xml');
        self::assertFileDoesNotExist($target . '/sample-9.9/readme.txt');
        self::assertSame('legacy', file_get_contents($target . '/legacy/1.0/old.xsd'));
        self::assertSame('metadata', file_get_contents($target . '/README.md'));
    }

    public function testHashMismatchLeavesExistingTreeUntouched(): void
    {
        $archive = $this->createArchive([
            'schema.xsd' => $this->schema('schema'),
        ]);
        $target = $this->tempDir . '/jmhz';
        self::assertTrue(mkdir($target, 0777, true));
        self::assertSame(8, file_put_contents($target . '/keep.xsd', 'original'));
        $downloader = new JmhzXsdDownloader([
            'sample' => [
                'target' => 'sample-1.0',
                'version' => '1.0',
                'url' => 'https://example.test/sample.zip',
                'sha256' => str_repeat('0', 64),
            ],
        ]);

        try {
            $downloader->installFromArchives(['sample' => $archive], $target);
            self::fail('A package with an unexpected hash must be rejected.');
        } catch (RuntimeException $e) {
            self::assertStringContainsString('SHA-256', $e->getMessage());
        }

        self::assertSame('original', file_get_contents($target . '/keep.xsd'));
        self::assertDirectoryDoesNotExist($target . '/sample');
    }

    public function testUnsafeXsdPathIsRejectedBeforeTargetReplacement(): void
    {
        $archive = $this->createArchive([
            '../escape.xsd' => $this->schema('escape'),
        ]);
        $target = $this->tempDir . '/jmhz';
        self::assertTrue(mkdir($target, 0777, true));
        self::assertSame(8, file_put_contents($target . '/keep.xsd', 'original'));
        $downloader = new JmhzXsdDownloader([
            'sample' => [
                'target' => 'sample-1.0',
                'version' => '1.0',
                'url' => 'https://example.test/sample.zip',
                'sha256' => $this->hash($archive),
            ],
        ]);

        try {
            $downloader->installFromArchives(['sample' => $archive], $target);
            self::fail('A path-traversal archive entry must be rejected.');
        } catch (RuntimeException $e) {
            self::assertStringContainsString('unsafe path', $e->getMessage());
        }

        self::assertSame('original', file_get_contents($target . '/keep.xsd'));
        self::assertFileDoesNotExist($this->tempDir . '/escape.xsd');
    }

    /** @param array<string,string> $entries */
    private function createArchive(array $entries): string
    {
        $path = $this->tempDir . '/' . bin2hex(random_bytes(6)) . '.zip';
        $zip = new ZipArchive();
        self::assertTrue($zip->open($path, ZipArchive::CREATE | ZipArchive::EXCL));
        foreach ($entries as $name => $contents) {
            self::assertTrue($zip->addFromString($name, $contents));
        }
        self::assertTrue($zip->close());

        return $path;
    }

    private function schema(string $element): string
    {
        return '<?xml version="1.0" encoding="UTF-8"?>'
            . '<xs:schema xmlns:xs="http://www.w3.org/2001/XMLSchema">'
            . '<xs:element name="' . $element . '" type="xs:string"/>'
            . '</xs:schema>';
    }

    /**
     * @return array<string,array{target:string,version:string,url:string,sha256:string}>
     */
    private function officialPackages(): array
    {
        $packages = require dirname(__DIR__, 4) . '/tools/jmhz-xsd-packages.php';
        self::assertIsArray($packages);

        $validated = [];
        foreach ($packages as $id => $package) {
            self::assertIsString($id);
            self::assertIsArray($package);
            $validated[$id] = [
                'target' => $this->stringField($package, 'target'),
                'version' => $this->stringField($package, 'version'),
                'url' => $this->stringField($package, 'url'),
                'sha256' => $this->stringField($package, 'sha256'),
            ];
        }

        return $validated;
    }

    /** @param array<mixed> $values */
    private function stringField(array $values, string $field): string
    {
        self::assertArrayHasKey($field, $values);
        self::assertIsString($values[$field]);

        return $values[$field];
    }

    private function hash(string $path): string
    {
        $hash = hash_file('sha256', $path);
        self::assertIsString($hash);

        return $hash;
    }

    private function removeDirectory(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }

        $items = new \FilesystemIterator($path, \FilesystemIterator::SKIP_DOTS);
        foreach ($items as $item) {
            self::assertInstanceOf(\SplFileInfo::class, $item);
            if ($item->isDir() && !$item->isLink()) {
                $this->removeDirectory($item->getPathname());
            } else {
                unlink($item->getPathname());
            }
        }
        rmdir($path);
    }
}
