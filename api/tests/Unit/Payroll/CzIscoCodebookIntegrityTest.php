<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Payroll;

use MyInvoice\Service\Payroll\CzIscoCodebook;
use PHPUnit\Framework\TestCase;

/**
 * Číselník musí být v repozitáři **kompletní**, ne jen přítomný.
 *
 * Projekt už jednou dostal do stromu stažený soubor, ze kterého zbyla hlavička —
 * a poznalo se to až podle toho, že aplikace nic nenašla. Tenhle test proto
 * neověřuje existenci souboru, ale počet položek, jejich rozložení po úrovních,
 * otisky a soulad se `SHA256SUMS`.
 */
final class CzIscoCodebookIntegrityTest extends TestCase
{
    private const EXPECTED_CURRENT = 1992;
    private const EXPECTED_RETIRED = 7;

    /** @var array<int,int> */
    private const EXPECTED_BY_LEVEL = ['1' => 10, '2' => 43, '3' => 130, '4' => 434, '5' => 1375];

    private const SOURCE_SHA256 =
        '2f9327f942fc54f3b302003380429501bda94b6d9728502c6a4352bd9d126ad5';
    private const SOURCE_BYTES = 278999;

    public function testManifestHasExpectedNumberOfEntries(): void
    {
        $payload = $this->payload();

        self::assertSame(self::EXPECTED_CURRENT, count($payload['current']));
        self::assertSame(self::EXPECTED_RETIRED, count($payload['retired']));
        self::assertSame(self::EXPECTED_CURRENT, $payload['counts']['current']);
        self::assertSame(self::EXPECTED_RETIRED, $payload['counts']['retired']);
        self::assertSame(self::EXPECTED_BY_LEVEL, $payload['counts']['current_by_level']);
        self::assertSame(
            self::EXPECTED_CURRENT,
            array_sum(self::EXPECTED_BY_LEVEL),
            'Součet úrovní se musí rovnat počtu položek.',
        );
    }

    public function testManifestHashMatchesPinnedValue(): void
    {
        $manifest = $this->manifest();

        self::assertSame(CzIscoCodebook::DEFAULT_MANIFEST_SHA256, $manifest['manifest_sha256']);
        // Nehodí výjimku jen tehdy, když sedí manifest_sha256, content_hash,
        // počty, hierarchie i identita balíčku.
        CzIscoCodebook::validateManifest($manifest, true);
    }

    public function testSourceSpreadsheetMatchesPinnedHashAndSize(): void
    {
        $path = $this->resourceRoot()
            . '/classification-2026-02-01/klasifikace_zamestnani_systematicka_cast_2026_02_01.xlsx';

        self::assertFileExists($path);
        self::assertSame(self::SOURCE_BYTES, filesize($path));
        self::assertSame(self::SOURCE_SHA256, hash_file('sha256', $path));
    }

    public function testSha256sumsCoversEveryPinnedFile(): void
    {
        $root = $this->resourceRoot();
        $lines = file($root . '/SHA256SUMS', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        self::assertIsArray($lines);
        self::assertNotSame([], $lines);

        $seen = [];
        foreach ($lines as $line) {
            self::assertSame(1, preg_match('/\A([0-9a-f]{64})\s+(\S.*)\z/D', $line, $m), $line);
            $path = $root . '/' . str_replace('\\', '/', $m[2]);
            self::assertFileExists($path);
            self::assertSame($m[1], hash_file('sha256', $path), "Otisk {$m[2]} nesedí.");
            $seen[basename($m[2])] = true;
        }
        self::assertArrayHasKey('manifest.json', $seen);
        self::assertArrayHasKey('klasifikace_zamestnani_systematicka_cast_2026_02_01.xlsx', $seen);
    }

    public function testTamperedManifestIsRejected(): void
    {
        $manifest = $this->manifest();
        array_pop($manifest['payload']['current']);

        $this->expectException(\UnexpectedValueException::class);
        CzIscoCodebook::validateManifest($manifest);
    }

    public function testEmptyCodebookIsRejectedInsteadOfSilentlyAcceptingNothing(): void
    {
        $manifest = $this->manifest();
        $manifest['payload']['current'] = [];
        $manifest['payload']['retired'] = [];

        $this->expectException(\UnexpectedValueException::class);
        CzIscoCodebook::validateManifest($manifest);
    }

    public function testMissingManifestFailsLoudly(): void
    {
        $codebook = new CzIscoCodebook(sys_get_temp_dir() . '/cz-isco-neexistuje');

        $this->expectException(\RuntimeException::class);
        $codebook->status('43111');
    }

    /** @return array{manifest_sha256:string,payload:array<string,mixed>} */
    private function manifest(): array
    {
        $json = file_get_contents(
            $this->resourceRoot() . '/classification-2026-02-01/manifest.json',
        );
        self::assertIsString($json);
        $decoded = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);
        self::assertIsString($decoded['manifest_sha256'] ?? null);
        self::assertIsArray($decoded['payload'] ?? null);

        /** @var array{manifest_sha256:string,payload:array<string,mixed>} $decoded */
        return $decoded;
    }

    /** @return array<string,mixed> */
    private function payload(): array
    {
        return $this->manifest()['payload'];
    }

    private function resourceRoot(): string
    {
        return dirname(__DIR__, 3) . '/resources/payroll/cz-isco';
    }
}
