<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Submission\Jmhz;

/**
 * Vykonávací implementace kontrol katalogu ČSSZ nad prvním profilem měsíčního
 * hlášení (`scenario_1`, `form:bezPriznaku`, řádné podání).
 *
 * Katalog 1.4.2.7 popisuje 199 kontrol textem, ne strojově. Tahle třída je
 * jediné místo, kde se text překládá do kódu, a drží tři pravidla:
 *
 * 1. **Sazby se nezadrátovávají.** Každý koeficient se bere z parametrických
 *    konstant katalogu, které jsou účinné k datu (0,288 v roce 2025 versus
 *    0,298 v roce 2026 u téže kontroly 10).
 * 2. **Chybějící atribut není nula.** Kontrola, jejíž vstupy v podání nejsou,
 *    vrací `NotApplicable`. Výjimkou jsou součtové vzorce, kde je nepřítomnost
 *    bloku doloženě rovna nule — a tam je to u každého sčítance napsané.
 * 3. **Co lokálně vyhodnotit nelze, se za splněné nevydává.** Kontroly proti
 *    registru ČSSZ končí `NotEvaluable` se zdůvodněním, ne `Passed`.
 *
 * Aritmetika je celočíselná. Pojistné se počítá v korunách, hodiny v tisícinách
 * a týdenní doba v setinách — porovnávat je přes float by pouštělo haléřové
 * rozdíly do kontroly, která má být přesná na korunu.
 */
final class JmhzScenario1ControlEvaluator
{
    /**
     * Kontroly, které ověřují stav v systémech ČSSZ nebo v naší evidenci
     * podání, a z vyrobeného XML je tedy vyhodnotit nelze. Nejsou to mezery
     * v pokrytí — rozhodne o nich až protokol o zpracování.
     */
    private const NOT_EVALUABLE = [
        143 => 'Tvar variabilního symbolu vynucuje serializér; shodu s registrem'
            . ' zaměstnavatelů ČSSZ lokálně ověřit nelze.',
        226 => 'Porovnání počtu součástí s registrem ČSSZ je možné až na straně'
            . ' ČSSZ; nesoulad vede k částečnému přijetí a formální výzvě.',
        261 => 'Shodu ID PPV a variabilního symbolu drží evidence ČSSZ.',
        262 => 'Existenci ID PPV ověřuje pouze registr ČSSZ.',
        263 => 'Existenci IK MPSV ověřuje pouze registr ČSSZ.',
        264 => 'Existenci dvojice IK MPSV a ID PPV ověřuje pouze registr ČSSZ.',
        326 => 'Jedinečnost řádného podání za období se rozhoduje nad evidencí'
            . ' podání, ne nad obsahem jednoho XML.',
    ];

    public function __construct(
        private readonly JmhzControlParameterCatalog $parameters,
        private readonly ?JmhzExternalCodebookCatalog $externalCodebooks = null,
        private readonly ?JmhzCodebookCatalog $codebooks = null,
        private readonly JmhzDeadlinePolicy $deadlines = new JmhzDeadlinePolicy(),
    ) {}

    /** @return list<int> */
    public function implementedControlIds(): array
    {
        return [
            1, 3, 4, 8, 10, 11, 13, 23, 31, 37, 50, 72, 74, 90, 94, 95, 96, 100,
            129, 131, 132, 134, 144, 145, 152, 153, 154, 159, 162, 167, 168,
            253, 255, 260, 286, 299, 304, 335, 355,
        ];
    }

    /** @return array<int, string> */
    public function notEvaluableControlIds(): array
    {
        return self::NOT_EVALUABLE;
    }

    public function handles(int $controlId): bool
    {
        return isset(self::NOT_EVALUABLE[$controlId])
            || in_array($controlId, $this->implementedControlIds(), true);
    }

    /**
     * Parametrické konstanty, které implementace kontroly vědomě používá.
     * Guard v testech porovná seznam s vazbami z katalogu, aby se přesun sazby
     * mezi kontrolami nedal přehlédnout.
     *
     * @return array<int, list<string>>
     */
    public function declaredParameterKeys(): array
    {
        return [
            3 => ['source_row_6'],
            8 => ['source_row_3'],
            10 => ['source_row_4'],
            74 => ['source_row_15'],
            167 => ['source_row_5'],
            // Katalog váže na 168 jen sazbu 0,07171. Tolerance 7,1 % je
            // v textu kontroly a katalog ji vede pod parametrem svázaným
            // s kontrolami 118 a 270, proto se uvádí navíc.
            168 => ['source_row_7', 'source_row_8'],
        ];
    }

    /** @return list<JmhzControlVerdict> */
    public function evaluate(
        int $controlId,
        JmhzAttributeProjection $projection,
        JmhzControlContext $context,
    ): array {
        $reason = self::NOT_EVALUABLE[$controlId] ?? null;
        if ($reason !== null) {
            return [JmhzControlVerdict::notEvaluable(
                JmhzAttributeProjection::PART_SUBMISSION,
                $reason,
            )];
        }

        return match ($controlId) {
            1 => $this->employerDiscountHeadcount($projection),
            3 => $this->employerDiscountAmount($projection),
            4 => $this->insurancePayable($projection),
            8 => $this->employerInsuranceRate($projection, '10024', '10023', 'source_row_3'),
            10 => $this->employerInsuranceRate($projection, '10026', '10025', 'source_row_4'),
            11 => $this->employerInsuranceTotal($projection),
            13 => $this->insuranceTotal($projection),
            23 => $this->unworkedHoursCoverVacation($projection),
            31, 131 => $this->periodNotBeforeStart($projection),
            37 => $this->personIdentifierChecksum($projection),
            50 => $this->assessmentBaseNotNegative($projection),
            72 => $this->incomeNotNegative($projection),
            74 => $this->taxBonusFloor($projection),
            90 => $this->periodAlreadyClosed($projection, $context),
            94 => $this->nonNegativeScaled($projection, '10259'),
            95 => $this->nonNegativeScaled($projection, '10260'),
            96 => $this->nonNegativeScaled($projection, '10261'),
            100 => $this->eldpValidityOrdering($projection),
            129 => $this->monthInRange($projection),
            132 => $this->amendmentWindow($projection),
            134 => $this->insuranceDaysWithinInterval($projection),
            144 => $this->obstacleWithinAgreedFund($projection, '10471'),
            145 => $this->obstacleWithinAgreedFund($projection, '10472'),
            152, 335 => $this->workplaceMunicipality($projection),
            153 => $this->workplaceCountry($projection),
            154 => $this->activePolicyInstrument($projection),
            159 => $this->activePolicyInstrumentRequired($projection),
            162 => $this->employerBasePresence($projection),
            167 => $this->employerInsuranceRate($projection, '10484', '10483', 'source_row_5'),
            168 => $this->employeeInsuranceTolerance($projection),
            253 => $this->employmentIdentifierUnique($projection),
            255 => $this->primaryEmploymentAtLeastOne($projection),
            260 => $this->primaryEmploymentAtMostOne($projection),
            286 => $this->unworkedHoursBreakdownEmpty($projection),
            299 => $this->insuranceIntervalWithinPeriod($projection),
            304 => $this->taxBaseNotNegative($projection),
            355 => $this->govTalkVariableSymbol($projection, $context),
            default => throw new \OutOfBoundsException(
                "Kontrola JMHZ {$controlId} nemá vykonávací implementaci.",
            ),
        };
    }

    // --- pojistná část ----------------------------------------------------

    /** @return list<JmhzControlVerdict> */
    private function employerInsuranceRate(
        JmhzAttributeProjection $projection,
        string $insuranceId,
        string $baseId,
        string $parameterKey,
    ): array {
        $pvpoj = $projection->pvpoj();
        $insurance = $pvpoj->integer($insuranceId);
        $base = $pvpoj->integer($baseId);
        if ($insurance === null && $base === null) {
            return [JmhzControlVerdict::notApplicable(JmhzAttributeProjection::PART_PVPOJ)];
        }
        // Chybějící protějšek není nula: vykázané pojistné bez vyměřovacího
        // základu (a naopak) je samo o sobě vada, ne důvod dopočítat nulu.
        if ($insurance === null || $base === null) {
            return [JmhzControlVerdict::failed(
                JmhzAttributeProjection::PART_PVPOJ,
                null,
                "Vykázán jen jeden z údajů {$baseId} a {$insuranceId}; sazbu nelze ověřit.",
            )];
        }
        $expected = $this->parameters->multiplyCeil(
            $base,
            $parameterKey,
            $this->periodStart($projection),
        );
        if ($insurance !== $expected) {
            return [JmhzControlVerdict::failed(
                JmhzAttributeProjection::PART_PVPOJ,
                null,
                "Pojistné {$insuranceId} = {$insurance} Kč neodpovídá sazbě ze základu"
                    . " {$baseId} = {$base} Kč; očekáváno {$expected} Kč.",
            )];
        }

        return [JmhzControlVerdict::passed(JmhzAttributeProjection::PART_PVPOJ)];
    }

    /** @return list<JmhzControlVerdict> */
    private function employerInsuranceTotal(JmhzAttributeProjection $projection): array
    {
        $pvpoj = $projection->pvpoj();
        $total = $pvpoj->integer('10027');
        if ($total === null) {
            return [JmhzControlVerdict::notApplicable(JmhzAttributeProjection::PART_PVPOJ)];
        }
        // Nepřítomný sčítanec je doloženě nula: bez zaměstnanců v dané skupině
        // se odpovídající blok pojistné části neuvádí vůbec.
        $sum = ($pvpoj->integer('10024') ?? 0)
            + ($pvpoj->integer('10026') ?? 0)
            + ($pvpoj->integer('10484') ?? 0);
        if ($total !== $sum) {
            return [JmhzControlVerdict::failed(
                JmhzAttributeProjection::PART_PVPOJ,
                null,
                "Pojistné za zaměstnavatele celkem {$total} Kč neodpovídá součtu"
                    . " dílčích sazeb {$sum} Kč.",
            )];
        }

        return [JmhzControlVerdict::passed(JmhzAttributeProjection::PART_PVPOJ)];
    }

    /** @return list<JmhzControlVerdict> */
    private function insuranceTotal(JmhzAttributeProjection $projection): array
    {
        $pvpoj = $projection->pvpoj();
        $total = $pvpoj->integer('10029');
        $employer = $pvpoj->integer('10027');
        $employee = $pvpoj->integer('10028');
        if ($total === null || $employer === null || $employee === null) {
            return [JmhzControlVerdict::notApplicable(JmhzAttributeProjection::PART_PVPOJ)];
        }
        if ($total !== $employer + $employee) {
            return [JmhzControlVerdict::failed(
                JmhzAttributeProjection::PART_PVPOJ,
                null,
                "Pojistné celkem {$total} Kč neodpovídá součtu {$employer} + {$employee} Kč.",
            )];
        }

        return [JmhzControlVerdict::passed(JmhzAttributeProjection::PART_PVPOJ)];
    }

    /** @return list<JmhzControlVerdict> */
    private function employerDiscountHeadcount(JmhzAttributeProjection $projection): array
    {
        $headcount = $projection->pvpoj()->integer('10030');
        if ($headcount === null) {
            return [JmhzControlVerdict::notApplicable(JmhzAttributeProjection::PART_PVPOJ)];
        }
        $claimed = 0;
        foreach ($projection->forms() as $form) {
            if ($form->boolean('10372') === true) {
                ++$claimed;
            }
        }
        if ($headcount !== $claimed) {
            return [JmhzControlVerdict::failed(
                JmhzAttributeProjection::PART_PVPOJ,
                null,
                "Počet zaměstnanců se slevou na pojistném {$headcount} neodpovídá"
                    . " počtu součástí s uplatněnou slevou {$claimed}.",
            )];
        }

        return [JmhzControlVerdict::passed(JmhzAttributeProjection::PART_PVPOJ)];
    }

    /** @return list<JmhzControlVerdict> */
    private function employerDiscountAmount(JmhzAttributeProjection $projection): array
    {
        $pvpoj = $projection->pvpoj();
        $discount = $pvpoj->integer('10032');
        $base = $pvpoj->integer('10031');
        if ($discount === null && $base === null) {
            return [JmhzControlVerdict::notApplicable(JmhzAttributeProjection::PART_PVPOJ)];
        }
        if ($discount === null || $base === null) {
            return [JmhzControlVerdict::failed(
                JmhzAttributeProjection::PART_PVPOJ,
                null,
                'Sleva na pojistném je vykázána bez úhrnu vyměřovacích základů, nebo naopak.',
            )];
        }
        $percent = $this->parameters->integerValue(
            'source_row_6',
            $this->periodStart($projection),
        );
        $expected = intdiv($base * $percent + 99, 100);
        if ($discount !== $expected) {
            return [JmhzControlVerdict::failed(
                JmhzAttributeProjection::PART_PVPOJ,
                null,
                "Sleva na pojistném {$discount} Kč neodpovídá {$percent} % z úhrnu"
                    . " {$base} Kč; očekáváno {$expected} Kč.",
            )];
        }

        return [JmhzControlVerdict::passed(JmhzAttributeProjection::PART_PVPOJ)];
    }

    /** @return list<JmhzControlVerdict> */
    private function insurancePayable(JmhzAttributeProjection $projection): array
    {
        $pvpoj = $projection->pvpoj();
        $payable = $pvpoj->integer('10033');
        $total = $pvpoj->integer('10029');
        if ($payable === null || $total === null) {
            return [JmhzControlVerdict::notApplicable(JmhzAttributeProjection::PART_PVPOJ)];
        }
        // Neuvedená sleva je doloženě nula — blok slevy se do pojistné části
        // dává jen tehdy, když ji zaměstnavatel uplatňuje.
        $expected = $total
            - ($pvpoj->integer('10032') ?? 0)
            - ($pvpoj->integer('10487') ?? 0)
            - ($pvpoj->integer('10545') ?? 0);
        if ($payable !== $expected) {
            return [JmhzControlVerdict::failed(
                JmhzAttributeProjection::PART_PVPOJ,
                null,
                "Pojistné k úhradě {$payable} Kč neodpovídá pojistnému po slevách"
                    . " {$expected} Kč.",
            )];
        }

        return [JmhzControlVerdict::passed(JmhzAttributeProjection::PART_PVPOJ)];
    }

    /** @return list<JmhzControlVerdict> */
    private function employerBasePresence(JmhzAttributeProjection $projection): array
    {
        $pvpoj = $projection->pvpoj();
        $bases = [];
        foreach (['10023', '10025', '10483'] as $attributeId) {
            $value = $pvpoj->integer($attributeId);
            if ($value !== null) {
                $bases[$attributeId] = $value;
            }
        }
        if ($bases === []) {
            return [JmhzControlVerdict::notApplicable(JmhzAttributeProjection::PART_PVPOJ)];
        }
        $positive = array_filter($bases, static fn (int $value): bool => $value > 0);
        $allZero = array_filter($bases, static fn (int $value): bool => $value !== 0) === [];
        if ($positive === [] && !$allZero) {
            return [JmhzControlVerdict::failed(
                JmhzAttributeProjection::PART_PVPOJ,
                null,
                'Vyměřovací základy zaměstnavatele musí být buď všechny nulové,'
                    . ' nebo alespoň jeden kladný.',
            )];
        }

        return [JmhzControlVerdict::passed(JmhzAttributeProjection::PART_PVPOJ)];
    }

    /**
     * Kontrola 168 — pojistné za zaměstnance proti úhrnu vyměřovacích základů.
     *
     * Katalog dává dvě nezávislé tolerance a přijímá hodnotu, když projde
     * aspoň jedna: relativní odchylku do 1 % a absolutní do 100 Kč. Důvod je
     * v zaokrouhlování — pojistné se počítá a zaokrouhluje nahoru u každého
     * zaměstnance zvlášť, takže úhrn nikdy nesedí na procento z celkového
     * základu přesně.
     *
     * Text kontroly navíc žádá dolní mez 7,171 % z úhrnu základů. Ta se tady
     * NEVYNUCUJE: na doloženém minimálním případě (základ 1 000 Kč, pojistné
     * 71 Kč) by ji neprošlo ani zcela správné podání, protože 7,171 % z 1 000
     * je 71,71 Kč. Uplatnit ji by znamenalo lokálně blokovat platná podání;
     * o skutečné mezi rozhodne protokol ČSSZ. Ověřit proti PDF katalogu.
     *
     * @return list<JmhzControlVerdict>
     */
    private function employeeInsuranceTolerance(JmhzAttributeProjection $projection): array
    {
        $pvpoj = $projection->pvpoj();
        $employee = $pvpoj->integer('10028');
        if ($employee === null) {
            return [JmhzControlVerdict::notApplicable(JmhzAttributeProjection::PART_PVPOJ)];
        }
        $base = ($pvpoj->integer('10023') ?? 0)
            + ($pvpoj->integer('10025') ?? 0)
            + ($pvpoj->integer('10483') ?? 0);
        if ($base === 0) {
            return $employee === 0
                ? [JmhzControlVerdict::passed(JmhzAttributeProjection::PART_PVPOJ)]
                : [JmhzControlVerdict::failed(
                    JmhzAttributeProjection::PART_PVPOJ,
                    null,
                    "Pojistné za zaměstnance {$employee} Kč je vykázáno bez vyměřovacího základu.",
                )];
        }
        [$numerator, $denominator] = $this->parameters->multiplyExact(
            $base,
            'source_row_7',
            $this->periodStart($projection),
        );
        // |expected - employee| <= 100 Kč, počítáno bez dělení, ať se nekrátí
        // zbytek: |numerator - employee * denominator| <= 100 * denominator.
        $absoluteDeviation = abs($numerator - $employee * $denominator);
        if ($absoluteDeviation <= 100 * $denominator) {
            return [JmhzControlVerdict::passed(JmhzAttributeProjection::PART_PVPOJ)];
        }
        // |1 - expected / employee| <= 0,01, opět bez dělení:
        // |employee * denominator - numerator| <= employee * denominator / 100.
        if ($employee !== 0
            && $absoluteDeviation * 100 <= abs($employee * $denominator)
        ) {
            return [JmhzControlVerdict::passed(JmhzAttributeProjection::PART_PVPOJ)];
        }
        $expected = intdiv($numerator, $denominator);

        return [JmhzControlVerdict::failed(
            JmhzAttributeProjection::PART_PVPOJ,
            null,
            "Pojistné za zaměstnance {$employee} Kč je mimo toleranci vůči úhrnu"
                . " vyměřovacích základů {$base} Kč; orientačně {$expected} Kč.",
        )];
    }

    // --- metadatová hlavička ----------------------------------------------

    /**
     * Začátek účinnosti JMHZ se nebere z letopočtu v kódu, ale z politiky lhůt,
     * která je jediným zdrojem pravdy o tom, za která období se vůbec podává.
     * Druhá letopočtová brána by se rozešla s rulesetem hned, jak se hranice
     * pohne.
     *
     * @return list<JmhzControlVerdict>
     */
    private function periodNotBeforeStart(JmhzAttributeProjection $projection): array
    {
        $period = $this->period($projection);
        if ($period === null) {
            return [JmhzControlVerdict::notApplicable(JmhzAttributeProjection::PART_SUBMISSION)];
        }
        [$year, $month] = $period;
        try {
            $this->deadlines->forPeriod($this->periodStart($projection));
        } catch (\InvalidArgumentException) {
            return [JmhzControlVerdict::failed(
                JmhzAttributeProjection::PART_SUBMISSION,
                null,
                "Za období {$month}/{$year} se měsíční hlášení nepodává;"
                    . ' je mimo účinnost jednotného měsíčního hlášení.',
            )];
        }

        return [JmhzControlVerdict::passed(JmhzAttributeProjection::PART_SUBMISSION)];
    }

    /** @return list<JmhzControlVerdict> */
    private function monthInRange(JmhzAttributeProjection $projection): array
    {
        $month = $projection->submission()->integer('10010');
        if ($month === null) {
            return [JmhzControlVerdict::notApplicable(JmhzAttributeProjection::PART_SUBMISSION)];
        }
        if ($month < 1 || $month > 12) {
            return [JmhzControlVerdict::failed(
                JmhzAttributeProjection::PART_SUBMISSION,
                null,
                "Číslo měsíce {$month} je mimo rozsah 1 až 12.",
            )];
        }

        return [JmhzControlVerdict::passed(JmhzAttributeProjection::PART_SUBMISSION)];
    }

    /** @return list<JmhzControlVerdict> */
    private function periodAlreadyClosed(
        JmhzAttributeProjection $projection,
        JmhzControlContext $context,
    ): array {
        $period = $this->period($projection);
        if ($period === null) {
            return [JmhzControlVerdict::notApplicable(JmhzAttributeProjection::PART_SUBMISSION)];
        }
        [$year, $month] = $period;
        $reported = sprintf('%04d-%02d', $year, $month);
        $current = substr($context->evaluatedOn, 0, 7);
        if (strcmp($reported, $current) >= 0) {
            return [JmhzControlVerdict::failed(
                JmhzAttributeProjection::PART_SUBMISSION,
                null,
                "Hlášené období {$reported} ještě neskončilo; podává se až za"
                    . ' uplynulý kalendářní měsíc.',
            )];
        }

        return [JmhzControlVerdict::passed(JmhzAttributeProjection::PART_SUBMISSION)];
    }

    /** @return list<JmhzControlVerdict> */
    private function amendmentWindow(JmhzAttributeProjection $projection): array
    {
        $type = $projection->submission()->value('10007');
        if ($type !== 'O') {
            return [JmhzControlVerdict::notApplicable(
                JmhzAttributeProjection::PART_SUBMISSION,
                'Lhůta pro opravné hlášení se na řádné podání nevztahuje.',
            )];
        }

        return [JmhzControlVerdict::notEvaluable(
            JmhzAttributeProjection::PART_SUBMISSION,
            'Desetiletá lhůta pro opravné hlášení se počítá od konce roku vzniku'
                . ' povinnosti; opravné podání zatím serializér nestaví.',
        )];
    }

    /** @return list<JmhzControlVerdict> */
    private function govTalkVariableSymbol(
        JmhzAttributeProjection $projection,
        JmhzControlContext $context,
    ): array {
        $inSubmission = $projection->submission()->value('10221');
        if ($inSubmission === null) {
            return [JmhzControlVerdict::notApplicable(JmhzAttributeProjection::PART_SUBMISSION)];
        }
        if ($context->govTalkVariableSymbol === null) {
            return [JmhzControlVerdict::notEvaluable(
                JmhzAttributeProjection::PART_SUBMISSION,
                'Obálka GovTalk vzniká až při odeslání přes VREP; bez ní není'
                    . ' s čím variabilní symbol porovnat.',
            )];
        }
        if (!hash_equals($context->govTalkVariableSymbol, $inSubmission)) {
            return [JmhzControlVerdict::failed(
                JmhzAttributeProjection::PART_SUBMISSION,
                null,
                'Variabilní symbol v podání neodpovídá symbolu v obálce GovTalk.',
            )];
        }

        return [JmhzControlVerdict::passed(JmhzAttributeProjection::PART_SUBMISSION)];
    }

    // --- součásti individualizované části ---------------------------------

    /** @return list<JmhzControlVerdict> */
    private function personIdentifierChecksum(JmhzAttributeProjection $projection): array
    {
        return $this->perForm($projection, static function (JmhzAttributeScope $form): ?string {
            $value = $form->value('10051');
            if ($value === null) {
                return null;
            }
            if (preg_match('/^\d{10}$/D', $value) !== 1) {
                return "IK MPSV {$value} nemá deset číslic.";
            }
            $body = (int) substr($value, 0, 9);
            // Zbytek 10 se do jedné kontrolní číslice nevejde; stejně jako
            // u rodného čísla se zapisuje nulou.
            $expected = $body % 11 % 10;
            if ((int) $value[9] !== $expected) {
                return "IK MPSV {$value} nesplňuje kontrolní číslici modulo 11.";
            }

            return null;
        });
    }

    /** @return list<JmhzControlVerdict> */
    private function assessmentBaseNotNegative(JmhzAttributeProjection $projection): array
    {
        return $this->perForm($projection, static function (JmhzAttributeScope $form): ?string {
            foreach ($form->all('10245') as $occurrence) {
                if ((int) $occurrence->value < 0) {
                    return "Vyměřovací základ ELDP {$occurrence->value} je záporný.";
                }
            }

            return null;
        });
    }

    /** @return list<JmhzControlVerdict> */
    private function incomeNotNegative(JmhzAttributeProjection $projection): array
    {
        return $this->nonNegativeInteger($projection, '10286', 'Zúčtovaný příjem celkem');
    }

    /** @return list<JmhzControlVerdict> */
    private function taxBaseNotNegative(JmhzAttributeProjection $projection): array
    {
        return $this->nonNegativeInteger($projection, '10535', 'Základ pro výpočet daně');
    }

    /** @return list<JmhzControlVerdict> */
    private function taxBonusFloor(JmhzAttributeProjection $projection): array
    {
        $floor = $this->parameters->integerValue(
            'source_row_15',
            $this->periodStart($projection),
        );

        return $this->perForm(
            $projection,
            static function (JmhzAttributeScope $form) use ($floor): ?string {
                $bonus = $form->integer('10306');
                if ($bonus === null) {
                    return null;
                }
                if ($bonus < 0) {
                    return "Měsíční daňový bonus {$bonus} Kč je záporný.";
                }
                if ($bonus > 0 && $bonus < $floor) {
                    return "Měsíční daňový bonus {$bonus} Kč je nižší než {$floor} Kč.";
                }

                return null;
            },
        );
    }

    /** @return list<JmhzControlVerdict> */
    private function unworkedHoursCoverVacation(JmhzAttributeProjection $projection): array
    {
        return $this->perForm($projection, static function (JmhzAttributeScope $form): ?string {
            $withPay = $form->scaled('10276');
            $vacation = $form->scaled('10279');
            if ($withPay === null || $vacation === null) {
                return null;
            }
            if (self::compareScaled($withPay, $vacation) < 0) {
                return 'Neodpracované hodiny s náhradou jsou nižší než hodiny čerpané dovolené.';
            }

            return null;
        });
    }

    /** @return list<JmhzControlVerdict> */
    private function obstacleWithinAgreedFund(
        JmhzAttributeProjection $projection,
        string $attributeId,
    ): array {
        return $this->perForm(
            $projection,
            static function (JmhzAttributeScope $form) use ($attributeId): ?string {
                $obstacle = $form->scaled($attributeId);
                $fund = $form->scaled('10260');
                if ($obstacle === null || $fund === null) {
                    return null;
                }
                if (self::compareScaled($obstacle, $fund) > 0) {
                    return "Překážky v práci ({$attributeId}) překračují sjednaný"
                        . ' fond pracovní doby.';
                }

                return null;
            },
        );
    }

    /** @return list<JmhzControlVerdict> */
    private function unworkedHoursBreakdownEmpty(JmhzAttributeProjection $projection): array
    {
        return $this->perForm($projection, static function (JmhzAttributeScope $form): ?string {
            $total = $form->scaled('10275');
            if ($total === null || $total[0] !== 0) {
                return null;
            }
            foreach (['10276', '10277', '10278', '10279', '10280', '10471', '10472'] as $id) {
                $value = $form->scaled($id);
                if ($value !== null && $value[0] !== 0) {
                    return "Celkový počet neodpracovaných hodin je nula, ale atribut"
                        . " {$id} je vyplněný.";
                }
            }

            return null;
        });
    }

    /** @return list<JmhzControlVerdict> */
    private function nonNegativeScaled(
        JmhzAttributeProjection $projection,
        string $attributeId,
    ): array {
        return $this->perForm(
            $projection,
            static function (JmhzAttributeScope $form) use ($attributeId): ?string {
                $value = $form->scaled($attributeId);
                if ($value === null || $value[0] >= 0) {
                    return null;
                }

                return "Atribut {$attributeId} je záporný.";
            },
        );
    }

    /** @return list<JmhzControlVerdict> */
    private function nonNegativeInteger(
        JmhzAttributeProjection $projection,
        string $attributeId,
        string $label,
    ): array {
        return $this->perForm(
            $projection,
            static function (JmhzAttributeScope $form) use ($attributeId, $label): ?string {
                $value = $form->integer($attributeId);
                if ($value === null || $value >= 0) {
                    return null;
                }

                return "{$label} ({$attributeId}) je záporný: {$value}.";
            },
        );
    }

    /** @return list<JmhzControlVerdict> */
    private function eldpValidityOrdering(JmhzAttributeProjection $projection): array
    {
        return $this->perForm($projection, static function (JmhzAttributeScope $form): ?string {
            foreach ($form->groupedBy(['10241', '10242']) as $section) {
                $from = $section['10241'] ?? null;
                $to = $section['10242'] ?? null;
                if ($from === null || $to === null) {
                    continue;
                }
                if (strcmp($from, $to) > 0) {
                    return "Platnost kódu ELDP od {$from} je po platnosti do {$to}.";
                }
            }

            return null;
        });
    }

    /**
     * Kontrola 134 — počet dnů pojištění v ELDP sekci proti intervalu trvání.
     *
     * Katalog píše `10356 <= 10355 - 10354`, což je rozdíl dat. Celý červenec
     * (1. až 31. 7.) má ale 31 dnů pojištění, ne 30, takže doslovné znění by
     * neprošlo ani u bezvadného hlášení za celý měsíc. Počítá se proto interval
     * včetně obou krajních dnů.
     *
     * @return list<JmhzControlVerdict>
     */
    private function insuranceDaysWithinInterval(JmhzAttributeProjection $projection): array
    {
        return $this->perForm($projection, static function (JmhzAttributeScope $form): ?string {
            $from = $form->value('10354');
            $to = $form->value('10355');
            if ($from === null || $to === null) {
                return null;
            }
            $start = \DateTimeImmutable::createFromFormat('!Y-m-d', $from, new \DateTimeZone('UTC'));
            $end = \DateTimeImmutable::createFromFormat('!Y-m-d', $to, new \DateTimeZone('UTC'));
            if (!$start instanceof \DateTimeImmutable || !$end instanceof \DateTimeImmutable) {
                return null;
            }
            $span = (int) $start->diff($end)->format('%r%a') + 1;
            foreach ($form->groupedBy(['10356']) as $section) {
                $days = $section['10356'] ?? null;
                if ($days === null) {
                    continue;
                }
                if ((int) $days > $span) {
                    return "Počet dnů pojištění {$days} překračuje délku intervalu"
                        . " {$from} až {$to} ({$span} dnů).";
                }
            }

            return null;
        });
    }

    /** @return list<JmhzControlVerdict> */
    private function insuranceIntervalWithinPeriod(JmhzAttributeProjection $projection): array
    {
        $period = $this->period($projection);
        if ($period === null) {
            return [JmhzControlVerdict::notApplicable(JmhzAttributeProjection::PART_FORM)];
        }
        $prefix = sprintf('%04d-%02d', $period[0], $period[1]);

        return $this->perForm(
            $projection,
            static function (JmhzAttributeScope $form) use ($prefix): ?string {
                foreach (['10354', '10355'] as $attributeId) {
                    $value = $form->value($attributeId);
                    if ($value !== null && !str_starts_with($value, $prefix . '-')) {
                        return "Datum {$attributeId} = {$value} leží mimo hlášený"
                            . " měsíc {$prefix}.";
                    }
                }

                return null;
            },
        );
    }

    /** @return list<JmhzControlVerdict> */
    private function workplaceMunicipality(JmhzAttributeProjection $projection): array
    {
        $catalog = $this->externalCodebooks;
        if ($catalog === null) {
            return [JmhzControlVerdict::notEvaluable(
                JmhzAttributeProjection::PART_FORM,
                'Číselník obcí CISOB je externí reference; bez připnutého overlay'
                    . ' jej ověřit nelze.',
            )];
        }
        $validOn = $this->periodStart($projection);

        return $this->perForm(
            $projection,
            static function (JmhzAttributeScope $form) use ($catalog, $validOn): ?string {
                $code = $form->value('10230');
                $name = $form->value('10229');
                if ($code === null) {
                    return null;
                }
                try {
                    $catalog->requireMunicipality($code, $name ?? '', $validOn);
                } catch (JmhzCodebookValueException | JmhzCodebookUnavailableException $exception) {
                    return $exception->getMessage();
                }

                return null;
            },
        );
    }

    /** @return list<JmhzControlVerdict> */
    private function workplaceCountry(JmhzAttributeProjection $projection): array
    {
        $catalog = $this->externalCodebooks;
        if ($catalog === null) {
            return [JmhzControlVerdict::notEvaluable(
                JmhzAttributeProjection::PART_FORM,
                'Číselník států CZEMALFA je externí reference; bez připnutého'
                    . ' overlay jej ověřit nelze.',
            )];
        }
        $validOn = $this->periodStart($projection);

        return $this->perForm(
            $projection,
            static function (JmhzAttributeScope $form) use ($catalog, $validOn): ?string {
                $code = $form->value('10231');
                if ($code === null) {
                    return null;
                }
                try {
                    $catalog->requireCountry($code, $validOn);
                } catch (JmhzCodebookValueException | JmhzCodebookUnavailableException $exception) {
                    return $exception->getMessage();
                }

                return null;
            },
        );
    }

    /** @return list<JmhzControlVerdict> */
    private function activePolicyInstrument(JmhzAttributeProjection $projection): array
    {
        $catalog = $this->codebooks;
        if ($catalog === null) {
            return [JmhzControlVerdict::notEvaluable(
                JmhzAttributeProjection::PART_FORM,
                'Číselník nástrojů APZ není k dispozici.',
            )];
        }

        return $this->perForm(
            $projection,
            static function (JmhzAttributeScope $form) use ($catalog): ?string {
                $code = $form->value('10233');
                if ($code === null) {
                    return null;
                }
                try {
                    $catalog->requireValue('nastroj_opatreni', $code);
                } catch (JmhzCodebookValueException | JmhzCodebookUnavailableException $exception) {
                    return $exception->getMessage();
                }

                return null;
            },
        );
    }

    /** @return list<JmhzControlVerdict> */
    private function activePolicyInstrumentRequired(JmhzAttributeProjection $projection): array
    {
        return $this->perForm($projection, static function (JmhzAttributeScope $form): ?string {
            if ($form->boolean('10232') !== true) {
                return null;
            }
            if ($form->value('10233') === null) {
                return 'Uplatněný mzdový příspěvek APZ vyžaduje vyplněný nástroj opatření.';
            }

            return null;
        });
    }

    /** @return list<JmhzControlVerdict> */
    private function employmentIdentifierUnique(JmhzAttributeProjection $projection): array
    {
        $seen = [];
        $duplicates = [];
        foreach ($projection->forms() as $form) {
            $identifier = $form->value('10228');
            if ($identifier === null) {
                continue;
            }
            if (isset($seen[$identifier])) {
                $duplicates[$identifier] = $form->ordinal;
            }
            $seen[$identifier] = true;
        }
        if ($seen === []) {
            return [JmhzControlVerdict::notApplicable(JmhzAttributeProjection::PART_FORM)];
        }
        if ($duplicates === []) {
            return [JmhzControlVerdict::passed(JmhzAttributeProjection::PART_FORM)];
        }
        $verdicts = [];
        foreach ($duplicates as $identifier => $ordinal) {
            $verdicts[] = JmhzControlVerdict::failed(
                JmhzAttributeProjection::PART_FORM,
                $ordinal,
                "ID PPV {$identifier} je v dílčím podání uvedeno více než jednou.",
            );
        }

        return $verdicts;
    }

    /** @return list<JmhzControlVerdict> */
    private function primaryEmploymentAtLeastOne(JmhzAttributeProjection $projection): array
    {
        return $this->primaryEmploymentCounts(
            $projection,
            static fn (int $count): bool => $count < 1,
            'Za IK MPSV %s není v podání žádné primární PPV.',
        );
    }

    /** @return list<JmhzControlVerdict> */
    private function primaryEmploymentAtMostOne(JmhzAttributeProjection $projection): array
    {
        return $this->primaryEmploymentCounts(
            $projection,
            static fn (int $count): bool => $count > 1,
            'Za IK MPSV %s je v podání více než jedno primární PPV.',
        );
    }

    /**
     * @param callable(int):bool $violates
     * @return list<JmhzControlVerdict>
     */
    private function primaryEmploymentCounts(
        JmhzAttributeProjection $projection,
        callable $violates,
        string $template,
    ): array {
        $counts = [];
        foreach ($projection->forms() as $form) {
            $person = $form->value('10051');
            if ($person === null) {
                continue;
            }
            $counts[$person] ??= 0;
            if ($form->boolean('10495') === true) {
                ++$counts[$person];
            }
        }
        if ($counts === []) {
            return [JmhzControlVerdict::notApplicable(JmhzAttributeProjection::PART_FORM)];
        }
        $verdicts = [];
        foreach ($counts as $person => $count) {
            if ($violates($count)) {
                $verdicts[] = JmhzControlVerdict::failed(
                    JmhzAttributeProjection::PART_FORM,
                    null,
                    sprintf($template, $person),
                );
            }
        }

        return $verdicts === []
            ? [JmhzControlVerdict::passed(JmhzAttributeProjection::PART_FORM)]
            : $verdicts;
    }

    // --- pomocné ----------------------------------------------------------

    /**
     * Projde všechny součásti podání. Nález se váže na konkrétní součást,
     * splnění se hlásí jednou souhrnně — u patnácti set formulářů by jinak
     * protokol utopil skutečné vady v tisících zelených řádků.
     *
     * @param callable(JmhzAttributeScope):?string $check
     * @return list<JmhzControlVerdict>
     */
    private function perForm(JmhzAttributeProjection $projection, callable $check): array
    {
        $verdicts = [];
        $evaluated = 0;
        foreach ($projection->forms() as $form) {
            ++$evaluated;
            $message = $check($form);
            if ($message !== null) {
                $verdicts[] = JmhzControlVerdict::failed(
                    JmhzAttributeProjection::PART_FORM,
                    $form->ordinal,
                    $message,
                );
            }
        }
        if ($evaluated === 0) {
            return [JmhzControlVerdict::notApplicable(JmhzAttributeProjection::PART_FORM)];
        }

        return $verdicts === []
            ? [JmhzControlVerdict::passed(JmhzAttributeProjection::PART_FORM)]
            : $verdicts;
    }

    /** @return array{0:int,1:int}|null */
    private function period(JmhzAttributeProjection $projection): ?array
    {
        $submission = $projection->submission();
        $month = $submission->integer('10010');
        $year = $submission->integer('10011');
        if ($month === null || $year === null) {
            return null;
        }

        return [$year, $month];
    }

    /**
     * První den vykazovaného období. Parametrické konstanty jsou účinné k datu
     * a rozhoduje období, za které se hlásí, ne den odeslání.
     */
    private function periodStart(JmhzAttributeProjection $projection): string
    {
        $period = $this->period($projection);
        if ($period === null) {
            throw new JmhzXmlException(
                'jmhz_control_period_missing',
                'Podání bez hlášeného období nelze proti katalogu kontrol vyhodnotit.',
            );
        }

        return sprintf('%04d-%02d-01', $period[0], $period[1]);
    }

    /**
     * @param array{0:int,1:int} $left
     * @param array{0:int,1:int} $right
     */
    private static function compareScaled(array $left, array $right): int
    {
        $scale = max($left[1], $right[1]);

        return ($left[0] * 10 ** ($scale - $left[1]))
            <=> ($right[0] * 10 ** ($scale - $right[1]));
    }
}
