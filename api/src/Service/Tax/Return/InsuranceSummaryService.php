<?php

declare(strict_types=1);

namespace MyInvoice\Service\Tax\Return;

use MyInvoice\Repository\TaxConstantsRepository;
use MyInvoice\Service\Report\CzechWorkingDays;
use MyInvoice\Repository\TaxReturnRepository;

/**
 * Přehledy pojistného OSVČ (ČSSZ + zdravotní pojišťovna) — Epic DP (issue #18).
 *
 * Skládá daňový základ §7 (z {@see DpfoReturnDataProvider}) + zaplacené zálohy
 * (z ručních vstupů přiznání) → {@see SocialInsuranceCalculator} a
 * {@see HealthInsuranceCalculator} → strukturovaný podklad pro Přehledy + PDF.
 */
final class InsuranceSummaryService
{
    public function __construct(
        private readonly DpfoReturnDataProvider $dpfoData,
        private readonly TaxReturnRepository $returns,
        private readonly TaxConstantsRepository $constants,
        private readonly SocialInsuranceCalculator $social,
        private readonly HealthInsuranceCalculator $health,
    ) {}

    /**
     * @return array<string,mixed>
     */
    public function build(int $supplierId, int $year): array
    {
        try {
            $c = $this->constants->forExactYear($year);
        } catch (\OutOfRangeException $e) {
            throw new TaxReturnException('missing_tax_constants', $e->getMessage(), 422);
        }
        $row = $this->returns->find($supplierId, $year, 'fo');
        $inputs = $row !== null ? (array) $row['inputs'] : [];
        // §7 z VH (double_entry) musí zahrnout stejné §25/§23 úpravy jako uložené přiznání,
        // aby přehledy pojistného a přiznání dávaly shodný daňový základ §7 (Fáze E nález N1).
        $data = $this->dpfoData->gather($supplierId, $year, $inputs);
        $profile = (array) $data['profile'];
        $taxBase7 = round((float) $data['s7_base'], 2);
        $isSecondary = !empty($profile['is_secondary']);
        $months = (array) ($profile['osvc_months'] ?? []);

        $socialAdvances = max(0.0, (float) ($inputs['social_paid_advances'] ?? 0));
        $healthAdvances = max(0.0, (float) ($inputs['health_paid_advances'] ?? 0));

        $social = $this->social->compute(
            $taxBase7,
            $isSecondary,
            $socialAdvances,
            !empty($profile['sickness_insured']),
            isset($profile['sickness_monthly_base']) ? (int) $profile['sickness_monthly_base'] : null,
            $c,
            $months,
        );
        $health = $this->health->compute($taxBase7, $isSecondary, $healthAdvances, $c, $months);

        $warnings = $data['warnings'];
        if ($taxBase7 <= 0) {
            $warnings[] = 'Daňový základ §7 je nulový/záporný — pojistné se počítá z minimálních vyměřovacích základů.';
        }

        return [
            'year' => $year,
            'tax_base_7' => $taxBase7,
            'is_secondary' => $isSecondary,
            'months' => $months,
            'social' => $social,
            'health' => $health,
            'deadlines' => $this->deadlines($year, (array) ($c['filing_deadlines'] ?? [])),
            'rates' => [
                'social' => (float) ($c['social_rate'] ?? 0.292),
                'health' => (float) ($c['health_rate'] ?? 0.135),
                'sickness' => (float) ($c['sickness_rate'] ?? 0.027),
                'social_assessment_pct' => (float) ($c['social_assessment_pct'] ?? 0.55),
                'health_assessment_pct' => (float) ($c['health_assessment_pct'] ?? 0.50),
            ],
            'warnings' => $warnings,
        ];
    }

    /**
     * Termíny přehledů: do 1 měsíce po lhůtě pro elektronické daňové přiznání (2. 5.,
     * s daňovým poradcem 1. 8. následujícího roku). Orientační.
     *
     * @return array{social:string,health:string,note:string}
     */
    private function deadlines(int $year, array $rules): array
    {
        $next = $year + 1;
        $regular = CzechWorkingDays::deadlineFromMonthDay(
            $next,
            (string) ($rules['insurance_electronic'] ?? '06-02'),
        );
        $advisor = CzechWorkingDays::deadlineFromMonthDay(
            $next,
            (string) ($rules['insurance_advisor'] ?? '08-01'),
        );
        return [
            'social' => $regular,
            'health' => $regular,
            'note' => sprintf('Přehledy se podávají v řádné lhůtě do %s a s daňovým poradcem do %s.',
                $regular, $advisor),
        ];
    }
}
