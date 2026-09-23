<?php

declare(strict_types=1);

namespace MyInvoice\Repository;

use MyInvoice\Infrastructure\Database\Connection;
use PDO;

/**
 * Položky dávkového převodu (`migration_batch_items`): jedna nahraná záloha = jedna
 * firma, s výsledkem převodu. Tenantem je firma, ze které účetní dávku spustila
 * (`supplier_id`); každý dotaz ho nese.
 */
final class MigrationBatchRepository
{
    private const COLUMNS = 'id, supplier_id, job_id, source, position, token, file_name, agenda_ico, agenda_name,
        target_supplier_id, status, company_action, from_year, run_id, summary, error, created_at, started_at, finished_at';

    public function __construct(private readonly Connection $db) {}

    /**
     * @param list<array{token?:?string,file_name?:?string,agenda_ico?:?string,agenda_name?:?string}> $items
     */
    public function createItems(int $supplierId, int $jobId, string $source, array $items): void
    {
        $stmt = $this->db->pdo()->prepare(
            'INSERT INTO migration_batch_items (supplier_id, job_id, source, position, token, file_name, agenda_ico, agenda_name)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
        );
        foreach (array_values($items) as $position => $item) {
            $stmt->execute([
                $supplierId, $jobId, $source, $position + 1,
                $item['token'] ?? null,
                isset($item['file_name']) ? mb_substr((string) $item['file_name'], 0, 255) : null,
                isset($item['agenda_ico']) ? mb_substr((string) $item['agenda_ico'], 0, 20) : null,
                isset($item['agenda_name']) ? mb_substr((string) $item['agenda_name'], 0, 190) : null,
            ]);
        }
    }

    /** @return list<array<string,mixed>> položky dávky v pořadí */
    public function items(int $jobId, int $supplierId): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT ' . self::COLUMNS . ' FROM migration_batch_items WHERE job_id = ? AND supplier_id = ? ORDER BY position'
        );
        $stmt->execute([$jobId, $supplierId]);
        return array_map([self::class, 'hydrate'], $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    public function markRunning(int $id, int $supplierId): void
    {
        $this->db->pdo()->prepare(
            "UPDATE migration_batch_items SET status = 'running', started_at = NOW(), finished_at = NULL, error = NULL
              WHERE id = ? AND supplier_id = ?"
        )->execute([$id, $supplierId]);
    }

    /**
     * @param array{status:string,target_supplier_id?:?int,company_action?:?string,from_year?:?int,run_id?:?int,summary?:?array<string,mixed>,error?:?string} $result
     */
    public function finish(int $id, int $supplierId, array $result): void
    {
        $summary = $result['summary'] ?? null;
        $this->db->pdo()->prepare(
            'UPDATE migration_batch_items
                SET status = ?, target_supplier_id = ?, company_action = ?, from_year = ?, run_id = ?,
                    summary = ?, error = ?, finished_at = NOW()
              WHERE id = ? AND supplier_id = ?'
        )->execute([
            $result['status'],
            $result['target_supplier_id'] ?? null,
            $result['company_action'] ?? null,
            $result['from_year'] ?? null,
            $result['run_id'] ?? null,
            $summary !== null ? json_encode($summary, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE) : null,
            isset($result['error']) ? mb_substr((string) $result['error'], 0, 1000) : null,
            $id,
            $supplierId,
        ]);
    }

    /**
     * Položky, které po sobě nechal spadlý worker ve stavu „běží", se uzavřou jako chyba.
     */
    public function closeInterrupted(int $jobId, int $supplierId): int
    {
        $stmt = $this->db->pdo()->prepare(
            "UPDATE migration_batch_items SET status = 'failed', finished_at = NOW(),
                    error = COALESCE(error, 'Převod firmy byl přerušen (worker skončil dřív, než doběhl).')
              WHERE job_id = ? AND supplier_id = ? AND status = 'running'"
        );
        $stmt->execute([$jobId, $supplierId]);
        return $stmt->rowCount();
    }

    /** @return list<int> joby dávek firmy, nejnovější první */
    public function recentJobIds(int $supplierId, string $source, int $limit = 20): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT job_id FROM migration_batch_items WHERE supplier_id = ? AND source = ?
              GROUP BY job_id ORDER BY job_id DESC LIMIT ?'
        );
        $stmt->bindValue(1, $supplierId, PDO::PARAM_INT);
        $stmt->bindValue(2, $source);
        $stmt->bindValue(3, max(1, $limit), PDO::PARAM_INT);
        $stmt->execute();
        return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    }

    /**
     * @param array<string,mixed> $row
     * @return array<string,mixed>
     */
    private static function hydrate(array $row): array
    {
        foreach (['id', 'supplier_id', 'job_id', 'position'] as $k) {
            $row[$k] = (int) $row[$k];
        }
        foreach (['target_supplier_id', 'from_year', 'run_id'] as $k) {
            $row[$k] = $row[$k] !== null ? (int) $row[$k] : null;
        }
        $row['summary'] = $row['summary'] !== null ? json_decode((string) $row['summary'], true) : null;
        return $row;
    }
}
