<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Oss;

use MyInvoice\Service\Oss\OssFilingSnapshot;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Rekonciliace OSS: porovnání archivovaného podání s dnešním náhledem.
 *
 * Testy míří na dva scénáře, u kterých by tichá shoda znamenala nedoplatek daně ve
 * státě spotřeby zjištěný až kontrolou: doklad opravený ZPĚTNĚ po podání a doklad,
 * který z období ZMIZEL (storno, přesun DUZP). Druhý případ je ten zákeřný — v aktuální
 * projekci období ho nenajde nikdo, takže bez snapshotu podání se o něm nedá dozvědět.
 *
 * Třetí hlídaná věc je {@see testMissingSnapshotIsNotSilentAgreement()}: archiv z doby
 * před zavedením snapshotu nesmí projít jako „souhlasí".
 */
#[Group('unit')]
final class OssFilingSnapshotDiffTest extends TestCase
{
    /**
     * @param list<array<string,mixed>> $rows
     * @param list<array<string,mixed>> $documents
     * @param list<array<string,mixed>> $corrections
     * @return array<string,mixed>
     */
    private static function snapshot(
        array $rows,
        array $documents,
        array $corrections = [],
        ?array $totals = null,
    ): array {
        return [
            'schema'          => OssFilingSnapshot::SCHEMA,
            'return_currency' => 'EUR',
            'totals'          => $totals ?? [
                'base'        => round(array_sum(array_column($rows, 'base')), 2),
                'vat'         => round(array_sum(array_column($rows, 'vat')), 2),
                'corrections' => round(array_sum(array_column($corrections, 'amount')), 2),
                'payable'     => round(
                    array_sum(array_column($rows, 'vat')) + array_sum(array_column($corrections, 'amount')),
                    2,
                ),
            ],
            'rows'            => $rows,
            'corrections'     => $corrections,
            'documents'       => $documents,
        ];
    }

    /** @return array<string,mixed> */
    private static function row(string $country, float $rate, float $base, float $vat): array
    {
        return [
            'country'     => $country,
            'supply_type' => 'goods',
            'rate_type'   => 'standard',
            'rate'        => $rate,
            'base'        => $base,
            'vat'         => $vat,
        ];
    }

    /** @return array<string,mixed> */
    private static function doc(int $itemId, string $country, float $base, float $vat, ?string $adjusted = null): array
    {
        return [
            'invoice_id'      => 100 + $itemId,
            'item_id'         => $itemId,
            'doc_number'      => 'TEST-' . $itemId,
            'country'         => $country,
            'tax_date'        => '2026-02-10',
            'rate'            => 23.0,
            'base'            => $base,
            'vat'             => $vat,
            'adjusted_period' => $adjusted,
            'status'          => 'sent',
            'updated_at'      => '2026-03-01 10:00:00',
        ];
    }

    public function testIdenticalSnapshotsAreInSync(): void
    {
        $snapshot = self::snapshot(
            [self::row('PL', 23.0, 1000.0, 230.0)],
            [self::doc(1, 'PL', 1000.0, 230.0)],
        );

        $diff = OssFilingSnapshot::diff($snapshot, $snapshot);

        self::assertTrue($diff['in_sync']);
        self::assertSame([], $diff['totals']);
        self::assertSame([], $diff['documents']);
    }

    /**
     * Doklad opravený po podání: základ i daň se změní, součet podání se rozejde.
     * Bez tohohle testu by rekonciliace mohla porovnávat jen počty řádků a mlčet.
     */
    public function testDocumentEditedAfterFilingIsReported(): void
    {
        $filed = self::snapshot(
            [self::row('PL', 23.0, 1000.0, 230.0)],
            [self::doc(1, 'PL', 1000.0, 230.0)],
        );
        $current = self::snapshot(
            [self::row('PL', 23.0, 1200.0, 276.0)],
            [self::doc(1, 'PL', 1200.0, 276.0)],
        );

        $diff = OssFilingSnapshot::diff($filed, $current);

        self::assertFalse($diff['in_sync']);
        self::assertSame(
            ['base', 'vat', 'payable'],
            array_column($diff['totals'], 'key'),
        );
        self::assertSame(46.0, $diff['totals'][1]['delta']);
        self::assertCount(1, $diff['rows']);
        self::assertSame('changed', $diff['rows'][0]['change']);
        self::assertCount(1, $diff['documents']);
        self::assertSame('changed', $diff['documents'][0]['change']);
        self::assertSame(1, $diff['documents'][0]['item_id']);
    }

    /**
     * Doklad, který z období ZMIZEL — stornovaný nebo s přesunutým DUZP. V dnešním
     * náhledu neexistuje, takže ho umí najít JEN snapshot podání. Kdyby se rekonciliace
     * dívala jen na to, co v období dnes je, tenhle rozdíl by nikdy nenahlásila.
     */
    public function testDocumentThatLeftThePeriodIsReportedAsRemoved(): void
    {
        $filed = self::snapshot(
            [self::row('PL', 23.0, 1000.0, 230.0), self::row('SK', 23.0, 500.0, 115.0)],
            [self::doc(1, 'PL', 1000.0, 230.0), self::doc(2, 'SK', 500.0, 115.0)],
        );
        $current = self::snapshot(
            [self::row('PL', 23.0, 1000.0, 230.0)],
            [self::doc(1, 'PL', 1000.0, 230.0)],
        );

        $diff = OssFilingSnapshot::diff($filed, $current);

        self::assertFalse($diff['in_sync']);
        $removedRows = array_values(array_filter($diff['rows'], static fn (array $r): bool => $r['change'] === 'removed'));
        self::assertCount(1, $removedRows);
        self::assertStringStartsWith('SK|', $removedRows[0]['key']);

        self::assertCount(1, $diff['documents']);
        self::assertSame('removed', $diff['documents'][0]['change']);
        self::assertSame(2, $diff['documents'][0]['item_id']);
    }

    /** Doklad doplněný do už podaného období — kandidát na opravné/dodatečné podání. */
    public function testDocumentAddedAfterFilingIsReported(): void
    {
        $filed = self::snapshot(
            [self::row('PL', 23.0, 1000.0, 230.0)],
            [self::doc(1, 'PL', 1000.0, 230.0)],
        );
        $current = self::snapshot(
            [self::row('PL', 23.0, 1000.0, 230.0), self::row('HU', 27.0, 200.0, 54.0)],
            [self::doc(1, 'PL', 1000.0, 230.0), self::doc(3, 'HU', 200.0, 54.0)],
        );

        $diff = OssFilingSnapshot::diff($filed, $current);

        self::assertFalse($diff['in_sync']);
        self::assertSame('added', $diff['documents'][0]['change']);
        self::assertSame(3, $diff['documents'][0]['item_id']);
    }

    /**
     * Změna STÁTU SPOTŘEBY při zachované částce. Součty podání zůstanou stejné, ale
     * daň se má odvést jinam — proto se rozdíl musí objevit na řádcích i u dokladu.
     */
    public function testConsumerCountryChangeIsReportedEvenWhenTotalsMatch(): void
    {
        $filed = self::snapshot(
            [self::row('PL', 23.0, 1000.0, 230.0)],
            [self::doc(1, 'PL', 1000.0, 230.0)],
        );
        $current = self::snapshot(
            [self::row('SK', 23.0, 1000.0, 230.0)],
            [self::doc(1, 'SK', 1000.0, 230.0)],
        );

        $diff = OssFilingSnapshot::diff($filed, $current);

        self::assertSame([], $diff['totals'], 'Součty se skutečně nezměnily.');
        self::assertFalse($diff['in_sync'], 'Přesun daně do jiného státu spotřeby nesmí projít jako shoda.');
        self::assertSame(['removed', 'added'], array_column($diff['rows'], 'change'));
        self::assertSame('changed', $diff['documents'][0]['change']);
    }

    /** Oprava minulého období (VetaO) se porovnává podle dvojice období × stát. */
    public function testCorrectionAmountChangeIsReported(): void
    {
        $filed = self::snapshot(
            [],
            [self::doc(4, 'PL', -100.0, -23.0, '2025Q4')],
            [['period' => '2025Q4', 'country' => 'PL', 'amount' => -23.0]],
        );
        $current = self::snapshot(
            [],
            [self::doc(4, 'PL', -200.0, -46.0, '2025Q4')],
            [['period' => '2025Q4', 'country' => 'PL', 'amount' => -46.0]],
        );

        $diff = OssFilingSnapshot::diff($filed, $current);

        self::assertFalse($diff['in_sync']);
        self::assertCount(1, $diff['corrections']);
        self::assertSame('changed', $diff['corrections'][0]['change']);
        self::assertSame(-23.0, $diff['corrections'][0]['amounts']['amount']['delta']);
    }

    /** Haléřový/centový šum ze zaokrouhlení nesmí vyrábět falešné rozdíly. */
    public function testRoundingNoiseIsNotADifference(): void
    {
        $filed = self::snapshot([self::row('PL', 23.0, 1000.0, 230.0)], [self::doc(1, 'PL', 1000.0, 230.0)]);
        $current = self::snapshot([self::row('PL', 23.0, 1000.001, 230.002)], [self::doc(1, 'PL', 1000.001, 230.002)]);

        self::assertTrue(OssFilingSnapshot::diff($filed, $current)['in_sync']);
    }

    /**
     * Archiv bez snapshotu (podání vzniklé před touhle epikou) se NESMÍ tvářit jako
     * porovnatelný. Kdyby `isUsable()` vrátila true, `diff()` by porovnala prázdno
     * s dneškem a vyhlásila… no, cokoli — jen ne pravdu.
     */
    public function testMissingSnapshotIsNotSilentAgreement(): void
    {
        self::assertFalse(OssFilingSnapshot::isUsable(null));
        self::assertFalse(OssFilingSnapshot::isUsable([]));
        self::assertFalse(OssFilingSnapshot::isUsable(['schema' => 'oss-filing-snapshot.v0']));
        self::assertTrue(OssFilingSnapshot::isUsable(['schema' => OssFilingSnapshot::SCHEMA]));
    }

    public function testFingerprintIgnoresDocumentsButNotAmounts(): void
    {
        $a = self::snapshot([self::row('PL', 23.0, 1000.0, 230.0)], [self::doc(1, 'PL', 1000.0, 230.0)]);
        $b = self::snapshot([self::row('PL', 23.0, 1000.0, 230.0)], [self::doc(9, 'PL', 1000.0, 230.0)]);
        $c = self::snapshot([self::row('PL', 23.0, 1001.0, 230.0)], [self::doc(1, 'PL', 1001.0, 230.0)]);

        self::assertSame(OssFilingSnapshot::fingerprint($a), OssFilingSnapshot::fingerprint($b));
        self::assertNotSame(OssFilingSnapshot::fingerprint($a), OssFilingSnapshot::fingerprint($c));
    }
}
