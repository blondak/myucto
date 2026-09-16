<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Component;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\Payroll\PayrollRecurringComponentRepository;
use MyInvoice\Repository\Payroll\PayrollTimeValue;
use MyInvoice\Service\Payroll\Absence\PayrollWageProrationService;

/**
 * ── Krácení základní mzdy za nepřítomnost ────────────────────────────────────
 *
 * Mzda přísluší ZA VYKONANOU PRÁCI (§ 109 odst. 1 ZP). Předpis měsíční mzdy
 * proto nesmí vyplatit plnou sjednanou částku za měsíc, ve kterém část doby
 * kryje jiný titul — náhrada za dovolenou, náhrada při DPN, placená překážka.
 * Jinak se tatáž doba zaplatí dvakrát: jednou mzdou a podruhé náhradou.
 *
 * Vlastní poměr počítá {@see \MyInvoice\Service\Payroll\Calculation\MonthlyWageProration}
 * z HODIN (fond měsíce minus nahrazené minuty), ne z pracovních dnů — viz
 * rozsudek NS 21 Cdo 2801/2021. Podklad sbírá {@see PayrollWageProrationService},
 * tedy TÝŽ zdroj, ze kterého krátí rychlý měsíční vstup; druhá vlastní
 * aritmetika by znamenala dvě různá čísla pro tutéž mzdu podle toho, kterou
 * cestou vstup vznikl.
 *
 * Krátí se jen složka druhu `base_wage`. Pevný měsíční benefit nebo příspěvek
 * se za nepřítomnost nekrátí — nepřísluší za odpracovanou dobu, takže poměr by
 * na něj neseděl.
 *
 * Fail-closed: bez doloženého časového podkladu (chybí kalendář, měsíc má
 * rozporná data) se částka NEODHADUJE a předpis jde k ručnímu posouzení.
 * Vrátit v takové chvíli plnou sjednanou mzdu je horší než nevrátit nic —
 * číslo vypadá hotově a nikdo ho už nezkontroluje.
 */
final class PayrollRecurringMaterializer
{
    public function __construct(
        private readonly Connection $db,
        private readonly PayrollRecurringComponentRepository $recurring,
        private readonly PayrollRecurringAmountCalculator $calculator,
        private readonly PayrollWageProrationService $wageProration,
    ) {
    }

    /** @return array<string,mixed> */
    public function materialize(int $supplierId, string $period, ?int $userId): array
    {
        $periodStart = $this->period($period);
        $pdo = $this->db->pdo();
        $ownsTransaction = !$pdo->inTransaction();
        if ($ownsTransaction) {
            $pdo->beginTransaction();
        }
        try {
            $created = [];
            $replayed = [];
            $manualReview = [];
            foreach ($this->recurring->effectiveForPeriod($supplierId, $periodStart) as $row) {
                if (!PayrollTimeValue::bool(
                    $row['component_is_active'] ?? null,
                    'component_is_active',
                )) {
                    $manualReview[] = $this->blocked($row, 'Mzdová složka není aktivní.');
                    continue;
                }
                $calculation = $this->calculator->calculate($row, $periodStart);
                if ($calculation['status'] === 'supported') {
                    $calculation = $this->prorateForAbsences(
                        $supplierId,
                        $periodStart,
                        $row,
                        $calculation,
                    );
                }
                if ($calculation['status'] !== 'supported') {
                    $blocker = $calculation['blocker'];
                    if (!is_string($blocker)) {
                        throw new \UnexpectedValueException(
                            'Ručně posuzovaný předpis nemá důvod blokace.'
                        );
                    }
                    $manualReview[] = $this->blocked(
                        $row,
                        $blocker,
                    );
                    continue;
                }
                $draft = $this->recurring->createDraftInput(
                    $supplierId,
                    $periodStart,
                    $row,
                    $calculation,
                    $userId,
                );
                $item = [
                    'recurring_component_id' => PayrollTimeValue::int(
                        $row['id'] ?? null,
                        'recurring_component_id',
                    ),
                    'input_id' => $draft['input_id'],
                    'amount_minor' => PayrollTimeValue::int(
                        $calculation['amount_minor'] ?? null,
                        'amount_minor',
                    ),
                ];
                if ($draft['created']) {
                    $created[] = $item;
                } else {
                    $replayed[] = $item;
                }
            }
            if ($ownsTransaction) {
                $pdo->commit();
            }
        } catch (\Throwable $e) {
            $this->rollbackOwned($pdo, $ownsTransaction);
            throw $e;
        }

        return [
            'period' => substr($periodStart, 0, 7),
            'created_count' => count($created),
            'replayed_count' => count($replayed),
            'manual_review_count' => count($manualReview),
            'created' => $created,
            'replayed' => $replayed,
            'manual_review' => $manualReview,
        ];
    }

    /**
     * Zkrácení spočítané částky o dobu, kterou v měsíci kryje jiný titul.
     *
     * Vstupem krácení je částka, kterou vrátil kalkulátor, ne sjednaná měsíční
     * mzda: kdyby předpis platil jen část měsíce (`calendar_days`), je poměrná
     * část už v ní a krátit znovu z plné sjednané částky by ji nafouklo zpátky.
     *
     * @param array<string,mixed> $row
     * @param array{status:string,amount_minor:?int,trace:array<string,mixed>,blocker:?string} $calculation
     * @return array{status:string,amount_minor:?int,trace:array<string,mixed>,blocker:?string}
     */
    private function prorateForAbsences(
        int $supplierId,
        string $periodStart,
        array $row,
        array $calculation,
    ): array {
        // Krátí se JEN základní mzda. Druh složky, ne kód — kód si firma může
        // přejmenovat, druh je vlastnost číselníku.
        if (($row['component_kind'] ?? null) !== 'base_wage') {
            return $calculation;
        }
        $amount = $calculation['amount_minor'];
        if (!is_int($amount) || $amount <= 0) {
            return $calculation;
        }

        // Období předává volající. Odvozovat ho z `valid_from` předpisu by bylo
        // hrubě špatně: to je den, od kterého předpis platí, ne zpracovávaný měsíc.
        $period = substr($periodStart, 0, 7);
        $employmentId = PayrollTimeValue::int($row['employment_id'] ?? null, 'employment_id');

        // Měsíc ze souhrnu importu nemá směny, takže se nepřítomnost měří
        // měsíčními součty hodin; měsíc se směnami se měří rozvrhem.
        $proration = ($row['time_work_source'] ?? null) === 'import_summary'
            ? $this->wageProration->forImportSummary($supplierId, $employmentId, $period, $amount)
            : $this->wageProration->forMonth($supplierId, $employmentId, $period, $amount);

        if ($proration['supported'] === false) {
            return [
                'status' => 'manual_review',
                'amount_minor' => null,
                'blocker' => self::prorationBlocker(
                    is_string($proration['reason'] ?? null) ? $proration['reason'] : 'unknown',
                ),
                'trace' => [],
            ];
        }
        // `amount_minor === null` znamená „v měsíci není co krátit" — celý fond
        // je odpracovaný. Částka zůstává, jak ji spočítal kalkulátor.
        $prorated = $proration['amount_minor'];
        if (!is_int($prorated)) {
            return $calculation;
        }

        $trace = $calculation['trace'];
        $trace['wage_proration'] = $proration['trace'];

        return [
            'status' => 'supported',
            'amount_minor' => $prorated,
            'blocker' => null,
            'trace' => $trace,
        ];
    }

    /** Důvod, proč krácení nejde doložit, ve tvaru pro mzdovou účetní. */
    private static function prorationBlocker(string $reason): string
    {
        return match ($reason) {
            'missing_work_calendar' =>
                'Pracovní vztah nemá pro tento měsíc pracovní kalendář, takže nelze určit '
                . 'fond pracovní doby ani poměrnou část mzdy za nepřítomnost. Doplňte kalendář.',
            'absence_pending_decision' =>
                'V měsíci je nerozhodnutá nepřítomnost. Rozhodněte ji, jinak by se poměrná '
                . 'část mzdy po schválení změnila.',
            'absence_correction_pending' =>
                'U nepřítomnosti v měsíci běží oprava. Dokončete ji a předpis zopakujte.',
            'sickness_calculation_missing' =>
                'Nemoc v měsíci nemá zmrazený výpočet náhrady, takže okno § 192 ZP nelze '
                . 'určit a poměrnou část mzdy nejde doložit.',
            'absence_exceeds_work_fund' =>
                'Nepřítomnost v měsíci přesahuje fond pracovní doby; evidence si odporuje '
                . 'a poměrná část mzdy by z ní vyšla nesprávně.',
            'import_summary_with_dated_absences' =>
                'Měsíc má docházku ze souhrnu importu a zároveň nepřítomnost s daty, takže '
                . 'by se tatáž doba krátila dvakrát. Ponechte jen jeden zdroj.',
            'import_summary_missing', 'empty_work_fund', 'import_fund_mismatch',
            'import_summary_inconsistent' =>
                'Měsíc nemá doložený fond pracovní doby ani hodiny nepřítomnosti, ze kterých '
                . 'by šla poměrná část mzdy spočítat.',
            default =>
                'Poměrnou část měsíční mzdy za nepřítomnost nelze pro tento měsíc doložit.',
        };
    }

    private function period(string $value): string
    {
        $date = \DateTimeImmutable::createFromFormat('!Y-m', $value);
        if ($date === false || $date->format('Y-m') !== $value) {
            throw new \InvalidArgumentException('Období musí být měsíc YYYY-MM.');
        }
        return $value . '-01';
    }

    /**
     * @param array<string,mixed> $row
     * @return array{recurring_component_id:int,employment_id:int,component_id:int,reason:string}
     */
    private function blocked(array $row, string $reason): array
    {
        return [
            'recurring_component_id' => PayrollTimeValue::int(
                $row['id'] ?? null,
                'recurring_component_id',
            ),
            'employment_id' => PayrollTimeValue::int(
                $row['employment_id'] ?? null,
                'employment_id',
            ),
            'component_id' => PayrollTimeValue::int(
                $row['component_id'] ?? null,
                'component_id',
            ),
            'reason' => $reason,
        ];
    }

    private function rollbackOwned(\PDO $pdo, bool $ownsTransaction): void
    {
        if ($ownsTransaction && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
    }
}
