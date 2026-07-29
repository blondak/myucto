<?php

declare(strict_types=1);

namespace MyInvoice\Service\Accounting\Activation;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\AccountingPeriodRepository;
use MyInvoice\Service\Accounting\Cash\CashDocumentService;
use MyInvoice\Service\Accounting\Cash\CashException;
use MyInvoice\Service\Accounting\PostingException;
use MyInvoice\Service\Accounting\PostingService;
use PDO;

final class CashBackfill
{
    public function __construct(
        private readonly Connection $db,
        private readonly CashDocumentService $cash,
        private readonly AccountingPeriodRepository $periods,
    ) {}

    public function run(
        int $supplierId,
        ?string $from,
        ?int $year,
        bool $dryRun,
        ?callable $onLog = null,
        ?callable $isCancelled = null,
    ): array {
        $emit = static function (string $line) use ($onLog): void {
            if ($onLog !== null) $onLog($line);
        };
        $where = '';
        $bind = ['sid' => $supplierId];
        if ($year !== null) {
            $where .= ' AND YEAR(issue_date) = :yr';
            $bind['yr'] = $year;
        }
        if ($from !== null) {
            $where .= ' AND issue_date >= :from_date';
            $bind['from_date'] = $from;
        }
        $stmt = $this->db->pdo()->prepare(
            "SELECT id, doc_number, doc_type, purpose, issue_date, tax_date, total_amount, description
               FROM cash_documents
              WHERE supplier_id = :sid AND status = 'posted' AND journal_entry_id IS NULL{$where}
           ORDER BY issue_date, id"
        );
        $stmt->execute($bind);
        $docs = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $emit('Ke zpracování: ' . count($docs) . " pokladních dokladů (posted, bez journal_entry_id).\n\n");

        $stats = ['posted' => 0, 'skipped' => 0, 'failed' => 0];
        $skipReasons = [];
        $sumAmount = 0.0;
        $ensuredYears = [];
        $processed = 0;
        $cancelled = false;
        foreach ($docs as $doc) {
            if ($isCancelled !== null && $isCancelled()) {
                $cancelled = true;
                break;
            }
            $processed++;
            $id = (int) $doc['id'];
            $entryDate = (string) $doc['issue_date'];
            $label = ($doc['doc_type'] === 'in' ? 'PPD' : 'VPD') . " #{$id} ({$doc['doc_number']}, {$entryDate}, {$doc['purpose']})";
            $amount = (float) $doc['total_amount'];
            try {
                if ($dryRun) {
                    $lines = $this->cash->previewBackfillLines($supplierId, $id);
                    PostingService::assertBalanced($lines);
                    $stats['posted']++;
                    $sumAmount += $amount;
                    $emit(sprintf("  [OK]   %-50s řádků=%d  %s Kč\n", $label, count($lines), number_format($amount, 2, ',', ' ')));
                    continue;
                }
                $yearKey = (int) substr($entryDate, 0, 4);
                if (!isset($ensuredYears[$yearKey])) {
                    $this->periods->ensureOpenPeriodFor($supplierId, $entryDate);
                    $ensuredYears[$yearKey] = true;
                }
                $result = $this->cash->backfillJournal($supplierId, $id);
                $sumAmount += $amount;
                if ($result['already']) {
                    $stats['skipped']++;
                    $skipReasons['already_posted'] = ($skipReasons['already_posted'] ?? 0) + 1;
                    $emit(sprintf("  [SKIP] %-50s už má zápis #%d\n", $label, $result['journal_entry_id']));
                } else {
                    $stats['posted']++;
                    $emit(sprintf("  [NEW]  %-50s → zápis #%d\n", $label, $result['journal_entry_id']));
                }
            } catch (PostingException $e) {
                $reason = $e->errorCode === 'period_not_open' ? 'period_closed' : $e->errorCode;
                if (in_array($e->errorCode, ['date_locked', 'period_not_open'], true)) {
                    $stats['skipped']++;
                    $skipReasons[$reason] = ($skipReasons[$reason] ?? 0) + 1;
                    $emit(sprintf("  [SKIP] %-50s %s\n", $label, $e->getMessage()));
                } else {
                    $stats['failed']++;
                    $emit(sprintf("  [FAIL] %-50s %s\n", $label, $e->getMessage()));
                }
            } catch (CashException $e) {
                $stats['failed']++;
                $emit(sprintf("  [FAIL] %-50s %s\n", $label, $e->getMessage()));
            } catch (\Throwable $e) {
                $stats['failed']++;
                $emit(sprintf("  [FAIL] %-50s %s\n", $label, $e->getMessage()));
            }
        }

        $emit("\n═══ KONTROLNÍ REPORT ═══════════════════════════════════════════\n");
        $verb = $dryRun ? 'postovatelné' : 'nové';
        $emit(sprintf("  Pokladní doklady  %s=%d  skipped=%d  failed=%d  Σ=%s Kč\n", $verb, $stats['posted'], $stats['skipped'], $stats['failed'], number_format($sumAmount, 2, ',', ' ')));
        $emit("═══════════════════════════════════════════════════════════════\n");
        if ($dryRun) $emit("\n(dry-run — nic nebylo zapsáno; pro ostrý běh spusť bez --dry-run)\n");

        return $stats + [
            'sum_amount' => round($sumAmount, 2),
            'skip_reasons' => $skipReasons,
            'processed' => $processed,
            'cancelled' => $cancelled,
        ];
    }
}
