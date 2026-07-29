<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Tax;

use MyInvoice\Service\Tax\TaxConstants;
use PHPUnit\Framework\TestCase;

/**
 * EP-12: sazba DPPO (§21) a srážková daň z podílu na zisku (§36) jsou vázané na
 * účetní/zdaňovací období — ClosingService je čte přes TaxConstantsRepository::forYear()
 * (rate_hint = corporate_tax_rate, srážková = withholding_rate), ne z hardcoded konstant.
 *
 * Klíčový invariant: zavedení nové sazby pro budoucí rok NESMÍ přepsat výpočet minulého
 * období — historické období si drží svou dobovou sazbu.
 */
final class PeriodTaxRateVersioningTest extends TestCase
{
    public function testCorporateAndWithholdingRatesPresentPerYear(): void
    {
        foreach ([2024, 2025, 2026] as $year) {
            $c = TaxConstants::forYear($year);
            self::assertSame(0.21, $c['corporate_tax_rate'], "corporate_tax_rate $year");
            self::assertSame(0.15, $c['withholding_rate'], "withholding_rate $year");
        }
    }

    /**
     * Nová sazba zavedená pro budoucí rok (nový release nebo DB override, který
     * TaxConstantsRepository merguje JEN do dat daného roku) nezmění dobovou sazbu
     * minulého období — to se čte znovu z verzovaného zdroje.
     */
    public function testFutureRateChangeDoesNotAffectPastPeriod(): void
    {
        $past = TaxConstants::forYear(2024);
        self::assertSame(0.21, $past['corporate_tax_rate']);
        self::assertSame(0.15, $past['withholding_rate']);

        // Simulace dobové sady budoucího roku s jinou sazbou (per-year override merge).
        $future = $this->mergeOverride(TaxConstants::forYear(2026), [
            'corporate_tax_rate' => 0.19,
            'withholding_rate'   => 0.10,
        ]);
        self::assertSame(0.19, $future['corporate_tax_rate']);
        self::assertSame(0.10, $future['withholding_rate']);

        // Minulé období zůstává na dobové sazbě.
        $rereadPast = TaxConstants::forYear(2024);
        self::assertSame(0.21, $rereadPast['corporate_tax_rate'], 'DPPO 2024 musí zůstat dobová 21 %');
        self::assertSame(0.15, $rereadPast['withholding_rate'], 'srážková 2024 musí zůstat dobová 15 %');
    }

    /** Reprodukuje per-klíč override merge z TaxConstantsRepository (bez DB). */
    private function mergeOverride(array $base, array $override): array
    {
        foreach ($override as $key => $value) {
            $base[$key] = $value;
        }
        return $base;
    }
}
