<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Retention;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\Payroll\PayrollEmployeeDeletionRepository;
use MyInvoice\Repository\Payroll\PayrollPersonAnonymizationRepository;
use MyInvoice\Repository\Payroll\PayrollRetentionPolicyRepository;
use MyInvoice\Repository\RetentionHoldRepository;
use PDO;

/**
 * Retence mzdové agendy: do kdy se osoba musí držet a co se s ní smí stát potom.
 *
 * ── Posuzuje se OSOBA, ne řádek ───────────────────────────────────────────────
 * Retenční lhůty se liší podle druhu záznamu, ale výmaz se týká IDENTITY — a tu
 * nejde odosobnit jen zpola. Kdyby se lhůty počítaly po řádcích, mzdy z roku 2015
 * by „expirovaly" u člověka, který u firmy pořád pracuje, a stejně by se nic
 * smazat nesmělo, protože identitu drží novější záznamy. Přineslo by to jen
 * riziko, žádný užitek.
 *
 * Proto se bere POSLEDNÍ rok, ve kterém po osobě zůstala stopa, a na něj se
 * uplatní NEJDELŠÍ lhůta ze všech kategorií, které se na osobu vztahují. Obojí
 * je konzervativní směr: drží se déle, nikdy kratší dobu.
 *
 * ── Kategorie se vztahuje jen tehdy, když pro ni osoba má záznamy ────────────
 * Jinak by neurčená lhůta u evidence pracovní doby zablokovala úplně všechny
 * a funkce by nikdy nic nenavrhla. Osoba bez docházky se tedy posuzuje bez ní.
 *
 * ── Výmaz vs. anonymizace se NEROZHODUJE ZNOVU ────────────────────────────────
 * Otázka „smí ta osoba zmizet celá?" už v aplikaci zodpovězená je —
 * {@see PayrollEmployeeDeletionRepository::canDelete()} blokuje právě tehdy, když
 * po osobě zůstal pohyb (zaúčtovaná mzda, výplata, podání, exekuce). Tahle služba
 * se ho ZEPTÁ místo toho, aby si pravidlo opsala: povolí-li mazání, jde o úplný
 * výmaz; blokuje-li, jde o anonymizaci se zachováním účetního záznamu.
 *
 * ── Nic se nemaže samo ────────────────────────────────────────────────────────
 * Uplynulá lhůta je konec povinnosti uchovávat, ne příkaz ke smazání (táž úvaha
 * jako {@see \MyInvoice\Service\Accounting\RetentionPolicy}). Služba proto umí
 * sestavit NÁVRH a provést ho až po schválení člověkem. Žádná cesta odsud
 * nemaže na pozadí.
 */
final class PayrollRetentionService
{
    public function __construct(
        private readonly Connection $db,
        private readonly PayrollRetentionPolicyRepository $policies,
        private readonly RetentionHoldRepository $holds,
        private readonly PayrollEmployeeDeletionRepository $deletion,
        private readonly PayrollPersonAnonymizationRepository $anonymization,
    ) {}

    /**
     * Posouzení všech osob tenanta k datu `$asOf`.
     *
     * @return list<PayrollRetentionAssessment>
     */
    public function assess(int $supplierId, ?string $asOf = null): array
    {
        $asOf ??= date('Y-m-d');
        $employees = $this->employeeYears($supplierId);
        if ($employees === []) {
            return [];
        }

        $years = $this->policies->effectiveYears($supplierId);
        $presence = $this->categoryPresence($supplierId, array_keys($employees));
        $heldIds = $this->holds->heldPayrollEmployeeIds($supplierId);

        $out = [];
        foreach ($employees as $employeeId => $lastYear) {
            $out[] = $this->assessOne(
                $supplierId,
                $employeeId,
                $lastYear,
                $years,
                $presence[$employeeId] ?? [],
                $heldIds,
                $asOf,
            );
        }

        return $out;
    }

    /**
     * @param array<string,int|null> $years             účinná lhůta per kategorie
     * @param list<string>           $presentCategories kategorie, pro které osoba má záznamy
     * @param list<int>|null         $heldIds           null = firemní hold, drží se všechno
     */
    private function assessOne(
        int $supplierId,
        int $employeeId,
        int $lastRecordYear,
        array $years,
        array $presentCategories,
        ?array $heldIds,
        string $asOf,
    ): PayrollRetentionAssessment {
        $categories = [];
        $retainedUntil = null;
        $governing = null;
        $undetermined = false;

        foreach (PayrollRetentionCatalog::rules() as $rule) {
            if (!in_array($rule->category, $presentCategories, true)) {
                continue;
            }
            $effective = $years[$rule->category] ?? $rule->retentionYears;
            $until = $effective === null
                ? null
                : sprintf('%04d-12-31', $lastRecordYear + $effective);

            $categories[] = [
                'category' => $rule->category,
                'label' => $rule->label,
                'years' => $effective,
                'statutory_years' => $rule->retentionYears,
                'source' => $rule->source(),
                'source_status' => $rule->sourceStatus,
                'accounting_relevant' => $rule->accountingRelevant,
                'retained_until' => $until,
                'note' => $rule->note,
            ];

            if ($until === null) {
                $undetermined = true;
                continue;
            }
            if ($retainedUntil === null || $until > $retainedUntil) {
                $retainedUntil = $until;
                $governing = $rule;
            }
        }

        // Zadržení se hlásí i tehdy, když lhůta ještě běží — schvalující musí vidět
        // celý důvod, ne jen ten, který se náhodou vyhodnotil první.
        $holds = ($heldIds === null || in_array($employeeId, $heldIds, true))
            ? $this->holds->activeHoldsForPayrollEmployee($supplierId, $employeeId)
            : [];

        $expired = $retainedUntil !== null && $asOf > $retainedUntil && !$undetermined;

        // Levné důvody blokace se vyhodnotí PRVNÍ a teprve když žádný neplatí, sáhne
        // se na drahé sondy. `canDelete()` i `preview()` jsou dohromady přes deset
        // dotazů na osobu — u firmy se stovkami zaměstnanců by přehled dělal tisíce
        // dotazů kvůli údaji, který u zablokované osoby stejně není k čemu použít.
        $blockedBy = match (true) {
            $holds !== [] => PayrollRetentionAssessment::BLOCK_HOLD,
            $categories === [] => PayrollRetentionAssessment::BLOCK_NO_BASIS,
            $undetermined, $retainedUntil === null
                => PayrollRetentionAssessment::BLOCK_UNDETERMINED,
            !$expired => PayrollRetentionAssessment::BLOCK_WITHIN_RETENTION,
            default => null,
        };

        $action = null;
        $preview = ['identity' => [], 'residue' => []];
        if ($blockedBy === null) {
            // Otázku „smí ta osoba zmizet celá?" nezodpovídá tahle třída — ptá se
            // mazací rutiny, která totéž pravidlo drží pro ruční smazání osoby.
            $decision = $this->deletion->canDelete($supplierId, $employeeId);
            $action = $decision !== null && $decision->canDelete
                ? PayrollRetentionAssessment::ACTION_ERASE
                : PayrollRetentionAssessment::ACTION_ANONYMIZE;
            $preview = $this->anonymization->preview($supplierId, $employeeId)
                ?? ['identity' => [], 'residue' => []];

            if ($action === PayrollRetentionAssessment::ACTION_ANONYMIZE
                && $this->anonymization->isAnonymized($supplierId, $employeeId)
            ) {
                $blockedBy = PayrollRetentionAssessment::BLOCK_ALREADY_DONE;
            }
        }

        return new PayrollRetentionAssessment(
            $employeeId,
            $lastRecordYear,
            $categories,
            $governing?->category,
            $governing?->source(),
            $governing?->sourceStatus,
            $retainedUntil,
            $expired,
            $holds,
            $action,
            $preview['identity'],
            $preview['residue'],
            $blockedBy,
        );
    }

    /**
     * Poslední rok, ve kterém po osobě zůstala stopa.
     *
     * Neběžící vztah bez data konce a jakýkoli aktivní vztah se počítají jako
     * AKTUÁLNÍ rok: dokud člověk u firmy je, lhůta se nemá od čeho odpíchnout.
     * Bez toho by zaměstnanec bez zaúčtované mzdy vypadal jako dávno odešlý.
     *
     * @return array<int,int> employee_id => rok
     */
    private function employeeYears(int $supplierId): array
    {
        $currentYear = (int) date('Y');
        $stmt = $this->db->pdo()->prepare(
            'SELECT e.id AS employee_id,
                    GREATEST(
                        COALESCE((SELECT MAX(r.year) FROM payroll_monthly_records r
                                   WHERE r.supplier_id = e.supplier_id AND r.employee_id = e.id), 0),
                        COALESCE((SELECT MAX(CASE
                                     WHEN m.status IN (\'draft\', \'active\') OR m.end_date IS NULL
                                          THEN ?
                                     ELSE YEAR(m.end_date) END)
                                    FROM payroll_employments m
                                   WHERE m.supplier_id = e.supplier_id AND m.employee_id = e.id), 0),
                        YEAR(e.updated_at)
                    ) AS last_year
               FROM payroll_employees e
              WHERE e.supplier_id = ?'
        );
        $stmt->execute([$currentYear, $supplierId]);

        $out = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $out[(int) $row['employee_id']] = (int) $row['last_year'];
        }

        return $out;
    }

    /**
     * Které kategorie se na kterou osobu vůbec vztahují.
     *
     * Jeden dotaz na TABULKU, ne na osobu — sonda po zaměstnancích by z přehledu
     * udělala N×25 dotazů. Tabulky vázané na pracovní vztah se přemostí přes
     * `payroll_employments`.
     *
     * @param  list<int> $employeeIds
     * @return array<int,list<string>>
     */
    private function categoryPresence(int $supplierId, array $employeeIds): array
    {
        if ($employeeIds === []) {
            return [];
        }

        $out = [];
        foreach (PayrollRetentionCatalog::rules() as $rule) {
            foreach ($rule->employeeTables as $table) {
                foreach ($this->distinctEmployees($supplierId, $table, false) as $employeeId) {
                    $out[$employeeId][$rule->category] = true;
                }
            }
            foreach ($rule->employmentTables as $table) {
                foreach ($this->distinctEmployees($supplierId, $table, true) as $employeeId) {
                    $out[$employeeId][$rule->category] = true;
                }
            }
        }

        return array_map(
            static fn (array $categories): array => array_keys($categories),
            $out,
        );
    }

    /** @return list<int> */
    private function distinctEmployees(int $supplierId, string $table, bool $viaEmployment): array
    {
        $sql = $viaEmployment
            ? "SELECT DISTINCT m.employee_id
                 FROM {$table} t
                 JOIN payroll_employments m
                   ON m.supplier_id = t.supplier_id AND m.id = t.employment_id
                WHERE t.supplier_id = ?"
            : "SELECT DISTINCT t.employee_id FROM {$table} t WHERE t.supplier_id = ?";

        $stmt = $this->db->pdo()->prepare($sql);
        $stmt->execute([$supplierId]);

        return array_map(intval(...), $stmt->fetchAll(PDO::FETCH_COLUMN));
    }
}
