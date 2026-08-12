<?php

declare(strict_types=1);

namespace MyInvoice\Repository;

use MyInvoice\Infrastructure\Database\Connection;
use PDO;

/**
 * Evidence běhů zúčtování DPH (migrace 1332) — ČÍM byl interní doklad zúčtování
 * naposledy vyvolán a KTERÉMU PODÁNÍ odpovídá.
 *
 * Jeden řádek na (dodavatel, zdaňovací období); klíčem je `source_id`, tedy totéž
 * deterministické ID období, které nese {@see \MyInvoice\Service\Accounting\Vat\VatClearingService}
 * v `journal_entries.source_id`. Vazba na doklad je proto 1:1 i bez cizího klíče.
 *
 * Tabulka je čistě AUDITNÍ/NAVIGAČNÍ — nedrží příznak zastaralosti a nesmí ho držet.
 * Aktuálnost zúčtování se vyhodnocuje živě přepočtem obratu období
 * ({@see \MyInvoice\Service\Accounting\Vat\VatClearingService::status()}); uložený
 * příznak by byl druhý zdroj pravdy, který se rozejde přesně tehdy, kdy nesmí.
 */
final class VatClearingRunRepository
{
    public function __construct(private readonly Connection $db) {}

    /**
     * Zapíše (nebo přepíše) záznam běhu pro období. Idempotentní přes
     * `uq_vcr_supplier_source` — opakovaný přepočet téhož období řádek aktualizuje.
     *
     * @param array{
     *   supplier_id:int, source_id:int, period_year:int, period_first_month:int,
     *   period_type:string, period_start:string, period_end:string, entry_id:?int,
     *   input_vat:float, output_vat:float, settlement:float, status:string,
     *   trigger_source:string, submission_id?:?int, submission_form?:?string,
     *   submission_variant?:?string, submitted_at?:?string, computed_by?:?int
     * } $run
     */
    public function record(array $run): void
    {
        $this->db->pdo()->prepare(
            'INSERT INTO vat_clearing_runs
                (supplier_id, source_id, period_year, period_first_month, period_type,
                 period_start, period_end, entry_id, input_vat, output_vat, settlement,
                 status, trigger_source, submission_id, submission_form, submission_variant,
                 submitted_at, computed_by)
             VALUES (:supplier_id, :source_id, :period_year, :period_first_month, :period_type,
                 :period_start, :period_end, :entry_id, :input_vat, :output_vat, :settlement,
                 :status, :trigger_source, :submission_id, :submission_form, :submission_variant,
                 :submitted_at, :computed_by)
             ON DUPLICATE KEY UPDATE
                 period_year        = VALUES(period_year),
                 period_first_month = VALUES(period_first_month),
                 period_type        = VALUES(period_type),
                 period_start       = VALUES(period_start),
                 period_end         = VALUES(period_end),
                 entry_id           = VALUES(entry_id),
                 input_vat          = VALUES(input_vat),
                 output_vat         = VALUES(output_vat),
                 settlement         = VALUES(settlement),
                 status             = VALUES(status),
                 trigger_source     = VALUES(trigger_source),
                 submission_id      = VALUES(submission_id),
                 submission_form    = VALUES(submission_form),
                 submission_variant = VALUES(submission_variant),
                 submitted_at       = VALUES(submitted_at),
                 computed_at        = current_timestamp(),
                 computed_by        = VALUES(computed_by)'
        )->execute([
            'supplier_id'        => $run['supplier_id'],
            'source_id'          => $run['source_id'],
            'period_year'        => $run['period_year'],
            'period_first_month' => $run['period_first_month'],
            'period_type'        => $run['period_type'],
            'period_start'       => $run['period_start'],
            'period_end'         => $run['period_end'],
            'entry_id'           => $run['entry_id'],
            'input_vat'          => $run['input_vat'],
            'output_vat'         => $run['output_vat'],
            'settlement'         => $run['settlement'],
            'status'             => $run['status'],
            'trigger_source'     => $run['trigger_source'],
            'submission_id'      => $run['submission_id'] ?? null,
            'submission_form'    => $run['submission_form'] ?? null,
            'submission_variant' => $run['submission_variant'] ?? null,
            'submitted_at'       => $run['submitted_at'] ?? null,
            'computed_by'        => $run['computed_by'] ?? null,
        ]);
    }

    /** @return array<string,mixed>|null */
    public function find(int $supplierId, int $sourceId): ?array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT * FROM vat_clearing_runs WHERE supplier_id = ? AND source_id = ?'
        );
        $stmt->execute([$supplierId, $sourceId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row !== false ? self::normalize($row) : null;
    }

    /**
     * Záznamy běhů pro období překrývající zadaný rozsah — podklad pro kontrolu
     * aktuálnosti v uzávěrce (indexováno klíčem `source_id`).
     *
     * @return array<int, array<string,mixed>>
     */
    public function betweenIndexed(int $supplierId, string $from, string $to): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT * FROM vat_clearing_runs
              WHERE supplier_id = ? AND period_end >= ? AND period_start <= ?
              ORDER BY period_start'
        );
        $stmt->execute([$supplierId, $from, $to]);
        $out = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
            $row = self::normalize($row);
            $out[(int) $row['source_id']] = $row;
        }

        return $out;
    }

    /** Zahodí záznam běhu — používá se, když zúčtování vyjde nulové a doklad se maže. */
    public function forget(int $supplierId, int $sourceId): void
    {
        $this->db->pdo()
            ->prepare('DELETE FROM vat_clearing_runs WHERE supplier_id = ? AND source_id = ?')
            ->execute([$supplierId, $sourceId]);
    }

    /**
     * @param array<string,mixed> $row
     * @return array<string,mixed>
     */
    private static function normalize(array $row): array
    {
        foreach (['id', 'supplier_id', 'source_id', 'period_year', 'period_first_month'] as $k) {
            $row[$k] = (int) $row[$k];
        }
        foreach (['entry_id', 'submission_id', 'computed_by'] as $k) {
            $row[$k] = $row[$k] !== null ? (int) $row[$k] : null;
        }
        foreach (['input_vat', 'output_vat', 'settlement'] as $k) {
            $row[$k] = (float) $row[$k];
        }

        return $row;
    }
}
