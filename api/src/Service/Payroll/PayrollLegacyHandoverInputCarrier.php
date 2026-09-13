<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\Payroll\PayrollEmploymentLifecycleSql;
use MyInvoice\Repository\Payroll\PayrollInputRepository;
use MyInvoice\Repository\Payroll\PayrollStatutoryAccumulatorRepository;
use MyInvoice\Service\Payroll\Component\PayrollInputValidator;
use PDO;

/**
 * Převod hrubé mzdy z odloženého mzdového listu do mzdového vstupu modulu.
 *
 * ── Proč to vzniklo ─────────────────────────────────────────────────────────
 * Předání měsíce od ruční rekapitulace ({@see PayrollLegacyRecapitulationService::handOverToModule()})
 * stornuje zápis, odloží mzdový list, ubere měsíc z počátečních stavů a uvolní
 * období — ale mzdové vstupy nezaloží. Kdo měl mzdu importovanou, tomu to
 * nevadí. Osoba bez importu (typicky společník s pevnou měsíční částkou) ale
 * v běhu za předaný měsíc skončí na `payroll_component_missing` a účetní musí
 * vstup vymyslet ručně, přestože hrubá mzda za ten měsíc leží v odloženém listu.
 *
 * ── Co dělá ─────────────────────────────────────────────────────────────────
 * Pro každý odložený list předaného měsíce založí jeden ruční vstup základní
 * složky vztahu s částkou hrubé mzdy z listu. Legacy rekapitulace žádný rozpad
 * na složky nezná — `PayrollCalculator` pracuje s jedinou hrubou mzdou — takže
 * jiná věrná podoba vstupu neexistuje.
 *
 * Všechno ostatní je fail-closed a vrací se jako důvod přeskočení, ne jako
 * výjimka: jeden nejasný člověk nesmí zablokovat předání celé firmy.
 *
 *  - vztah se k listu (ten zná jen osobu) páruje na JEDINÝ vztah účinný
 *    v měsíci; víc vztahů neumíme rozdělit,
 *  - existuje-li pro vztah a měsíc nezrušený vstup nebo pravidelná složka,
 *    nezakládá se nic — obojí by s převedenou hrubou mzdou vedlo na dvojí
 *    započtení,
 *  - složka se odvozuje z dat: nejdřív ze základní složky, kterou vztah už
 *    používal, jinak z jediné účinné složky druhu `base_wage` v číselníku;
 *    víc kandidátů nebo hodinová mzda = přeskočit,
 *  - převedená částka musí sedět na to, co měsíc vážil v počátečním stavu,
 *    ze kterého ho předání odebralo (viz {@see openingCheck()}).
 *
 * Idempotence: stabilní `external_id` `legacy-handover:<RRRR-MM>:<vztah>`
 * a hlavně pravidlo „žádný nezrušený vstup za vztah a měsíc" — opakované
 * předání najde vstup z prvního převodu a přeskočí.
 */
final class PayrollLegacyHandoverInputCarrier
{
    public const EXTERNAL_PREFIX = 'legacy-handover:';

    /** Druhy složek, které jsou pro vztah jeho „základem". */
    private const BASE_KINDS = ['base_wage', 'hourly_wage'];

    public function __construct(
        private readonly Connection $db,
        private readonly PayrollInputValidator $validator,
        private readonly PayrollInputRepository $inputs,
        private readonly PayrollStatutoryAccumulatorRepository $accumulators,
    ) {}

    public static function externalId(int $year, int $month, int $employmentId): string
    {
        return sprintf('%s%04d-%02d:%d', self::EXTERNAL_PREFIX, $year, $month, $employmentId);
    }

    /**
     * @return array{
     *   carried_over_inputs:int,
     *   carried_input_ids:list<int>,
     *   skipped:list<array{employee_id:int,reason:string,message:string}>
     * }
     */
    public function carryOver(
        int $supplierId,
        int $year,
        int $month,
        ?int $userId,
        bool $approve,
    ): array {
        $periodStart = sprintf('%04d-%02d-01', $year, $month);
        $periodEnd = (new \DateTimeImmutable($periodStart))
            ->modify('last day of this month')
            ->format('Y-m-d');

        $carried = [];
        $skipped = [];
        foreach ($this->retiredRecords($supplierId, $year, $month) as $record) {
            $employeeId = $record['employee_id'];
            $skip = static function (string $reason, string $message) use (&$skipped, $employeeId): void {
                $skipped[] = [
                    'employee_id' => $employeeId,
                    'reason' => $reason,
                    'message' => $message,
                ];
            };

            if ($record['gross'] <= 0) {
                $skip(
                    'legacy_gross_not_positive',
                    'Odložený mzdový list nemá kladnou hrubou mzdu, není co převést.',
                );
                continue;
            }
            $amountMinor = $record['gross'] * 100;

            $employmentIds = $this->employmentsInMonth($supplierId, $employeeId, $periodStart, $periodEnd);
            if ($employmentIds === []) {
                $skip(
                    'employment_missing',
                    'Osoba nemá v předaném měsíci účinný pracovní vztah.',
                );
                continue;
            }
            if (count($employmentIds) > 1) {
                $skip(
                    'multiple_employments',
                    'Osoba má v předaném měsíci víc vztahů; mzdový list je za osobu a na vztahy ho rozdělit neumíme.',
                );
                continue;
            }
            $employmentId = $employmentIds[0];

            if ($this->hasLiveInput($supplierId, $employmentId, $periodStart)) {
                $skip(
                    'inputs_exist',
                    'Pro vztah a měsíc už existuje nezrušený mzdový vstup; převod by mzdu započetl dvakrát.',
                );
                continue;
            }
            if ($this->hasRecurringComponent($supplierId, $employmentId, $periodStart, $periodEnd)) {
                $skip(
                    'recurring_components_exist',
                    'Vztah má na předaný měsíc pravidelnou mzdovou složku; běh ji založí sám a převod by mzdu započetl dvakrát.',
                );
                continue;
            }

            $opening = $this->openingCheck($supplierId, $employeeId, $year, $month, $amountMinor);
            if ($opening !== null) {
                $skip($opening['reason'], $opening['message']);
                continue;
            }

            $component = $this->baseComponent($supplierId, $employmentId, $periodStart);
            if (isset($component['reason'])) {
                $skip($component['reason'], $component['message']);
                continue;
            }

            try {
                $data = $this->validator->validate([
                    'employee_id' => $employeeId,
                    'employment_id' => $employmentId,
                    'component_id' => $component['component_id'],
                    'period' => substr($periodStart, 0, 7),
                    'amount_minor' => $amountMinor,
                    'source_kind' => 'manual',
                    'external_id' => self::externalId($year, $month, $employmentId),
                ]);
                $input = $approve
                    ? $this->inputs->createApproved($supplierId, $data, $userId)
                    : $this->inputs->create($supplierId, $data, $userId);
            } catch (\InvalidArgumentException|\DomainException $e) {
                $skip('input_rejected', $e->getMessage());
                continue;
            }
            $carried[] = (int) $input['id'];
        }

        return [
            'carried_over_inputs' => count($carried),
            'carried_input_ids' => $carried,
            'skipped' => $skipped,
        ];
    }

    /**
     * Odložené listy za období. Čte se ODLOŽENÝ stav, ne „ten, který teď
     * odkládám": převod musí jít i dodatečně u měsíce, který byl předán
     * dřív, než tahle volba existovala.
     *
     * @return list<array{employee_id:int,gross:int}>
     */
    private function retiredRecords(int $supplierId, int $year, int $month): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT employee_id, gross
               FROM payroll_monthly_records
              WHERE supplier_id = ? AND year = ? AND month = ?
                AND retired_at IS NOT NULL
              ORDER BY employee_id'
        );
        $stmt->execute([$supplierId, $year, $month]);

        return array_map(
            static fn (array $row): array => [
                'employee_id' => (int) $row['employee_id'],
                'gross' => (int) $row['gross'],
            ],
            $stmt->fetchAll(PDO::FETCH_ASSOC),
        );
    }

    /**
     * Vztahy osoby účinné aspoň část měsíce — totéž vymezení jako rychlé
     * zadání mezd (stav k poslednímu dni, nástup do konce a konec od začátku
     * měsíce).
     *
     * @return list<int>
     */
    private function employmentsInMonth(
        int $supplierId,
        int $employeeId,
        string $periodStart,
        string $periodEnd,
    ): array {
        $stmt = $this->db->pdo()->prepare(
            'SELECT employment.id
               FROM payroll_employments employment
              WHERE employment.supplier_id = ? AND employment.employee_id = ?
                AND COALESCE(
                      employment.actual_start_date,
                      employment.start_date,
                      CASE WHEN employment.is_legacy_projection = 1
                           THEN "1900-01-01" ELSE NULL END
                    ) <= ?
                AND (employment.end_date IS NULL OR employment.end_date >= ?)
                AND ' . PayrollEmploymentLifecycleSql::effectiveStatusAtPlaceholder() . '
                    IN ("active", "suspended", "ended")
              ORDER BY employment.id'
        );
        $stmt->execute([$supplierId, $employeeId, $periodEnd, $periodStart, $periodEnd]);

        return array_map(
            static fn (mixed $id): int => (int) $id,
            $stmt->fetchAll(PDO::FETCH_COLUMN),
        );
    }

    private function hasLiveInput(int $supplierId, int $employmentId, string $periodStart): bool
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT 1
               FROM payroll_inputs
              WHERE supplier_id = ? AND employment_id = ? AND period_start = ?
                AND status <> "cancelled"
              LIMIT 1'
        );
        $stmt->execute([$supplierId, $employmentId, $periodStart]);

        return $stmt->fetchColumn() !== false;
    }

    private function hasRecurringComponent(
        int $supplierId,
        int $employmentId,
        string $periodStart,
        string $periodEnd,
    ): bool {
        $stmt = $this->db->pdo()->prepare(
            'SELECT 1
               FROM payroll_recurring_components
              WHERE supplier_id = ? AND employment_id = ?
                AND is_active = 1
                AND valid_from <= ?
                AND (valid_to IS NULL OR valid_to >= ?)
              LIMIT 1'
        );
        $stmt->execute([$supplierId, $employmentId, $periodEnd, $periodStart]);

        return $stmt->fetchColumn() !== false;
    }

    /**
     * Sedí převáděná částka na počáteční stav?
     *
     * Předání měsíc z počátečních stavů ubírá a běh ho pak započte znovu.
     * Roční kumulace (vyměřovací základ, daňový základ) zůstane beze změny jen
     * tehdy, když vstup váží přesně tolik, kolik měsíc vážil v openingu. Proto:
     *
     *  - opening bez rozpisu měsíců (a nenulový) předání ubrat neumí — měsíc
     *    v něm dál sedí a vstup by ho započetl podruhé,
     *  - měsíc, který v aktuálním openingu pořád je, taky,
     *  - měsíc odebraný předáním se porovná s rozpisem verze PŘED předáním:
     *    daňový základ (zálohový + srážkový) = hrubá mzda; bez daňových čísel
     *    vyměřovací základ sociálního pojištění.
     *
     * Opening, ve kterém měsíc nikdy nebyl, nic nekontroluje: měsíc do roční
     * kumulace přinese jen běh.
     *
     * @return array{reason:string,message:string}|null null = v pořádku
     */
    private function openingCheck(
        int $supplierId,
        int $employeeId,
        int $year,
        int $month,
        int $amountMinor,
    ): ?array {
        foreach (['income_tax', 'social_insurance'] as $kind) {
            $versions = $this->accumulators->openingVersions($supplierId, $employeeId, $year, $kind);
            if ($versions === []) {
                continue;
            }
            $byId = [];
            $replaced = [];
            foreach ($versions as $version) {
                $byId[$version['id']] = $version;
                if ($version['replaces_opening_id'] !== null) {
                    $replaced[$version['replaces_opening_id']] = true;
                }
            }

            $handoverReference = PayrollLegacyRecapitulationService::handOverOpeningReference($year, $month);
            foreach ($versions as $version) {
                if ($version['source_reference'] !== $handoverReference
                    || $version['replaces_opening_id'] === null
                ) {
                    continue;
                }
                $row = self::monthRow($byId[$version['replaces_opening_id']] ?? null, $month);
                if ($row === null) {
                    continue;
                }
                $expected = self::monthWeight($row);
                if ($expected !== $amountMinor) {
                    return [
                        'reason' => 'opening_balance_mismatch',
                        'message' => sprintf(
                            'Počáteční stav vedl měsíc s částkou %s Kč, odložený mzdový list s %s Kč; převod by změnil roční úhrny.',
                            self::formatMinor($expected),
                            self::formatMinor($amountMinor),
                        ),
                    ];
                }

                return null;
            }

            $current = null;
            foreach ($versions as $version) {
                if (!isset($replaced[$version['id']])) {
                    $current = $version;
                }
            }
            if ($current === null) {
                return null;
            }
            if (self::monthRow($current, $month) !== null) {
                return [
                    'reason' => 'opening_balance_contains_month',
                    'message' => 'Měsíc je pořád v počátečních stavech; běh by ho započetl podruhé.',
                ];
            }
            $months = $current['evidence']['months'] ?? null;
            if ((!is_array($months) || $months === [])
                && array_filter($current['values'], static fn (int $value): bool => $value !== 0) !== []
            ) {
                return [
                    'reason' => 'opening_balance_not_itemized',
                    'message' => 'Počáteční stav nemá rozpis po měsících, takže z něj předaný měsíc nešlo ubrat.',
                ];
            }

            return null;
        }

        return null;
    }

    /**
     * @param array<string,mixed>|null $opening
     * @return array<string,mixed>|null
     */
    private static function monthRow(?array $opening, int $month): ?array
    {
        $months = $opening['evidence']['months'] ?? null;
        if (!is_array($months)) {
            return null;
        }
        foreach ($months as $row) {
            if (is_array($row) && (int) ($row['month'] ?? 0) === $month) {
                return $row;
            }
        }

        return null;
    }

    /** @param array<string,mixed> $row */
    private static function monthWeight(array $row): int
    {
        $taxBase = (int) ($row['advance_base_minor_units'] ?? 0)
            + (int) ($row['withholding_base_minor_units'] ?? 0);

        return $taxBase > 0
            ? $taxBase
            : (int) ($row['social_assessment_base_minor_units'] ?? 0);
    }

    private static function formatMinor(int $minor): string
    {
        return number_format($minor / 100, 2, ',', ' ');
    }

    /**
     * Základní složka vztahu k začátku měsíce.
     *
     * Nevymýšlí se: nejdřív se bere základní složka, kterou vztah už někdy
     * měl (vstup nebo pravidelná složka), a teprve když žádnou nemá, jediná
     * účinná složka druhu `base_wage` v číselníku firmy (ve výchozím
     * číselníku `MZDA_MESICNI` — tutéž bere pro základ i rychlé zadání).
     *
     * @return array{component_id:int}|array{reason:string,message:string}
     */
    private function baseComponent(int $supplierId, int $employmentId, string $periodStart): array
    {
        $kinds = implode(',', array_fill(0, count(self::BASE_KINDS), '?'));
        $history = $this->db->pdo()->prepare(
            'SELECT component.code, component.component_kind
               FROM payroll_inputs input
               JOIN payroll_component_definitions component
                 ON component.supplier_id = input.supplier_id
                AND component.id = input.component_id
              WHERE input.supplier_id = ? AND input.employment_id = ?
                AND input.status <> "cancelled"
                AND component.component_kind IN (' . $kinds . ')
             UNION
             SELECT component.code, component.component_kind
               FROM payroll_recurring_components recurring
               JOIN payroll_component_definitions component
                 ON component.supplier_id = recurring.supplier_id
                AND component.id = recurring.component_id
              WHERE recurring.supplier_id = ? AND recurring.employment_id = ?
                AND component.component_kind IN (' . $kinds . ')'
        );
        $history->execute([
            $supplierId,
            $employmentId,
            ...self::BASE_KINDS,
            $supplierId,
            $employmentId,
            ...self::BASE_KINDS,
        ]);
        $used = [];
        foreach ($history->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $used[(string) $row['code']] = (string) $row['component_kind'];
        }

        if (count($used) > 1) {
            return [
                'reason' => 'base_component_ambiguous',
                'message' => 'Vztah dosud používal víc základních složek (' . implode(', ', array_keys($used)) . '); kterou z nich převést, nelze určit.',
            ];
        }
        if (count($used) === 1) {
            $code = (string) array_key_first($used);
            if ($used[$code] === 'hourly_wage') {
                return [
                    'reason' => 'base_component_hourly',
                    'message' => "Vztah je odměňovaný hodinovou mzdou ({$code}); hrubou mzdu z listu nejde převést bez počtu hodin.",
                ];
            }
        } else {
            $catalog = $this->db->pdo()->prepare(
                'SELECT DISTINCT code
                   FROM payroll_component_definitions
                  WHERE supplier_id = ?
                    AND component_kind = "base_wage"
                    AND value_kind = "monetary"
                    AND is_active = 1
                    AND valid_from <= ?
                    AND (valid_to IS NULL OR valid_to >= ?)
                  ORDER BY code'
            );
            $catalog->execute([$supplierId, $periodStart, $periodStart]);
            $codes = array_map(
                static fn (mixed $code): string => (string) $code,
                $catalog->fetchAll(PDO::FETCH_COLUMN),
            );
            if ($codes === []) {
                return [
                    'reason' => 'base_component_missing',
                    'message' => 'V číselníku není pro předaný měsíc účinná základní mzdová složka.',
                ];
            }
            if (count($codes) > 1) {
                return [
                    'reason' => 'base_component_ambiguous',
                    'message' => 'V číselníku je víc základních mzdových složek (' . implode(', ', $codes) . '); kterou převést, nelze určit.',
                ];
            }
            $code = $codes[0];
        }

        $effective = $this->db->pdo()->prepare(
            'SELECT id
               FROM payroll_component_definitions
              WHERE supplier_id = ? AND code = ?
                AND is_active = 1
                AND valid_from <= ?
                AND (valid_to IS NULL OR valid_to >= ?)
              ORDER BY valid_from DESC, id DESC
              LIMIT 1'
        );
        $effective->execute([$supplierId, $code, $periodStart, $periodStart]);
        $id = $effective->fetchColumn();
        if ($id === false) {
            return [
                'reason' => 'base_component_not_effective',
                'message' => "Základní složka vztahu {$code} není pro předaný měsíc účinná.",
            ];
        }

        return ['component_id' => (int) $id];
    }
}
