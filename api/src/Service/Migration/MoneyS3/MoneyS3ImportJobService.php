<?php

declare(strict_types=1);

namespace MyInvoice\Service\Migration\MoneyS3;

use MyInvoice\Repository\ImportJobRepository;
use MyInvoice\Repository\MoneyS3ImportRepository;
use MyInvoice\Service\ActivityLogger;

/**
 * Převod z Money S3 na pozadí (`import_jobs.source = money_s3_import`, migrace 1807).
 *
 * Tahle třída řeší jen to, co přidává job: najít nahranou zálohu, hlásit průběh, uložit
 * protokol k běhu a uklidit. Převod sám dělá {@see MoneyS3Importer}.
 *
 * Zkouška nanečisto běží v jedné transakci, která se vrací. Průběh do řádku jobu během
 * ní nezapisuje: zápis by držel zámek řádku až do konce a požadavek na zrušení z UI by
 * na něm visel. UI proto u zkoušky ukazuje jen „běží".
 *
 * Běh drží po celou dobu zámek firmy ({@see MoneyS3ImportRepository::acquireLock()}).
 * Job bez hlášení průběhu (zkouška nanečisto) by jinak po čtvrthodině vypadal jako
 * mrtvý, úklid by ho ukončil a mohl by se spustit druhý převod nad toutéž mapou.
 */
final class MoneyS3ImportJobService
{
    public const SOURCE = 'money_s3_import';

    /**
     * Zpracování zálohy nahrané po částech (kontrolní součet, rozbalení, údaje agendy)
     * běží jako job téhož zdroje s `params.mode = prepare`. Nic nepřevádí: nebere zámek
     * firmy, nezakládá protokol převodu a do „Převod už běží" se nepočítá.
     */
    public const MODE_PREPARE = 'prepare';
    private const PREPARE_FAILED = 'Zálohu agendy se nepodařilo přečíst.';

    private const STEP_LABELS = [
        'chart' => 'Účtová osnova',
        'journal' => 'Účetní období a deník',
        'accounting_mode' => 'Režim účetní jednotky',
        'partners' => 'Adresář partnerů',
        'posting_rules' => 'Předkontace',
        'purchase_invoices' => 'Přijaté faktury',
        'issued_invoices' => 'Vydané faktury',
        'cash' => 'Pokladna',
        'bank' => 'Banka',
        'link' => 'Vazby dokladů na deník',
        'payments' => 'Úhrady faktur',
        'closing' => 'Uzávěrka historických let',
        'reconciliation' => 'Rekonciliace',
        'done' => 'Dokončuji',
    ];

    public function __construct(
        private readonly ImportJobRepository $jobs,
        private readonly MoneyS3ImportRepository $runs,
        private readonly MoneyS3Importer $importer,
        private readonly ActivityLogger $logger,
    ) {}

    /** @param array<string,mixed> $job řádek import_jobs */
    public static function isPrepareJob(array $job): bool
    {
        return is_array($job['params'] ?? null) && ($job['params']['mode'] ?? null) === self::MODE_PREPARE;
    }

    public function run(int $jobId): void
    {
        $job = $this->jobs->findById($jobId);
        if ($job === null || !$this->jobs->markRunning($jobId)) {
            return;
        }
        $supplierId = (int) $job['supplier_id'];
        if (self::isPrepareJob($job)) {
            $this->prepare($jobId, $job, $supplierId);
            return;
        }
        if (!$this->runs->acquireLock($supplierId)) {
            $this->jobs->markFailed($jobId, 'Převod této firmy už běží v jiném procesu, druhý se nespouští.');
            return;
        }
        try {
            $this->runLocked($jobId, $job, $supplierId);
        } finally {
            $this->runs->releaseLock($supplierId);
        }
    }

    /** @param array<string,mixed> $job */
    private function runLocked(int $jobId, array $job, int $supplierId): void
    {
        $userId = (int) ($job['created_by'] ?? 0);
        $params = is_array($job['params'] ?? null) ? $job['params'] : [];
        $token = (string) ($params['token'] ?? '');
        $mode = (string) ($params['mode'] ?? ImportOptions::MODE_DRY_RUN);
        $runId = null;

        try {
            $interrupted = $this->runs->closeInterruptedRuns($supplierId);
            if ($interrupted > 0) {
                $this->jobs->appendLog($jobId, "Uzavřeno {$interrupted} přerušených běhů převodu.");
            }
            $meta = MoneyS3Uploads::meta($supplierId, $token);
            $backup = Ms3Backup::open(MoneyS3Uploads::agendaDir($supplierId, $token));
            $options = new ImportOptions(
                $mode,
                (bool) ($params['close_history'] ?? true),
                isset($params['first_period_start']) && $params['first_period_start'] !== '' ? (string) $params['first_period_start'] : null,
                array_values(array_map('strval', (array) ($params['related_party_icos'] ?? []))),
                MoneyS3Uploads::reports($supplierId, $token),
                (bool) ($params['confirm_ico'] ?? false),
                (int) ($params['from_year'] ?? 0) > 0 ? (int) $params['from_year'] : null,
            );
            $agenda = (array) ($meta['agenda'] ?? []);
            $runId = $this->runs->startRun($supplierId, $jobId, $mode, [
                'agenda_ico' => $agenda['ico'] ?? null,
                'agenda_name' => $agenda['name'] ?? null,
                'money_version' => $agenda['version'] ?? null,
                'backup_sha256' => $meta['sha256'] ?? null,
            ], $userId > 0 ? $userId : null);

            $steps = MoneyS3Importer::stepKeys();
            $this->jobs->updateProgress($jobId, [
                'total_items' => count($steps),
                'processed' => 0,
                'current_step' => $options->isDryRun() ? 'Zkouška nanečisto běží' : 'Připravuji převod',
            ]);
            $this->jobs->appendLog($jobId, ($options->isDryRun() ? 'Zkouška nanečisto' : 'Ostrý převod') . ' agendy ' . ($agenda['name'] ?? '') . '.');

            $progress = $options->isDryRun() ? null : function (string $step, int $done, int $total) use ($jobId, $steps): void {
                $index = array_search($step, $steps, true);
                $this->jobs->updateProgress($jobId, [
                    'processed' => $index === false ? count($steps) : (int) $index,
                    'current_step' => mb_substr(self::STEP_LABELS[$step] ?? $step, 0, 120),
                ]);
            };
            $cancel = $options->isDryRun() ? null : fn (): bool => $this->jobs->isCancelRequested($jobId);

            $protocol = $this->importer->run($supplierId, $userId, $backup, $options, $runId, $progress, $cancel);
            $result = $protocol->toArray();
            $cancelled = $result['failure'] === 'cancelled';
            $status = $cancelled ? 'cancelled' : $protocol->status();
            $this->runs->finishRun($runId, $supplierId, $status, $result);

            $journal = array_column($result['steps'], null, 'key')['journal']['counts'] ?? [];
            $errors = 0;
            $warnings = 0;
            foreach ($result['steps'] as $s) {
                foreach ($s['messages'] as $m) {
                    $errors += $m['level'] === 'error' ? 1 : 0;
                    $warnings += $m['level'] === 'warning' ? 1 : 0;
                }
            }
            $this->jobs->updateProgress($jobId, [
                'processed' => count($steps),
                'created_count' => (int) ($journal['entries'] ?? 0),
                'skipped_count' => (int) ($journal['existing'] ?? 0),
                'failed_count' => $errors,
                'current_step' => $cancelled ? 'Zrušeno uživatelem' : 'Hotovo',
            ]);
            $this->jobs->appendLog($jobId, sprintf('Protokol #%d: %d chyb, %d upozornění.', $runId, $errors, $warnings));

            if ($cancelled) {
                $this->jobs->markCancelled($jobId);
            } elseif ($status === 'failed') {
                $this->jobs->markFailed($jobId, 'Převod nedoběhl nebo nesedí rekonciliace — podrobnosti v protokolu #' . $runId . '.');
            } elseif ($status === 'completed_with_warnings') {
                $this->jobs->markCompletedWithWarnings($jobId);
            } else {
                $this->jobs->markCompleted($jobId);
            }
            if (!$options->isDryRun() && $status !== 'failed' && !$cancelled) {
                MoneyS3Uploads::purge($supplierId, $token);
            }
        } catch (\Throwable $e) {
            if ($e instanceof MoneyS3Exception) {
                $message = $e->getMessage();
            } else {
                error_log(sprintf('Money S3: převod zálohy %s firmy %d selhal: %s', $token, $supplierId, (string) $e));
                $message = 'Převod z Money S3 selhal na neočekávané chybě, podrobnosti jsou v logu serveru.';
            }
            if ($runId !== null) {
                $this->runs->finishRun($runId, $supplierId, 'failed', ['mode' => $mode, 'status' => 'failed', 'failure' => 'unexpected', 'error' => $message, 'steps' => []]);
            }
            $this->jobs->markFailed($jobId, $message);
        }
    }

    /**
     * Záloha nahraná po částech → kontrolní součet, rozbalení agendy, údaje agendy
     * a `meta.json` stejného tvaru jako u nahrání jedním požadavkem. Chyba se uloží
     * do stavu nahrávání, odkud ji průvodce ukáže.
     *
     * @param array<string,mixed> $job
     */
    private function prepare(int $jobId, array $job, int $supplierId): void
    {
        $params = (array) $job['params'];
        $token = (string) ($params['token'] ?? '');
        try {
            $lock = MoneyS3Uploads::acquireJobLock($supplierId, $token);
        } catch (MoneyS3Exception $e) {
            $this->jobs->markFailed($jobId, $e->getMessage());
            return;
        }
        if ($lock === null) {
            $this->jobs->markFailed($jobId, 'Zálohu už zpracovává jiný proces.');
            return;
        }
        try {
            $state = MoneyS3Uploads::state($supplierId, $token);
            if ($state === null) {
                throw new MoneyS3Exception('upload_not_found', 'Nahraná záloha nebyla nalezena (mohla být už uklizena).', [], 404);
            }
            $size = (int) ($state['size'] ?? 0);
            if (MoneyS3Uploads::partSize($supplierId, $token) !== $size || $size <= 0) {
                throw new MoneyS3Exception('upload_incomplete', 'Záloha agendy není nahraná celá, nahrajte ji znovu.');
            }
            MoneyS3Uploads::updateState($supplierId, $token, ['status' => MoneyS3Uploads::STATUS_PROCESSING, 'job_id' => $jobId, 'error' => null]);
            $this->jobs->updateProgress($jobId, ['total_items' => 3, 'processed' => 0, 'current_step' => 'Kontrolní součet zálohy']);
            $part = MoneyS3Uploads::partPath($supplierId, $token);
            $sha = (string) hash_file('sha256', $part);

            $this->jobs->updateProgress($jobId, ['processed' => 1, 'current_step' => 'Rozbaluji zálohu agendy']);
            $backup = Ms3Backup::extract($part, MoneyS3Uploads::agendaDir($supplierId, $token));
            @unlink($part);

            $this->jobs->updateProgress($jobId, ['processed' => 2, 'current_step' => 'Čtu údaje agendy']);
            $agenda = AgendaInfo::fromBackup($backup);
            $userId = (int) ($state['uploaded_by'] ?? 0);
            MoneyS3Uploads::writeMeta($supplierId, $token, [
                'token' => $token,
                'file_name' => (string) ($state['file_name'] ?? 'agenda.lz'),
                'sha256' => $sha,
                'uploaded_at' => date('c'),
                'uploaded_by' => $userId,
                'agenda' => $agenda->toArray(),
            ]);
            MoneyS3Uploads::updateState($supplierId, $token, ['status' => MoneyS3Uploads::STATUS_READY, 'error' => null]);
            $this->logger->log('import.money_s3_uploaded', $userId > 0 ? $userId : null, 'supplier', $supplierId,
                ['agenda_ico' => $agenda->ico, 'version' => $agenda->version, 'years' => $agenda->fiscalYears()],
                isset($params['ip']) ? (string) $params['ip'] : null,
                isset($params['user_agent']) ? (string) $params['user_agent'] : null);

            $this->jobs->updateProgress($jobId, ['processed' => 3, 'current_step' => 'Hotovo']);
            $this->jobs->appendLog($jobId, 'Záloha agendy ' . $agenda->name . ' načtena.');
            $this->jobs->markCompleted($jobId);
        } catch (\Throwable $e) {
            if ($e instanceof MoneyS3Exception) {
                $message = $e->getMessage();
            } else {
                error_log(sprintf('Money S3: zpracování zálohy %s firmy %d selhalo: %s', $token, $supplierId, (string) $e));
                $message = self::PREPARE_FAILED;
            }
            MoneyS3Uploads::discardData($supplierId, $token);
            try {
                MoneyS3Uploads::updateState($supplierId, $token, ['status' => MoneyS3Uploads::STATUS_FAILED, 'error' => $message]);
            } catch (\Throwable) {
                // adresář zálohy mezitím zmizel — chyba zůstane aspoň u jobu
            }
            $this->jobs->markFailed($jobId, $message);
        } finally {
            MoneyS3Uploads::releaseJobLock($lock);
        }
    }
}
