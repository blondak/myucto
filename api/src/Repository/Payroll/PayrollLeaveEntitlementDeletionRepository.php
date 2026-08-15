<?php

declare(strict_types=1);

namespace MyInvoice\Repository\Payroll;

use PDO;

/**
 * Smazání špatně spočítaného nároku na dovolenou.
 *
 * ── Co všechno jeden nárok vytvoří ────────────────────────────────────────────
 * `PayrollLeaveRepository::recordEntitlement()` zapíše tři věci: snapshot výpočtu,
 * položku typu `entitlement` v knize dovolené a — pokud pro daný rok nárok už
 * jednou počítal — reverzi, která odečte nárok předchozí revize. Smazat samotný
 * snapshot by proto nestačilo: v saldu by zůstaly přičtené minuty i odečet
 * předchozí revize a zůstatek by nesouhlasil. Odstraňují se proto všechny tři
 * zápisy najednou, čímž se saldo vrátí přesně do stavu před výpočtem.
 *
 * ── Proč tvrdé smazání ────────────────────────────────────────────────────────
 * Snapshot nároku žádný stav nemá — je to protokol výpočtu. „Zrušený protokol"
 * by v knize dovolené nic nespravil, protože ta počítá se součtem položek.
 *
 * ── Co smí blokovat ───────────────────────────────────────────────────────────
 * • Novější revize nároku za tentýž rok: smazat jde vždy jen ta poslední, jinak
 *   by řetězec reverzí přestal dávat smysl.
 * • Schválený výpočet: za rok, na který je schválená revize mzdového běhu, se
 *   nárok nepřepisuje — ten běh z něj vycházel.
 */
final class PayrollLeaveEntitlementDeletionRepository extends PayrollRowDeletionRepository
{
    private const PREVIOUS_ENTRY_SQL = "SELECT previous.leave_ledger_entry_id
                       FROM payroll_leave_entitlement_snapshots previous
                      WHERE previous.supplier_id = snapshot.supplier_id
                        AND previous.employment_id = snapshot.employment_id
                        AND previous.leave_year = snapshot.leave_year
                        AND previous.revision_no < snapshot.revision_no
                      ORDER BY previous.revision_no DESC
                      LIMIT 1";

    protected static function blockers(): array
    {
        return [
            'superseded' => [
                'code' => 'payroll_leave_entitlement_superseded',
                'message' => 'Za tento rok existuje novější revize nároku na dovolenou. '
                    . 'Smazat jde vždy jen poslední revize — nejdřív smažte tu novější.',
                'sql' => 'EXISTS (
                    SELECT 1
                      FROM payroll_leave_entitlement_snapshots newer
                     WHERE newer.supplier_id = snapshot.supplier_id
                       AND newer.employment_id = snapshot.employment_id
                       AND newer.leave_year = snapshot.leave_year
                       AND newer.revision_no > snapshot.revision_no
                )',
            ],
            'approved_run' => [
                'code' => 'payroll_leave_entitlement_in_approved_run',
                'message' => 'Za tento rok už je schválená revize mzdového běhu, která '
                    . 'z nároku na dovolenou vycházela. Nárok smazat nelze — přepočítejte '
                    . 'ho novou revizí.',
                'sql' => self::approvedRunForLeaveYearSql('snapshot'),
            ],
        ];
    }

    protected static function cascade(): array
    {
        return [
            'ledger' => 'SELECT COUNT(*)
                           FROM payroll_leave_ledger entry
                          WHERE entry.supplier_id = snapshot.supplier_id
                            AND (
                                  entry.id = snapshot.leave_ledger_entry_id
                                  OR (
                                       entry.entry_type = \'reversal\'
                                       AND entry.reversal_of_id = (' . self::PREVIOUS_ENTRY_SQL . ')
                                     )
                                )',
        ];
    }

    protected static function table(): string
    {
        return 'payroll_leave_entitlement_snapshots';
    }

    protected static function rowAlias(): string
    {
        return 'snapshot';
    }

    protected static function notFoundMessage(): string
    {
        return 'Nárok na dovolenou nebyl nalezen.';
    }

    protected static function auditAction(): string
    {
        return 'payroll.leave_entitlement.deleted';
    }

    protected static function auditEntity(): string
    {
        return 'payroll_leave_entitlement_snapshot';
    }

    protected static function lockedColumns(): array
    {
        return [
            'id',
            'employment_id',
            'leave_year',
            'revision_no',
            'entitlement_minutes',
            'leave_ledger_entry_id',
            'row_version',
        ];
    }

    protected static function auditPayload(array $row): array
    {
        return [
            'employment_id' => (int) ($row['employment_id'] ?? 0),
            'leave_year' => (int) ($row['leave_year'] ?? 0),
            'revision_no' => (int) ($row['revision_no'] ?? 0),
            'entitlement_minutes' => (int) ($row['entitlement_minutes'] ?? 0),
            'leave_ledger_entry_id' => $row['leave_ledger_entry_id'] === null
                ? null
                : (int) $row['leave_ledger_entry_id'],
            'row_version' => (int) ($row['row_version'] ?? 0),
        ];
    }

    /**
     * Zápisy v knize dovolené musí zmizet AŽ PO snapshotu — snapshot na svůj
     * `entitlement` zápis drží cizí klíč s RESTRICT.
     *
     * @param array<string,string|int|null> $row
     */
    protected function afterGuardedDelete(int $supplierId, int $id, array $row): void
    {
        $pdo = $this->db->pdo();
        $employmentId = (int) ($row['employment_id'] ?? 0);
        $leaveYear = (int) ($row['leave_year'] ?? 0);

        // Reverze, kterou tahle revize při vzniku vystavila proti předchozí revizi.
        // Po smazání snapshotu je „předchozí" revize zase tou poslední.
        $previous = $pdo->prepare(
            'SELECT leave_ledger_entry_id
               FROM payroll_leave_entitlement_snapshots
              WHERE supplier_id = ? AND employment_id = ? AND leave_year = ?
              ORDER BY revision_no DESC
              LIMIT 1'
        );
        $previous->execute([$supplierId, $employmentId, $leaveYear]);
        $previousEntryId = $previous->fetch(PDO::FETCH_NUM);
        if (is_array($previousEntryId) && ($previousEntryId[0] ?? null) !== null) {
            $pdo->prepare(
                "DELETE FROM payroll_leave_ledger
                  WHERE supplier_id = ? AND reversal_of_id = ? AND entry_type = 'reversal'"
            )->execute([$supplierId, (int) $previousEntryId[0]]);
        }

        $ownEntryId = $row['leave_ledger_entry_id'] ?? null;
        if ($ownEntryId !== null) {
            $pdo->prepare(
                'DELETE FROM payroll_leave_ledger WHERE supplier_id = ? AND id = ?'
            )->execute([$supplierId, (int) $ownEntryId]);
        }
    }
}
