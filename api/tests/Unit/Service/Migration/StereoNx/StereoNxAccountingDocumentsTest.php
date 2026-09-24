<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Migration\StereoNx;

use MyInvoice\Service\Migration\StereoNx\StereoNxAccountingDocuments;
use MyInvoice\Service\Migration\StereoNx\StereoNxBackup;
use MyInvoice\Service\Migration\StereoNx\StereoNxImporter;
use MyInvoice\Service\Migration\StereoNx\StereoNxSourcePlan;
use MyInvoice\Tests\Fixtures\StereoNx\SyntheticNx1Archive;
use MyInvoice\Tests\Fixtures\StereoNx\SyntheticStereoNxTables;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../../../Fixtures/StereoNx/SyntheticStereoNxTables.php';
require_once __DIR__ . '/../../../../Fixtures/StereoNx/SyntheticNx1Archive.php';

final class StereoNxAccountingDocumentsTest extends TestCase
{
    public function testDocumentsOnlyAcceptsSignedNxLineKeyAndForeignCurrencyAsReviewDraft(): void
    {
        $tables = SyntheticStereoNxTables::tables();
        $header = $tables['Svfh'][0];
        $line = $tables['Svfp'][0];
        $header['Mena'] = 'EUR';
        $header['Kurz'] = 25.1;
        $header['KurzMn'] = 1;
        $header['Celkem'] = 10.0;
        $header['CenySDPH'] = false;
        $line['Klic'] = -2147483647;
        $line['Mnozstvi'] = 2.0;
        $line['JednCenaC'] = 5.0;
        $tables['Svfh'] = [$header];
        $tables['Svfp'] = [$line];
        $tables['SPFH'] = [];
        $tables['Spfp'] = [];

        $plan = StereoNxSourcePlan::fromTables($tables, SyntheticStereoNxTables::identity(), true, true);

        self::assertCount(1, $plan['issued']);
        self::assertSame('EUR', $plan['issued'][0]['currency_code']);
        self::assertSame(25.1, $plan['issued'][0]['exchange_rate']);
        self::assertSame(10.0, $plan['issued'][0]['source_total_with_vat']);
        self::assertTrue($plan['issued'][0]['requires_draft']);
        self::assertContains('foreign_currency_vat_unverified', $plan['issued'][0]['review_codes']);
        self::assertSame('zdroj neobsahuje ověřený korunový celkem dokladu',
            $plan['issued'][0]['foreign_takeover_blocked']);
    }

    public function testForeignVatBaseMismatchIsExposedInDocumentReview(): void
    {
        $tables = SyntheticStereoNxTables::tables();
        $tables['Svfh'][0]['Mena'] = 'EUR';
        $tables['Svfh'][0]['Kurz'] = 25.1;
        $tables['Svfh'][0]['KurzMn'] = 1.0;
        $tables['Svfh'][0]['Celkem'] = 10.0;
        $tables['Svfh'][0]['CelkemVlastni'] = 251.0;
        $tables['Svfh'][0]['ZaklDPHz'] = 0.0;
        $tables['Svfh'][0]['DPHz'] = 0.0;
        $tables['Svfh'][0]['BezDane'] = 32.0;
        $tables['Lsdph'][0]['E19RadekZaklad0'] = '20';
        $tables['Svfp'][0]['TypSazby'] = '0';
        $tables['Svfp'][0]['Mnozstvi'] = 2.0;
        $tables['Svfp'][0]['JednCenaC'] = 5.0;
        $tables['Svfp'][0]['JednCena'] = 16.0;
        $tables['Svfp'][0]['ZakladDPH'] = 32.0;
        $tables['Svfp'][0]['CelkemDPH'] = 0.0;
        $tables['Svfp'][0]['SazbaDPH'] = 0.0;
        $plan = StereoNxSourcePlan::fromTables($tables, SyntheticStereoNxTables::identity(), true, true);

        self::assertContains('foreign_currency_vat_base_mismatch', $plan['issued'][0]['review_codes']);
        self::assertTrue($plan['issued'][0]['requires_draft']);
        self::assertNotNull($plan['issued'][0]['foreign_takeover_blocked']);
    }

    public function testVerifiedForeignHomeAmountsPassSharedCurrencyCheck(): void
    {
        $tables = SyntheticStereoNxTables::tables();
        $tables['Svfh'][0]['Mena'] = 'EUR';
        $tables['Svfh'][0]['Kurz'] = 25.1;
        $tables['Svfh'][0]['Celkem'] = 10.0;
        $tables['Svfh'][0]['CelkemVlastni'] = 251.0;
        $tables['Svfp'][0]['Mnozstvi'] = 2.0;
        $tables['Svfp'][0]['JednCenaC'] = 5.0;
        $tables['Svfp'][0]['ZakladDPH'] = 251.0;
        $tables['Svfp'][0]['CelkemDPH'] = 0.0;
        $tables['SPFH'] = []; $tables['Spfp'] = [];

        $plan = StereoNxSourcePlan::fromTables($tables, SyntheticStereoNxTables::identity(), true, true);
        self::assertNull($plan['issued'][0]['foreign_takeover_blocked']);
        self::assertSame(251.0, $plan['issued'][0]['foreign_home_total']);
    }

    public function testForeignRateMismatchIsExposedInDocumentReview(): void
    {
        $tables = SyntheticStereoNxTables::tables();
        $tables['Svfh'][0]['Mena'] = 'EUR';
        $tables['Svfh'][0]['Kurz'] = 25.1;
        $tables['Svfh'][0]['KurzMn'] = 1.0;
        $tables['Svfh'][0]['Celkem'] = 10.0;
        $tables['Svfh'][0]['CelkemVlastni'] = 32.0;
        $tables['Svfh'][0]['ZaklDPHz'] = 0.0;
        $tables['Svfh'][0]['DPHz'] = 0.0;
        $tables['Svfh'][0]['BezDane'] = 32.0;
        $tables['Lsdph'][0]['E19RadekZaklad0'] = '20';
        $tables['Svfp'][0]['TypSazby'] = '0';
        $tables['Svfp'][0]['Mnozstvi'] = 2.0;
        $tables['Svfp'][0]['JednCenaC'] = 5.0;
        $tables['Svfp'][0]['JednCena'] = 16.0;
        $tables['Svfp'][0]['ZakladDPH'] = 32.0;
        $tables['Svfp'][0]['CelkemDPH'] = 0.0;
        $tables['Svfp'][0]['SazbaDPH'] = 0.0;
        $plan = StereoNxSourcePlan::fromTables($tables, SyntheticStereoNxTables::identity(), true, true);

        self::assertContains('foreign_currency_rate_mismatch', $plan['issued'][0]['review_codes']);
        self::assertTrue($plan['issued'][0]['requires_draft']);
        self::assertNotNull($plan['issued'][0]['foreign_takeover_blocked']);
    }

    public function testVerifiedPurchaseSourceLinesPreserveItemsWithoutReview(): void
    {
        $tables = SyntheticStereoNxTables::tables();
        $header = $tables['SPFH'][0];
        $tables['Spfp'] = [[
            'DoklSRada' => $header['DoklSRada'], 'DoklSCislo' => $header['DoklSCislo'],
            'Klic' => -2147483647, 'Text' => 'Syntetická položka',
            'Stornovano' => false, 'Zaloha' => false, 'ZalohaProforma' => false,
            'TypSazby' => 'Z', 'Mnozstvi' => 2.0, 'JednCena' => 50.0,
            'ProcSlevy' => 0.0, 'ZakladDPH' => 100.0, 'CelkemDPH' => 21.0,
            'SazbaDPH' => 21.0,
        ]];
        $plan = StereoNxSourcePlan::fromTables($tables, SyntheticStereoNxTables::identity(), true, true);

        self::assertNotEmpty($plan['purchases']);
        self::assertFalse($plan['purchases'][0]['requires_draft']);
        self::assertSame('source_lines', $plan['purchases'][0]['origin']);
        self::assertCount(1, $plan['purchases'][0]['items']);
        self::assertSame(2.0, $plan['purchases'][0]['items'][0]['quantity']);
        self::assertSame('Syntetická položka', $plan['purchases'][0]['items'][0]['description']);
    }

    public function testUnverifiedPurchaseSourceLineFallsBackToRecapDraft(): void
    {
        $tables = SyntheticStereoNxTables::tables();
        $header = $tables['SPFH'][0];
        $tables['Spfp'] = [[
            'DoklSRada' => $header['DoklSRada'], 'DoklSCislo' => $header['DoklSCislo'],
            'Klic' => 1, 'Stornovano' => false, 'Zaloha' => 1,
        ]];
        $plan = StereoNxSourcePlan::fromTables($tables, SyntheticStereoNxTables::identity(), true, true);

        self::assertTrue($plan['purchases'][0]['requires_draft']);
        self::assertSame('vat_recap', $plan['purchases'][0]['origin']);
        self::assertContains('purchase_lines_aggregated', $plan['purchases'][0]['review_codes']);
    }

    public function testPurchaseAdvanceApplicationKeepsSignedTaxLinesAsDraft(): void
    {
        $tables = SyntheticStereoNxTables::tables();
        $header = $tables['SPFH'][0];
        $header['ZaklDPHz'] = 200.0;
        $header['DPHz'] = 42.0;
        $header['Celkem'] = 242.0;
        $tables['SPFH'] = [$header];
        $tables['Spfp'] = [
            ['DoklSRada' => 'PF', 'DoklSCislo' => 1, 'Klic' => 1,
                'Text' => 'Syntetická položka', 'Stornovano' => false, 'Zaloha' => false,
                'ZalohaProforma' => false, 'TypSazby' => 'Z', 'Mnozstvi' => 1.0,
                'JednCena' => 300.0, 'ProcSlevy' => 0.0, 'ZakladDPH' => 300.0,
                'CelkemDPH' => 63.0, 'SazbaDPH' => 21.0],
            ['DoklSRada' => 'PF', 'DoklSCislo' => 1, 'Klic' => 2,
                'Text' => 'Syntetická záloha', 'Stornovano' => false, 'Zaloha' => true,
                'ZalohaProforma' => false, 'TypSazby' => 'Z', 'Mnozstvi' => 1.0,
                'JednCena' => -100.0, 'ProcSlevy' => 0.0, 'ZakladDPH' => -100.0,
                'CelkemDPH' => -21.0, 'SazbaDPH' => 21.0],
        ];
        $plan = StereoNxSourcePlan::fromTables($tables, SyntheticStereoNxTables::identity(), true, true);

        self::assertSame('source_lines', $plan['purchases'][0]['origin']);
        self::assertSame([300.0, -100.0], array_column($plan['purchases'][0]['items'], 'total_without_vat'));
        self::assertSame([63.0, -21.0], array_column($plan['purchases'][0]['items'], 'total_vat'));
        self::assertTrue($plan['purchases'][0]['requires_draft']);
        self::assertContains('advance_application_unlinked', $plan['purchases'][0]['review_codes']);
    }

    public function testAdvanceApplicationKeepsBothTaxLinesButRequiresDraft(): void
    {
        $tables = SyntheticStereoNxTables::tables();
        $tables['Svfh'][0]['ZaklDPHz'] = 0.0;
        $tables['Svfh'][0]['DPHz'] = 0.0;
        $tables['Svfh'][0]['Celkem'] = 0.0;
        $advance = $tables['Svfp'][0];
        $advance['Klic'] = 2;
        $advance['Zaloha'] = true;
        $advance['JednCena'] = -100.0;
        $advance['ZakladDPH'] = -100.0;
        $advance['CelkemDPH'] = -21.0;
        $tables['Svfp'][] = $advance;
        $plan = StereoNxSourcePlan::fromTables($tables, SyntheticStereoNxTables::identity(), true, true);

        self::assertSame('source_lines', $plan['issued'][0]['origin']);
        self::assertCount(2, $plan['issued'][0]['items']);
        self::assertSame([21.0, -21.0], array_column($plan['issued'][0]['items'], 'total_vat'));
        self::assertTrue($plan['issued'][0]['requires_draft']);
        self::assertContains('advance_application_unlinked', $plan['issued'][0]['review_codes']);
    }

    public function testNonBooleanAdvanceFlagCannotProduceConfirmedSourceLines(): void
    {
        $tables = SyntheticStereoNxTables::tables();
        $tables['Svfp'][0]['Zaloha'] = 1;
        $plan = StereoNxSourcePlan::fromTables($tables, SyntheticStereoNxTables::identity(), true, true);

        self::assertTrue($plan['issued'][0]['requires_draft']);
        self::assertSame('vat_recap', $plan['issued'][0]['origin']);
        self::assertContains('issued_lines_aggregated', $plan['issued'][0]['review_codes']);
    }

    public function testTaxExcludedIssuedAdvanceKeepsLinesAsProforma(): void
    {
        $tables = SyntheticStereoNxTables::tables();
        $tables['Svfh'][0]['TypDokladu'] = 'Z';
        $tables['Svfh'][0]['ZpracovatDPH'] = false;
        $tables['Svfh'][0]['DatumDPH'] = null;
        $tables['Svfp'][0]['ZalohaProforma'] = true;
        $plan = StereoNxSourcePlan::fromTables($tables, SyntheticStereoNxTables::identity(), true, true);

        self::assertSame('proforma', $plan['issued'][0]['target_document_kind']);
        self::assertSame('source_lines', $plan['issued'][0]['origin']);
        self::assertFalse($plan['issued'][0]['requires_draft']);
        self::assertNotContains('document_tax_date_missing', $plan['issued'][0]['review_codes']);
    }

    public function testTaxExcludedPurchaseAdvanceUsesRecapOutsideVatLedger(): void
    {
        $tables = SyntheticStereoNxTables::tables();
        $tables['SPFH'][0]['TypDokladu'] = 'Z';
        $tables['SPFH'][0]['ZpracovatDPH'] = false;
        $tables['SPFH'][0]['DatumDPH'] = null;
        $plan = StereoNxSourcePlan::fromTables($tables, SyntheticStereoNxTables::identity(), true, true);

        self::assertSame('advance', $plan['purchases'][0]['target_document_kind']);
        self::assertSame('vat_recap', $plan['purchases'][0]['origin']);
        self::assertFalse($plan['purchases'][0]['requires_draft']);
        self::assertNotContains('document_tax_date_missing', $plan['purchases'][0]['review_codes']);
    }

    public function testUntaxedAdvancesWithoutItemsKeepOnlyExplicitTotal(): void
    {
        $tables = SyntheticStereoNxTables::tables();
        foreach (['Svfh', 'SPFH'] as $table) {
            $tables[$table][0]['TypDokladu'] = 'Z';
            $tables[$table][0]['ZpracovatDPH'] = false;
            $tables[$table][0]['DatumDPH'] = null;
            $tables[$table][0]['ZaklDPHz'] = 0.0;
            $tables[$table][0]['DPHz'] = 0.0;
            $tables[$table][0]['Celkem'] = 500.0;
        }
        $tables['Svfp'] = [];
        $plan = StereoNxSourcePlan::fromTables($tables, SyntheticStereoNxTables::identity(), true, true);

        foreach ([$plan['issued'][0], $plan['purchases'][0]] as $record) {
            self::assertSame('advance_total', $record['origin']);
            self::assertFalse($record['requires_draft']);
            self::assertCount(1, $record['items']);
            self::assertSame(500.0, $record['items'][0]['total_without_vat']);
            self::assertSame(0.0, $record['items'][0]['total_vat']);
            self::assertNotContains('document_tax_date_missing', $record['review_codes']);
        }
        self::assertSame('proforma', $plan['issued'][0]['target_document_kind']);
        self::assertSame('advance', $plan['purchases'][0]['target_document_kind']);
    }

    public function testOrdinaryPurchaseWithoutItemsOrRecapStaysDraft(): void
    {
        $tables = SyntheticStereoNxTables::tables();
        $tables['SPFH'][0]['ZpracovatDPH'] = false;
        $tables['SPFH'][0]['DatumDPH'] = null;
        $tables['SPFH'][0]['ZaklDPHz'] = 0.0;
        $tables['SPFH'][0]['DPHz'] = 0.0;
        $tables['SPFH'][0]['Celkem'] = 500.0;
        $plan = StereoNxSourcePlan::fromTables($tables, SyntheticStereoNxTables::identity(), true, true);

        self::assertSame('invoice', $plan['purchases'][0]['target_document_kind']);
        self::assertTrue($plan['purchases'][0]['requires_draft']);
        self::assertContains('document_tax_mapping_unverified', $plan['purchases'][0]['review_codes']);
    }

    public function testForeignDocumentWarnsThatLedgerHasNoForeignBalance(): void
    {
        $tables = SyntheticStereoNxTables::tables();
        $tables['Svfh'][0]['Mena'] = 'EUR';
        $tables['Svfh'][0]['Kurz'] = 25.1;
        $tables['Svfh'][0]['KurzMn'] = 1;
        $tables['Svfh'][0]['Celkem'] = 10.0;
        $tables['Svfp'][0]['Mnozstvi'] = 2.0;
        $tables['Svfp'][0]['JednCenaC'] = 5.0;
        $path = sys_get_temp_dir() . '/stereo-foreign-ledger-' . bin2hex(random_bytes(6)) . '.zip';
        SyntheticNx1Archive::write($path, $tables, SyntheticStereoNxTables::identity());
        try {
            $importer = (new \ReflectionClass(StereoNxImporter::class))->newInstanceWithoutConstructor();
            $module = new StereoNxAccountingDocuments(new StereoNxSourcePlan(), $importer);
            $plan = $module->prepare(StereoNxBackup::open($path, 0), true);
        } finally {
            @unlink($path);
        }

        $warning = array_values(array_filter($plan['warnings'],
            static fn (array $w): bool => $w['code'] === 'foreign_document_ledger_currency_unavailable'));
        self::assertCount(1, $warning);
        self::assertSame(1, $warning[0]['count']);
        self::assertSame(1, $plan['counts']['skipped_foreign_documents']);
        self::assertSame(0, $plan['counts']['issued']);
        self::assertNotEmpty($plan['records']['purchases']);
        self::assertContains('foreign_document_unverified', array_column($plan['warnings'], 'code'));
    }
}
