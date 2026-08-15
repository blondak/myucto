<?php

declare(strict_types=1);

namespace MyInvoice\Repository\Payroll;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Payroll\Retention\PayrollRetentionCatalog;
use MyInvoice\Service\Payroll\Retention\PayrollRetentionRule;
use PDO;

/**
 * Tenantní odchylka od zákonné retenční lhůty — a brána, která hlídá, že jde
 * VŽDY SMĚREM NAHORU.
 *
 * Zákonné lhůty žijí v {@see PayrollRetentionCatalog}, tedy v kódu. Kdyby je šlo
 * z tabulky zkrátit, katalog by byl na nic: jeden UPDATE a maže se pět let po
 * nástupu. Proto tabulka nenese „lhůtu", ale dvě různé věci:
 *
 *   `extra_years`    přičítá se k lhůtě z katalogu (smlouva, vnitřní předpis).
 *   `override_years` dodává lhůtu tam, kde ji katalog NEMÁ — tedy jen pro
 *                    kategorie bez čísla (dnes spis k exekučním srážkám).
 *                    U kategorie, která číslo má, se odmítne, protože by ho
 *                    mohla zkrátit.
 *
 * Zkracovat nejde ani lhůtu, kterou katalog vede jako DODANOU POLITIKU
 * (`ORIGIN_HOUSE_POLICY`, dnes zdravotní pojištění). Není sice zákonná, ale
 * odpovídá zákonnému minimu, které na tytéž řádky dopadá odjinud — a hlavně
 * platí totéž riziko: číslo, které jde z tabulky snížit, maže dřív.
 *
 * Kategorie bez lhůty a bez `override_years` zůstává neurčená a osobu, které se
 * týká, modul k výmazu nenavrhne. To není chyba, ale výsledek: dokud nikdo
 * neřekne, jak dlouho se spis k exekuci drží, nesmí ho nic smazat.
 */
final class PayrollRetentionPolicyRepository
{
    public function __construct(private readonly Connection $db) {}

    /**
     * Účinná lhůta v letech pro každou kategorii katalogu.
     *
     * `null` = neurčená (zákon mlčí a tenant lhůtu nedodal) → nikdy neexpiruje.
     *
     * @return array<string,int|null>
     */
    public function effectiveYears(int $supplierId): array
    {
        $overrides = $this->byCategory($supplierId);
        $out = [];
        foreach (PayrollRetentionCatalog::rules() as $rule) {
            $out[$rule->category] = $this->applyOverride($rule, $overrides[$rule->category] ?? null);
        }

        return $out;
    }

    /**
     * @param array{extra_years:int,override_years:int|null}|null $override
     */
    private function applyOverride(PayrollRetentionRule $rule, ?array $override): ?int
    {
        if ($rule->retentionYears !== null) {
            // Lhůta z katalogu existuje — jde ji jen prodloužit. `override_years` se
            // tu ignoruje záměrně; upsert() ho pro takovou kategorii ani nepustí.
            return $rule->retentionYears + ($override['extra_years'] ?? 0);
        }

        if ($override === null || $override['override_years'] === null) {
            return null;
        }

        return $override['override_years'] + $override['extra_years'];
    }

    /**
     * @return array<string,array{extra_years:int,override_years:int|null}>
     */
    private function byCategory(int $supplierId): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT category, extra_years, override_years
               FROM payroll_retention_policies
              WHERE supplier_id = ?'
        );
        $stmt->execute([$supplierId]);

        $out = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $out[(string) $row['category']] = [
                'extra_years' => (int) $row['extra_years'],
                'override_years' => $row['override_years'] === null
                    ? null
                    : (int) $row['override_years'],
            ];
        }

        return $out;
    }

    /** @return list<array<string,mixed>> */
    public function all(int $supplierId): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT * FROM payroll_retention_policies
              WHERE supplier_id = ?
              ORDER BY category'
        );
        $stmt->execute([$supplierId]);

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Zápis odchylky. Nezkracuje — {@see PayrollRetentionPolicyException} letí dřív,
     * než se čehokoli dotkne DB.
     */
    public function upsert(
        int $supplierId,
        string $category,
        int $extraYears,
        ?int $overrideYears,
        string $reason,
        ?int $userId,
    ): void {
        if (!PayrollRetentionCatalog::has($category)) {
            throw new PayrollRetentionPolicyException(
                'payroll_retention_unknown_category',
                'Neznámá retenční kategorie mzdové agendy.',
            );
        }
        $rule = PayrollRetentionCatalog::rule($category);

        if ($extraYears < 0) {
            throw new PayrollRetentionPolicyException(
                'payroll_retention_shortening',
                'Retenční lhůtu jde jen prodloužit, ne zkrátit.',
            );
        }
        if ($overrideYears !== null && $rule->isDetermined()) {
            // Hláška musí říct PRAVDU o původu lhůty: u zdravotního pojištění za
            // číslem nestojí paragraf, ale rozhodnutí aplikace. Tvrdit tam „zákonná
            // lhůta" by uživatele odkazovalo na předpis, ve kterém žádná není.
            throw new PayrollRetentionPolicyException(
                'payroll_retention_statutory_override',
                'Kategorie „' . $rule->label . '" má '
                . ($rule->isStatutory() ? 'zákonnou lhůtu' : 'lhůtu dodanou aplikací')
                . ' (' . $rule->source() . '). Vlastní lhůtu jí nastavit nelze — '
                . 'jde ji jen prodloužit.',
            );
        }
        if ($overrideYears !== null && $overrideYears <= 0) {
            throw new PayrollRetentionPolicyException(
                'payroll_retention_nonpositive',
                'Dodaná retenční lhůta musí být aspoň jeden rok.',
            );
        }
        if ($extraYears === 0 && $overrideYears === null) {
            throw new PayrollRetentionPolicyException(
                'payroll_retention_empty',
                'Odchylka musí lhůtu buď prodloužit, nebo dodat — jinak nemá co uložit.',
            );
        }
        if (trim($reason) === '') {
            throw new PayrollRetentionPolicyException(
                'payroll_retention_reason_required',
                'Odchylka od zákonné lhůty musí být zdůvodněná.',
            );
        }

        $stmt = $this->db->pdo()->prepare(
            'INSERT INTO payroll_retention_policies
                (supplier_id, category, extra_years, override_years, reason, created_by, updated_by)
             VALUES (?, ?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE
                extra_years = VALUES(extra_years),
                override_years = VALUES(override_years),
                reason = VALUES(reason),
                updated_by = VALUES(updated_by),
                row_version = row_version + 1'
        );
        $stmt->execute([
            $supplierId,
            $category,
            $extraYears,
            $overrideYears,
            mb_substr(trim($reason), 0, 500),
            $userId,
            $userId,
        ]);
    }

    public function delete(int $supplierId, string $category): bool
    {
        $stmt = $this->db->pdo()->prepare(
            'DELETE FROM payroll_retention_policies WHERE supplier_id = ? AND category = ?'
        );
        $stmt->execute([$supplierId, $category]);

        return $stmt->rowCount() > 0;
    }
}
