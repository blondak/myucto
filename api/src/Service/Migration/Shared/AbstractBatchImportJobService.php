<?php

declare(strict_types=1);

namespace MyInvoice\Service\Migration\Shared;

use MyInvoice\Repository\AbstractMigrationImportRepository;
use MyInvoice\Repository\ImportJobRepository;
use MyInvoice\Repository\MigrationBatchRepository;
use MyInvoice\Service\ActivityLogger;

/**
 * Dávkový převod více firem z cizího programu jako jeden job na pozadí se sdíleným
 * průběhem: položky dávky (`migration_batch_items`, jedna záloha = jedna firma) běží
 * po jedné přes {@see MigrationBatchRunner}, každá se zapíše hned, jak doběhne, a pád
 * jedné firmy nezastaví ostatní. Zrušení se uplatní mezi firmami a u ostrého převodu
 * i uvnitř firmy.
 *
 * Průběh jobu: celkem = položky × kroky převodu jedné firmy; text kroku nese pořadí
 * firmy („Firma 3 z 12: Banka"). Zkouška nanečisto běží u každé firmy v transakci,
 * průběh uvnitř firmy proto nehlásí (viz {@see AbstractImportJobService}).
 *
 * Opakovaný job téže dávky (spadlý worker) převede jen položky, které ještě neběžely.
 *
 * Zdroj dodá kroky převodu jedné firmy, přípravu dávky a převod položky.
 */
abstract class AbstractBatchImportJobService extends AbstractImportJobService
{
    public function __construct(
        ImportJobRepository $jobs,
        AbstractMigrationImportRepository $runs,
        ActivityLogger $logger,
        protected readonly MigrationBatchRepository $batches,
        protected readonly MigrationBatchRunner $runner,
    ) {
        parent::__construct($jobs, $runs, $logger);
    }

    /** @return list<string> kroky převodu jedné firmy (pro průběh jobu) */
    abstract protected function itemSteps(): array;

    /**
     * Rozhodnutí platná pro celou dávku (skupina firem, podaná přiznání, oprávnění).
     *
     * @param array<string,mixed> $params
     * @param list<array<string,mixed>> $items
     */
    abstract protected function prepareBatch(int $jobId, array $params, int $supplierId, array $items): object;

    /**
     * Převod jedné položky.
     *
     * @param array<string,mixed> $item řádek migration_batch_items
     * @param (callable(string,int,int):void)|null $progress
     * @param (callable():bool)|null $cancel
     * @return array<string,mixed> výsledek pro {@see MigrationBatchRepository::finish()}
     */
    abstract protected function runItem(int $jobId, array $item, int $supplierId, object $batch, ?callable $progress, ?callable $cancel): array;

    /** Je dávka zkouškou nanečisto? */
    abstract protected function isDryRun(array $params): bool;

    /**
     * Po doběhnutí položky (úklid nahraného souboru úspěšně převedené firmy apod.).
     *
     * @param array<string,mixed> $item
     * @param array<string,mixed> $result
     */
    protected function afterItem(array $item, int $supplierId, array $params, array $result): void
    {
    }

    /** @param array<string,mixed> $job */
    protected function runLocked(int $jobId, array $job, int $supplierId): void
    {
        $params = is_array($job['params'] ?? null) ? $job['params'] : [];
        $dryRun = $this->isDryRun($params);
        try {
            $interrupted = $this->batches->closeInterrupted($jobId, $supplierId);
            if ($interrupted > 0) {
                $this->jobs->appendLog($jobId, "Uzavřeno {$interrupted} přerušených převodů firem.");
            }
            $items = $this->batches->items($jobId, $supplierId);
            $pending = array_values(array_filter($items, static fn (array $i): bool => $i['status'] === 'queued'));
            $steps = $this->itemSteps();
            $total = count($items);
            $this->jobs->updateProgress($jobId, [
                'total_items' => count($steps) * max(1, $total),
                'processed' => count($steps) * ($total - count($pending)),
                'current_step' => $dryRun ? 'Zkouška nanečisto běží' : 'Připravuji dávku',
            ]);
            $this->jobs->appendLog($jobId, sprintf('%s dávky %d firem.', $dryRun ? 'Zkouška nanečisto' : 'Ostrý převod', $total));
            $batch = $this->prepareBatch($jobId, $params, $supplierId, $items);

            $counts = ['created' => 0, 'skipped' => 0, 'failed' => 0];
            $positions = array_column($items, 'position', 'id');
            $this->runner->run(
                $pending,
                function (array $item) use ($jobId, $supplierId, $batch, $dryRun, $steps, $total, $positions): array {
                    $offset = count($steps) * ((int) $positions[$item['id']] - 1);
                    $label = sprintf('Firma %d z %d', (int) $positions[$item['id']], $total);
                    return $this->runItem($jobId, $item, $supplierId, $batch,
                        $dryRun ? null : $this->progressCallback($jobId, $steps, $offset, $label),
                        $dryRun ? null : $this->cancelCallback($jobId));
                },
                fn (\Throwable $e): string => $this->failureMessage($e, sprintf('převod položky dávky jobu %d firmy %d selhal', $jobId, $supplierId), static::RUN_FAILED),
                $this->cancelCallback($jobId),
                function (array $item) use ($jobId, $supplierId, $steps, $total): void {
                    $this->batches->markRunning((int) $item['id'], $supplierId);
                    $this->jobs->updateProgress($jobId, [
                        'processed' => count($steps) * ((int) $item['position'] - 1),
                        'current_step' => mb_substr(sprintf('Firma %d z %d: %s', (int) $item['position'], $total, (string) ($item['agenda_name'] ?? $item['file_name'] ?? '')), 0, 120),
                    ]);
                },
                function (array $item, int $index, array $result) use ($jobId, $supplierId, $steps, $params, &$counts): void {
                    $this->batches->finish((int) $item['id'], $supplierId, $result);
                    $status = (string) $result['status'];
                    $counts['created'] += in_array($status, [MigrationBatchRunner::COMPLETED, MigrationBatchRunner::COMPLETED_WITH_WARNINGS], true) ? 1 : 0;
                    $counts['skipped'] += $status === MigrationBatchRunner::SKIPPED ? 1 : 0;
                    $counts['failed'] += $status === MigrationBatchRunner::FAILED ? 1 : 0;
                    $this->jobs->updateProgress($jobId, [
                        'processed' => count($steps) * (int) $item['position'],
                        'created_count' => $counts['created'],
                        'skipped_count' => $counts['skipped'],
                        'failed_count' => $counts['failed'],
                    ]);
                    $this->jobs->appendLog($jobId, sprintf('Firma %d (%s): %s%s', (int) $item['position'],
                        (string) ($item['agenda_name'] ?? $item['file_name'] ?? ''), $status,
                        isset($result['error']) && $result['error'] !== null ? ' - ' . $result['error'] : ''));
                    $this->afterItem($item, $supplierId, $params, $result);
                },
            );

            $statuses = array_column($this->batches->items($jobId, $supplierId), 'status');
            $status = MigrationBatchRunner::batchStatus($statuses);
            $this->jobs->updateProgress($jobId, ['current_step' => $status === 'cancelled' ? 'Zrušeno uživatelem' : 'Hotovo']);
            $this->finishJob($jobId, $status, 'Žádnou firmu dávky se nepodařilo převést, podrobnosti jsou u jednotlivých firem.');
        } catch (\Throwable $e) {
            $this->jobs->markFailed($jobId, $this->failureMessage($e, sprintf('dávka jobu %d firmy %d selhala', $jobId, $supplierId), static::RUN_FAILED));
        }
    }
}
