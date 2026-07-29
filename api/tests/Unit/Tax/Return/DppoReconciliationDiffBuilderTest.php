<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Tax\Return;

use MyInvoice\Service\Tax\Return\DppoReconciliationDiffBuilder;
use PHPUnit\Framework\TestCase;

/**
 * Featura A — diff „náš výpočet vs. podané přiznání". Čistá logika bez DB/XML;
 * pokrývá shodu, rozdíl nad toleranci, toleranci 1 Kč, chybějící atribut v XML (= 0)
 * a sledování řádku s největším rozdílem (typicky ten, kde je vada).
 */
final class DppoReconciliationDiffBuilderTest extends TestCase
{
    private function line(int $line, float $value): array
    {
        return ['line' => $line, 'code' => (string) $line, 'label' => 'Řádek ' . $line, 'value' => $value, 'source' => 'test'];
    }

    public function testAllMatchWhenValuesEqual(): void
    {
        $ourLines = [$this->line(10, 500000.0), $this->line(200, 580000.0)];
        $filed = [10 => 500000.0, 200 => 580000.0];

        $diff = (new DppoReconciliationDiffBuilder())->build($ourLines, $filed);

        self::assertSame(2, $diff['matched']);
        self::assertSame(0, $diff['mismatched']);
        self::assertSame(0.0, $diff['max_abs_diff']);
        self::assertNull($diff['max_abs_diff_line']);
        foreach ($diff['rows'] as $row) {
            self::assertTrue($row['match']);
            self::assertSame(0.0, $row['diff']);
        }
    }

    public function testDetectsMismatchAndTracksMaxDiffLine(): void
    {
        $ourLines = [$this->line(10, 500000.0), $this->line(200, 580000.0), $this->line(290, 70560.0)];
        $filed = [10 => 500000.0, 200 => 536838.0, 290 => 70560.0]; // reálný nález: 95 100 vs 536 838 typu rozdílu na ř.200

        $diff = (new DppoReconciliationDiffBuilder())->build($ourLines, $filed);

        self::assertSame(2, $diff['matched']);
        self::assertSame(1, $diff['mismatched']);
        self::assertSame(200, $diff['max_abs_diff_line']);
        self::assertEqualsWithDelta(43162.0, $diff['max_abs_diff'], 0.01);

        $row200 = self::rowFor($diff['rows'], 200);
        self::assertFalse($row200['match']);
        self::assertSame(580000.0, $row200['our_value']);
        self::assertSame(536838.0, $row200['filed_value']);
        self::assertEqualsWithDelta(43162.0, $row200['diff'], 0.01);
    }

    public function testWithinOneCzkToleranceCountsAsMatch(): void
    {
        $ourLines = [$this->line(340, 100000.5)];
        $filed = [340 => 100000.0]; // 0.5 Kč rozdíl — zaokrouhlovací šum, ne vada

        $diff = (new DppoReconciliationDiffBuilder())->build($ourLines, $filed);

        self::assertSame(1, $diff['matched']);
        self::assertTrue($diff['rows'][0]['match']);
    }

    public function testMissingFiledAttributeTreatedAsZeroNotPresent(): void
    {
        $ourLines = [$this->line(62, 5000.0)];
        $filed = []; // atribut v podaném XML chybí (EPO konvence: nulové se nevypisují)

        $diff = (new DppoReconciliationDiffBuilder())->build($ourLines, $filed);

        $row = $diff['rows'][0];
        self::assertFalse($row['filed_present']);
        self::assertSame(0.0, $row['filed_value']);
        self::assertSame(5000.0, $row['diff']);
        self::assertFalse($row['match']);
    }

    /** @param list<array<string,mixed>> $rows */
    private static function rowFor(array $rows, int $line): array
    {
        foreach ($rows as $r) {
            if ($r['line'] === $line) {
                return $r;
            }
        }
        throw new \RuntimeException("Řádek $line nenalezen");
    }
}
