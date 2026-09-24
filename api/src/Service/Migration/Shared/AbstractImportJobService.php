<?php

declare(strict_types=1);

namespace MyInvoice\Service\Migration\Shared;

use MyInvoice\Repository\AbstractMigrationImportRepository;
use MyInvoice\Repository\ImportJobRepository;
use MyInvoice\Service\ActivityLogger;
use MyInvoice\Service\Migration\ImportYears;

/**
 * Společná kostra převodu z cizího programu na pozadí (`import_jobs`).
 *
 * Job zdroje dělá dvě věci: zpracuje soubor nahraný po částech (`params.mode = prepare`:
 * kontrolní součet, rozbalení, přehled agendy do `meta.json`) a spustí převod. Zpracování
 * nic nepřevádí: nebere zámek firmy, nezakládá protokol převodu a do „Převod už běží" se
 * nepočítá. Převod drží po celou dobu zámek firmy
 * ({@see AbstractMigrationImportRepository::acquireLock()}); job bez hlášení průběhu
 * (zkouška nanečisto) by jinak po čtvrthodině vypadal jako mrtvý, úklid by ho ukončil
 * a mohl by se spustit druhý převod nad toutéž mapou.
 *
 * Zkouška nanečisto běží v jedné transakci, která se vrací. Průběh do řádku jobu během ní
 * nezapisuje: zápis by držel zámek řádku až do konce a požadavek na zrušení z UI by na něm
 * visel. UI proto u zkoušky ukazuje jen „běží".
 *
 * Zdroj dodá úložiště, třídu výjimky, texty a vlastní kroky: čtení rozbaleného souboru
 * ({@see extractUpload()}, {@see describeUpload()}) a převod ({@see runLocked()}).
 */
abstract class AbstractImportJobService
{
    public const MODE_PREPARE = 'prepare';

    /** `import_jobs.source` zdroje. */
    public const SOURCE = '';
    /** Předpona zápisu do logu serveru (`Money S3`, `POHODA`, …). */
    protected const LOG_PREFIX = '';
    /** Nahraný soubor ve 2. pádě pro log serveru (`zálohy`, `exportu`). */
    protected const UPLOAD_NOUN = '';
    /** Třída výjimky zdroje; její text jde uživateli, jiné výjimky jen do logu serveru. */
    protected const EXCEPTION_CLASS = \RuntimeException::class;
    /** Hláška jobu, když zpracování nahraného souboru spadne na neočekávané chybě. */
    protected const PREPARE_FAILED = '';
    /** Hláška jobu, když převod spadne na neočekávané chybě. */
    protected const RUN_FAILED = '';
    /** Nahraný soubor už zpracovává jiný proces (zámek jobu je obsazený). */
    protected const PREPARE_BUSY = '';
    /** Soubor v úložišti není celý. */
    protected const UPLOAD_INCOMPLETE = '';
    /** Jméno souboru do `meta.json`, když ho stav nahrávání nemá. */
    protected const DEFAULT_FILE_NAME = '';
    /** Událost activity logu po zpracování nahraného souboru. */
    protected const UPLOADED_EVENT = '';
    /** @var array{string,string,string} kroky zpracování: kontrolní součet, rozbalení, čtení */
    protected const PREPARE_STEPS = ['', '', ''];
    /** @var array<string,string> klíč kroku převodu => text do průběhu jobu */
    protected const STEP_LABELS = [];

    public function __construct(
        protected readonly ImportJobRepository $jobs,
        protected readonly ?AbstractMigrationImportRepository $runs,
        protected readonly ActivityLogger $logger,
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
        if (static::isPrepareJob($job)) {
            if (!$this->supportsPrepareJob()) {
                $this->jobs->markFailed($jobId, 'Tento převod nepodporuje zpracování zálohy jako samostatný job.');
                return;
            }
            $this->prepare($jobId, $job, $supplierId);
            return;
        }
        if (!$this->acquireCompanyLock($supplierId)) {
            $this->jobs->markFailed($jobId, 'Převod této firmy už běží v jiném procesu, druhý se nespouští.');
            return;
        }
        try {
            $this->runLocked($jobId, $job, $supplierId);
        } finally {
            $this->releaseCompanyLock($supplierId);
        }
    }

    protected function supportsPrepareJob(): bool
    {
        return true;
    }

    protected function acquireCompanyLock(int $supplierId): bool
    {
        return $this->runRepository()->acquireLock($supplierId);
    }

    protected function releaseCompanyLock(int $supplierId): void
    {
        $this->runRepository()->releaseLock($supplierId);
    }

    private function runRepository(): AbstractMigrationImportRepository
    {
        return $this->runs ?? throw new \LogicException('Zdroj bez evidence běhů musí dodat vlastní zámek firmy.');
    }

    /** Úložiště nahraných souborů zdroje. */
    abstract protected function uploads(): ChunkedUploadStore;

    /**
     * Převod se zámkem firmy.
     *
     * @param array<string,mixed> $job
     */
    abstract protected function runLocked(int $jobId, array $job, int $supplierId): void;

    /**
     * Rozbalí nahraný soubor do úložiště.
     *
     * @return mixed co potřebuje {@see describeUpload()}
     */
    abstract protected function extractUpload(string $part, int $supplierId, string $token): mixed;

    /**
     * Přečte rozbalený soubor pro `meta.json`.
     *
     * @return array{meta: array<string,mixed>, activity: array<string,mixed>, log: string}
     *         `meta` = údaje za společnými klíči `meta.json`, `activity` = kontext activity logu,
     *         `log` = řádek do logu jobu
     */
    abstract protected function describeUpload(mixed $extracted, int $supplierId, string $token): array;

    /**
     * Soubor nahraný po částech: kontrolní součet, rozbalení, přehled agendy a `meta.json`
     * stejného tvaru jako u nahrání jedním požadavkem. Chyba se uloží do stavu nahrávání,
     * odkud ji průvodce ukáže.
     *
     * @param array<string,mixed> $job
     */
    private function prepare(int $jobId, array $job, int $supplierId): void
    {
        $uploads = $this->uploads();
        $params = (array) $job['params'];
        $token = (string) ($params['token'] ?? '');
        try {
            $lock = $uploads->acquireJobLock($supplierId, $token);
        } catch (\Throwable $e) {
            if (!$this->isSourceException($e)) {
                throw $e;
            }
            $this->jobs->markFailed($jobId, $e->getMessage());
            return;
        }
        if ($lock === null) {
            $this->jobs->markFailed($jobId, static::PREPARE_BUSY);
            return;
        }
        try {
            $state = $uploads->state($supplierId, $token);
            if ($state === null) {
                throw $this->sourceException('upload_not_found', $uploads->messages()->metaNotFound, [], 404);
            }
            $size = (int) ($state['size'] ?? 0);
            if ($uploads->partSize($supplierId, $token) !== $size || $size <= 0) {
                throw $this->sourceException('upload_incomplete', static::UPLOAD_INCOMPLETE);
            }
            $uploads->updateState($supplierId, $token, ['status' => ChunkedUploadStore::STATUS_PROCESSING, 'job_id' => $jobId, 'error' => null]);
            $this->jobs->updateProgress($jobId, ['total_items' => 3, 'processed' => 0, 'current_step' => static::PREPARE_STEPS[0]]);
            $part = $uploads->partPath($supplierId, $token);
            $sha = (string) hash_file('sha256', $part);

            $this->jobs->updateProgress($jobId, ['processed' => 1, 'current_step' => static::PREPARE_STEPS[1]]);
            $extracted = $this->extractUpload($part, $supplierId, $token);
            @unlink($part);

            $this->jobs->updateProgress($jobId, ['processed' => 2, 'current_step' => static::PREPARE_STEPS[2]]);
            $described = $this->describeUpload($extracted, $supplierId, $token);
            $userId = (int) ($state['uploaded_by'] ?? 0);
            $uploads->writeMeta($supplierId, $token, [
                'token' => $token,
                'file_name' => (string) ($state['file_name'] ?? static::DEFAULT_FILE_NAME),
                'sha256' => $sha,
                'uploaded_at' => date('c'),
                'uploaded_by' => $userId,
            ] + $described['meta']);
            $uploads->updateState($supplierId, $token, ['status' => ChunkedUploadStore::STATUS_READY, 'error' => null]);
            $this->logger->log(static::UPLOADED_EVENT, $userId > 0 ? $userId : null, 'supplier', $supplierId,
                $described['activity'],
                isset($params['ip']) ? (string) $params['ip'] : null,
                isset($params['user_agent']) ? (string) $params['user_agent'] : null);

            $this->jobs->updateProgress($jobId, ['processed' => 3, 'current_step' => 'Hotovo']);
            $this->jobs->appendLog($jobId, $described['log']);
            $this->jobs->markCompleted($jobId);
        } catch (\Throwable $e) {
            $message = $this->failureMessage($e, sprintf('zpracování %s %s firmy %d selhalo', static::UPLOAD_NOUN, $token, $supplierId), static::PREPARE_FAILED);
            $uploads->discardData($supplierId, $token);
            try {
                $uploads->updateState($supplierId, $token, ['status' => ChunkedUploadStore::STATUS_FAILED, 'error' => $message]);
            } catch (\Throwable) {
                // adresář mezitím zmizel — chyba zůstane aspoň u jobu
            }
            $this->jobs->markFailed($jobId, $message);
        } finally {
            $uploads->releaseJobLock($lock);
        }
    }

    /**
     * Běh převodu z více roků: vybrané roky jdou vzestupně, každý s vlastním záznamem běhu
     * a protokolem ({@see runYear()}). Rok, který skončí chybou nebo zrušením, zastaví
     * i roky po něm (stavěly by na neúplném základu). Výsledný stav jobu je nejhorší ze
     * stavů roků; job se jím rovnou uzavře.
     *
     * @param list<int> $planYears roky vzestupně
     * @param list<string> $steps kroky převodu jednoho roku
     * @param callable(int,array{created:int,skipped:int,failed:int}):array{status:string,year:int,run_id:?int,error:?string} $runYear
     *        převod roku s daným pořadím; druhý argument přebírá odkazem
     * @return string výsledný stav
     */
    protected function runYears(int $jobId, array $planYears, bool $dryRun, array $steps, callable $runYear): string
    {
        $total = count($planYears);
        if ($total > 1) {
            $this->jobs->appendLog($jobId, 'Převádí se ' . $total . ' roky vzestupně: ' . implode(', ', $planYears) . '.');
            if ($dryRun) {
                $this->jobs->appendLog($jobId, ImportYears::DRY_RUN_NOTE);
            }
        }
        $this->jobs->updateProgress($jobId, [
            'total_items' => count($steps) * $total,
            'processed' => 0,
            'current_step' => $dryRun ? 'Zkouška nanečisto běží' : 'Připravuji převod',
        ]);

        $totals = ['created' => 0, 'skipped' => 0, 'failed' => 0];
        $results = [];
        foreach ($planYears as $index => $year) {
            if ($index > 0 && $this->jobs->isCancelRequested($jobId)) {
                $results[] = ['status' => 'cancelled', 'year' => $year, 'run_id' => null, 'error' => null];
                $this->jobs->appendLog($jobId, 'Převod zrušen, roky ' . implode(', ', array_slice($planYears, $index)) . ' se nespouštějí.');
                break;
            }
            $result = $runYear($index, $totals);
            $results[] = $result;
            if (in_array($result['status'], ['failed', 'cancelled'], true)) {
                $rest = array_slice($planYears, $index + 1);
                if ($rest !== []) {
                    $this->jobs->appendLog($jobId, sprintf('Rok %d skončil %s, roky %s se nespouštějí.',
                        $year, $result['status'] === 'failed' ? 'chybou' : 'zrušením', implode(', ', $rest)));
                }
                break;
            }
        }
        $status = ImportYears::worstStatus(array_column($results, 'status'));
        $this->jobs->updateProgress($jobId, ['current_step' => $status === 'cancelled' ? 'Zrušeno uživatelem' : 'Hotovo']);
        $failed = array_values(array_filter($results, static fn (array $r): bool => $r['status'] === 'failed'));
        $this->finishJob($jobId, $status, (string) ($failed[0]['error'] ?? 'Převod se nepodařil.'));
        return $status;
    }

    /**
     * Převod jednoho roku s vlastním záznamem běhu a protokolem. Zdroj v `$start` najde
     * agendu roku, založí běh ({@see AbstractMigrationImportRepository::startRun()})
     * a vrátí, jak rok převést.
     *
     * @param array<string,mixed> $params
     * @param list<int> $planYears
     * @param list<string> $steps
     * @param array{created:int,skipped:int,failed:int} $totals MĚNÍ SE: počty za celý job
     * @param callable():array{run_id:int,log:string,kind:string,import:callable(?callable,?callable):object,journal?:callable(array<string,mixed>):array<string,mixed>} $start
     * @return array{status:string,year:int,run_id:?int,error:?string}
     */
    protected function runYear(int $jobId, array $params, int $supplierId, int $index, array $planYears, array $steps, array &$totals, callable $start): array
    {
        $token = (string) ($params['token'] ?? '');
        $mode = ($params['mode'] ?? '') === 'import' ? 'import' : 'dry_run';
        $dryRun = $mode === 'dry_run';
        $year = $planYears[$index];
        $total = count($planYears);
        $label = $total > 1 ? sprintf('Rok %d (%d z %d)', $year, $index + 1, $total) : "Rok {$year}";
        $offset = count($steps) * $index;
        $runId = null;
        try {
            $started = $start();
            $runId = $started['run_id'];

            $this->jobs->updateProgress($jobId, [
                'processed' => $offset,
                'current_step' => mb_substr($label . ': ' . ($dryRun ? 'zkouška nanečisto běží' : 'připravuji převod'), 0, 120),
            ]);
            $this->jobs->appendLog($jobId, $started['log']);

            $progress = $dryRun ? null : $this->progressCallback($jobId, $steps, $offset, $label);
            $cancel = $dryRun ? null : $this->cancelCallback($jobId);

            $protocol = ($started['import'])($progress, $cancel);
            $result = $protocol->toArray() + ['kind' => $started['kind']];
            if ($total > 1) {
                $result['job_years'] = ['years' => $planYears, 'index' => $index + 1, 'dry_run_isolated' => $dryRun];
            }
            $cancelled = $result['failure'] === 'cancelled';
            $status = $cancelled ? 'cancelled' : $protocol->status();
            $this->runRepository()->finishRun($runId, $supplierId, $status, $result);

            $byStep = array_column($result['steps'], null, 'key');
            $journal = isset($started['journal']) ? ($started['journal'])($byStep) : ($byStep['journal']['counts'] ?? []);
            [$errors, $warnings] = self::messageCounts($result);
            $totals['created'] += (int) ($journal['entries'] ?? 0);
            $totals['skipped'] += (int) ($journal['existing'] ?? 0);
            $totals['failed'] += $errors;
            $this->jobs->updateProgress($jobId, [
                'processed' => $offset + count($steps),
                'created_count' => $totals['created'],
                'skipped_count' => $totals['skipped'],
                'failed_count' => $totals['failed'],
                'current_step' => mb_substr($label . ': ' . ($cancelled ? 'zrušeno uživatelem' : 'hotovo'), 0, 120),
            ]);
            $this->jobs->appendLog($jobId, sprintf('%s: protokol #%d, %d chyb, %d upozornění.', $label, $runId, $errors, $warnings));
            return [
                'status' => $status,
                'year' => $year,
                'run_id' => $runId,
                'error' => $status === 'failed' ? "Převod roku {$year} nedoběhl nebo nesedí rekonciliace - podrobnosti v protokolu #{$runId}." : null,
            ];
        } catch (\Throwable $e) {
            $message = $this->failureMessage($e, sprintf('převod %s %s firmy %d, rok %d selhal', static::UPLOAD_NOUN, $token, $supplierId, $year), static::RUN_FAILED);
            if ($runId !== null) {
                $this->runRepository()->finishRun($runId, $supplierId, 'failed', ['mode' => $mode, 'status' => 'failed', 'failure' => 'unexpected', 'error' => $message, 'steps' => []]);
            }
            $this->jobs->appendLog($jobId, "{$label}: {$message}");
            return ['status' => 'failed', 'year' => $year, 'run_id' => $runId, 'error' => $message];
        }
    }

    /**
     * Zámek nahraného souboru po dobu převodu: denní úklid ani nové nahrání ho nesmaže,
     * dokud z něj převod běží.
     *
     * @return resource
     */
    protected function acquireRunUploadLock(int $supplierId, string $token, string $busyMessage)
    {
        $lock = $this->uploads()->acquireJobLock($supplierId, $token);
        if ($lock === null) {
            throw $this->sourceException('upload_busy', $busyMessage);
        }
        return $lock;
    }

    /** Uzavře běhy, které po sobě nechal spadlý worker, a zapíše to do logu jobu. */
    protected function closeInterruptedRuns(int $jobId, int $supplierId): void
    {
        $interrupted = $this->runRepository()->closeInterruptedRuns($supplierId);
        if ($interrupted > 0) {
            $this->jobs->appendLog($jobId, "Uzavřeno {$interrupted} přerušených běhů převodu.");
        }
    }

    /**
     * Hlášení průběhu převodu do řádku jobu. Krok mimo seznam kroků (závěr) je „vše hotovo".
     *
     * @param list<string> $steps
     * @param string|null $label předpona textu kroku (rok u převodu více roků)
     * @return callable(string,int,int):void
     */
    protected function progressCallback(int $jobId, array $steps, int $offset, ?string $label): callable
    {
        return function (string $step, int $done, int $all) use ($jobId, $steps, $offset, $label): void {
            $position = array_search($step, $steps, true);
            $text = static::STEP_LABELS[$step] ?? $step;
            $this->jobs->updateProgress($jobId, [
                'processed' => $offset + ($position === false ? count($steps) : (int) $position),
                'current_step' => mb_substr($label !== null ? $label . ': ' . $text : $text, 0, 120),
            ]);
        };
    }

    /** @return callable():bool požádal uživatel o zrušení? */
    protected function cancelCallback(int $jobId): callable
    {
        return fn (): bool => $this->jobs->isCancelRequested($jobId);
    }

    /** Uzavře job podle výsledného stavu převodu. */
    protected function finishJob(int $jobId, string $status, string $failedMessage): void
    {
        if ($status === 'cancelled') {
            $this->jobs->markCancelled($jobId);
        } elseif ($status === 'failed') {
            $this->jobs->markFailed($jobId, $failedMessage);
        } elseif ($status === 'completed_with_warnings') {
            $this->jobs->markCompletedWithWarnings($jobId);
        } else {
            $this->jobs->markCompleted($jobId);
        }
    }

    /**
     * Počet chyb a upozornění v protokolu převodu.
     *
     * @param array{steps:list<array{messages:list<array{level:string}>}>} $result
     * @return array{int,int} [chyby, upozornění]
     */
    public static function messageCounts(array $result): array
    {
        $errors = 0;
        $warnings = 0;
        foreach ($result['steps'] as $s) {
            foreach ($s['messages'] as $m) {
                $errors += $m['level'] === 'error' ? 1 : 0;
                $warnings += $m['level'] === 'warning' ? 1 : 0;
            }
        }
        return [$errors, $warnings];
    }

    /**
     * Text chyby pro uživatele: u výjimky zdroje její text, jinak obecná hláška a celá
     * výjimka do logu serveru (`<zdroj>: <co> selhalo: <výjimka>`).
     */
    protected function failureMessage(\Throwable $e, string $what, string $fallback): string
    {
        if ($this->isSourceException($e)) {
            return $e->getMessage();
        }
        error_log(static::LOG_PREFIX . ': ' . $what . ': ' . (string) $e);
        return $fallback;
    }

    protected function isSourceException(\Throwable $e): bool
    {
        return is_a($e, static::EXCEPTION_CLASS);
    }

    /** @param array<string,mixed> $context */
    protected function sourceException(string $code, string $message, array $context = [], int $status = 422): \RuntimeException
    {
        $class = static::EXCEPTION_CLASS;
        return new $class($code, $message, $context, $status);
    }
}
