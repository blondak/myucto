<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Time\Surcharge;

use JsonSerializable;
use MyInvoice\Service\Payroll\Calculation\CalculationStep;
use MyInvoice\Service\Payroll\Calculation\DecimalRate;
use MyInvoice\Service\Payroll\Calculation\RoundingMode;

/**
 * Výsledek jednoho druhu příplatku za měsíc, i s nezaokrouhleným zlomkem.
 *
 * ── Proč se nepoužívá {@see CalculationStep} na samotnou částku ──────────────
 *
 * `CalculationStep` umí `vstup × čitatel / jmenovatel`, ale jmenovatel bere
 * z {@see DecimalRate}, tedy mocninu deseti. Příplatek potřebuje jmenovatel
 * `jmenovatel_sazby × 60`, protože se počítá z HODINOVÉHO základu za MINUTY.
 * Kdyby se to poskládalo ze dvou kroků, zaokrouhlovalo by se dvakrát a chyba by
 * se přes desítky hodin kumulovala — u dvousměnného provozu klidně o koruny
 * měsíčně, systematicky v neprospěch zaměstnance.
 *
 * Řádek proto počítá JEDNÍM zlomkem a nese ho celý, stejně jako to dělá
 * {@see \MyInvoice\Service\Payroll\Absence\SicknessCompensationCalculator}.
 * `CalculationStep` se používá tam, kde je přesný: na odvození HODINOVÉ sazby
 * příplatku, které se čte na výplatní pásce.
 *
 * ── Procento, nebo PEVNÁ ČÁSTKA ─────────────────────────────────────────────
 *
 * Sjednat jde obojí (viz {@see PayrollSurchargePolicy}) a liší se jen tím, jak
 * vznikne HODINOVÁ sazba příplatku: u procenta se spočítá ze základu, u pevné
 * částky je sjednaná rovnou. Násobení minutami a jedno zaokrouhlení na konci je
 * pak u obou totéž.
 *
 * ── Zákonné minimum u pevné částky ──────────────────────────────────────────
 *
 * Tady, a nikde jinde. Zásada sama porovnat nemůže, protože zákonné minimum je
 * podíl z průměrného výdělku konkrétního člověka, a ten se zjistí až ve mzdě —
 * táž sjednaná čtyřicetikoruna je nad minimem u jednoho a pod ním u druhého.
 *
 * Když je sjednaná částka nižší než zákonná, počítá se ZÁKONNÁ. Není to
 * velkorysost: u § 114, § 115 a § 117 je „nejméně" kogentní a nižší sjednání je
 * v tom rozsahu neplatné, takže vyplatit míň by byl nedoplatek. U § 116 a § 118
 * se nižší sjednat SMÍ — tam se tedy ctí sjednaná částka a nic se nedopočítává.
 * V obou případech ale řádek nese `belowStatutory`, aby účtárna viděla, že se
 * sjednané číslo a vyplacené číslo rozešly, a mohla sjednání opravit.
 */
final readonly class PayrollSurchargeLine implements JsonSerializable
{
    /**
     * @param list<array{local_date:string,minutes:int,factors:int,weighted_minutes:int}> $segments
     * @param list<array<string,mixed>> $waivedSegments doba, za kterou se příplatek nepočítá
     */
    private function __construct(
        public PayrollSurchargeKind $kind,
        public PayrollSurchargeBasis $basis,
        public int $basisHourlyMinor,
        public DecimalRate $rate,
        public bool $rateIsAgreed,
        public int $minutes,
        public int $weightedMinutes,
        public int $unroundedNumerator,
        public int $unroundedDenominator,
        public RoundingMode $roundingMode,
        public int $amountMinor,
        public CalculationStep $hourlySurchargeStep,
        public array $segments,
        public array $waivedSegments,
        /** Sjednaná pevná částka za hodinu, nebo `null` u sazby v procentech. */
        public ?int $agreedFixedHourlyMinor = null,
        /** Sjednaná pevná částka nedosáhla zákonného minima. */
        public bool $belowStatutory = false,
        /** Hodinová částka, na kterou se skutečně počítalo. */
        public ?int $appliedFixedHourlyMinor = null,
    ) {}

    /**
     * @param list<PayrollSurchargeSegment> $segments
     * @param list<array<string,mixed>> $waivedSegments
     * @param int|null $agreedFixedHourlyMinor sjednaná pevná částka v haléřích za
     *        hodinu; je-li zadaná, `$rate` slouží už jen jako zákonné minimum,
     *        proti kterému se poměřuje
     */
    public static function calculate(
        PayrollSurchargeKind $kind,
        PayrollSurchargeBasis $basis,
        int $basisHourlyMinor,
        DecimalRate $rate,
        bool $rateIsAgreed,
        array $segments,
        array $waivedSegments = [],
        ?int $agreedFixedHourlyMinor = null,
    ): self {
        if ($basisHourlyMinor <= 0) {
            throw PayrollSurchargeException::of(
                'basis_missing',
                sprintf(
                    'Příplatek %s nelze spočítat: %s musí být kladný.',
                    $kind->section(),
                    $basis->label(),
                ),
            );
        }
        if ($agreedFixedHourlyMinor !== null && $agreedFixedHourlyMinor <= 0) {
            throw PayrollSurchargeException::of(
                'fixed_hourly_invalid',
                sprintf(
                    'Sjednaná pevná částka příplatku %s musí být kladná.',
                    $kind->section(),
                ),
            );
        }

        $minutes = 0;
        $weighted = 0;
        $trace = [];
        foreach ($segments as $segment) {
            if ($segment->kind !== $kind) {
                throw PayrollSurchargeException::of(
                    'segment_kind_mismatch',
                    'Řádek příplatku dostal dobu jiného druhu.',
                );
            }
            $minutes += $segment->minutes;
            $weighted += $segment->weightedMinutes();
            $trace[] = [
                'local_date' => $segment->localDate,
                'minutes' => $segment->minutes,
                'factors' => $segment->factors,
                'weighted_minutes' => $segment->weightedMinutes(),
            ];
        }

        // Hodinová sazba příplatku je exaktní krok: základ × sazba, jmenovatel je
        // mocnina deseti, žádné minuty se do něj nepletou.
        $hourlyStep = CalculationStep::calculate(
            "surcharge.{$kind->value}.hourly",
            $basisHourlyMinor,
            $rate,
            RoundingMode::HalfUp,
        );

        if ($agreedFixedHourlyMinor === null) {
            $numerator = self::multiplyExactly(
                self::multiplyExactly($basisHourlyMinor, $rate->numerator),
                $weighted,
            );
            $denominator = self::multiplyExactly($rate->denominator, 60);

            return new self(
                $kind,
                $basis,
                $basisHourlyMinor,
                $rate,
                $rateIsAgreed,
                $minutes,
                $weighted,
                $numerator,
                $denominator,
                RoundingMode::HalfUp,
                RoundingMode::HalfUp->roundFraction($numerator, $denominator),
                $hourlyStep,
                $trace,
                $waivedSegments,
            );
        }

        // Zákonné minimum v haléřích za hodinu. Porovnává se AŽ TADY, protože
        // dřív se neví, z jakého základu se počítá — u § 117 je to minimální
        // mzda, u ostatních průměrný výdělek konkrétního člověka.
        $statutoryHourlyMinor = $hourlyStep->outputMinorUnits;
        $belowStatutory = $agreedFixedHourlyMinor < $statutoryHourlyMinor;
        // Podlézt smí jen § 116 a § 118. Jinde je „nejméně" kogentní, takže se
        // dopočítá zákonná částka — vyplatit sjednanou nižší by byl nedoplatek.
        $appliedHourlyMinor = $belowStatutory && !$kind->allowsLowerAgreedRate()
            ? $statutoryHourlyMinor
            : $agreedFixedHourlyMinor;

        // Týž tvar zlomku jako u procenta: násobí se MINUTAMI a dělí šedesáti,
        // aby se zaokrouhlovalo jednou za měsíc, ne po hodinách.
        $numerator = self::multiplyExactly($appliedHourlyMinor, $weighted);

        return new self(
            $kind,
            $basis,
            $basisHourlyMinor,
            $rate,
            $rateIsAgreed,
            $minutes,
            $weighted,
            $numerator,
            60,
            RoundingMode::HalfUp,
            RoundingMode::HalfUp->roundFraction($numerator, 60),
            $hourlyStep,
            $trace,
            $waivedSegments,
            $agreedFixedHourlyMinor,
            $belowStatutory,
            $appliedHourlyMinor,
        );
    }

    private static function multiplyExactly(int $left, int $right): int
    {
        if ($left < 0 || $right < 0) {
            throw PayrollSurchargeException::of(
                'negative_factor',
                'Výpočet příplatku nepracuje se zápornými činiteli.',
            );
        }
        if ($left !== 0 && $right > intdiv(PHP_INT_MAX, $left)) {
            throw PayrollSurchargeException::of(
                'overflow',
                'Výpočet příplatku překročil celočíselný rozsah.',
            );
        }

        return $left * $right;
    }

    /** @return array<string,mixed> */
    public function jsonSerialize(): array
    {
        return [
            'kind' => $this->kind->value,
            'section' => $this->kind->section(),
            'component_code' => $this->kind->componentCode(),
            'basis' => $this->basis->value,
            'basis_hourly_minor' => $this->basisHourlyMinor,
            'rate' => $this->rate->jsonSerialize(),
            'rate_is_agreed' => $this->rateIsAgreed,
            'minutes' => $this->minutes,
            'weighted_minutes' => $this->weightedMinutes,
            'unrounded_numerator' => $this->unroundedNumerator,
            'unrounded_denominator' => $this->unroundedDenominator,
            'rounding_mode' => $this->roundingMode->value,
            'amount_minor' => $this->amountMinor,
            'hourly_surcharge_minor' => $this->hourlySurchargeStep->outputMinorUnits,
            'hourly_surcharge_step' => $this->hourlySurchargeStep->jsonSerialize(),
            // Sjednaná vs. použitá hodinová částka se liší právě tehdy, když
            // sjednání nedosáhlo kogentního minima. Na pásce musí být vidět obojí.
            'agreed_fixed_hourly_minor' => $this->agreedFixedHourlyMinor,
            'applied_fixed_hourly_minor' => $this->appliedFixedHourlyMinor,
            'below_statutory' => $this->belowStatutory,
            'segments' => $this->segments,
            'waived_segments' => $this->waivedSegments,
        ];
    }
}
