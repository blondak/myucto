<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Absence;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\Payroll\PayrollAbsenceRepository;
use MyInvoice\Repository\Payroll\PayrollTimeRepository;
use MyInvoice\Repository\Payroll\PayrollTimeValue;
use MyInvoice\Service\Payroll\Calculation\MonthlyWageProration;
use MyInvoice\Service\Payroll\Ruleset\PayrollRulesetProvider;
use MyInvoice\Service\Payroll\Time\CzechHolidayCalendar;
use MyInvoice\Service\Payroll\Time\PayrollEmploymentCalendarProvisioner;
use MyInvoice\Service\Payroll\Time\PayrollMonthlyFundService;
use MyInvoice\Service\Payroll\Time\PayrollWorkCalendarSchedule;
use PDO;

/**
 * Podklad pro krácení měsíční mzdy za absence jednoho vztahu v jednom měsíci.
 *
 * Aritmetiku dělá {@see MonthlyWageProration}; tahle služba jen sesbírá dvě
 * čísla, ze kterých se počítá — fond pracovní doby měsíce a minuty, které
 * z něj vypadly.
 *
 * Doba nepřítomnosti s daty se měří publikovanými směnami. Měsíc ze souhrnu
 * importu ale rozvrh směn nemá a nikdy mít nebude, takže se tam měří rozvrhem
 * pracovního kalendáře ({@see calendarSegments()}); okno § 192, svátek i rozpad
 * na tituly jsou v obou případech jedny a tytéž.
 *
 * ── Fail-closed ─────────────────────────────────────────────────────────────
 *
 * Nenavrhne se nic, dokud si aplikace není jistá:
 *
 *  - vztah nemá pracovní kalendář → z čeho krátit se neví
 *    ({@see PayrollMonthlyFundService::minutes()} vrací `null`, ne nulu),
 *  - v měsíci leží absence, o které se ještě nerozhodlo → částka by se po
 *    schválení změnila,
 *  - nemoc nemá zmrazený výpočet náhrady → okno § 192 by se hádalo znovu,
 *  - nahrazené minuty přesahují fond → evidence si odporuje.
 *
 * Vrátit v takové chvíli celou sjednanou mzdu je horší než nenavrhnout nic:
 * číslo vypadá hotově a nikdo ho už nezkontroluje.
 */
final class PayrollWageProrationService
{
    /**
     * Hodiny souhrnu importu docházky → titul náhrady. Stejné tituly jako
     * {@see PayrollWageReplacementTitle::forAbsenceType()} pro absence s daty.
     * Nemoc se v měsíčním součtu nedá rozdělit oknem § 192, klíč je ale jen
     * popisný a do aritmetiky vstupuje součtem. Svátek, odpracované hodiny,
     * pracovní cesta ani práce z domova základní mzdu nekrátí.
     */
    private const IMPORT_SUMMARY_TITLES = [
        'vacation_hours' => PayrollWageReplacementTitle::Vacation,
        'sick_hours' => PayrollWageReplacementTitle::SicknessCompensation,
        'care_hours' => PayrollWageReplacementTitle::StateBenefit,
        'paternity_hours' => PayrollWageReplacementTitle::StateBenefit,
        'doctor_hours' => PayrollWageReplacementTitle::PaidObstacle,
        'obstacle_employee_hours' => PayrollWageReplacementTitle::PaidObstacle,
        'obstacle_employer_hours' => PayrollWageReplacementTitle::PaidObstacle,
        'unpaid_leave_hours' => PayrollWageReplacementTitle::Unpaid,
        'unexcused_hours' => PayrollWageReplacementTitle::Unpaid,
        'compensatory_time_off_hours' => PayrollWageReplacementTitle::Unpaid,
    ];

    public function __construct(
        private readonly Connection $db,
        private readonly PayrollAbsenceRepository $absences,
        private readonly PayrollMonthlyFundService $fund,
        private readonly PayrollTimeRepository $time,
        private readonly PayrollEmploymentCalendarProvisioner $calendars,
        private readonly PayrollWorkCalendarSchedule $schedule,
        private readonly PayrollRulesetProvider $rulesets,
        private readonly CzechHolidayCalendar $holidays = new CzechHolidayCalendar(),
    ) {}

    /**
     * Krácení měsíční mzdy v měsíci, jehož docházka je souhrnem importu.
     *
     * Nahrazené minuty se skládají ze DVOU zdrojů, protože z převodu mezd
     * z jiného programu takový měsíc obojí opravdu má: bezdatové hodiny
     * souhrnu (dovolená, překážky) a nepřítomnosti s daty (nemoc, ošetřovné,
     * otcovská, neplacené volno, neomluvená absence, náhradní volno). Druhá
     * strana se měří přesně toutéž mechanikou jako v {@see forMonth()} a obě
     * se sčítají po TITULECH, tedy v jednom výpočtu — dva výpočty a sečtené
     * částky by se rozešly na zaokrouhlení.
     *
     * Dvojí započtení vylučuje disjunktnost obou zdrojů: co se zapisuje
     * datovaně, do souhrnu nejde
     * ({@see \MyInvoice\Service\Migration\Pohoda\Payroll\PohodaPayrollCatalog::absenceNeedsDates()}).
     * Titul v obou zdrojích naráz proto není součet, ale míchaná evidence —
     * a ta se odmítne, protože sečíst i vybrat jednu stranu by byl odhad.
     *
     * Doba datovaných nepřítomností se měří publikovanými směnami, a nemá-li
     * měsíc žádné, rozvrhem pracovního kalendáře ({@see calendarSegments()}) —
     * jinak by převzatý měsíc, který směny nikdy mít nebude, naměřil nulu.
     *
     * Fail-closed dál tam, kde by se souhrn s jiným podkladem rozešel: fond
     * z podkladů, který nesedí s kalendářem (hodiny by se vztahovaly k jinému
     * fondu), a datovaná nepřítomnost bez měřitelné doby ani jednou z obou cest
     * (krátilo by se jen podle souhrnu a doložená nepřítomnost by tiše zůstala
     * zaplacená).
     *
     * @return array{
     *   supported:bool,
     *   reason:?string,
     *   fund_minutes:?int,
     *   replaced_minutes:int,
     *   replaced_minutes_by_title:array<string,int>,
     *   amount_minor:?int,
     *   trace:?array<string,mixed>
     * }
     */
    public function forImportSummary(
        int $supplierId,
        int $employmentId,
        string $period,
        int $monthlyGrossMinor,
    ): array {
        $start = \DateTimeImmutable::createFromFormat('!Y-m-d', $period . '-01');
        if ($start === false || $start->format('Y-m') !== $period) {
            throw new \InvalidArgumentException('period musí být ve formátu YYYY-MM.');
        }
        $periodStart = $start->format('Y-m-d');
        $periodEnd = $start->modify('last day of this month')->format('Y-m-d');

        $summary = $this->time->importSummary($supplierId, $employmentId, $periodStart);
        if ($summary === null) {
            return self::unsupported('import_summary_missing');
        }
        // Souhrn se čte první: celý výpočet na něm stojí a nečitelný souhrn se
        // nedá zachránit ani tím, co je v datované evidenci.
        try {
            $summaryTitles = self::replacedMinutesFromImportSummary($summary['values']);
        } catch (\InvalidArgumentException) {
            return self::unsupported('import_summary_inconsistent');
        }

        $rows = $this->absencesInMonth($supplierId, $employmentId, $periodStart, $periodEnd);
        $datedTitles = [];
        $holidayMinutes = 0;
        $fundMinutes = null;
        if ($rows !== []) {
            $pending = self::pendingDecisionReason($rows);
            if ($pending !== null) {
                return self::unsupported($pending);
            }
            // Pořadí jako ve forMonth(): chybějící kalendář je bližší příčina
            // než cokoliv, co se z absencí dopočítá.
            $fundMinutes = $this->fund->minutes($supplierId, $employmentId, $period);
            if ($fundMinutes === null) {
                return self::unsupported('missing_work_calendar');
            }
            // Měsíc převzatý z jiného programu rozvrh směn nemá; jeho doba se
            // proto měří kalendářem. Jakmile měsíc směny má, měří se jimi —
            // rozvrh by u nich byl druhý, méně přesný zdroj téhož údaje.
            $dated = $this->datedReplacedMinutes(
                $supplierId,
                $rows,
                $periodStart,
                $periodEnd,
                fromCalendar: !$this->hasPublishedShifts($supplierId, $employmentId, $periodStart, $periodEnd),
            );
            if ($dated['reason'] !== null) {
                return self::unsupported($dated['reason']);
            }
            $datedTitles = array_filter(
                $dated['by_title'],
                static fn (int $minutes): bool => $minutes > 0,
            );
            $holidayMinutes = $dated['holiday_minutes'];
            // Poslední záchrana: doba se nedala změřit ani směnami, ani
            // rozvrhem. Krátit pak jen podle souhrnu by datovanou nepřítomnost
            // tiše nechalo zaplacenou, a to je horší než nenavrhnout nic.
            if ($datedTitles === []) {
                return self::unsupported('dated_absence_without_shift_time');
            }
            if (array_intersect_key($datedTitles, $summaryTitles) !== []) {
                return self::unsupported('import_summary_title_in_both_sources');
            }
        }

        // Po vyloučení překryvu jde o sloučení dvou disjunktních map; součet
        // je tu proto, aby se případná budoucí shoda titulů neztratila tiše.
        $byTitle = $summaryTitles;
        foreach ($datedTitles as $title => $minutes) {
            $byTitle[$title] = ($byTitle[$title] ?? 0) + $minutes;
        }
        if ($byTitle === []) {
            return self::none();
        }

        $fundMinutes ??= $this->fund->minutes($supplierId, $employmentId, $period);
        if ($fundMinutes === null) {
            return self::unsupported('missing_work_calendar');
        }
        if ($fundMinutes <= 0) {
            return self::unsupported('empty_work_fund');
        }
        if (isset($summary['values']['fund_hours'])
            && $this->calendars->fundCheck($supplierId, $employmentId, $periodStart, $summary['values']['fund_hours']) !== null
        ) {
            return self::unsupported('import_fund_mismatch');
        }
        // Svátek v okně § 192 fond nezná, ale mezi nahrazenými minutami je —
        // o tolik smí absence fond přesáhnout. Souhrn svátky nenese, takže
        // u čistého souhrnu je odpočet nulový.
        if (array_sum($byTitle) - $holidayMinutes > $fundMinutes) {
            return self::unsupported('absence_exceeds_work_fund');
        }

        return self::prorated($monthlyGrossMinor, $fundMinutes, $byTitle);
    }

    /**
     * Nahrazené minuty podle titulu z hodin souhrnu (význam → millihodiny).
     * Minuty se zaokrouhlují po významech stejně jako u náhrady mzdy, aby
     * se krácení a náhrada opíraly o tytéž minuty.
     *
     * @param array<string,int> $values
     * @return array<string,int> titul => minuty, jen kladné
     */
    public static function replacedMinutesFromImportSummary(array $values): array
    {
        $byTitle = [];
        foreach (self::IMPORT_SUMMARY_TITLES as $meaning => $title) {
            $minutes = PayrollImportAbsenceCompensationMaterializer::minutes($values[$meaning] ?? 0);
            if ($minutes > 0) {
                $byTitle[$title->value] = ($byTitle[$title->value] ?? 0) + $minutes;
            }
        }

        return $byTitle;
    }

    /**
     * @return array{
     *   supported:bool,
     *   reason:?string,
     *   fund_minutes:?int,
     *   replaced_minutes:int,
     *   replaced_minutes_by_title:array<string,int>,
     *   amount_minor:?int,
     *   trace:?array<string,mixed>
     * }
     */
    public function forMonth(
        int $supplierId,
        int $employmentId,
        string $period,
        int $monthlyGrossMinor,
    ): array {
        $start = \DateTimeImmutable::createFromFormat('!Y-m-d', $period . '-01');
        if ($start === false || $start->format('Y-m') !== $period) {
            throw new \InvalidArgumentException('period musí být ve formátu YYYY-MM.');
        }
        $periodStart = $start->format('Y-m-d');
        $periodEnd = $start->modify('last day of this month')->format('Y-m-d');

        $rows = $this->absencesInMonth($supplierId, $employmentId, $periodStart, $periodEnd);
        if ($rows === []) {
            return self::none();
        }
        $pending = self::pendingDecisionReason($rows);
        if ($pending !== null) {
            return self::unsupported($pending);
        }

        $fundMinutes = $this->fund->minutes($supplierId, $employmentId, $period);
        if ($fundMinutes === null) {
            return self::unsupported('missing_work_calendar');
        }

        // Měsíc se směnami se měří směnami. Kalendářní náhrada patří jen měsíci
        // ze souhrnu importu, kde rozvrh směn z principu nevznikne.
        $dated = $this->datedReplacedMinutes($supplierId, $rows, $periodStart, $periodEnd);
        if ($dated['reason'] !== null) {
            return self::unsupported($dated['reason']);
        }

        $byTitle = array_filter(
            $dated['by_title'],
            static fn (int $minutes): bool => $minutes > 0,
        );
        if ($byTitle === []) {
            return self::none();
        }
        if ($fundMinutes <= 0) {
            return self::unsupported('empty_work_fund');
        }
        // Fond svátky nezná, ale mezi nahrazenými minutami jsou — o tolik smí
        // absence fond přesáhnout. Cokoli nad to znamená, že si evidence
        // odporuje (třeba směna publikovaná mimo rozvrh) a číslo by lhalo.
        if (array_sum($byTitle) - $dated['holiday_minutes'] > $fundMinutes) {
            return self::unsupported('absence_exceeds_work_fund');
        }

        return self::prorated($monthlyGrossMinor, $fundMinutes, $byTitle);
    }

    /**
     * Nerozhodnutá nepřítomnost v měsíci: částka by se po schválení změnila.
     *
     * @param list<array<string,mixed>> $rows
     */
    private static function pendingDecisionReason(array $rows): ?string
    {
        foreach ($rows as $row) {
            if (PayrollTimeValue::string($row['status'] ?? null, 'status') !== 'approved') {
                return 'absence_pending_decision';
            }
            if ((int) ($row['correction_pending'] ?? 0) === 1) {
                return 'absence_correction_pending';
            }
        }

        return null;
    }

    /**
     * Nahrazené minuty nepřítomností S DATY podle titulu. Jediná mechanika pro
     * obě vstupní cesty — měsíc se směnami i měsíc, kde datovaná evidence
     * doplňuje souhrn importu.
     *
     * `$fromCalendar` mění JEN to, čím se doba měří (publikované směny proti
     * rozvrhu kalendáře); okno § 192, zacházení se svátkem i rozpad na tituly
     * zůstávají jedny.
     *
     * `by_title` může nést i nulu nebo zápornou hodnotu (svátek odečtený od
     * doby, kterou nikdo nenahrazuje); filtruje ji volající, protože o tom, co
     * s prázdným výsledkem, rozhoduje jeho podklad.
     *
     * @param list<array<string,mixed>> $rows
     * @return array{by_title:array<string,int>,holiday_minutes:int,reason:?string}
     */
    private function datedReplacedMinutes(
        int $supplierId,
        array $rows,
        string $periodStart,
        string $periodEnd,
        bool $fromCalendar = false,
    ): array {
        $byTitle = [];
        $holidayMinutes = 0;
        $holidays = PayrollWorkCalendarSchedule::holidaysBetween(
            $this->holidays,
            $periodStart,
            $periodEnd,
        );
        foreach ($rows as $row) {
            $type = PayrollTimeValue::string($row['absence_type'] ?? null, 'absence_type');
            if ($type === 'dpn' || $type === 'quarantine') {
                $firstDayFullyWorked = $this->firstDayFullyWorked($supplierId, $row);
                if ($firstDayFullyWorked === null) {
                    return self::datedNothing('sickness_calculation_missing');
                }
                $window = $this->windowSegments(
                    $row,
                    $firstDayFullyWorked,
                    AbsenceHolidayTreatment::CompensateSickness,
                    $fromCalendar,
                );
                $inWindow = self::minutesInMonth($window, $periodStart, $periodEnd);
                $byTitle[PayrollWageReplacementTitle::SicknessCompensation->value]
                    = ($byTitle[PayrollWageReplacementTitle::SicknessCompensation->value] ?? 0)
                    + $inWindow;
                $byTitle[PayrollWageReplacementTitle::StateBenefit->value]
                    = ($byTitle[PayrollWageReplacementTitle::StateBenefit->value] ?? 0)
                    + self::minutesInMonth(
                        $this->beyondWindowSegments($row, $firstDayFullyWorked, $fromCalendar),
                        $periodStart,
                        $periodEnd,
                    );
                // Svátek v okně § 192 se proplácí náhradou, takže tatáž doba
                // nesmí zůstat i v základní mzdě. Fond ji ale nezná — svátku
                // ukládá nula plánovaných minut — a bez tohohle dopočtu by
                // svátek zůstal zaplacený dvakrát.
                $holidayMinutes += self::minutesOnDates($window, $periodStart, $periodEnd, $holidays);
                continue;
            }

            $title = PayrollWageReplacementTitle::forAbsenceType($type);
            if ($title === null) {
                return self::datedNothing('absence_type_unsupported');
            }
            $segments = $this->windowSegments(
                $row,
                false,
                PayrollWageReplacementTitle::holidayTreatment($type),
                $fromCalendar,
            );
            // Ze základní mzdy vypadne svátek JEN tehdy, když ho nějaký titul
            // opravdu zaplatí — a to umí pouze náhrada při DPN (§ 192 odst. 1).
            // U dovolené se svátek nečerpá (§ 219 odst. 1) a u ostatních absencí
            // za něj nikdo nic neposkytuje, takže mzda za něj zůstává nekrácená
            // (§ 115 odst. 3). Publikovaná směna na svátek by jinak srazila mzdu
            // za dobu, kterou nic nenahrazuje.
            $byTitle[$title->value] = ($byTitle[$title->value] ?? 0)
                + self::minutesInMonth($segments, $periodStart, $periodEnd)
                - self::minutesOnDates($segments, $periodStart, $periodEnd, $holidays);
        }

        return ['by_title' => $byTitle, 'holiday_minutes' => $holidayMinutes, 'reason' => null];
    }

    /**
     * Doba nepřítomnosti v okně, kterým se řídí titul: u nemoci okno § 192 ZP,
     * jinak celý rozsah nepřítomnosti.
     *
     * @param array<string,mixed> $row
     * @return list<array{shift_id:?int,local_date:string,planned_minutes:int,eligible_minutes:int}>
     */
    private function windowSegments(
        array $row,
        bool $firstDayFullyWorked,
        AbsenceHolidayTreatment $holidayTreatment,
        bool $fromCalendar,
    ): array {
        if (!$fromCalendar) {
            return $this->absences->publishedShiftSegments($row, $firstDayFullyWorked, $holidayTreatment);
        }

        $from = self::localDate($row, (string) $row['date_from']);
        if ($firstDayFullyWorked) {
            $from = $from->modify('+1 day');
        }
        $to = self::localDate($row, (string) $row['date_to']);
        if (self::isSickness($row)) {
            $windowEnd = $this->sicknessWindowEnd($row, $from);
            if ($windowEnd < $to) {
                $to = $windowEnd;
            }
        }

        return $this->calendarSegments($row, $from, $to, $holidayTreatment);
    }

    /**
     * Doba nemoci ZA oknem § 192 ZP — tu už zaměstnavatel nehradí, ale mzda za
     * ni stejně nepřísluší.
     *
     * @param array<string,mixed> $row
     * @return list<array{shift_id:?int,local_date:string,planned_minutes:int,eligible_minutes:int}>
     */
    private function beyondWindowSegments(array $row, bool $firstDayFullyWorked, bool $fromCalendar): array
    {
        if (!$fromCalendar) {
            return $this->absences->publishedShiftSegmentsBeyondSicknessWindow($row, $firstDayFullyWorked);
        }
        if (!self::isSickness($row)) {
            return [];
        }

        $from = self::localDate($row, (string) $row['date_from']);
        if ($firstDayFullyWorked) {
            $from = $from->modify('+1 day');
        }
        $to = self::localDate($row, (string) $row['date_to']);
        $tailFrom = $this->sicknessWindowEnd($row, $from)->modify('+1 day');
        if ($tailFrom < $from) {
            $tailFrom = $from;
        }
        if ($tailFrom > $to) {
            return [];
        }

        // Svátek se za oknem neřeší: zaměstnavatel za něj neposkytuje nic, takže
        // bez rozvržené směny je hodin nula — stejně jako u směnové cesty.
        return $this->calendarSegments($row, $tailFrom, $to, AbsenceHolidayTreatment::Ignore);
    }

    /**
     * Doba nepřítomnosti měřená ROZVRHEM pracovního kalendáře, ne publikovanými
     * směnami.
     *
     * Měsíc převzatý z jiného mzdového programu směny nikdy mít nebude — převod
     * zakládá pracovní kalendář a měsíční souhrn, rozvrh směn nikoliv — a přitom
     * v něm skoro vždy leží nemoc nebo ošetřovné. Měřit takový měsíc směnami
     * znamená naměřit nulu, a to je u krácení mzdy tiché přeplacení.
     *
     * Kalendář tu není odhad: u měsíce ze souhrnu se jeho fond porovnává s fondem
     * z podkladů převodu (`fund_hours`) a rozdíl krácení zastaví
     * ({@see PayrollEmploymentCalendarProvisioner::fundCheck()}, guard
     * `import_fund_mismatch`). Doba se proto měří proti rozvrhu, o kterém je
     * doloženo, že sedí s tím, co o měsíci tvrdí předchozí program.
     *
     * Zdrojem denních minut je {@see PayrollWorkCalendarSchedule::plannedMinutes()},
     * tedy tatáž odpověď na otázku „kolik by ten den odpracoval", jakou používá
     * svátek v absenci u směnové cesty. Svátek se pak řeší doslova týmiž pravidly
     * ({@see AbsenceHolidaySegments}) a částečný den týmž stropem jako u směn.
     *
     * @param array<string,mixed> $row
     * @return list<array{shift_id:?int,local_date:string,planned_minutes:int,eligible_minutes:int}>
     */
    private function calendarSegments(
        array $row,
        \DateTimeImmutable $windowFrom,
        \DateTimeImmutable $windowTo,
        AbsenceHolidayTreatment $holidayTreatment,
    ): array {
        if ($windowTo < $windowFrom) {
            return [];
        }
        $supplierId = PayrollTimeValue::int($row['supplier_id'] ?? null, 'supplier_id');
        $employmentId = PayrollTimeValue::int($row['employment_id'] ?? null, 'employment_id');

        $dates = [];
        for ($day = $windowFrom; $day <= $windowTo; $day = $day->modify('+1 day')) {
            $dates[] = $day->format('Y-m-d');
        }
        $planned = $this->schedule->plannedMinutes($supplierId, $employmentId, $dates);

        $remainingByDate = [];
        if ($row['partial_first_minutes'] !== null) {
            $remainingByDate[(string) $row['date_from']] = (int) $row['partial_first_minutes'];
        }
        if ($row['partial_last_minutes'] !== null) {
            $lastDate = (string) $row['date_to'];
            $lastLimit = (int) $row['partial_last_minutes'];
            $remainingByDate[$lastDate] = isset($remainingByDate[$lastDate])
                ? min($remainingByDate[$lastDate], $lastLimit)
                : $lastLimit;
        }

        $segments = [];
        foreach ($dates as $date) {
            $minutes = $planned[$date] ?? 0;
            if ($minutes <= 0) {
                continue;
            }
            $eligible = $minutes;
            if (array_key_exists($date, $remainingByDate)) {
                $eligible = min($eligible, $remainingByDate[$date]);
                $remainingByDate[$date] -= $eligible;
            }
            if ($eligible <= 0) {
                continue;
            }
            $segments[] = [
                'shift_id' => null,
                'local_date' => $date,
                'planned_minutes' => $minutes,
                'eligible_minutes' => $eligible,
            ];
        }

        if ($holidayTreatment === AbsenceHolidayTreatment::Ignore) {
            return $segments;
        }
        $holidays = PayrollWorkCalendarSchedule::holidaysBetween(
            $this->holidays,
            $windowFrom->format('Y-m-d'),
            $windowTo->format('Y-m-d'),
        );
        if ($holidays === []) {
            return $segments;
        }
        if ($holidayTreatment === AbsenceHolidayTreatment::ExcludeFromLeave) {
            return AbsenceHolidaySegments::excludeFromLeave($segments, $holidays);
        }

        // Rozvrh svátek nevynechává, takže den v okně § 192 už mezi segmenty je
        // a compensateSickness ho jen ponechá. Volá se stejně jako u směn, aby
        // obě cesty držely tentýž výklad § 192 odst. 1 ZP.
        return AbsenceHolidaySegments::compensateSickness(
            $segments,
            $this->schedule->plannedMinutes($supplierId, $employmentId, array_keys($holidays)),
            $remainingByDate,
        );
    }

    /**
     * Poslední den okna náhrady mzdy podle § 192 ZP — týž výpočet z rulesetu
     * i týž zdroj dnů vyčerpaných předchozím plátcem, jaký používá
     * {@see PayrollAbsenceRepository::publishedShiftSegments()}.
     *
     * @param array<string,mixed> $row
     */
    private function sicknessWindowEnd(array $row, \DateTimeImmutable $windowFrom): \DateTimeImmutable
    {
        return AbsenceRuleset::forDate($this->rulesets, (string) $row['date_from'])
            ->sicknessWindowEnd($windowFrom, PayrollAbsenceRepository::carriedWindowDays($row));
    }

    /** @param array<string,mixed> $row */
    private static function isSickness(array $row): bool
    {
        return in_array($row['absence_type'] ?? null, ['dpn', 'quarantine'], true);
    }

    /** @param array<string,mixed> $row */
    private static function localDate(array $row, string $date): \DateTimeImmutable
    {
        return new \DateTimeImmutable($date, new \DateTimeZone((string) $row['timezone_name']));
    }

    /** @return array{by_title:array<string,int>,holiday_minutes:int,reason:string} */
    private static function datedNothing(string $reason): array
    {
        return ['by_title' => [], 'holiday_minutes' => 0, 'reason' => $reason];
    }

    /**
     * @param array<string,int> $byTitle
     * @return array{
     *   supported:bool,reason:?string,fund_minutes:?int,replaced_minutes:int,
     *   replaced_minutes_by_title:array<string,int>,amount_minor:?int,trace:?array<string,mixed>
     * }
     */
    private static function prorated(int $monthlyGrossMinor, int $fundMinutes, array $byTitle): array
    {
        $result = MonthlyWageProration::calculate($monthlyGrossMinor, $fundMinutes, $byTitle);

        return [
            'supported' => true,
            'reason' => null,
            'fund_minutes' => $result->fundMinutes,
            'replaced_minutes' => $result->replacedMinutes,
            'replaced_minutes_by_title' => $result->replacedMinutesByTitle,
            'amount_minor' => $result->amountMinor,
            'trace' => $result->trace(),
        ];
    }

    /**
     * Má vztah v měsíci vůbec nějakou publikovanou směnu?
     *
     * Rozhoduje se za celý měsíc, ne za jednotlivou nepřítomnost: rozvrh se
     * publikuje po měsících a míchat v jednom měsíci dva způsoby měření by
     * znamenalo, že táž doba vychází různě podle toho, na který den padne.
     * Rozsah je o den širší na obě strany, ať noční směna přes půlnoc měsíc
     * nepodstřelí — nejistota se tu vyhodnocuje ve prospěch přísnější
     * směnové cesty.
     */
    private function hasPublishedShifts(
        int $supplierId,
        int $employmentId,
        string $periodStart,
        string $periodEnd,
    ): bool {
        if (!$this->db->hasTable('payroll_shifts')) {
            return false;
        }
        $stmt = $this->db->pdo()->prepare(
            "SELECT 1 FROM payroll_shifts
              WHERE supplier_id = ? AND employment_id = ? AND status = 'published'
                AND starts_at_utc < ? AND ends_at_utc > ?
              LIMIT 1"
        );
        $stmt->execute([
            $supplierId,
            $employmentId,
            (new \DateTimeImmutable($periodEnd))->modify('+2 days')->format('Y-m-d 00:00:00'),
            (new \DateTimeImmutable($periodStart))->modify('-1 day')->format('Y-m-d 00:00:00'),
        ]);

        return $stmt->fetchColumn() !== false;
    }

    /** @return list<array<string,mixed>> */
    private function absencesInMonth(
        int $supplierId,
        int $employmentId,
        string $periodStart,
        string $periodEnd,
    ): array {
        $stmt = $this->db->pdo()->prepare(
            'SELECT * FROM payroll_absences
              WHERE supplier_id = ? AND employment_id = ?
                AND status IN ("requested", "approved")
                AND date_from <= ? AND date_to >= ?
              ORDER BY date_from, id'
        );
        $stmt->execute([$supplierId, $employmentId, $periodEnd, $periodStart]);

        return PayrollTimeValue::rows($stmt->fetchAll(PDO::FETCH_ASSOC), 'payroll_absences');
    }

    /**
     * Byl první den nemoci odpracován celý? Odpověď je zmrazená ve výpočtu
     * náhrady; hádat ji znovu by posunulo okno § 192 o den.
     *
     * @param array<string,mixed> $absence
     */
    private function firstDayFullyWorked(int $supplierId, array $absence): ?bool
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT first_day_fully_worked
               FROM payroll_sickness_events
              WHERE supplier_id = ? AND absence_id = ?'
        );
        $stmt->execute([$supplierId, PayrollTimeValue::int($absence['id'] ?? null, 'absence_id')]);
        $value = $stmt->fetchColumn();

        return $value === false ? null : (int) $value === 1;
    }

    /**
     * @param list<array{shift_id:?int,local_date:string,planned_minutes:int,eligible_minutes:int}> $segments
     */
    private static function minutesInMonth(array $segments, string $from, string $to): int
    {
        $minutes = 0;
        foreach ($segments as $segment) {
            $date = (string) $segment['local_date'];
            if ($date >= $from && $date <= $to) {
                $minutes += (int) $segment['eligible_minutes'];
            }
        }

        return $minutes;
    }

    /**
     * @param list<array{shift_id:?int,local_date:string,planned_minutes:int,eligible_minutes:int}> $segments
     * @param array<string,mixed> $dates
     */
    private static function minutesOnDates(
        array $segments,
        string $from,
        string $to,
        array $dates,
    ): int {
        $minutes = 0;
        foreach ($segments as $segment) {
            $date = (string) $segment['local_date'];
            if ($date >= $from && $date <= $to && array_key_exists($date, $dates)) {
                $minutes += (int) $segment['eligible_minutes'];
            }
        }

        return $minutes;
    }

    /**
     * @return array{
     *   supported:bool,reason:?string,fund_minutes:?int,replaced_minutes:int,
     *   replaced_minutes_by_title:array<string,int>,amount_minor:?int,trace:?array<string,mixed>
     * }
     */
    private static function none(): array
    {
        return [
            'supported' => true,
            'reason' => null,
            'fund_minutes' => null,
            'replaced_minutes' => 0,
            'replaced_minutes_by_title' => [],
            'amount_minor' => null,
            'trace' => null,
        ];
    }

    /**
     * @return array{
     *   supported:bool,reason:?string,fund_minutes:?int,replaced_minutes:int,
     *   replaced_minutes_by_title:array<string,int>,amount_minor:?int,trace:?array<string,mixed>
     * }
     */
    private static function unsupported(string $reason): array
    {
        return [
            'supported' => false,
            'reason' => $reason,
            'fund_minutes' => null,
            'replaced_minutes' => 0,
            'replaced_minutes_by_title' => [],
            'amount_minor' => null,
            'trace' => null,
        ];
    }
}
