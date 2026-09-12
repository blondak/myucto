<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Tax\Return;

use MyInvoice\Service\Tax\Return\DppoReturnCalculator;
use MyInvoice\Service\Tax\Return\DppoXmlBuilder;
use MyInvoice\Service\Tax\TaxConstants;
use PHPUnit\Framework\TestCase;

/**
 * Příloha účetní závěrky nese všechny řádky plného výkazu zisku a ztráty a rozvahy, které
 * aplikace počítá. Dřív v ní chyběly A.1., E.1.2., E.2., E.3., III.1.–III.3., F.1., F.2.,
 * F.4., M., rozpad finančních položek IV./V./VI./J. a v aktivech A. a podřádky C.II.1./C.II.2.
 * Firma s prodaným zbožím (504) tak měla ve výkazu náklady, které v příloze přiznání byly
 * nulové. Čísla řádků podle číselníku MF ČR (tabulky 23810 a 25810, platnost=2026).
 */
final class DppoXmlBuilderAppendixFullRowsTest extends TestCase
{
    private function build(array $appendix): string
    {
        $supplier = [
            'company_name' => 'Ukázková firma s.r.o.', 'street' => 'Zkušební 123/4',
            'city' => 'Vzorov', 'zip' => '100 00', 'country_iso2' => 'CZ',
            'ic' => '12345678', 'dic' => 'CZ12345678', 'taxpayer_type' => 'po',
            'financial_office_code' => '451', 'cz_nace_code' => '62020',
        ];
        $calc = (new DppoReturnCalculator())->compute(
            ['vh' => 500000, 'depreciation' => ['tax' => 0, 'accounting' => 0]],
            ['tax_paid_advances' => 0],
            TaxConstants::forYear(2025)
        );
        return (new DppoXmlBuilder())->build($supplier, 2025, $calc, [], $appendix)['xml'];
    }

    /** @param array<string,float> $amounts */
    private function incomeStatement(array $amounts): array
    {
        $rows = [];
        foreach ($amounts as $code => $amount) {
            $rows[] = ['row_code' => $code, 'amount' => $amount, 'prev_amount' => 0.0];
        }
        return ['rows' => $rows];
    }

    private function minimalBalanceSheet(): array
    {
        return ['assets' => [
            ['row_code' => 'B.', 'gross' => 1000.0, 'correction' => 0.0, 'net' => 1000.0, 'prev_net' => 0.0],
        ]];
    }

    public function testIncomeStatementDetailRowsReachTheAppendix(): void
    {
        $xml = $this->build([
            'balance_sheet' => $this->minimalBalanceSheet(),
            'income_statement' => $this->incomeStatement([
                'A.' => 1228742.0, 'A.1.' => 1028742.0, 'A.2.' => 150000.0, 'A.3.' => 50000.0,
                'E.' => 16000.0, 'E.1.' => 7000.0, 'E.1.2.' => 7000.0, 'E.2.' => 5000.0, 'E.3.' => 4000.0,
                'III.' => 2656000.0, 'III.1.' => 2370000.0, 'III.2.' => 11000.0, 'III.3.' => 275000.0,
                'F.' => 1308000.0, 'F.1.' => 1290000.0, 'F.2.' => 9000.0, 'F.4.' => 9000.0,
                'IV.' => 10000.0, 'IV.2.' => 10000.0,
                'V.' => 8000.0, 'V.2.' => 8000.0,
                'VI.' => 5000.0, 'VI.2.' => 5000.0,
                'J.' => 21000.0, 'J.2.' => 21000.0,
                'M.' => 50000.0,
            ]),
        ]);

        foreach ([
            4 => 1029, 17 => 7, 18 => 5, 19 => 4, 21 => 2370, 22 => 11, 23 => 275,
            25 => 1290, 26 => 9, 28 => 9, 33 => 10, 37 => 8, 41 => 5, 45 => 21, 54 => 50,
        ] as $cRadku => $thousands) {
            self::assertStringContainsString(
                sprintf('<VetaUB c_radku="%d" kc_min="0" kc_sled="%d"/>', $cRadku, $thousands),
                $xml,
                "ř. {$cRadku}",
            );
        }
        // Spřízněná osoba (IV.1./V.1./VI.1./J.1.) je v datech nulová, řádky se nevypíší.
        foreach ([32, 36, 40, 44] as $cRadku) {
            self::assertStringNotContainsString(sprintf('<VetaUB c_radku="%d"', $cRadku), $xml);
        }
    }

    /**
     * VI. se dřív psalo do ř. 39 i ř. 41 („VI.2. Ostatní"), J. ale jen do ř. 43 a ř. 45
     * zůstal nulový. Podřádek „ostatní" nese vlastní hodnotu z výkazu, rodič ji nekopíruje.
     */
    public function testFinancialSubRowsCarryTheirOwnValues(): void
    {
        $xml = $this->build([
            'balance_sheet' => $this->minimalBalanceSheet(),
            'income_statement' => $this->incomeStatement([
                'VI.' => 9000.0, 'VI.1.' => 4000.0, 'VI.2.' => 5000.0,
                'J.' => 30000.0, 'J.1.' => 12000.0, 'J.2.' => 18000.0,
            ]),
        ]);

        self::assertStringContainsString('<VetaUB c_radku="39" kc_min="0" kc_sled="9"/>', $xml);
        self::assertStringContainsString('<VetaUB c_radku="40" kc_min="0" kc_sled="4"/>', $xml);
        self::assertStringContainsString('<VetaUB c_radku="41" kc_min="0" kc_sled="5"/>', $xml);
        self::assertStringContainsString('<VetaUB c_radku="43" kc_min="0" kc_sled="30"/>', $xml);
        self::assertStringContainsString('<VetaUB c_radku="44" kc_min="0" kc_sled="12"/>', $xml);
        self::assertStringContainsString('<VetaUB c_radku="45" kc_min="0" kc_sled="18"/>', $xml);
    }

    /**
     * Podřádky výsledovky se zaokrouhlují každý zvlášť a do rodiče se nedorovnávají
     * (§ 4 odst. 3 vyhl. 500/2002 Sb. připouští oba způsoby, podané přílohy je tak nesou):
     * A. = 1 000 500 Kč dá 1001 tis., každý ze tří podřádků po 333 500 Kč dá 334 tis.
     */
    public function testIncomeStatementSubRowsAreRoundedIndependently(): void
    {
        $xml = $this->build([
            'balance_sheet' => $this->minimalBalanceSheet(),
            'income_statement' => $this->incomeStatement([
                'A.' => 1000500.0, 'A.1.' => 333500.0, 'A.2.' => 333500.0, 'A.3.' => 333500.0,
            ]),
        ]);

        self::assertStringContainsString('<VetaUB c_radku="3" kc_min="0" kc_sled="1001"/>', $xml);
        self::assertStringContainsString('<VetaUB c_radku="4" kc_min="0" kc_sled="334"/>', $xml);
        self::assertStringContainsString('<VetaUB c_radku="5" kc_min="0" kc_sled="334"/>', $xml);
        self::assertStringContainsString('<VetaUB c_radku="6" kc_min="0" kc_sled="334"/>', $xml);
    }

    /**
     * Rozpad IV./V./VI./J. na „ovládaná osoba" a „ostatní" musí dát přesně rodiče, i když
     * se rodič dorovnal do vzorce FVH: VI. = 50 200 Kč dá 50 tis., FVH (50 914 Kč = 51 tis.)
     * ho ale zvedne na 51 a podřádek VI.2. musí jít s ním. J. = 1 000 500 Kč dá 1001 tis.,
     * dva podřádky po 500 250 Kč dají 500 + 500, rozdíl jde do prvního největšího.
     */
    public function testFinancialSplitRowsAddUpToTheirParent(): void
    {
        $xml = $this->build([
            'balance_sheet' => $this->minimalBalanceSheet(),
            'income_statement' => $this->incomeStatement([
                'VI.' => 50200.0, 'VI.2.' => 50200.0, 'VII.' => 5276.0, 'K.' => 4562.0, 'FVH' => 50914.0,
            ]),
        ]);
        self::assertStringContainsString('<VetaUB c_radku="39" kc_min="0" kc_sled="51"/>', $xml);
        self::assertStringContainsString('<VetaUB c_radku="41" kc_min="0" kc_sled="51"/>', $xml);

        $xml = $this->build([
            'balance_sheet' => $this->minimalBalanceSheet(),
            'income_statement' => $this->incomeStatement([
                'J.' => 1000500.0, 'J.1.' => 500250.0, 'J.2.' => 500250.0,
            ]),
        ]);
        self::assertStringContainsString('<VetaUB c_radku="43" kc_min="0" kc_sled="1001"/>', $xml);
        self::assertStringContainsString('<VetaUB c_radku="44" kc_min="0" kc_sled="501"/>', $xml);
        self::assertStringContainsString('<VetaUB c_radku="45" kc_min="0" kc_sled="500"/>', $xml);
    }

    /** Výsledek za účetní období (ř. 55) = výsledek po zdanění − převod podílu společníkům (M.). */
    public function testProfitShareTransferReducesResultForThePeriod(): void
    {
        $xml = $this->build([
            'balance_sheet' => $this->minimalBalanceSheet(),
            'income_statement' => $this->incomeStatement([
                'PVH' => 500000.0, 'FVH' => 0.0, 'L.' => 100000.0, 'M.' => 50000.0,
            ]),
        ]);

        self::assertStringContainsString('<VetaUB c_radku="53" kc_min="0" kc_sled="400"/>', $xml);
        self::assertStringContainsString('<VetaUB c_radku="54" kc_min="0" kc_sled="50"/>', $xml);
        self::assertStringContainsString('<VetaUB c_radku="55" kc_min="0" kc_sled="350"/>', $xml);
    }

    public function testAssetsCarrySubscribedCapitalAndReceivableDetail(): void
    {
        $row = static fn (string $code, float $net): array => ['row_code' => $code, 'gross' => $net, 'correction' => 0.0, 'net' => $net, 'prev_net' => 0.0];
        $xml = $this->build([
            'balance_sheet' => ['assets' => [
                $row('AKTIVA', 1500000.0), $row('A.', 100000.0), $row('C.', 1400000.0),
                $row('C.II.', 1400000.0), $row('C.II.1.', 200000.0), $row('C.II.1.1.', 200000.0),
                $row('C.II.2.', 1200000.0), $row('C.II.2.1.', 800000.0),
                $row('C.II.2.4.', 400000.0), $row('C.II.2.4.3.', 400000.0),
            ]],
            'income_statement' => $this->incomeStatement(['I.' => 1000.0]),
        ]);

        foreach ([2 => 100, 47 => 200, 48 => 200, 57 => 1200, 58 => 800, 61 => 400, 64 => 400] as $cRadku => $thousands) {
            self::assertStringContainsString(
                sprintf('<VetaUA c_radku="%d" kc_brutto="%d" kc_korekce="0" kc_netto="%d" kc_netto_min="0"/>', $cRadku, $thousands, $thousands),
                $xml,
                "ř. {$cRadku}",
            );
        }
    }

    /**
     * AKTIVA CELKEM = A.+B.+C.+D. drží i po zaokrouhlení: B. a C. po 333 500 Kč dají
     * 334 tis., D. 334 tis., celek 1 001 000 Kč jen 1001. Rozdíl jde do B.
     */
    public function testAssetsTotalAbsorbsRoundingDrift(): void
    {
        $row = static fn (string $code, float $net): array => ['row_code' => $code, 'gross' => $net, 'correction' => 0.0, 'net' => $net, 'prev_net' => 0.0];
        $xml = $this->build([
            'balance_sheet' => ['assets' => [
                $row('AKTIVA', 1001000.0), $row('B.', 333500.0), $row('C.', 333500.0), $row('D.', 334000.0),
            ]],
            'income_statement' => $this->incomeStatement(['I.' => 1000.0]),
        ]);

        self::assertStringContainsString('<VetaUA c_radku="1" kc_brutto="1001" kc_korekce="0" kc_netto="1001" kc_netto_min="0"/>', $xml);
        self::assertStringContainsString('<VetaUA c_radku="3" kc_brutto="333" kc_korekce="0" kc_netto="333" kc_netto_min="0"/>', $xml);
        self::assertStringContainsString('<VetaUA c_radku="37" kc_brutto="334" kc_korekce="0" kc_netto="334" kc_netto_min="0"/>', $xml);
        self::assertStringContainsString('<VetaUA c_radku="74" kc_brutto="334" kc_korekce="0" kc_netto="334" kc_netto_min="0"/>', $xml);
    }
}
