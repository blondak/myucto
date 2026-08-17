<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Submission\HealthInsurance;

/**
 * Přehled o platbě pojistného zaměstnavatele (PPZ) pro jednu pojišťovnu.
 *
 * Dvě pasti typového systému schématu, které se musí ohlídat TADY, protože
 * XSD je odhalí až po sestavení dokumentu:
 *
 * - `pocetZamestnancu` je `positiveInteger` — **nula neprojde**. Měsíc bez
 *   započtené osoby se tedy nepodává jako nulový přehled, protože ho datová
 *   věta neumí vyjádřit.
 * - `soucetPojistneho` je `nonNegativeInteger`, tedy CELÉ KORUNY. Haléře
 *   se do věty nevejdou a zaokrouhlovat je smí jen ten, kdo počítal pojistné,
 *   ne serializér.
 */
final readonly class HealthPaymentOverviewPayload
{
    public const KIND_REGULAR = 'radny';
    public const KIND_CORRECTIVE = 'opravny';

    public function __construct(
        public string $insurerCode,
        public string $overviewKind,
        public HealthEmployerIdentification $employer,
        public int $month,
        public int $year,
        public int $employeeCount,
        public int $assessmentBaseMinorUnits,
        public int $contributionCzk,
        public ?string $internalReference = null,
    ) {}

    public function assertValid(HealthInsuranceSchemaCatalog $schemas): void
    {
        $schemas->assertInsurerCode($this->insurerCode);
        $this->employer->assertValid();
        if (!in_array(
            $this->overviewKind,
            [self::KIND_REGULAR, self::KIND_CORRECTIVE],
            true,
        )) {
            throw new HealthNotificationException(
                'zp_overview_kind_invalid',
                'Typ přehledu musí být řádný nebo opravný.',
            );
        }
        if ($this->month < 1 || $this->month > 12) {
            throw new HealthNotificationException(
                'zp_overview_month_invalid',
                'Měsíc hlášení musí být 1 až 12.',
            );
        }
        if ($this->year < 2000 || $this->year > 2099) {
            throw new HealthNotificationException(
                'zp_overview_year_invalid',
                'Rok hlášení musí být 2000 až 2099.',
            );
        }
        if ($this->employeeCount < 1) {
            throw new HealthNotificationException(
                'zp_overview_employee_count_invalid',
                'Datová věta neumí přehled bez zaměstnance: počet je '
                . 'positiveInteger, takže nula neprojde. Za měsíc bez '
                . 'započtené osoby se přehled touhle cestou nepodává.',
            );
        }
        if ($this->assessmentBaseMinorUnits < 0) {
            throw new HealthNotificationException(
                'zp_overview_assessment_base_invalid',
                'Součet vyměřovacích základů nesmí být záporný.',
            );
        }
        if ($this->contributionCzk < 0) {
            throw new HealthNotificationException(
                'zp_overview_contribution_invalid',
                'Součet pojistného nesmí být záporný.',
            );
        }
        if ($this->internalReference !== null
            && preg_match('/^[0-9A-Za-z._:-]{1,64}$/', $this->internalReference) !== 1
        ) {
            throw new HealthNotificationException(
                'zp_internal_reference_invalid',
                'Interní identifikace podání smí obsahovat jen bezpečné znaky.',
            );
        }
    }

    /** Vyměřovací základ v korunách s dvěma desetinnými místy, jak žádá XSD. */
    public function assessmentBaseDecimal(): string
    {
        $sign = $this->assessmentBaseMinorUnits < 0 ? '-' : '';
        $absolute = abs($this->assessmentBaseMinorUnits);

        return sprintf(
            '%s%d.%02d',
            $sign,
            intdiv($absolute, 100),
            $absolute % 100,
        );
    }

    public function period(): string
    {
        return sprintf('%04d-%02d', $this->year, $this->month);
    }
}
