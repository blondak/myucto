<?php

declare(strict_types=1);

namespace MyInvoice\Service\Migration\MoneyS3;

use MyInvoice\Repository\ImportJobRepository;
use MyInvoice\Repository\MigrationBatchRepository;
use MyInvoice\Repository\MoneyS3ImportRepository;
use MyInvoice\Service\ActivityLogger;
use MyInvoice\Service\Migration\Shared\AbstractBatchImportJobService;
use MyInvoice\Service\Migration\Shared\ChunkedUploadStore;
use MyInvoice\Service\Migration\Shared\MigrationBatchActor;
use MyInvoice\Service\Migration\Shared\MigrationBatchRunner;

/**
 * Dávkový převod více záloh Money S3 na pozadí (`import_jobs.source = money_s3_batch`).
 *
 * Nahraná záloha dávky se zpracuje stejným jobem (`params.mode = prepare`): kontrolní
 * součet, přehled agendy a doporučený rok „od"; rozbalená data se hned zase smažou
 * ({@see MoneyS3BatchUploads}). Běh dávky převádí položky po jedné přes
 * {@see MoneyS3BatchImporter}.
 */
final class MoneyS3BatchJobService extends AbstractBatchImportJobService
{
    public const SOURCE = 'money_s3_batch';
    public const ITEM_SOURCE = 'money_s3';

    protected const LOG_PREFIX = 'Money S3 dávka';
    protected const UPLOAD_NOUN = 'zálohy dávky';
    protected const EXCEPTION_CLASS = MoneyS3Exception::class;
    protected const PREPARE_FAILED = 'Zálohu agendy se nepodařilo přečíst.';
    protected const RUN_FAILED = 'Převod firmy z Money S3 selhal na neočekávané chybě, podrobnosti jsou v logu serveru.';
    protected const PREPARE_BUSY = 'Zálohu už zpracovává jiný proces.';
    protected const UPLOAD_INCOMPLETE = 'Záloha agendy není nahraná celá, nahrajte ji znovu.';
    protected const DEFAULT_FILE_NAME = 'agenda.lz';
    protected const UPLOADED_EVENT = 'import.money_s3_batch_uploaded';
    protected const PREPARE_STEPS = ['Kontrolní součet zálohy', 'Rozbaluji zálohu agendy', 'Čtu údaje agendy'];
    protected const STEP_LABELS = MoneyS3ImportJobService::STEP_LABELS;

    public function __construct(
        ImportJobRepository $jobs,
        MoneyS3ImportRepository $runs,
        ActivityLogger $logger,
        MigrationBatchRepository $batches,
        MigrationBatchRunner $runner,
        private readonly MoneyS3BatchImporter $importer,
        private readonly JournalImporter $journal,
    ) {
        parent::__construct($jobs, $runs, $logger, $batches, $runner);
    }

    protected function uploads(): ChunkedUploadStore
    {
        return MoneyS3BatchUploads::store();
    }

    protected function extractUpload(string $part, int $supplierId, string $token): mixed
    {
        $backupPath = MoneyS3BatchUploads::backupPath($supplierId, $token);
        if (!@rename($part, $backupPath)) {
            throw new MoneyS3Exception('storage_not_writable', 'Zálohu se nepodařilo uložit.', [], 500);
        }
        return Ms3Backup::extract($backupPath, MoneyS3BatchUploads::store()->dataDir($supplierId, $token));
    }

    protected function describeUpload(mixed $extracted, int $supplierId, string $token): array
    {
        try {
            $agenda = AgendaInfo::fromBackup($extracted);
            $suggested = JournalImporter::suggestedFromYear($this->journal->chainBreaks($extracted, new ImportOptions()));
        } finally {
            MoneyS3BatchImporter::removeTree(MoneyS3BatchUploads::store()->dataDir($supplierId, $token));
        }
        return [
            'meta' => ['agenda' => $agenda->toArray(), 'suggested_from_year' => $suggested],
            'activity' => ['agenda_ico' => $agenda->ico, 'version' => $agenda->version, 'years' => $agenda->fiscalYears(), 'batch' => true],
            'log' => 'Záloha agendy ' . $agenda->name . ' načtena do dávky.',
        ];
    }

    protected function itemSteps(): array
    {
        return MoneyS3Importer::stepKeys();
    }

    protected function isDryRun(array $params): bool
    {
        return ($params['options']['mode'] ?? ImportOptions::MODE_DRY_RUN) !== ImportOptions::MODE_IMPORT;
    }

    protected function prepareBatch(int $jobId, array $params, int $supplierId, array $items): object
    {
        $options = MoneyS3BatchOptions::fromArray((array) ($params['options'] ?? []));
        $icos = array_values(array_filter(array_map(static fn (array $i): string => (string) ($i['agenda_ico'] ?? ''), $items)));
        $options = $this->importer->prepareBatch($options, $icos);
        if ($options->groupId !== null) {
            $this->jobs->appendLog($jobId, "Firmy se zařadí do skupiny #{$options->groupId}.");
        }
        $filings = [];
        if ($options->takeOverFilings) {
            $wanted = array_flip(array_map('strval', (array) ($params['filing_ids'] ?? [])));
            foreach (MoneyS3BatchUploads::filings($supplierId) as $f) {
                if (isset($wanted[$f['id']])) {
                    $filings[] = ['filing' => $f['filing'], 'xml' => $f['xml'], 'file' => $f['file_name']];
                }
            }
            if ($filings !== []) {
                $this->jobs->appendLog($jobId, 'Podaná přiznání k DPPO: ' . count($filings) . '.');
            }
        }
        return (object) [
            'options' => $options,
            'actor' => MigrationBatchActor::fromArray((array) ($params['actor'] ?? [])),
            'filings' => $filings,
        ];
    }

    protected function runItem(int $jobId, array $item, int $supplierId, object $batch, ?callable $progress, ?callable $cancel): array
    {
        $token = (string) ($item['token'] ?? '');
        $lock = $this->acquireRunUploadLock($supplierId, $token, 'Zálohu právě zpracovává jiný proces.');
        try {
            $result = $this->importer->importCompany(
                MoneyS3BatchUploads::backupPath($supplierId, $token),
                MoneyS3BatchUploads::store()->dataDir($supplierId, $token),
                $batch->options,
                $batch->actor,
                $batch->filings,
                $jobId,
                $progress,
                $cancel,
            );
        } finally {
            $this->uploads()->releaseJobLock($lock);
        }
        if ($result['target_supplier_id'] !== null) {
            // Firma založená dávkou je pro další položky „existující, přístupná".
            $batch->actor = $batch->actor->withSupplier((int) $result['target_supplier_id']);
        }
        return $result;
    }

    protected function afterItem(array $item, int $supplierId, array $params, array $result): void
    {
        // Záloha úspěšně převedené firmy už není potřeba (stejně jako u průvodce jedné firmy);
        // po zkoušce nanečisto nebo chybě zůstává pro další běh dávky.
        if (!$this->isDryRun($params) && in_array($result['status'], [MigrationBatchRunner::COMPLETED, MigrationBatchRunner::COMPLETED_WITH_WARNINGS], true)) {
            MoneyS3BatchUploads::store()->purge($supplierId, (string) ($item['token'] ?? ''));
        }
    }
}
