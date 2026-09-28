<?php
declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Migration\Abra;

use MyInvoice\Service\Migration\Abra\AbraJournalMapper;
use MyInvoice\Service\Migration\Abra\AbraPaymentMapper;
use MyInvoice\Service\Migration\Abra\AbraSnapshotBuilder;
use MyInvoice\Service\Migration\Abra\AbraSource;
use PHPUnit\Framework\TestCase;

final class AbraSourceCompactionTest extends TestCase
{
    public function testJournalKeepsFieldsUsedForPostingAndDropsUnrelatedPayload(): void
    {
        $row = [
            'idUcetniDenik' => '7', 'datUcto' => '2026-03-10', 'mdUcet' => 'code:311000',
            'dalUcet' => 'code:602000', 'sumTuz' => '121.00', 'storno' => false,
            'idDokl@evidencePath' => 'faktura-vydana/19', 'dimens' => ['stredisko' => 'S1'],
            'irrelevant' => str_repeat('x', 2000),
        ];

        $compact = AbraSource::compactJournal($row);
        $mapped = (new AbraJournalMapper())->mapEntry($compact);

        self::assertSame('7', $mapped['source_key']);
        self::assertSame(121.0, $mapped['amount']);
        self::assertSame('19', $mapped['document_relation']['key']);
        self::assertArrayNotHasKey('irrelevant', $compact);
    }

    public function testJournalCombinesEvidencePathAndSeparateDocumentId(): void
    {
        $row = ['idUcetniDenik' => '7', 'datUcto' => '2026-03-10', 'mdUcet' => 'code:311000',
            'dalUcet' => 'code:602000', 'sumTuz' => '121.00',
            'idDokl' => '19', 'idDokl@evidencePath' => 'faktura-vydana'];

        $mapped = (new AbraJournalMapper())->mapEntry(AbraSource::compactJournal($row));

        self::assertSame(['evidence' => 'faktura-vydana', 'key' => '19'], $mapped['document_relation']);
    }

    public function testAccountMovementKeepsReconciliationFields(): void
    {
        $row = ['idUcetniDenik' => '7', 'datUcto' => '2026-03-10', 'ucet' => 'code:311000',
            'sumTuzMd' => '121.00', 'sumTuzDal' => '0.00', 'irrelevant' => str_repeat('x', 2000)];

        $compact = AbraSource::compactAccountMovement($row);

        self::assertSame('7', AbraSource::sourceKey($compact));
        self::assertSame('121.00', $compact['sumTuzMd']);
        self::assertSame('0.00', $compact['sumTuzDal']);
        self::assertArrayNotHasKey('irrelevant', $compact);
    }

    public function testBankDocumentKeepsPaymentAndInvoiceRelationsWithoutItemPayload(): void
    {
        $row = ['id' => '31', 'kod' => 'SYN-B-31', 'datUcto' => '2026-03-10', 'mena' => 'code:CZK',
            'sumCelkem' => '121.00', 'typPohybuK' => 'typPohybu.prijem', 'bankaUcet' => '1000000005',
            'banka' => 'code:SYNTHETIC', 'banka@ref' => '/c/demo/bankovni-ucet/9.json', 'zuctovano' => true,
            'bankaKod' => '0100', 'vazby' => [['id' => '8', 'a' => ['evidencePath' => 'banka/31'],
                'b' => ['evidencePath' => 'faktura-vydana/12'], 'castka' => '121.00']],
            'vazebni-doklady' => [['idDokl' => '12', 'idDokl@evidencePath' => 'faktura-vydana/12',
                'popis' => str_repeat('x', 2000)]],
            'polozkyDokladu' => [['popis' => str_repeat('x', 2000)]],
        ];
        $compact = AbraSource::compactPaymentDocument($row);
        $before = (new AbraPaymentMapper())->mapMovement($row, 'banka');
        $after = (new AbraPaymentMapper())->mapMovement($compact, 'banka');
        unset($before['source_hash'], $after['source_hash']);

        self::assertSame($before, $after);
        self::assertSame(
            AbraSnapshotBuilder::linkedInvoiceKeys(['banka' => [$row], 'pokladni-pohyb' => [], 'vazba' => []]),
            AbraSnapshotBuilder::linkedInvoiceKeys(['banka' => [$compact], 'pokladni-pohyb' => [], 'vazba' => []]),
        );
        self::assertArrayNotHasKey('polozkyDokladu', $compact);
        self::assertArrayNotHasKey('popis', $compact['vazebni-doklady'][0]);
    }

    public function testCashVatSurvivesCompactionAndBlocksUnsafeImport(): void
    {
        $row = ['id' => '31', 'datUcto' => '2025-06-02', 'mena' => 'code:CZK',
            'sumCelkem' => '121.00', 'sumDphCelkem' => '0.00',
            'polozkyDokladu' => [['sumDph' => '21.00', 'popis' => str_repeat('x', 2000)]]];

        $compact = AbraSource::compactPaymentDocument($row);

        self::assertArrayNotHasKey('polozkyDokladu', $compact);
        self::assertContains('cash_vat_requires_review',
            (new AbraPaymentMapper())->mapMovement($compact, 'pokladni-pohyb')['blockers']);
    }

    public function testPaymentCompactionPreservesCancellationAndChangesMovementIdentity(): void
    {
        $active = ['id' => '31', 'datUcto' => '2026-03-10', 'sumCelkem' => '121.00',
            'mena' => 'code:CZK', 'storno' => false];
        $cancelled = $active;
        $cancelled['storno'] = true;

        $active = AbraSource::compactPaymentDocument($active);
        $cancelled = AbraSource::compactPaymentDocument($cancelled);
        self::assertArrayHasKey('storno', $cancelled);
        $previousActive = $active;
        unset($previousActive['storno']);
        self::assertSame(AbraSource::movementHash($previousActive), AbraSource::movementHash($active));
        self::assertNotSame(AbraSource::movementHash($active), AbraSource::movementHash($cancelled));
        self::assertTrue((new AbraPaymentMapper())->mapMovement($cancelled, 'banka')['storno']);
    }
}
