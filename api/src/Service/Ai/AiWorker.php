<?php

declare(strict_types=1);

namespace MyInvoice\Service\Ai;

use MyInvoice\Infrastructure\Database\Connection;
use PDO;

final class AiWorker
{
    public function __construct(
        private readonly Connection $db,
        private readonly AiJobService $jobs,
        private readonly AiSuggestionService $suggestions,
        private readonly EmbeddingWriter $writer,
        private readonly AiDpaGate $dpa,
    ) {}

    /** @return array{processed:int,done:int,skipped:int,failed:int} */
    public function run(?int $onlySupplier, int $limit, bool $dryRun = false): array
    {
        $suppliers = $onlySupplier === null ? $this->jobs->enabledSuppliers() : [$onlySupplier];
        $stats = ['processed' => 0, 'done' => 0, 'skipped' => 0, 'failed' => 0];
        foreach ($suppliers as $supplierId) {
            if ($dryRun) {
                $stats['processed'] += count($this->jobs->peekBatch($supplierId, $limit));
                continue;
            }
            foreach ($this->jobs->claimBatch($supplierId, $limit) as $job) {
                $stats['processed']++;
                try {
                    $result = $this->process($supplierId, $job);
                    if (($result['ok'] ?? false) === true) {
                        $this->jobs->done((int) $job['id']);
                        $stats['done']++;
                    } else {
                        $error = (string) ($result['error'] ?? 'failed');
                        if ($error === 'daily_limit') {
                            $this->jobs->deferUntilTomorrow((int) $job['id']);
                            $stats['skipped']++;
                        } elseif ($error === 'stale_document') {
                            $this->jobs->skipped((int) $job['id'], $error);
                            $this->jobs->enqueue(
                                $supplierId,
                                (string) $job['job_type'],
                                (string) $job['entity_type'],
                                (int) $job['entity_id'],
                            );
                            $stats['skipped']++;
                        // `already_posted` / `rule_matched` = job zestárl ve frontě, mezitím
                        // se tx vyřešila bez AI. Není to chyba a nemá smysl ho vracet zpět
                        // (na rozdíl od `stale_document`) — jen se uzavře jako skipped.
                        } elseif (in_array($error, ['ai_disabled', 'dpa_not_confirmed', 'residency_conflict', 'source_muted', 'previously_rejected', 'not_found', 'already_posted', 'rule_matched'], true)) {
                            $this->jobs->skipped((int) $job['id'], $error);
                            $stats['skipped']++;
                        } else {
                            $this->jobs->failed((int) $job['id'], $error, $this->transient($error), (int) $job['attempts']);
                            $stats['failed']++;
                        }
                    }
                } catch (\Throwable $e) {
                    $this->jobs->failed((int) $job['id'], 'worker_error', false, (int) $job['attempts']);
                    $stats['failed']++;
                }
            }
        }
        return $stats;
    }

    /** @param array<string,mixed> $job @return array<string,mixed> */
    private function process(int $supplierId, array $job): array
    {
        return match ((string) $job['job_type']) {
            'classify_bank_tx' => $this->suggestions->suggestBankNow($supplierId, (int) $job['entity_id']),
            'classify_purchase' => $this->suggestions->suggestPurchaseNow($supplierId, (int) $job['entity_id']),
            'embed_write' => $this->writer->write($supplierId, (string) $job['entity_type'], (int) $job['entity_id']),
            'embed_backfill' => $this->backfill($supplierId, (string) $job['entity_type']),
            default => ['ok' => false, 'error' => 'unknown_job'],
        };
    }

    /** @return array<string,mixed> */
    private function backfill(int $supplierId, string $entityType): array
    {
        if ($entityType === 'bank_transaction') {
            $bank = $this->db->pdo()->prepare(
                "SELECT DISTINCT source_id FROM journal_entries WHERE supplier_id=? AND source_type='bank' AND reversed_by IS NULL ORDER BY source_id DESC LIMIT 100"
            );
            $bank->execute([$supplierId]);
            foreach ($bank->fetchAll(PDO::FETCH_COLUMN) as $id) {
                $this->jobs->enqueue($supplierId, 'embed_write', 'bank_transaction', (int) $id);
            }
        }
        if ($entityType === 'purchase_invoice') {
            $purchases = $this->db->pdo()->prepare(
                "SELECT DISTINCT source_id FROM journal_entries WHERE supplier_id=? AND source_type='purchase_invoice' AND reversed_by IS NULL ORDER BY source_id DESC LIMIT 100"
            );
            $purchases->execute([$supplierId]);
            foreach ($purchases->fetchAll(PDO::FETCH_COLUMN) as $id) {
                $this->jobs->enqueue($supplierId, 'embed_write', 'purchase_invoice', (int) $id);
            }
        }
        return ['ok' => true];
    }

    private function transient(string $error): bool
    {
        return str_contains($error, '429') || str_contains($error, 'timeout')
            || $error === 'provider_transport_error' || str_starts_with($error, 'provider_http_5');
    }
}
