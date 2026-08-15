<?php

declare(strict_types=1);

namespace MyInvoice\Repository\Payroll;

/**
 * Smazání špatně zadaného snapshotu průměrného výdělku.
 *
 * ── Proč tvrdé smazání, ne zrušení stavem ─────────────────────────────────────
 * Stavový automat snapshotu (`draft` → `manual_review` → `approved` → `superseded`)
 * popisuje ŽIVOTNÍ CYKLUS VÝPOČTU, ne jeho zrušení — stav „zrušeno" v něm není
 * a přidat ho by znamenalo, že každý dotaz na „platný průměr" musí nově vylučovat
 * další hodnotu. Snapshot čekající na kontrolu přitom nikam nedosáhl: nic se podle
 * něj nepočítalo. Proto se maže tvrdě a stopa zůstává v `activity_log`.
 *
 * ── Co smí blokovat ───────────────────────────────────────────────────────────
 * Schválený výpočet (`approved`) a výpočet, který schválený byl a novější revize
 * ho odsunula (`superseded`) — obojí je hotové rozhodnutí. A dále peníze v podobě
 * navázané náhrady: absence i nemocenská si průměr drží cizím klíčem s RESTRICT,
 * protože z něj počítají náhradu mzdy.
 */
final class PayrollAverageEarningDeletionRepository extends PayrollRowDeletionRepository
{
    protected static function blockers(): array
    {
        return [
            'approved' => [
                'code' => 'payroll_average_approved',
                'message' => 'Průměrný výdělek je schválený, nebo už ho nahradila novější '
                    . 'revize. Schválený výpočet smazat nelze — pokud byl zadaný špatně, '
                    . 'založte novou revizi a nechte ji schválit.',
                'sql' => "snapshot.status IN ('approved', 'superseded')",
            ],
            'absence' => [
                'code' => 'payroll_average_used_by_absence',
                'message' => 'Podle tohoto průměru se počítá náhrada u zaevidované absence. '
                    . 'Nejdřív zrušte tu absenci, teprve pak půjde průměr smazat.',
                'sql' => 'EXISTS (
                    SELECT 1
                      FROM payroll_absences absence
                     WHERE absence.supplier_id = snapshot.supplier_id
                       AND absence.average_snapshot_id = snapshot.id
                )',
            ],
            'sickness' => [
                'code' => 'payroll_average_used_by_sickness',
                'message' => 'Podle tohoto průměru se počítá náhrada za dočasnou pracovní '
                    . 'neschopnost. Nejdřív zrušte tu nemocenskou, teprve pak půjde průměr '
                    . 'smazat.',
                'sql' => 'EXISTS (
                    SELECT 1
                      FROM payroll_sickness_events sickness
                     WHERE sickness.supplier_id = snapshot.supplier_id
                       AND sickness.average_snapshot_id = snapshot.id
                )',
            ],
        ];
    }

    protected static function cascade(): array
    {
        return [];
    }

    protected static function table(): string
    {
        return 'payroll_average_earning_snapshots';
    }

    protected static function rowAlias(): string
    {
        return 'snapshot';
    }

    protected static function notFoundMessage(): string
    {
        return 'Snapshot průměrného výdělku nebyl nalezen.';
    }

    protected static function auditAction(): string
    {
        return 'payroll.average_earning.deleted';
    }

    protected static function auditEntity(): string
    {
        return 'payroll_average_earning_snapshot';
    }

    protected static function lockedColumns(): array
    {
        return [
            'id',
            'employment_id',
            'applicable_year',
            'applicable_quarter',
            'revision_no',
            'source_kind',
            'status',
            'average_hourly_minor',
            'row_version',
        ];
    }

    protected static function auditPayload(array $row): array
    {
        return [
            'employment_id' => (int) ($row['employment_id'] ?? 0),
            'applicable_year' => (int) ($row['applicable_year'] ?? 0),
            'applicable_quarter' => (int) ($row['applicable_quarter'] ?? 0),
            'revision_no' => (int) ($row['revision_no'] ?? 0),
            'source_kind' => (string) ($row['source_kind'] ?? ''),
            'status' => (string) ($row['status'] ?? ''),
            'average_hourly_minor' => (int) ($row['average_hourly_minor'] ?? 0),
            'row_version' => (int) ($row['row_version'] ?? 0),
        ];
    }
}
