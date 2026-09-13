<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Import\Registration;

use MyInvoice\Repository\Payroll\PayrollEmploymentRepository;
use MyInvoice\Service\License\LicensePayrollLimitExceeded;
use MyInvoice\Service\Payroll\Import\ImportFiles;
use MyInvoice\Service\Payroll\Import\Jmhz\JmhzAveragePlanner;
use MyInvoice\Service\Payroll\Import\Jmhz\JmhzBatch;
use MyInvoice\Service\Payroll\Import\Jmhz\JmhzBatchItem;
use MyInvoice\Service\Payroll\Import\Jmhz\JmhzOpeningBalancePlanner;
use MyInvoice\Service\Payroll\Import\Jmhz\JmhzReportLookup;
use MyInvoice\Service\Payroll\Import\Jmhz\JmhzReportPlanner;
use MyInvoice\Service\Payroll\Import\Jmhz\JmhzReportReader;
use MyInvoice\Service\Payroll\Import\Jmhz\JmhzReportWriter;

/**
 * Import registrací ČSSZ (REGZEC25, PREZEC26) a měsíčních hlášení JMHZ
 * jiného mzdového programu do mzdové evidence: náhled a použití vybraných vět.
 *
 * Soubor se rozpozná podle kořenového elementu; v jedné dávce mohou být
 * registrace i hlášení za víc měsíců.
 *
 * Náhled nic nezapisuje. Použití si soubory přečte a každou vybranou větu či
 * formulář naplánuje znovu nad AKTUÁLNÍM stavem evidence těsně před zápisem —
 * věty se tak navzájem vidí (přihlášení v jednom souboru, hlášení téže osoby
 * v druhém) a klientovi se nevěří nic kromě seznamu vybraných klíčů a ručního
 * párování formulářů s pracovními vztahy. Počáteční stavy a průměry se
 * počítají až po zápisu formulářů, aby viděly identifikátory, které právě
 * vznikly.
 */
final class RegistrationImportService
{
    private const ENVIRONMENTS = ['production', 'test'];
    private const KEY_PATTERN = '/^[0-9a-f]{16}:[0-9]{1,5}$/D';
    private const AUTO_CHANGE_NOTE = 'Odškrtnuto importem: změnu už vykázalo importované podání.';

    public function __construct(
        private readonly RegistrationXmlReader $reader,
        private readonly RegistrationImportPlanner $planner,
        private readonly RegistrationImportWriter $writer,
        private readonly JmhzReportReader $jmhzReader,
        private readonly JmhzReportPlanner $jmhzPlanner,
        private readonly JmhzReportWriter $jmhzWriter,
        private readonly JmhzOpeningBalancePlanner $openingPlanner,
        private readonly JmhzAveragePlanner $averagePlanner,
        private readonly JmhzReportLookup $jmhzLookup,
        private readonly PayrollEmploymentRepository $employments,
    ) {}

    /** @return array<string,mixed> */
    public function preview(int $supplierId, string $environment, mixed $files, mixed $pairs = null): array
    {
        $this->environment($environment);
        $pairMap = $this->pairs($pairs);
        $read = $this->read($files);
        $records = [];
        foreach ($read['registrations'] as $item) {
            $records[$this->order($item['file_index'], $item['record']->position)] = self::publicPlan($this->planner->plan(
                $supplierId,
                $environment,
                $item['record'],
                $item['file'],
                $item['sha256'],
            ));
        }
        $jmhzPlans = $this->planJmhz($supplierId, $environment, $read['batch'], $pairMap);
        foreach ($jmhzPlans as $plan) {
            /** @var JmhzBatchItem $item */
            $item = $plan['_item'];
            $records[$this->order($item->fileIndex, $item->form->position)] = self::publicPlan($plan);
        }
        ksort($records, SORT_STRING);
        $records = array_values($records);

        return [
            'environment' => $environment,
            'files' => $read['files'],
            'records' => $records,
            'summary' => $this->summary($records),
            'employment_options' => $read['has_jmhz'] ? $this->jmhzLookup->employmentOptions($supplierId) : [],
            'opening_balances' => $read['has_jmhz']
                ? $this->openingPlanner->preview($supplierId, array_values($jmhzPlans), $read['batch'])
                : [],
            'averages' => $read['has_jmhz']
                ? $this->averagePlanner->preview($supplierId, array_values($jmhzPlans))
                : [],
        ];
    }

    /**
     * @param mixed $keys
     * @return array{
     *   results:list<array<string,mixed>>,
     *   summary:array{applied:int,failed:int,skipped:int},
     *   opening_balances:array{saved:int,skipped:list<array<string,mixed>>},
     *   averages:array{created:int,approved:int,skipped:list<array<string,mixed>>},
     *   change_checklist:array{completed:int,failed:list<array{employment_id:int,item_key:string,message:string}>}
     * }
     */
    public function apply(
        int $supplierId,
        string $environment,
        mixed $files,
        mixed $keys,
        bool $evidenceConfirmed,
        ?int $officeId,
        ?int $userId,
        ?string $ip,
        ?string $userAgent,
        mixed $pairs = null,
        bool $applyOpeningBalances = false,
        bool $applyAverages = false,
        bool $autoApproveChanges = false,
        bool $autoApproveAverages = false,
    ): array {
        $this->environment($environment);
        if (!$evidenceConfirmed) {
            throw new \InvalidArgumentException(
                'Potvrďte, že soubory odpovídají podáním přijatým ČSSZ. Import podle nich zapisuje '
                . 'identifikátory OIČ a ID PPV jako ověřený opis, stejně jako ruční zadání z protokolu.',
            );
        }
        if (!is_array($keys) || !array_is_list($keys)) {
            throw new \InvalidArgumentException('Vyberte aspoň jednu větu, která se má zapsat.');
        }
        if ($keys === [] && !$applyOpeningBalances && !$applyAverages) {
            throw new \InvalidArgumentException('Vyberte aspoň jednu větu, která se má zapsat.');
        }
        $selected = [];
        foreach ($keys as $key) {
            if (!is_string($key) || preg_match(self::KEY_PATTERN, $key) !== 1) {
                throw new \InvalidArgumentException('Seznam vybraných vět obsahuje neplatný klíč.');
            }
            $selected[$key] = true;
        }
        $pairMap = $this->pairs($pairs);
        if ($officeId !== null && $officeId <= 0) {
            throw new \InvalidArgumentException('Mzdová účtárna není platná.');
        }

        $read = $this->read($files);
        $byKey = [];
        foreach ($read['registrations'] as $item) {
            $byKey[RegistrationImportPlanner::key($item['sha256'], $item['record']->position)] = $item;
        }
        $ordered = [];
        foreach (array_keys($selected) as $key) {
            if (isset($byKey[$key])) {
                $ordered[] = $byKey[$key];
            }
        }
        // Přihlášení musí jít před změnou a odhlášením téže osoby, i když
        // leží v souborech v jiném pořadí.
        usort($ordered, static fn (array $a, array $b): int => [
            $a['record']->decisiveDate() ?? '9999-12-31',
            $a['record']->documentType === 'PREZEC26' ? 0 : 1,
            $a['file_index'],
            $a['record']->position,
        ] <=> [
            $b['record']->decisiveDate() ?? '9999-12-31',
            $b['record']->documentType === 'PREZEC26' ? 0 : 1,
            $b['file_index'],
            $b['record']->position,
        ]);

        $changesSince = $autoApproveChanges ? $this->employments->databaseNow() : null;
        $results = [];
        foreach (array_keys($selected) as $key) {
            if (!isset($byKey[$key]) && $read['batch']->item($key) === null) {
                $results[$key] = $this->result($key, 'skipped', 'Věta s tímto klíčem v nahraných souborech není. '
                    . 'Nahrajte soubory znovu a obnovte náhled.');
            }
        }
        foreach ($ordered as $item) {
            $plan = $this->planner->plan(
                $supplierId,
                $environment,
                $item['record'],
                $item['file'],
                $item['sha256'],
            );
            $key = (string) $plan['key'];
            if ($plan['blocker'] !== null) {
                $results[$key] = $this->result($key, 'skipped', (string) $plan['blocker'], $plan);
                continue;
            }
            if (!$plan['selectable']) {
                $results[$key] = $this->result($key, 'skipped', 'Věta nemá co zapsat — evidence už odpovídá.', $plan);
                continue;
            }
            try {
                $applied = $this->writer->apply($supplierId, $environment, $plan, $officeId, $userId, $ip, $userAgent);
                $results[$key] = ['key' => $key] + $applied;
            } catch (LicensePayrollLimitExceeded) {
                $results[$key] = $this->result($key, 'failed', 'Dalšího aktivního zaměstnance lze přidat až po '
                    . 'rozšíření mzdového doplňku.', $plan);
            } catch (\Exception $e) {
                $results[$key] = $this->result($key, 'failed', $e->getMessage(), $plan);
            }
        }

        $batch = $read['batch'];
        // Konflikty (dva formuláře na tentýž vztah a měsíc) se určí jednou
        // nad celou dávkou; jednotlivé formuláře se pak plánují znovu těsně
        // před zápisem.
        $this->planJmhz($supplierId, $environment, $batch, $pairMap);
        $jmhzSelected = array_values(array_filter(
            $batch->items(),
            static fn (JmhzBatchItem $item): bool => isset($selected[$item->key]),
        ));
        usort($jmhzSelected, static fn (JmhzBatchItem $a, JmhzBatchItem $b): int => $a->sortKey() <=> $b->sortKey());
        foreach ($jmhzSelected as $item) {
            $plan = $this->jmhzPlanner->plan($supplierId, $environment, $item, $batch, $pairMap[$item->key] ?? null);
            $key = $item->key;
            if ($plan['blocker'] !== null) {
                $results[$key] = $this->result($key, 'skipped', (string) $plan['blocker'], $plan);
                continue;
            }
            if ($plan['operation'] === 'pair_required') {
                $results[$key] = $this->result($key, 'skipped', 'Formulář není spárovaný s pracovním vztahem. '
                    . 'Vyberte vztah, ke kterému patří, a použití zopakujte.', $plan);
                continue;
            }
            if (!$plan['selectable']) {
                $results[$key] = $this->result(
                    $key,
                    'skipped',
                    $batch->note($key) ?? 'Formulář nemá co zapsat — evidence už odpovídá.',
                    $plan,
                );
                continue;
            }
            try {
                $applied = $this->jmhzWriter->apply($supplierId, $environment, $plan, $userId, $ip, $userAgent);
                $results[$key] = ['key' => $key] + $applied;
            } catch (\Exception $e) {
                $results[$key] = $this->result($key, 'failed', $e->getMessage(), $plan);
            }
        }

        $checklist = ['completed' => 0, 'failed' => []];
        if ($changesSince !== null) {
            $checklist = $this->completeImportedChanges($supplierId, $results, $changesSince, $userId, $ip, $userAgent);
        }

        $list = [];
        foreach (array_keys($selected) as $key) {
            if (isset($results[$key])) {
                $list[] = $results[$key];
            }
        }
        $summary = ['applied' => 0, 'failed' => 0, 'skipped' => 0];
        foreach ($list as $row) {
            $summary[$row['status']]++;
        }

        $openings = ['saved' => 0, 'skipped' => []];
        $averages = ['created' => 0, 'approved' => 0, 'skipped' => []];
        if (($applyOpeningBalances || $applyAverages) && $batch->items() !== []) {
            $fresh = array_values($this->planJmhz($supplierId, $environment, $batch, $pairMap));
            if ($applyOpeningBalances) {
                $openings = $this->openingPlanner->apply($supplierId, $fresh, $batch, $userId);
            }
            if ($applyAverages) {
                $averages = $this->averagePlanner->apply($supplierId, $fresh, $userId, $autoApproveAverages);
            }
        }

        return [
            'results' => $list,
            'summary' => $summary,
            'opening_balances' => $openings,
            'averages' => $averages,
            'change_checklist' => $checklist,
        ];
    }

    /**
     * Povinnosti ke změně (dodatek, oznámení pojišťovně a ČSSZ), které založila
     * nová verze podmínek z importu, odškrtne jako splněné: změnu už vykázalo
     * importované podání. Jde přes tutéž cestu jako ruční odškrtnutí, takže
     * na kartě vztahu zůstane událost. Starší rozpracované položky a fáze
     * nástupu a skončení nechává účetní.
     *
     * @param array<string,array<string,mixed>> $results
     * @return array{completed:int,failed:list<array{employment_id:int,item_key:string,message:string}>}
     */
    private function completeImportedChanges(
        int $supplierId,
        array $results,
        string $since,
        ?int $userId,
        ?string $ip,
        ?string $userAgent,
    ): array {
        $employmentIds = [];
        foreach ($results as $row) {
            if (($row['status'] ?? null) === 'applied' && isset($row['employment_id'])) {
                $employmentIds[(int) $row['employment_id']] = true;
            }
        }
        $completed = 0;
        $failed = [];
        foreach (array_keys($employmentIds) as $employmentId) {
            foreach ($this->employments->pendingChecklistItemsSince($supplierId, $employmentId, 'change', $since) as $item) {
                try {
                    $this->employments->updateChecklist(
                        $supplierId,
                        $employmentId,
                        $item['item_key'],
                        $item['row_version'],
                        'completed',
                        self::AUTO_CHANGE_NOTE,
                        $userId,
                        $ip,
                        $userAgent,
                    );
                    $completed++;
                } catch (\Exception $e) {
                    $failed[] = [
                        'employment_id' => $employmentId,
                        'item_key' => $item['item_key'],
                        'message' => $e->getMessage(),
                    ];
                }
            }
        }

        return ['completed' => $completed, 'failed' => $failed];
    }

    /**
     * Plány všech formulářů hlášení. Dva platné formuláře, které se spárují
     * na tentýž vztah za tentýž měsíc, se zablokují oba — z dávky nejde poznat,
     * který ČSSZ přijala.
     *
     * @param array<string,int> $pairs
     * @return array<string,array<string,mixed>>
     */
    private function planJmhz(int $supplierId, string $environment, JmhzBatch $batch, array $pairs): array
    {
        $plans = [];
        foreach ($batch->items() as $item) {
            $plans[$item->key] = $this->jmhzPlanner->plan($supplierId, $environment, $item, $batch, $pairs[$item->key] ?? null);
        }
        $byEmployment = [];
        foreach ($plans as $key => $plan) {
            if ($plan['_effective'] === true && $plan['_employment_id'] !== null && $plan['blocker'] === null) {
                $byEmployment[$plan['period'] . '|' . $plan['_employment_id']][] = $key;
            }
        }
        foreach ($byEmployment as $keys) {
            if (count($keys) < 2) {
                continue;
            }
            foreach ($keys as $key) {
                $batch->markConflict($key);
                $item = $batch->item($key);
                if ($item !== null) {
                    $plans[$key] = $this->jmhzPlanner->plan($supplierId, $environment, $item, $batch, $pairs[$key] ?? null);
                }
            }
        }

        return $plans;
    }

    /**
     * @return array{
     *   files:list<array<string,mixed>>,
     *   registrations:list<array{record:RegistrationRecord,file:string,sha256:string,file_index:int}>,
     *   batch:JmhzBatch,
     *   has_jmhz:bool
     * }
     */
    private function read(mixed $files): array
    {
        $fileRows = [];
        $records = [];
        $jmhzItems = [];
        $stornos = [];
        $hasJmhz = false;
        foreach (ImportFiles::fromRequest($files, ['xml']) as $index => $file) {
            if (JmhzReportReader::isJmhz($file['content'])) {
                $hasJmhz = true;
                try {
                    $report = $this->jmhzReader->read($file['content']);
                } catch (RegistrationImportFileException $e) {
                    $fileRows[] = $this->fileRow($file, 'JMHZ', 0, $e->getMessage());
                    continue;
                }
                $warnings = $report->warnings;
                if ($report->submissionType === 'S' && $report->forms === []) {
                    $stornos[] = ['file' => $report, 'name' => $file['name']];
                    $warnings[] = 'Stornující podání za ' . $report->period() . ' — z hlášení, které ruší, se nic nepřebírá.';
                } else {
                    $warnings[] = 'ELDP a zdravotní pojištění z hlášení import nepřebírá: evidence pro ně počáteční '
                        . 'stavy nevede. Údaje ukazuje jen náhled.';
                }
                $fileRows[] = $this->fileRow($file, 'JMHZ', count($report->forms), null, $warnings, $report->period(), $report->submissionType);
                foreach ($report->forms as $form) {
                    $jmhzItems[] = new JmhzBatchItem(
                        RegistrationImportPlanner::key($file['sha256'], $form->position),
                        $report,
                        $form,
                        $file['name'],
                        $file['sha256'],
                        $index,
                    );
                }
                continue;
            }
            try {
                $read = $this->reader->read($file['content']);
            } catch (RegistrationImportFileException $e) {
                $fileRows[] = $this->fileRow($file, null, 0, $e->getMessage());
                continue;
            }
            $fileRows[] = $this->fileRow($file, $read['document_type'], count($read['records']), null);
            foreach ($read['records'] as $record) {
                $records[] = [
                    'record' => $record,
                    'file' => $file['name'],
                    'sha256' => $file['sha256'],
                    'file_index' => $index,
                ];
            }
        }

        return [
            'files' => $fileRows,
            'registrations' => $records,
            'batch' => JmhzBatch::build($jmhzItems, $stornos),
            'has_jmhz' => $hasJmhz,
        ];
    }

    /**
     * @param array{name:string,sha256:string} $file
     * @param list<string> $warnings
     * @return array<string,mixed>
     */
    private function fileRow(
        array $file,
        ?string $documentType,
        int $recordCount,
        ?string $error,
        array $warnings = [],
        ?string $period = null,
        ?string $submissionType = null,
    ): array {
        return [
            'name' => $file['name'],
            'sha256' => $file['sha256'],
            'document_type' => $documentType,
            'record_count' => $recordCount,
            'error' => $error,
            'warnings' => $warnings,
            'period' => $period,
            'submission_type' => $submissionType,
        ];
    }

    /**
     * Ruční párování formulářů hlášení s pracovními vztahy: `[{key, employment_id}]`.
     *
     * @return array<string,int>
     */
    private function pairs(mixed $pairs): array
    {
        if ($pairs === null) {
            return [];
        }
        if (!is_array($pairs) || !array_is_list($pairs)) {
            throw new \InvalidArgumentException('Ruční párování musí být seznam dvojic {key, employment_id}.');
        }
        $result = [];
        foreach ($pairs as $pair) {
            $key = is_array($pair) ? ($pair['key'] ?? null) : null;
            $employmentId = is_array($pair) ? ($pair['employment_id'] ?? null) : null;
            if (!is_string($key) || preg_match(self::KEY_PATTERN, $key) !== 1
                || !is_int($employmentId) || $employmentId <= 0
            ) {
                throw new \InvalidArgumentException('Ruční párování obsahuje neplatný klíč formuláře nebo pracovní vztah.');
            }
            if (isset($result[$key])) {
                throw new \InvalidArgumentException('Formulář je v ručním párování uvedený dvakrát.');
            }
            $result[$key] = $employmentId;
        }

        return $result;
    }

    private function order(int $fileIndex, int $position): string
    {
        return sprintf('%05d:%05d', $fileIndex, $position);
    }

    /**
     * @param list<array<string,mixed>> $plans
     * @return array<string,int>
     */
    private function summary(array $plans): array
    {
        $summary = [
            'total' => count($plans),
            'create' => 0,
            'update' => 0,
            'terminate' => 0,
            'none' => 0,
            'blocked' => 0,
            'pair_required' => 0,
        ];
        foreach ($plans as $plan) {
            if ($plan['blocker'] !== null || $plan['operation'] === 'unsupported') {
                $summary['blocked']++;
                continue;
            }
            $bucket = match ($plan['operation']) {
                'create_person', 'create_employment' => 'create',
                'update', 'assign_identifiers' => 'update',
                'terminate' => 'terminate',
                'pair_required' => 'pair_required',
                default => 'none',
            };
            $summary[$bucket]++;
        }

        return $summary;
    }

    /**
     * @param array<string,mixed>|null $plan
     * @return array<string,mixed>
     */
    private function result(string $key, string $status, string $message, ?array $plan = null): array
    {
        return [
            'key' => $key,
            'status' => $status,
            'message' => $message,
            'employee_id' => $plan['_employee_id'] ?? null,
            'employment_id' => $plan['_employment_id'] ?? null,
            'operations' => [],
        ];
    }

    /**
     * @param array<string,mixed> $plan
     * @return array<string,mixed>
     */
    private static function publicPlan(array $plan): array
    {
        return array_filter($plan, static fn (string $key): bool => !str_starts_with($key, '_'), ARRAY_FILTER_USE_KEY);
    }

    private function environment(string $environment): void
    {
        if (!in_array($environment, self::ENVIRONMENTS, true)) {
            throw new \InvalidArgumentException('Prostředí musí být ostré (production), nebo testovací (test).');
        }
    }
}
