<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Accounting;

use MyInvoice\Service\Accounting\TemplateCsvMatcher;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Jednotkové testy CSV importu mzdové rekapitulace na šablonu (Fáze F, mzdový
 * můstek) — pure, bez DB. Ověřuje párování podle kódu účtu i podle názvu řádku
 * (diakritika/velikost/mezery sjednocené přes AbstractCodebookImportService::normalize),
 * ignorování hlavičky a report nenapárovaných položek.
 */
#[Group('unit')]
final class TemplateCsvMatcherTest extends TestCase
{
    /** @return list<array{line_no:int,label:?string,account_code:string,side:string,default_amount:?float,cost_center:?string}> */
    private function payrollTemplateLines(): array
    {
        return [
            ['line_no' => 1, 'label' => 'Hrubé mzdy', 'account_code' => '521', 'side' => 'debit', 'default_amount' => null, 'cost_center' => null],
            ['line_no' => 2, 'label' => 'Sociální a zdravotní pojištění za zaměstnavatele', 'account_code' => '524', 'side' => 'debit', 'default_amount' => null, 'cost_center' => null],
            ['line_no' => 3, 'label' => 'Závazek vůči zaměstnancům (čistá mzda k výplatě)', 'account_code' => '331', 'side' => 'credit', 'default_amount' => null, 'cost_center' => null],
            ['line_no' => 4, 'label' => 'Zúčtování se OSSZ a zdravotními pojišťovnami', 'account_code' => '336', 'side' => 'credit', 'default_amount' => null, 'cost_center' => null],
            ['line_no' => 5, 'label' => 'Záloha na daň ze závislé činnosti', 'account_code' => '342', 'side' => 'credit', 'default_amount' => null, 'cost_center' => null],
        ];
    }

    public function testMatchesByAccountCodeAndByNormalizedLabel(): void
    {
        $csv = "Položka;Částka\n"
            . "521;50000\n"                                          // podle kódu účtu
            . "Hruba mzda;0\n"                                        // (nula se zahodí — jen kontrola, že se neshoduje s výše)
            . "zavazek vuci zamestnancum (cista mzda k vyplate);33500\n" // podle normalizovaného názvu (bez diakritiky/case)
            . "336;12000\n"
            . "342;4500\n";

        $matcher = new TemplateCsvMatcher();
        $result = $matcher->match($this->payrollTemplateLines(), $csv);

        $byLine = [];
        foreach ($result['lines'] as $l) {
            $byLine[$l['line_no']] = $l['amount'];
        }

        self::assertSame(50000.0, $byLine[1], '521 napárováno podle kódu účtu.');
        self::assertNull($byLine[2], '524 nebylo v CSV — zůstává NULL (doplň při vložení).');
        self::assertSame(33500.0, $byLine[3], '331 napárováno podle normalizovaného názvu řádku.');
        self::assertSame(12000.0, $byLine[4], '336 napárováno podle kódu účtu.');
        self::assertSame(4500.0, $byLine[5], '342 napárováno podle kódu účtu.');
    }

    public function testUnmatchedRowsAreReportedSeparately(): void
    {
        $csv = "Položka;Částka\n"
            . "521;50000\n"
            . "Stravenkový paušál;3000\n"; // neexistuje v šabloně

        $matcher = new TemplateCsvMatcher();
        $result = $matcher->match($this->payrollTemplateLines(), $csv);

        self::assertSame(1, $result['matched_count']);
        self::assertCount(1, $result['unmatched']);
        self::assertSame('Stravenkový paušál', $result['unmatched'][0]['value']);
        self::assertSame(3000.0, $result['unmatched'][0]['amount']);
    }

    public function testCzechDecimalCommaIsParsed(): void
    {
        $csv = "521;50 000,50\n";

        $matcher = new TemplateCsvMatcher();
        $result = $matcher->match($this->payrollTemplateLines(), $csv);

        $byLine = [];
        foreach ($result['lines'] as $l) {
            $byLine[$l['line_no']] = $l['amount'];
        }
        self::assertSame(50000.5, $byLine[1]);
    }

    public function testHeaderRowWithoutNumericAmountIsSkipped(): void
    {
        // Bez explicitní hlavičky, jen řádek, jehož druhý sloupec neparsuje jako číslo.
        $csv = "Nazev;Castka\n521;10000\n";

        $matcher = new TemplateCsvMatcher();
        $result = $matcher->match($this->payrollTemplateLines(), $csv);

        self::assertSame(1, $result['matched_count'], 'Hlavičkový řádek se tiše přeskočí, jen datový se napáruje.');
    }

    public function testDefaultAmountKeptWhenNoCsvRowMatches(): void
    {
        $lines = $this->payrollTemplateLines();
        $lines[0]['default_amount'] = 45000.0;

        $matcher = new TemplateCsvMatcher();
        $result = $matcher->match($lines, "336;12000\n");

        $byLine = [];
        foreach ($result['lines'] as $l) {
            $byLine[$l['line_no']] = $l['amount'];
        }
        self::assertSame(45000.0, $byLine[1], 'Bez CSV shody se zachová výchozí částka šablony.');
        self::assertSame(12000.0, $byLine[4]);
    }
}
