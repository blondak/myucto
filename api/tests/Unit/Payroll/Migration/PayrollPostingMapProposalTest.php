<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Payroll\Migration;

use MyInvoice\Service\Payroll\Migration\PayrollLegacyAccountCode;
use MyInvoice\Service\Payroll\Migration\PayrollLegacyPostingRow;
use MyInvoice\Service\Payroll\Migration\PayrollPostingMapProposalBuilder;
use MyInvoice\Service\Payroll\PayrollAccountingDefaults;
use PHPUnit\Framework\TestCase;

/**
 * Odvození mzdových předkontací z převzatého zaúčtování (PAM-16).
 *
 * Testuje se právě to, co se dá udělat špatně a přitom to vypadá hotově:
 * jednoznačný účet se musí odvodit, ROZPOR se nesmí rozhodnout většinou, účet
 * mimo osnovu se nesmí nabídnout jako hotová volba a NEPOTVRZENÝ návrh nesmí
 * nastavení změnit. Data jsou syntetická.
 */
final class PayrollPostingMapProposalTest extends TestCase
{
    /** Osnova firmy: 336.300 ani 524.900 v ní záměrně NEJSOU. */
    private const CHART = ['521', '331', '336.200', '342.100', '524', '379.100'];

    public function testDerivesUnambiguousAccountWithEvidence(): void
    {
        $keys = $this->buildKeys();

        $debit = $keys['employment_gross_debit'];
        self::assertSame(PayrollPostingMapProposalBuilder::STATUS_UNAMBIGUOUS, $debit['status']);
        self::assertSame('521', $debit['suggested_code']);
        self::assertCount(1, $debit['candidates']);
        self::assertSame(1200, $debit['candidates'][0]['line_count']);
        self::assertSame(5_000_000, $debit['candidates'][0]['amount_minor']);
        self::assertSame(['521000'], $debit['candidates'][0]['source_codes']);
        self::assertTrue($debit['candidates'][0]['in_chart']);
        self::assertSame(['SKLAD', 'VYROBA'], $debit['candidates'][0]['cost_centers']);

        // Závazkový účet mzdy potvrzuje i strana MD u pojistného a u srážky -
        // doklad se sčítá přes všechny významy, které na tentýž klíč míří.
        $credit = $keys['employment_gross_credit'];
        self::assertSame(PayrollPostingMapProposalBuilder::STATUS_UNAMBIGUOUS, $credit['status']);
        self::assertSame('331', $credit['suggested_code']);
        self::assertSame(1200 + 200 + 60 + 40, $credit['candidates'][0]['line_count']);
    }

    public function testConflictIsShownAndNeverDecidedByMajority(): void
    {
        $health = $this->buildKeys()['health_insurance_credit'];

        self::assertSame(PayrollPostingMapProposalBuilder::STATUS_CONFLICT, $health['status']);
        self::assertNull($health['suggested_code']);
        self::assertSame(
            ['336.200', '336.300'],
            array_column($health['candidates'], 'code'),
        );
        // Většinový účet je sice první (řadí se podle počtu řádků), ale
        // doporučením se tím NESTÁVÁ - obě pojišťovny mohou být správně.
        self::assertSame(200, $health['candidates'][0]['line_count']);
        self::assertSame(60, $health['candidates'][1]['line_count']);
        self::assertTrue($health['candidates'][0]['in_chart']);
        self::assertFalse($health['candidates'][1]['in_chart']);
    }

    public function testAccountOutsideChartIsFlaggedAndNotSuggested(): void
    {
        $insurance = $this->buildKeys()['employer_insurance_debit'];

        self::assertSame(PayrollPostingMapProposalBuilder::STATUS_OUTSIDE_CHART, $insurance['status']);
        self::assertNull($insurance['suggested_code']);
        self::assertSame('524.900', $insurance['candidates'][0]['code']);
        self::assertSame(['524900'], $insurance['candidates'][0]['source_codes']);
        self::assertFalse($insurance['candidates'][0]['in_chart']);
        // Syntetika je jen informace („524 v osnově máte"), ne návrh.
        self::assertSame('524', $insurance['candidates'][0]['synthetic_code']);
        self::assertTrue($insurance['candidates'][0]['synthetic_in_chart']);
    }

    public function testUnderivableMeaningStaysOnDefault(): void
    {
        $travel = $this->buildKeys()['travel_expense_debit'];

        self::assertSame(PayrollPostingMapProposalBuilder::STATUS_MISSING, $travel['status']);
        self::assertNull($travel['suggested_code']);
        self::assertSame([], $travel['candidates']);
        self::assertSame(
            PayrollAccountingDefaults::defaultCode('travel_expense_debit'),
            $travel['current_code'],
        );
    }

    public function testUnrecognizedRowIsReportedNotDropped(): void
    {
        $proposal = $this->buildProposal();

        self::assertCount(1, $proposal['unmapped']);
        self::assertSame('Zaúčtování zálohy zaměstnance', $proposal['unmapped'][0]['label']);
        self::assertSame('331', $proposal['unmapped'][0]['debit_code']);
        self::assertSame('379', $proposal['unmapped'][0]['credit_code']);
        self::assertSame(1, $proposal['summary']['conflict']);
        self::assertSame(1, $proposal['summary']['outside_chart']);
    }

    public function testUnconfirmedProposalChangesNothing(): void
    {
        $current = PayrollAccountingDefaults::codes();

        self::assertSame(
            $current,
            PayrollPostingMapProposalBuilder::confirmedAccounts($current, []),
        );
    }

    public function testConfirmationChangesOnlyConfirmedKeys(): void
    {
        $current = PayrollAccountingDefaults::codes();
        $confirmed = PayrollPostingMapProposalBuilder::confirmedAccounts($current, [
            'employment_gross_debit' => '521.100',
        ]);

        self::assertSame('521.100', $confirmed['employment_gross_debit']);
        unset($confirmed['employment_gross_debit'], $current['employment_gross_debit']);
        self::assertSame($current, $confirmed);
    }

    public function testConfirmationRejectsUnknownKeyAndEmptyAccount(): void
    {
        $current = PayrollAccountingDefaults::codes();

        $this->expectException(\InvalidArgumentException::class);
        PayrollPostingMapProposalBuilder::confirmedAccounts($current, ['made_up_key' => '521']);
    }

    public function testConfirmationRejectsEmptyAccount(): void
    {
        $current = PayrollAccountingDefaults::codes();

        $this->expectException(\InvalidArgumentException::class);
        PayrollPostingMapProposalBuilder::confirmedAccounts($current, ['employment_gross_debit' => '  ']);
    }

    public function testAccountCodeNormalizationKeepsAnalyticsLiteral(): void
    {
        self::assertSame('521', PayrollLegacyAccountCode::normalize('521000'));
        self::assertSame('336.001', PayrollLegacyAccountCode::normalize('336001'));
        self::assertSame('336.100', PayrollLegacyAccountCode::normalize('336.100'));
        self::assertNull(PayrollLegacyAccountCode::normalize('  '));
        self::assertSame('336', PayrollLegacyAccountCode::synthetic('336.001'));
    }

    /** @return array<string,array<string,mixed>> */
    private function buildKeys(): array
    {
        return array_column($this->buildProposal()['keys'], null, 'key');
    }

    /** @return array<string,mixed> */
    private function buildProposal(): array
    {
        return PayrollPostingMapProposalBuilder::build(
            'pamica',
            $this->rows(),
            self::CHART,
            PayrollAccountingDefaults::codes(),
        );
    }

    /** @return list<PayrollLegacyPostingRow> */
    private function rows(): array
    {
        return [
            $this->row('employment_gross', 'Hrubá mzda zaměstnance', '521', '331', '521000', '331000', 1200, 5_000_000, ['VYROBA', 'SKLAD']),
            // Dvě zdravotní pojišťovny, každá na své analytice - rozpor, který
            // se nesmí rozhodnout za účetní.
            $this->row('employee_health', 'Zdravotní pojištění (zaměstnanec) VZP', '331', '336.200', '331000', '336200', 200, 400_000),
            $this->row('employee_health', 'Zdravotní pojištění (zaměstnanec) ČPZP', '331', '336.300', '331000', '336300', 60, 120_000),
            // Nákladový účet pojistného, který firma v osnově nemá.
            $this->row('employer_social', 'Sociální pojištění (firma)', '524.900', null, '524900', null, 180, 1_100_000),
            $this->row('other_deductions', 'Srážky ze mzdy zaměstnance', '331', '379.100', '331000', '379100', 40, 60_000),
            // Plnění, pro které MyÚčto předkontaci nemá; musí být vidět.
            $this->row(null, 'Zaúčtování zálohy zaměstnance', '331', '379', '331000', '379000', 12, 90_000),
        ];
    }

    /** @param list<string> $costCenters */
    private function row(
        ?string $concept,
        string $label,
        ?string $debit,
        ?string $credit,
        ?string $debitSource,
        ?string $creditSource,
        int $lines,
        int $amount,
        array $costCenters = [],
    ): PayrollLegacyPostingRow {
        return new PayrollLegacyPostingRow(
            'pamica',
            'pamica:pPK:' . $label,
            $concept,
            $label,
            $debit,
            $credit,
            $debitSource,
            $creditSource,
            $lines,
            $amount,
            $costCenters,
        );
    }
}
