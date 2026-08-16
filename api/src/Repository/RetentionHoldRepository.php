<?php

declare(strict_types=1);

namespace MyInvoice\Repository;

use MyInvoice\Infrastructure\Database\Connection;

/**
 * § 32 ZoÚ — zadržení záznamů po dobu daňového řízení („legal hold").
 *
 * Lhůty podle § 31 jdou spočítat, tahle skutečnost ne: daňová kontrola ani soudní spor
 * se v účetních datech nikde neobjeví. Bez ručně zadaného holdu by brána proti smazání
 * uvolnila právě ty dokumenty, které správce daně prověřuje.
 *
 * Hold bez `period_year` platí na celé účetnictví firmy — rozsáhlá kontrola se nemusí
 * vázat na jediné období.
 *
 * ── Rozsah: firma vs. osoba (migrace 1396) ──────────────────────────────────────────
 * Mzdová agenda potřebuje zadržet výmaz KONKRÉTNÍHO ČLOVĚKA (exekuce, spor), ne celé
 * firmy. Kdyby na to vznikla druhá tabulka, rozpadl by se jeden právní institut na dva
 * a kontrola zadaná na účetní straně by mzdový výmaz nezastavila. Proto `subject_kind`.
 *
 * Účetní dotazy se tím NESMÍ posunout: hold na jednoho zaměstnance nemá co blokovat
 * mazání faktur. Firemní cesta se proto ptá výslovně na `subject_kind = 'company'`.
 * Firemní hold naopak platí i na mzdy — to je celý smysl sdílené tabulky.
 */
final class RetentionHoldRepository
{
    public const SUBJECT_COMPANY = 'company';
    public const SUBJECT_PAYROLL_EMPLOYEE = 'payroll_employee';

    public function __construct(private readonly Connection $db) {}

    /** Trvá na daný rok (nebo na celé účetnictví) aktivní firemní hold? */
    public function hasActiveHold(int $supplierId, ?int $periodYear = null): bool
    {
        $sql = "SELECT 1 FROM retention_holds
                 WHERE supplier_id = ? AND released_on IS NULL
                   AND subject_kind = '" . self::SUBJECT_COMPANY . "'";
        $params = [$supplierId];
        if ($periodYear !== null) {
            $sql .= ' AND (period_year IS NULL OR period_year = ?)';
            $params[] = $periodYear;
        }
        $sql .= ' LIMIT 1';

        $stmt = $this->db->pdo()->prepare($sql);
        $stmt->execute($params);

        return $stmt->fetchColumn() !== false;
    }

    /**
     * Aktivní holdy dotýkající se roku — do chybové hlášky, ať uživatel ví, co blokuje.
     *
     * @return list<array<string,mixed>>
     */
    public function activeHolds(int $supplierId, ?int $periodYear = null): array
    {
        $sql = "SELECT * FROM retention_holds
                 WHERE supplier_id = ? AND released_on IS NULL
                   AND subject_kind = '" . self::SUBJECT_COMPANY . "'";
        $params = [$supplierId];
        if ($periodYear !== null) {
            $sql .= ' AND (period_year IS NULL OR period_year = ?)';
            $params[] = $periodYear;
        }
        $sql .= ' ORDER BY placed_on, id';

        $stmt = $this->db->pdo()->prepare($sql);
        $stmt->execute($params);

        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }

    /**
     * Holdy zadržující výmaz KONKRÉTNÍ mzdové osoby — firemní i osobní dohromady.
     *
     * Firemní hold se musí započítat: daňová kontrola vedená na celou firmu se
     * mzdových listů týká úplně stejně jako faktur. Kdyby se ptalo jen na osobní
     * holdy, běžící kontrola by výmaz nezastavila.
     *
     * @return list<array<string,mixed>>
     */
    public function activeHoldsForPayrollEmployee(int $supplierId, int $employeeId): array
    {
        $stmt = $this->db->pdo()->prepare(
            "SELECT * FROM retention_holds
              WHERE supplier_id = ?
                AND released_on IS NULL
                AND (subject_kind = '" . self::SUBJECT_COMPANY . "'
                     OR (subject_kind = '" . self::SUBJECT_PAYROLL_EMPLOYEE . "' AND subject_id = ?))
              ORDER BY placed_on, id"
        );
        $stmt->execute([$supplierId, $employeeId]);

        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }

    /**
     * Osoby tenanta, na které padá aktivní hold — jedním dotazem, ať návrh výmazu
     * nedělá dotaz na osobu (N+1 nad seznamem zaměstnanců).
     *
     * Vrací `null`, když je aktivní FIREMNÍ hold: ten padá na všechny, včetně osob,
     * které v tabulce holdů nemají vlastní řádek. Kdyby se vracel jen výčet
     * `subject_id`, firemní hold by se ztratil a výmaz by proběhl uprostřed kontroly.
     *
     * @return list<int>|null null = firemní hold, drží se úplně všechno
     */
    public function heldPayrollEmployeeIds(int $supplierId): ?array
    {
        if ($this->hasActiveHold($supplierId)) {
            return null;
        }

        $stmt = $this->db->pdo()->prepare(
            "SELECT DISTINCT subject_id FROM retention_holds
              WHERE supplier_id = ?
                AND released_on IS NULL
                AND subject_kind = '" . self::SUBJECT_PAYROLL_EMPLOYEE . "'
                AND subject_id IS NOT NULL"
        );
        $stmt->execute([$supplierId]);

        return array_map(intval(...), $stmt->fetchAll(\PDO::FETCH_COLUMN));
    }

    /**
     * Firemní holdy pro účetní přehled. Osobní (mzdové) sem NEPATŘÍ — účetní stránka
     * o zaměstnancích nic neví a `subject_id` by v ní bylo jen neidentifikovatelné číslo.
     *
     * @return list<array<string,mixed>>
     */
    public function all(int $supplierId, bool $includeReleased = false): array
    {
        $sql = "SELECT * FROM retention_holds WHERE supplier_id = ?
                  AND subject_kind = '" . self::SUBJECT_COMPANY . "'";
        if (!$includeReleased) {
            $sql .= ' AND released_on IS NULL';
        }
        $sql .= ' ORDER BY released_on IS NOT NULL, placed_on DESC, id DESC';

        $stmt = $this->db->pdo()->prepare($sql);
        $stmt->execute([$supplierId]);

        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }

    public function place(
        int $supplierId,
        ?int $periodYear,
        string $reason,
        string $description,
        string $placedOn,
        ?int $userId,
        string $subjectKind = self::SUBJECT_COMPANY,
        ?int $subjectId = null,
    ): int {
        if ($subjectKind === self::SUBJECT_COMPANY) {
            $subjectId = null;
        } elseif ($subjectKind === self::SUBJECT_PAYROLL_EMPLOYEE) {
            if ($subjectId === null || $subjectId <= 0) {
                throw new \InvalidArgumentException(
                    'Zadržení vázané na osobu musí jmenovat, o kterou osobu jde.',
                );
            }
        } else {
            throw new \InvalidArgumentException('Neznámý rozsah zadržení.');
        }

        $stmt = $this->db->pdo()->prepare(
            'INSERT INTO retention_holds
                (supplier_id, subject_kind, subject_id, period_year, reason, description,
                 placed_on, created_by)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $supplierId,
            $subjectKind,
            $subjectId,
            $periodYear,
            $reason,
            $description,
            $placedOn,
            $userId,
        ]);

        return (int) $this->db->pdo()->lastInsertId();
    }

    /**
     * Mzdové (osobní) holdy pro přehled v mzdovém modulu — protějšek {@see all()}.
     *
     * @return list<array<string,mixed>>
     */
    public function payrollHolds(int $supplierId, bool $includeReleased = false): array
    {
        $sql = "SELECT * FROM retention_holds WHERE supplier_id = ?
                  AND subject_kind = '" . self::SUBJECT_PAYROLL_EMPLOYEE . "'";
        if (!$includeReleased) {
            $sql .= ' AND released_on IS NULL';
        }
        $sql .= ' ORDER BY released_on IS NOT NULL, placed_on DESC, id DESC';

        $stmt = $this->db->pdo()->prepare($sql);
        $stmt->execute([$supplierId]);

        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }

    /**
     * Uvolnění holdu je vědomý úkon — záznam zůstává, jen dostane `released_on`.
     *
     * `$subjectKind` je povinná součást podmínky, ne ozdoba: bez ní by účetní
     * endpoint s oprávněním `accounting` uvolnil i zadržení vedené proti osobě
     * (exekuce, insolvence), o kterém účetní stránka vůbec neví.
     */
    public function release(
        int $supplierId,
        int $id,
        string $releasedOn,
        ?int $userId,
        string $subjectKind = self::SUBJECT_COMPANY,
    ): bool {
        $stmt = $this->db->pdo()->prepare(
            'UPDATE retention_holds
                SET released_on = ?, released_by = ?
              WHERE id = ? AND supplier_id = ? AND subject_kind = ? AND released_on IS NULL'
        );
        $stmt->execute([$releasedOn, $userId, $id, $supplierId, $subjectKind]);

        return $stmt->rowCount() > 0;
    }
}
