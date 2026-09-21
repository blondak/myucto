<?php

declare(strict_types=1);

namespace MyInvoice\Service\Migration\Premier;

use MyInvoice\Repository\ImportJobRepository;
use MyInvoice\Repository\PremierImportRepository;
use MyInvoice\Service\ActivityLogger;

/**
 * Převod z PREMIER na pozadí (`import_jobs.source = premier_import`, migrace 1859).
 *
 * Job dělá dvě věci: zpracuje nahranou zálohu (`params.mode = prepare`: kontrolní součet,
 * rozbalení, přehled účetních let) a spustí převod zvoleného roku ({@see PremierImporter}).
 * Převod drží po celou dobu zámek firmy ({@see PremierImportRepository::acquireLock()}).
 * Zkouška nanečisto běží v jedné transakci, průběh do řádku jobu během ní nezapisuje
 * (stejně jako u POHODY a Money S3).
 *
 * Na rozdíl od POHODY nese jedna záloha VŠECHNY účetní roky (databáze Visual FoxPro),
 * takže se po úspěšném ostrém převodu nemaže - uživatel v ní postupně převádí rok po
 * roku. Uklidí ji až denní úklid ({@see PremierUploads::purgeStaleAll()}).
 */
final class PremierImportJobService
{
    public const SOURCE = 'premier_import';
    public const MODE_PREPARE = 'prepare';
    private const PREPARE_FAILED = 'Zálohu dat PREMIER se nepodařilo přečíst.';

    private const STEP_LABELS = [
        'chart' => 'Účtová osnova',
        'journal' => 'Účetní období a deník',
        'accounting_mode' => 'Režim účetní jednotky',
        'partners' => 'Adresář partnerů',
        'purchase_invoices' => 'Přijaté faktury',
        'issued_invoices' => 'Vydané faktury',
        'vat_documents' => 'Doklady s DPH mimo faktury',
        'bank' => 'Banka',
        'link' => 'Vazby dokladů na deník',
        'payments' => 'Úhrady dokladů',
        'assets' => 'Dlouhodobý majetek',
        'reconciliation' => 'Rekonciliace',
        'tax_return' => 'Úpravy základu daně z přiznání PREMIER',
        'verification' => 'Kontrola proti podáním z PREMIER',
        'done' => 'Dokončuji',
    ];

    public function __construct(
        private readonly ImportJobRepository $jobs,
        private readonly PremierImportRepository $runs,
        private readonly PremierImporter $importer,
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
        $mode = ($params['mode'] ?? '') === 'import' ? 'import' : 'dry_run';
        $dryRun = $mode === 'dry_run';
        $year = (int) ($params['year'] ?? 0);
        $runId = null;
        $lock = null;

        try {
            // Zámek zálohy: denní úklid ani nové nahrání ji nesmaže, dokud z ní převod běží.
            $lock = PremierUploads::acquireJobLock($supplierId, $token);
            if ($lock === null) {
                throw new PremierException('upload_busy', 'Zálohu právě zpracovává jiný proces, nebo už byla uklizena - nahrajte ji znovu.');
            }
            $interrupted = $this->runs->closeInterruptedRuns($supplierId);
            if ($interrupted > 0) {
                $this->jobs->appendLog($jobId, "Uzavřeno {$interrupted} přerušených běhů převodu.");
            }
            $meta = PremierUploads::meta($supplierId, $token);
            $agenda = self::agenda($meta, (string) ($params['ico'] ?? ''), $year);
            if ($agenda === null) {
                throw new PremierException('agenda_not_found', "Záloha neobsahuje účetní rok {$year} této firmy.");
            }
            $backup = PremierBackup::open(PremierUploads::backupDir($supplierId, $token));
            $runId = $this->runs->startRun($supplierId, $jobId, $mode, [
                'ico' => $backup->ico,
                'year' => $year,
                'program' => 'PREMIER',
                'sha256' => $meta['sha256'] ?? null,
            ], $userId > 0 ? $userId : null);

            $steps = PremierImporter::stepKeys();
            $this->jobs->updateProgress($jobId, [
                'total_items' => count($steps),
                'processed' => 0,
                'current_step' => $dryRun ? 'Zkouška nanečisto běží' : 'Připravuji převod',
            ]);
            $this->jobs->appendLog($jobId, ($dryRun ? 'Zkouška nanečisto' : 'Ostrý převod') . " agendy IČO {$agenda['ico']}, rok {$year}.");

            $progress = $dryRun ? null : function (string $step, int $done, int $total) use ($jobId, $steps): void {
                $index = array_search($step, $steps, true);
                $this->jobs->updateProgress($jobId, [
                    'processed' => $index === false ? count($steps) : (int) $index,
                    'current_step' => mb_substr(self::STEP_LABELS[$step] ?? $step, 0, 120),
                ]);
            };
            $cancel = $dryRun ? null : fn (): bool => $this->jobs->isCancelRequested($jobId);

            $protocol = $this->importer->run($supplierId, $userId, $backup, $year, $dryRun, $runId, $progress, $cancel);
            $result = $protocol->toArray() + ['kind' => 'accounting'];
            $cancelled = $result['failure'] === 'cancelled';
            $status = $cancelled ? 'cancelled' : $protocol->status();
            $this->runs->finishRun($runId, $supplierId, $status, $result);

            $byStep = array_column($result['steps'], null, 'key');
            $journal = $byStep['journal']['counts'] ?? [];
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
                $this->jobs->markFailed($jobId, 'Převod nedoběhl nebo nesedí rekonciliace - podrobnosti v protokolu #' . $runId . '.');
            } elseif ($status === 'completed_with_warnings') {
                $this->jobs->markCompletedWithWarnings($jobId);
            } else {
                $this->jobs->markCompleted($jobId);
            }
        } catch (\Throwable $e) {
            if ($e instanceof PremierException) {
                $message = $e->getMessage();
            } else {
                error_log(sprintf('PREMIER: převod zálohy %s firmy %d selhal: %s', $token, $supplierId, (string) $e));
                $message = 'Převod z PREMIER selhal na neočekávané chybě, podrobnosti jsou v logu serveru.';
            }
            if ($runId !== null) {
                $this->runs->finishRun($runId, $supplierId, 'failed', ['mode' => $mode, 'status' => 'failed', 'failure' => 'unexpected', 'error' => $message, 'steps' => []]);
            }
            $this->jobs->markFailed($jobId, $message);
        } finally {
            if ($lock !== null) {
                PremierUploads::releaseJobLock($lock);
            }
        }
        // Záloha zůstává (drží ostatní roky) - úklid ji nechává denní údržbě.
    }

    /**
     * Agenda (účetní rok) zvoleného IČO z přehledu nahrané zálohy.
     *
     * @param array<string,mixed> $meta
     * @return array{dir:string,ico:string,year:int}|null
     */
    public static function agenda(array $meta, string $ico, int $year): ?array
    {
        foreach ((array) ($meta['agendas'] ?? []) as $a) {
            if ((int) ($a['year'] ?? 0) === $year && ($ico === '' || (string) ($a['ico'] ?? '') === $ico)) {
                return [
                    'dir' => (string) $a['dir'],
                    'ico' => (string) $a['ico'],
                    'year' => (int) $a['year'],
                ];
            }
        }
        return null;
    }

    /**
     * Záloha nahraná po částech: kontrolní součet, rozbalení, přehled účetních let a
     * `meta.json`. Chyba se uloží do stavu nahrávání, odkud ji průvodce ukáže.
     *
     * @param array<string,mixed> $job
     */
    private function prepare(int $jobId, array $job, int $supplierId): void
    {
        $params = (array) $job['params'];
        $token = (string) ($params['token'] ?? '');
        try {
            $lock = PremierUploads::acquireJobLock($supplierId, $token);
        } catch (PremierException $e) {
            $this->jobs->markFailed($jobId, $e->getMessage());
            return;
        }
        if ($lock === null) {
            $this->jobs->markFailed($jobId, 'Zálohu už zpracovává jiný proces.');
            return;
        }
        try {
            $state = PremierUploads::state($supplierId, $token);
            if ($state === null) {
                throw new PremierException('upload_not_found', 'Nahraná záloha nebyla nalezena (mohla být už uklizena).', [], 404);
            }
            $size = (int) ($state['size'] ?? 0);
            if (PremierUploads::partSize($supplierId, $token) !== $size || $size <= 0) {
                throw new PremierException('upload_incomplete', 'Záloha není nahraná celá, nahrajte ji znovu.');
            }
            PremierUploads::updateState($supplierId, $token, ['status' => PremierUploads::STATUS_PROCESSING, 'job_id' => $jobId, 'error' => null]);
            $this->jobs->updateProgress($jobId, ['total_items' => 3, 'processed' => 0, 'current_step' => 'Kontrolní součet zálohy']);
            $part = PremierUploads::partPath($supplierId, $token);
            $sha = (string) hash_file('sha256', $part);

            $this->jobs->updateProgress($jobId, ['processed' => 1, 'current_step' => 'Rozbaluji zálohu']);
            $root = PremierUploads::backupDir($supplierId, $token);
            PremierBackup::extractArchive($part, $root);
            @unlink($part);

            $this->jobs->updateProgress($jobId, ['processed' => 2, 'current_step' => 'Čtu účetní roky zálohy']);
            $agendas = PremierBackup::overview($root);
            if ($agendas === []) {
                throw new PremierException('no_agenda', 'Záloha neobsahuje žádný účetní zápis.');
            }
            $userId = (int) ($state['uploaded_by'] ?? 0);
            $first = $agendas[0];
            PremierUploads::writeMeta($supplierId, $token, [
                'token' => $token,
                'file_name' => (string) ($state['file_name'] ?? 'premier_zaloha.izip'),
                'sha256' => $sha,
                'uploaded_at' => date('c'),
                'uploaded_by' => $userId,
                'company' => ['ico' => (string) $first['ico'], 'dic' => (string) $first['dic'], 'name' => (string) $first['company']],
                'agendas' => $agendas,
            ]);
            PremierUploads::updateState($supplierId, $token, ['status' => PremierUploads::STATUS_READY, 'error' => null]);
            $this->logger->log('import.premier_uploaded', $userId > 0 ? $userId : null, 'supplier', $supplierId,
                ['agendas' => array_map(static fn (array $a): string => $a['ico'] . '/' . $a['year'], $agendas)],
                isset($params['ip']) ? (string) $params['ip'] : null,
                isset($params['user_agent']) ? (string) $params['user_agent'] : null);

            $this->jobs->updateProgress($jobId, ['processed' => 3, 'current_step' => 'Hotovo']);
            $this->jobs->appendLog($jobId, 'Záloha PREMIER načtena (' . count($agendas) . ' účetních let).');
            $this->jobs->markCompleted($jobId);
        } catch (\Throwable $e) {
            if ($e instanceof PremierException) {
                $message = $e->getMessage();
            } else {
                error_log(sprintf('PREMIER: zpracování zálohy %s firmy %d selhalo: %s', $token, $supplierId, (string) $e));
                $message = self::PREPARE_FAILED;
            }
            PremierUploads::discardData($supplierId, $token);
            try {
                PremierUploads::updateState($supplierId, $token, ['status' => PremierUploads::STATUS_FAILED, 'error' => $message]);
            } catch (\Throwable) {
                // adresář zálohy mezitím zmizel - chyba zůstane aspoň u jobu
            }
            $this->jobs->markFailed($jobId, $message);
        } finally {
            PremierUploads::releaseJobLock($lock);
        }
    }
}
