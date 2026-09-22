<?php

declare(strict_types=1);

namespace MyInvoice\Service\Migration\Pohoda;

use MyInvoice\Repository\ImportJobRepository;
use MyInvoice\Repository\PohodaImportRepository;
use MyInvoice\Service\ActivityLogger;
use MyInvoice\Service\Migration\ImportYears;
use MyInvoice\Service\Migration\Pohoda\Payroll\PohodaPayrollImporter;

/**
 * Převod z POHODY na pozadí (`import_jobs.source = pohoda_import`, migrace 1844).
 *
 * Job dělá dvě věci: zpracuje nahraný ZIP exportu (`params.mode = prepare`: kontrolní
 * součet, rozbalení, přehled agend) a spustí převod vybraných roků (`params.years`
 * vzestupně, každý rok vlastní běh a protokol; {@see PohodaImporter}).
 * Převod drží po celou dobu zámek firmy ({@see PohodaImportRepository::acquireLock()}).
 * Zkouška nanečisto běží v jedné transakci, průběh do řádku jobu během ní nezapisuje
 * (stejně jako u Money S3).
 */
final class PohodaImportJobService
{
    public const SOURCE = 'pohoda_import';
    public const MODE_PREPARE = 'prepare';
    private const PREPARE_FAILED = 'Export z POHODY se nepodařilo přečíst.';

    private const STEP_LABELS = [
        'chart' => 'Účtová osnova',
        'journal' => 'Účetní období a deník',
        'accounting_mode' => 'Režim účetní jednotky',
        'partners' => 'Adresář partnerů',
        'posting_rules' => 'Předkontace',
        'purchase_invoices' => 'Přijaté doklady',
        'issued_invoices' => 'Vydané doklady',
        'internal_tax_documents' => 'Daňové doklady k platbám',
        'cash' => 'Pokladna',
        'bank' => 'Banka',
        'link' => 'Vazby dokladů na deník',
        'payments' => 'Úhrady dokladů',
        'assets' => 'Dlouhodobý majetek',
        'payroll_preflight' => 'Kontrola před převodem mezd',
        'payroll_profile' => 'Profil importu mezd',
        'payroll_months' => 'Mzdy po měsících',
        'payroll_people' => 'Údaje osob a vztahů',
        'payroll_deductions' => 'Srážky, exekuce a insolvence',
        'payroll_sickness' => 'Nemocenská a náhrady mzdy',
        'payroll_posting_map' => 'Kontace z původního programu',
        'small_assets' => 'Drobný majetek',
        'reconciliation' => 'Rekonciliace',
        'done' => 'Dokončuji',
    ];

    public function __construct(
        private readonly ImportJobRepository $jobs,
        private readonly PohodaImportRepository $runs,
        private readonly PohodaImporter $importer,
        private readonly ActivityLogger $logger,
        private readonly PohodaPayrollImporter $payroll,
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

    /**
     * Vybrané roky jdou vzestupně, každý s vlastním záznamem běhu a protokolem. Rok, který
     * skončí chybou nebo zrušením, zastaví i roky po něm (stavěly by na neúplném základu).
     * Výsledný stav jobu je nejhorší ze stavů roků.
     *
     * @param array<string,mixed> $job
     */
    private function runLocked(int $jobId, array $job, int $supplierId): void
    {
        $userId = (int) ($job['created_by'] ?? 0);
        $params = is_array($job['params'] ?? null) ? $job['params'] : [];
        $token = (string) ($params['token'] ?? '');
        $dryRun = ($params['mode'] ?? '') !== 'import';
        // Mzdy jsou vlastní akce průvodce (i pro export, ve kterém jsou jen mzdy).
        $payroll = ($params['kind'] ?? '') === 'payroll';
        $ico = (string) ($params['ico'] ?? '');
        $lock = null;
        $purge = false;

        try {
            // Zámek exportu: denní úklid ani nové nahrání nesmaže export, ze kterého se převádí.
            $lock = PohodaUploads::acquireJobLock($supplierId, $token);
            if ($lock === null) {
                throw new PohodaException('upload_busy', 'Export právě zpracovává jiný proces, nebo už byl uklizen - nahrajte ho znovu.');
            }
            $interrupted = $this->runs->closeInterruptedRuns($supplierId);
            if ($interrupted > 0) {
                $this->jobs->appendLog($jobId, "Uzavřeno {$interrupted} přerušených běhů převodu.");
            }
            $meta = PohodaUploads::meta($supplierId, $token);
            $plan = self::plan($meta, $ico, ImportYears::fromParams($params), $payroll, $supplierId, $token);
            $planYears = array_column($plan, 'year');
            $total = count($plan);
            if ($total > 1) {
                $this->jobs->appendLog($jobId, 'Převádí se ' . $total . ' roky vzestupně: ' . implode(', ', $planYears) . '.');
                if ($dryRun) {
                    $this->jobs->appendLog($jobId, ImportYears::DRY_RUN_NOTE);
                }
            }
            $steps = $payroll ? PohodaPayrollImporter::stepKeys() : PohodaImporter::stepKeys();
            $this->jobs->updateProgress($jobId, [
                'total_items' => count($steps) * $total,
                'processed' => 0,
                'current_step' => $dryRun ? 'Zkouška nanečisto běží' : 'Připravuji převod',
            ]);

            $totals = ['created' => 0, 'skipped' => 0, 'failed' => 0];
            $results = [];
            foreach ($plan as $index => $item) {
                if ($index > 0 && $this->jobs->isCancelRequested($jobId)) {
                    $results[] = ['status' => 'cancelled', 'year' => $item['year'], 'run_id' => null, 'error' => null];
                    $this->jobs->appendLog($jobId, 'Převod zrušen, roky ' . implode(', ', array_slice($planYears, $index)) . ' se nespouštějí.');
                    break;
                }
                $result = $this->runYear($jobId, $params, $meta, $supplierId, $userId, $item, $index, $planYears, $steps, $totals);
                $results[] = $result;
                if (in_array($result['status'], ['failed', 'cancelled'], true)) {
                    $rest = array_slice($planYears, $index + 1);
                    if ($rest !== []) {
                        $this->jobs->appendLog($jobId, sprintf('Rok %d skončil %s, roky %s se nespouštějí.',
                            $item['year'], $result['status'] === 'failed' ? 'chybou' : 'zrušením', implode(', ', $rest)));
                    }
                    break;
                }
            }
            $status = ImportYears::worstStatus(array_column($results, 'status'));
            $this->jobs->updateProgress($jobId, ['current_step' => $status === 'cancelled' ? 'Zrušeno uživatelem' : 'Hotovo']);
            $failed = array_values(array_filter($results, static fn (array $r): bool => $r['status'] === 'failed'));
            if ($status === 'cancelled') {
                $this->jobs->markCancelled($jobId);
            } elseif ($status === 'failed') {
                $this->jobs->markFailed($jobId, (string) ($failed[0]['error'] ?? 'Převod se nepodařil.'));
            } elseif ($status === 'completed_with_warnings') {
                $this->jobs->markCompletedWithWarnings($jobId);
            } else {
                $this->jobs->markCompleted($jobId);
            }
            // Export se po převodu smaže, jen když z něj nic nezbývá: prošly všechny agendy
            // firmy a žádná nemá druhou část (účetnictví / mzdy).
            $purge = !$dryRun && ($status === 'completed' || $status === 'completed_with_warnings')
                && self::nothingLeft($meta, $ico, $planYears, $payroll);
        } catch (\Throwable $e) {
            if ($e instanceof PohodaException) {
                $message = $e->getMessage();
            } else {
                error_log(sprintf('POHODA: převod exportu %s firmy %d selhal: %s', $token, $supplierId, (string) $e));
                $message = 'Převod z POHODY selhal na neočekávané chybě, podrobnosti jsou v logu serveru.';
            }
            $this->jobs->markFailed($jobId, $message);
        } finally {
            if ($lock !== null) {
                PohodaUploads::releaseJobLock($lock);
            }
        }
        // Až po uvolnění zámku - otevřený soubor zámku by na Windows adresář nechal viset.
        if ($purge) {
            PohodaUploads::purge($supplierId, $token);
        }
    }

    /**
     * Převod jednoho roku (agendy) s vlastním záznamem běhu a protokolem.
     *
     * @param array<string,mixed> $params
     * @param array<string,mixed> $meta
     * @param array{year:int,skip:list<int>,later:list<int>} $item
     * @param list<int> $planYears
     * @param list<string> $steps
     * @param array{created:int,skipped:int,failed:int} $totals MĚNÍ SE: počty za celý job
     * @return array{status:string,year:int,run_id:?int,error:?string}
     */
    private function runYear(int $jobId, array $params, array $meta, int $supplierId, int $userId, array $item, int $index, array $planYears, array $steps, array &$totals): array
    {
        $token = (string) ($params['token'] ?? '');
        $mode = ($params['mode'] ?? '') === 'import' ? 'import' : 'dry_run';
        $dryRun = $mode === 'dry_run';
        $payroll = ($params['kind'] ?? '') === 'payroll';
        $year = $item['year'];
        $total = count($planYears);
        $label = $total > 1 ? sprintf('Rok %d (%d z %d)', $year, $index + 1, $total) : "Rok {$year}";
        $offset = count($steps) * $index;
        $runId = null;
        try {
            $agenda = self::agenda($meta, (string) ($params['ico'] ?? ''), $year);
            if ($agenda === null) {
                throw new PohodaException('agenda_not_found', "Export neobsahuje agendu roku {$year} této firmy.");
            }
            $agendaDir = PohodaUploads::exportDir($supplierId, $token) . DIRECTORY_SEPARATOR . $agenda['dir'];
            if ($payroll && !$agenda['has_payroll']) {
                throw new PohodaException('payroll_missing', "Export roku {$year} neobsahuje mzdy (91_mzdy.xml).");
            }
            if (!$payroll && !$agenda['has_accounting']) {
                throw new PohodaException('accounting_missing', "Export roku {$year} obsahuje jen mzdy, účetnictví v něm není.");
            }
            $export = $payroll ? null : PohodaExport::open($agendaDir);
            $runId = $this->runs->startRun($supplierId, $jobId, $mode, [
                'ico' => $export !== null ? $export->ico : $agenda['ico'],
                'year' => $export !== null ? $export->year : $agenda['year'],
                'program' => $export !== null ? $export->info['program'] : 'POHODA Mzdy',
                'sha256' => $meta['sha256'] ?? null,
            ], $userId > 0 ? $userId : null);

            $this->jobs->updateProgress($jobId, [
                'processed' => $offset,
                'current_step' => mb_substr($label . ': ' . ($dryRun ? 'zkouška nanečisto běží' : 'připravuji převod'), 0, 120),
            ]);
            $included = array_values(array_diff($item['later'], $item['skip']));
            $this->jobs->appendLog($jobId, ($dryRun ? 'Zkouška nanečisto' : 'Ostrý převod') . ($payroll ? ' mezd' : ' agendy')
                . " IČO {$agenda['ico']}, rok {$agenda['year']}"
                . ($included !== [] ? ' (včetně dokladů roku ' . implode(', ', $included) . ')' : '')
                . ($item['skip'] !== [] ? ', bez dokladů nevybraného roku ' . implode(', ', $item['skip']) : '') . '.');

            $progress = $dryRun ? null : function (string $step, int $done, int $all) use ($jobId, $steps, $offset, $label): void {
                $position = array_search($step, $steps, true);
                $this->jobs->updateProgress($jobId, [
                    'processed' => $offset + ($position === false ? count($steps) : (int) $position),
                    'current_step' => mb_substr($label . ': ' . (self::STEP_LABELS[$step] ?? $step), 0, 120),
                ]);
            };
            $cancel = $dryRun ? null : fn (): bool => $this->jobs->isCancelRequested($jobId);

            $protocol = $export === null
                ? $this->payroll->run($supplierId, $userId, $agendaDir . DIRECTORY_SEPARATOR . PohodaExport::FILES['payroll'], (int) $agenda['year'], $dryRun, $runId, $progress, $cancel,
                    (bool) ($params['confirm_identifiers'] ?? false), (bool) ($params['approve_taken_over'] ?? false))
                : $this->importer->run($supplierId, $userId, $export, $dryRun, $runId, $progress, $cancel, $item['skip']);
            $result = $protocol->toArray() + ['kind' => $payroll ? 'payroll' : 'accounting'];
            if ($total > 1) {
                $result['job_years'] = ['years' => $planYears, 'index' => $index + 1, 'dry_run_isolated' => $dryRun];
            }
            $cancelled = $result['failure'] === 'cancelled';
            $status = $cancelled ? 'cancelled' : $protocol->status();
            $this->runs->finishRun($runId, $supplierId, $status, $result);

            $byStep = array_column($result['steps'], null, 'key');
            $journal = $payroll
                ? ['entries' => $byStep[PohodaPayrollImporter::STEP_MONTHS]['counts']['months'] ?? 0, 'existing' => $byStep[PohodaPayrollImporter::STEP_MONTHS]['counts']['existing'] ?? 0]
                : $byStep['journal']['counts'] ?? [];
            $errors = 0;
            $warnings = 0;
            foreach ($result['steps'] as $s) {
                foreach ($s['messages'] as $m) {
                    $errors += $m['level'] === 'error' ? 1 : 0;
                    $warnings += $m['level'] === 'warning' ? 1 : 0;
                }
            }
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
            if ($e instanceof PohodaException) {
                $message = $e->getMessage();
            } else {
                error_log(sprintf('POHODA: převod exportu %s firmy %d, rok %d selhal: %s', $token, $supplierId, $year, (string) $e));
                $message = 'Převod z POHODY selhal na neočekávané chybě, podrobnosti jsou v logu serveru.';
            }
            if ($runId !== null) {
                $this->runs->finishRun($runId, $supplierId, 'failed', ['mode' => $mode, 'status' => 'failed', 'failure' => 'unexpected', 'error' => $message, 'steps' => []]);
            }
            $this->jobs->appendLog($jobId, "{$label}: {$message}");
            return ['status' => 'failed', 'year' => $year, 'run_id' => $runId, 'error' => $message];
        }
    }

    /**
     * Plán převodu vybraných roků: agendy vzestupně a u každé roky po ní, které vede
     * (`later`) a které se nepřevádějí (`skip`). Rok bez vlastní agendy je jen pozdější rok
     * agendy (POHODA vede doklady i po 31. 12.) - převést ho jde jen s rokem té agendy.
     *
     * @param array<string,mixed> $meta
     * @param list<int> $years
     * @return list<array{year:int,skip:list<int>,later:list<int>}>
     * @throws PohodaException `invalid_year` / `invalid_kind` (kód 422)
     */
    public static function plan(array $meta, string $ico, array $years, bool $payroll, int $supplierId, string $token): array
    {
        if ($years === []) {
            throw new PohodaException('invalid_year', 'Vyberte aspoň jeden rok převodu.', [], 422);
        }
        $plan = [];
        $covered = [];
        foreach ($years as $year) {
            $agenda = self::agenda($meta, $ico, $year);
            if ($agenda === null) {
                continue;
            }
            if ($payroll ? !$agenda['has_payroll'] : !$agenda['has_accounting']) {
                throw new PohodaException('invalid_kind', $payroll
                    ? "Export roku {$year} neobsahuje mzdy (91_mzdy.xml)."
                    : "Export roku {$year} obsahuje jen mzdy, účetnictví v něm není.", ['year' => $year], 422);
            }
            $later = $payroll ? [] : self::laterYears($agenda, $supplierId, $token);
            $plan[] = ['year' => $year, 'skip' => array_values(array_diff($later, $years)), 'later' => $later];
            foreach ($later as $y) {
                $covered[$y] = true;
            }
        }
        foreach ($years as $year) {
            if (self::agenda($meta, $ico, $year) === null && !isset($covered[$year])) {
                throw new PohodaException('invalid_year', "Export neobsahuje agendu roku {$year} s IČO této firmy a vybraná agenda předchozího roku doklady roku {$year} nevede."
                    . ' Pozdější rok agendy jde převést jen spolu s rokem agendy.', ['year' => $year], 422);
            }
        }
        return $plan;
    }

    /**
     * Roky po roce agendy z přehledu exportu; přehled nahraný před výběrem roků je nemá,
     * pak se čtou z deníku agendy.
     *
     * @param array{dir:string,later_years:?list<int>} $agenda
     * @return list<int>
     */
    public static function laterYears(array $agenda, int $supplierId, string $token): array
    {
        if ($agenda['later_years'] !== null) {
            return $agenda['later_years'];
        }
        return ChartJournalImporter::laterYears(PohodaExport::open(PohodaUploads::exportDir($supplierId, $token) . DIRECTORY_SEPARATOR . $agenda['dir']));
    }

    /**
     * Z exportu po převodu nic nezbývá: prošly všechny agendy firmy a žádná nemá druhou
     * část (účetnictví / mzdy), kterou převádí druhý průvodce.
     *
     * @param array<string,mixed> $meta
     * @param list<int> $done
     */
    private static function nothingLeft(array $meta, string $ico, array $done, bool $payroll): bool
    {
        foreach ((array) ($meta['agendas'] ?? []) as $a) {
            if ($ico !== '' && (string) ($a['ico'] ?? '') !== $ico) {
                continue;
            }
            $agenda = self::agenda($meta, $ico, (int) ($a['year'] ?? 0));
            if ($agenda === null || !in_array($agenda['year'], $done, true) || ($payroll ? $agenda['has_accounting'] : $agenda['has_payroll'])) {
                return false;
            }
        }
        return true;
    }

    /**
     * Agenda zvoleného roku z přehledu nahraného exportu.
     *
     * @param array<string,mixed> $meta
     * @return array{dir:string,ico:string,year:int,has_accounting:bool,has_payroll:bool,later_years:?list<int>}|null
     */
    public static function agenda(array $meta, string $ico, int $year): ?array
    {
        foreach ((array) ($meta['agendas'] ?? []) as $a) {
            if ((int) ($a['year'] ?? 0) === $year && ($ico === '' || (string) ($a['ico'] ?? '') === $ico)) {
                $later = $a['counts']['later_years'] ?? null;
                return [
                    'dir' => (string) $a['dir'],
                    'ico' => (string) $a['ico'],
                    'year' => (int) $a['year'],
                    // Přehled nahraný před podporou mezd klíče nemá: to byla vždy agenda účetnictví.
                    'has_accounting' => (bool) ($a['has_accounting'] ?? true),
                    'has_payroll' => (bool) ($a['has_payroll'] ?? false),
                    // Přehled nahraný před výběrem roků je nemá ({@see laterYears()}).
                    'later_years' => is_array($later) ? array_values(array_map('intval', $later)) : null,
                ];
            }
        }
        return null;
    }

    /**
     * ZIP exportu nahraný po částech: kontrolní součet, rozbalení (jen XML agend), přehled
     * agend a `meta.json`. Chyba se uloží do stavu nahrávání, odkud ji průvodce ukáže.
     *
     * @param array<string,mixed> $job
     */
    private function prepare(int $jobId, array $job, int $supplierId): void
    {
        $params = (array) $job['params'];
        $token = (string) ($params['token'] ?? '');
        try {
            $lock = PohodaUploads::acquireJobLock($supplierId, $token);
        } catch (PohodaException $e) {
            $this->jobs->markFailed($jobId, $e->getMessage());
            return;
        }
        if ($lock === null) {
            $this->jobs->markFailed($jobId, 'Export už zpracovává jiný proces.');
            return;
        }
        try {
            $state = PohodaUploads::state($supplierId, $token);
            if ($state === null) {
                throw new PohodaException('upload_not_found', 'Nahraný export nebyl nalezen (mohl být už uklizen).', [], 404);
            }
            $size = (int) ($state['size'] ?? 0);
            if (PohodaUploads::partSize($supplierId, $token) !== $size || $size <= 0) {
                throw new PohodaException('upload_incomplete', 'Export není nahraný celý, nahrajte ho znovu.');
            }
            PohodaUploads::updateState($supplierId, $token, ['status' => PohodaUploads::STATUS_PROCESSING, 'job_id' => $jobId, 'error' => null]);
            $this->jobs->updateProgress($jobId, ['total_items' => 3, 'processed' => 0, 'current_step' => 'Kontrolní součet exportu']);
            $part = PohodaUploads::partPath($supplierId, $token);
            $sha = (string) hash_file('sha256', $part);

            $this->jobs->updateProgress($jobId, ['processed' => 1, 'current_step' => 'Rozbaluji export']);
            $root = PohodaUploads::exportDir($supplierId, $token);
            PohodaExport::extractArchive($part, $root);
            @unlink($part);

            $this->jobs->updateProgress($jobId, ['processed' => 2, 'current_step' => 'Čtu agendy exportu']);
            $agendas = PohodaExport::overview($root);
            if ($agendas === []) {
                throw new PohodaException('no_agenda', 'ZIP neobsahuje export agendy z POHODY (složku IČO_rok s účetním deníkem). Nahrajte ZIP, který vytvořil exportní nástroj.');
            }
            $userId = (int) ($state['uploaded_by'] ?? 0);
            PohodaUploads::writeMeta($supplierId, $token, [
                'token' => $token,
                'file_name' => (string) ($state['file_name'] ?? 'pohoda_export.zip'),
                'sha256' => $sha,
                'uploaded_at' => date('c'),
                'uploaded_by' => $userId,
                'agendas' => $agendas,
            ]);
            PohodaUploads::updateState($supplierId, $token, ['status' => PohodaUploads::STATUS_READY, 'error' => null]);
            $this->logger->log('import.pohoda_uploaded', $userId > 0 ? $userId : null, 'supplier', $supplierId,
                ['agendas' => array_map(static fn (array $a): string => $a['ico'] . '/' . $a['year'], $agendas)],
                isset($params['ip']) ? (string) $params['ip'] : null,
                isset($params['user_agent']) ? (string) $params['user_agent'] : null);

            $this->jobs->updateProgress($jobId, ['processed' => 3, 'current_step' => 'Hotovo']);
            $this->jobs->appendLog($jobId, 'Export z POHODY načten (' . count($agendas) . ' agend).');
            $this->jobs->markCompleted($jobId);
        } catch (\Throwable $e) {
            if ($e instanceof PohodaException) {
                $message = $e->getMessage();
            } else {
                error_log(sprintf('POHODA: zpracování exportu %s firmy %d selhalo: %s', $token, $supplierId, (string) $e));
                $message = self::PREPARE_FAILED;
            }
            PohodaUploads::discardData($supplierId, $token);
            try {
                PohodaUploads::updateState($supplierId, $token, ['status' => PohodaUploads::STATUS_FAILED, 'error' => $message]);
            } catch (\Throwable) {
                // adresář exportu mezitím zmizel - chyba zůstane aspoň u jobu
            }
            $this->jobs->markFailed($jobId, $message);
        } finally {
            PohodaUploads::releaseJobLock($lock);
        }
    }
}
