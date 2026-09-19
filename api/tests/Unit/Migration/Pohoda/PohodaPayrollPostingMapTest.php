<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Migration\Pohoda;

use MyInvoice\Service\Migration\Pohoda\Payroll\PohodaPayrollPostingMap;
use MyInvoice\Service\Payroll\Migration\PayrollLegacyPostingRow;
use MyInvoice\Service\Payroll\Migration\PayrollPostingMapProposalBuilder;
use MyInvoice\Service\Payroll\PayrollAccountingDefaults;
use PHPUnit\Framework\TestCase;

/**
 * Čtečka převzatého zaúčtování z `91_mzdy.xml` (PAM-16).
 *
 * Hlídá tři věci, na kterých se to dá utrhnout: význam jde z číselníku
 * předkontací (ne z čísla položky), strany MD/D se berou z číselníku (v řádku
 * jsou u záporné částky PROHOZENÉ) a analytika se převádí DOSLOVNĚ. Data jsou
 * syntetická.
 */
final class PohodaPayrollPostingMapTest extends TestCase
{
    private string $tmp = '';

    protected function setUp(): void
    {
        $this->tmp = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'pohoda_posting_map_' . bin2hex(random_bytes(5));
        mkdir($this->tmp, 0755, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->tmp . DIRECTORY_SEPARATOR . '*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($this->tmp);
    }

    public function testReadsMeaningAccountsAndCostCentres(): void
    {
        $rows = $this->rowsByConcept();

        $gross = $rows['employment_gross'];
        self::assertSame('521', $gross->debitAccount);
        self::assertSame('331', $gross->creditAccount);
        self::assertSame(2, $gross->lineCount);
        self::assertSame(3_700_000, $gross->amountMinor);
        // Středisko z řádku i z rozúčtování (`MZzauctRoz`).
        self::assertSame(['PLZ', 'SKLAD', 'VYROBA'], $gross->costCenters);

        $partner = $rows['partner_gross'];
        self::assertSame('522', $partner->debitAccount);
        self::assertSame('366', $partner->creditAccount);
    }

    public function testSwappedSidesOfNegativeRowComeFromCatalog(): void
    {
        // Sražené pojistné má v exportu zápornou částku a UMD/UD prohozené.
        // Bez opory v číselníku by 336 skončilo jako nákladová strana.
        $social = $this->rowsByConcept()['employee_social'];

        self::assertSame('331', $social->debitAccount);
        self::assertSame('336.001', $social->creditAccount);
        self::assertSame('336001', $social->creditSourceCode);
        self::assertSame(240_500, $social->amountMinor);
    }

    public function testSharedCatalogIdsFallsBackToTheOnlyMeaningfulEntry(): void
    {
        // „331000/379000" je ve výchozím číselníku dvakrát: záloha (bez
        // protějšku v MyÚčtu) a srážky. Prázdný význam nic netvrdí, takže
        // nesmí přebít ten jediný, který význam má.
        $deduction = $this->rowsByConcept()['other_deductions'];

        self::assertSame('331', $deduction->debitAccount);
        self::assertSame('379', $deduction->creditAccount);
        self::assertStringContainsString('Srážky ze mzdy', $deduction->label);
        self::assertStringContainsString('zálohy', $deduction->label);
    }

    public function testRowWithoutCatalogEntryStaysVisibleAndOtherYearIsSkipped(): void
    {
        $rows = PohodaPayrollPostingMap::read($this->write(), 2026)->postingRows();
        $unmapped = array_values(array_filter(
            $rows,
            static fn (PayrollLegacyPostingRow $row): bool => $row->concept === null,
        ));

        self::assertCount(1, $unmapped);
        self::assertSame('Zaúčtování bez předkontace', $unmapped[0]->label);
        self::assertSame(50_000, $unmapped[0]->amountMinor);
        // Řádek roku 2025 se do převodu roku 2026 nedostal.
        self::assertSame(
            6,
            array_sum(array_map(static fn (PayrollLegacyPostingRow $row): int => $row->lineCount, $rows)),
        );
    }

    public function testFeedsTheGenericProposalBuilder(): void
    {
        $proposal = PayrollPostingMapProposalBuilder::build(
            PohodaPayrollPostingMap::SOURCE,
            PohodaPayrollPostingMap::read($this->write(), 2026)->postingRows(),
            ['521', '331', '522', '366', '379'],
            PayrollAccountingDefaults::codes(),
        );
        $keys = array_column($proposal['keys'], null, 'key');

        self::assertSame('521', $keys['employment_gross_debit']['suggested_code']);
        self::assertSame('331', $keys['employment_gross_credit']['suggested_code']);
        self::assertSame('522', $keys['partner_gross_debit']['suggested_code']);
        // 336.001 z původního programu v osnově není, takže se nenabídne.
        self::assertSame(
            PayrollPostingMapProposalBuilder::STATUS_OUTSIDE_CHART,
            $keys['social_insurance_credit']['status'],
        );
        self::assertNull($keys['social_insurance_credit']['suggested_code']);
    }

    /** @return array<string,PayrollLegacyPostingRow> */
    private function rowsByConcept(): array
    {
        $out = [];
        foreach (PohodaPayrollPostingMap::read($this->write(), 2026)->postingRows() as $row) {
            if ($row->concept !== null) {
                $out[$row->concept] = $row;
            }
        }

        return $out;
    }

    private function write(): string
    {
        $xml = '';
        $row = static function (string $table, array $cols) use (&$xml): void {
            $xml .= "<{$table}>";
            foreach ($cols as $key => $value) {
                $xml .= "<{$key}>" . htmlspecialchars((string) $value, ENT_XML1) . "</{$key}>";
            }
            $xml .= "</{$table}>";
        };

        $row('pOS', ['ID' => 1, 'Ucet' => '521000', 'Nazev' => 'Mzdové náklady']);
        $row('pOS', ['ID' => 2, 'Ucet' => '331000', 'Nazev' => 'Zaměstnanci']);
        $row('pOS', ['ID' => 3, 'Ucet' => '336001', 'Nazev' => 'Sociální pojištění']);

        $row('pPK', ['ID' => 1, 'IDS' => '521000/331000', 'SText' => 'Hrubá mzda zaměstnance', 'UMD' => '521000', 'UD' => '331000']);
        $row('pPK', ['ID' => 2, 'IDS' => '331000/336001', 'SText' => 'Sociální pojištění (zaměstnanec)', 'UMD' => '331000', 'UD' => '336001']);
        $row('pPK', ['ID' => 3, 'IDS' => '331000/379000', 'SText' => 'Zaúčtování zálohy zaměstnance', 'UMD' => '331000', 'UD' => '379000']);
        $row('pPK', ['ID' => 4, 'IDS' => '331000/379000', 'SText' => 'Srážky ze mzdy zaměstnance', 'UMD' => '331000', 'UD' => '379000']);
        $row('pPK', ['ID' => 5, 'IDS' => '522000/366000', 'SText' => 'Hrubá mzda společníka', 'UMD' => '522000', 'UD' => '366000']);

        $row('MZzauct', ['ID' => 1, 'Rok' => 2026, 'RelMes' => 1, 'Kc' => 25000, 'ResPk' => '521000/331000',
            'UMD' => '521000', 'UD' => '331000', 'ResStr' => 'VYROBA', 'Spolec' => 0]);
        $row('MZzauct', ['ID' => 2, 'Rok' => 2026, 'RelMes' => 1, 'Kc' => 12000, 'ResPk' => '521000/331000',
            'UMD' => '521000', 'UD' => '331000', 'ResStr' => 'SKLAD', 'Spolec' => 0]);
        // Záporná částka a prohozené strany - přesně jak to píše původní program.
        $row('MZzauct', ['ID' => 3, 'Rok' => 2026, 'RelMes' => 1, 'Kc' => -2405, 'ResPk' => '331000/336001',
            'UMD' => '336001', 'UD' => '331000', 'Spolec' => 0]);
        $row('MZzauct', ['ID' => 4, 'Rok' => 2026, 'RelMes' => 1, 'Kc' => -1500, 'ResPk' => '331000/379000',
            'UMD' => '379000', 'UD' => '331000', 'Spolec' => 0]);
        $row('MZzauct', ['ID' => 5, 'Rok' => 2025, 'RelMes' => 12, 'Kc' => 9999, 'ResPk' => '521000/331000',
            'UMD' => '521000', 'UD' => '331000', 'Spolec' => 0]);
        $row('MZzauct', ['ID' => 6, 'Rok' => 2026, 'RelMes' => 2, 'Kc' => 500, 'ResPk' => '', 'Spolec' => 0]);
        $row('MZzauct', ['ID' => 7, 'Rok' => 2026, 'RelMes' => 1, 'Kc' => 40000, 'ResPk' => '522000/366000',
            'UMD' => '522000', 'UD' => '366000', 'Spolec' => 1]);

        $row('MZzauctRoz', ['ID' => 1, 'Rok' => 2026, 'RefMZzauct' => 1, 'ResStrSl' => 'PLZ']);

        $file = $this->tmp . DIRECTORY_SEPARATOR . '91_mzdy.xml';
        file_put_contents($file, '<?xml version="1.0" encoding="UTF-8"?>' . "\n"
            . '<mdbExport version="1" group="mzdy" ico="12345678" year="2026" source="POHODA" state="ok">'
            . $xml . '</mdbExport>');

        return $file;
    }
}
