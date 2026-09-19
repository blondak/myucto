<?php

declare(strict_types=1);

namespace MyInvoice\Service\Migration\Pohoda\Payroll;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\Payroll\PayrollImportProfileRepository;
use MyInvoice\Repository\Payroll\PayrollInputFilter;
use MyInvoice\Repository\Payroll\PayrollInputRepository;
use MyInvoice\Repository\PohodaImportRepository;
use MyInvoice\Service\Migration\MoneyS3\ImportProtocol;
use MyInvoice\Service\Migration\Pohoda\PohodaException;
use MyInvoice\Service\Migration\Pohoda\PohodaXml;
use MyInvoice\Service\Payroll\Migration\PayrollMigrationReferenceTotals;
use MyInvoice\Service\Payroll\Migration\PayrollMigrationReferenceTotalsWriter;
use MyInvoice\Service\Payroll\Migration\PayrollPostingMapProposalService;
use MyInvoice\Service\Payroll\Import\Attendance\AttendanceImportService;
use MyInvoice\Service\Payroll\Import\Attendance\AttendanceMeaning;
use MyInvoice\Service\Payroll\Import\Attendance\AttendanceProfileComponents;
use MyInvoice\Service\Payroll\Import\Attendance\AttendanceRules;
use MyInvoice\Service\Payroll\Component\PayrollComponentJmhzMappingDefaults;

/**
 * Převod mezd z datového souboru POHODA Mzdy / PAMICA (`91_mzdy.xml`) do mezd firmy.
 *
 * Jde stejnou cestou jako ruční import v Mzdy → Importy → Docházka
 * ({@see AttendanceImportService}): profil importu „POHODA mzdy (převod)", pro každý
 * měsíc sešit z {@see PohodaPayrollConverter}, náhled, založení osob, které ve firmě
 * nejsou, a použití dávky (vazby, vstupy, chybějící mzdové složky, měsíční mzda vztahu,
 * souhrn docházky, srážky). Převod účetnictví se mzdami nepracuje - mzdy jsou vlastní
 * akce průvodce, i pro export, ve kterém jsou jen mzdy.
 *
 * **Opakovaný převod** měsíc, který už prošel (období a otisk sešitu v mapě převodu),
 * přeskočí; import dávky je navíc idempotentní sám (otisk dávky).
 * **Zkouška nanečisto** běží celá v jedné transakci, která se na konci vrátí.
 */
final class PohodaPayrollImporter
{
    public const STEP_PREFLIGHT = 'payroll_preflight';
    public const STEP_PROFILE = 'payroll_profile';
    public const STEP_MONTHS = 'payroll_months';
    public const STEP_PEOPLE = 'payroll_people';
    public const STEP_DEDUCTIONS = 'payroll_deductions';
    public const STEP_SICKNESS = 'payroll_sickness';
    public const STEP_POSTING_MAP = 'payroll_posting_map';
    private const PERSON_CHUNK = 100;
    private const MESSAGE_LIMIT = 20;

    public function __construct(
        private readonly Connection $db,
        private readonly PohodaImportRepository $map,
        private readonly AttendanceImportService $attendance,
        private readonly PayrollImportProfileRepository $profiles,
        private readonly PohodaPayrollPeopleWriter $people,
        private readonly PayrollInputRepository $inputs,
        private readonly PohodaPayrollDeductionsWriter $deductions,
        private readonly PohodaPayrollSicknessWriter $sickness,
        private readonly PayrollMigrationReferenceTotalsWriter $referenceTotals,
        private readonly PayrollPostingMapProposalService $postingMap,
    ) {}

    /** @return list<string> */
    public static function stepKeys(): array
    {
        return [self::STEP_PREFLIGHT, self::STEP_PROFILE, self::STEP_MONTHS, self::STEP_PEOPLE, self::STEP_DEDUCTIONS,
            self::STEP_SICKNESS, self::STEP_POSTING_MAP];
    }

    /**
     * Kontrola před převodem mezd - nic nezapisuje.
     *
     * @return list<array{level:string,code:string,message:string,context:array<string,mixed>}>
     */
    public function preflight(int $supplierId, string $file, int $year): array
    {
        $out = [];
        $add = static function (string $level, string $code, string $message, array $context = []) use (&$out): void {
            $out[] = ['level' => $level, 'code' => $code, 'message' => $message, 'context' => $context];
        };
        $pdo = $this->db->pdo();
        $stmt = $pdo->prepare('SELECT payroll_enabled FROM supplier WHERE id = ?');
        $stmt->execute([$supplierId]);
        if ((int) $stmt->fetchColumn() !== 1) {
            $add('error', 'payroll_disabled', 'Firma nemá zapnutý modul Mzdy. Zapněte ho v Nastavení → Moduly, jinak mzdy nejde převést.');
        }
        // Počáteční stavy ročních kumulací se zapisují za měsíce před začátkem vedení mezd
        // v MyÚčtu; bez něj je převod nezapíše a mzdový běh je bude hlásit jako chybějící.
        if ($this->db->hasTable('payroll_module_state')) {
            $moduleStart = $pdo->prepare('SELECT start_period FROM payroll_module_state WHERE supplier_id = ?');
            $moduleStart->execute([$supplierId]);
            if (($moduleStart->fetchColumn() ?: null) === null) {
                $add('warning', 'payroll_start_missing', 'Firma nemá nastavený začátek vedení mezd v MyÚčtu (Mzdy → Nastavení). Převod bez něj nezapíše počáteční stavy ročních kumulací; nastavte první měsíc, který PAMICA nezpracovala, a převod zopakujte.');
            }
        }
        if ($this->db->hasTable('payroll_employer_settings')) {
            $office = $pdo->prepare('SELECT default_office_id FROM payroll_employer_settings WHERE supplier_id = ?');
            $office->execute([$supplierId]);
            if (($office->fetchColumn() ?: null) === null) {
                $add('error', 'payroll_office_missing', 'Chybí výchozí mzdová účtárna zaměstnavatele (Mzdy → Nastavení). Bez ní převod nezaloží pracovní vztahy.');
            }
        }
        try {
            $converter = PohodaPayrollConverter::read($file);
            $periods = $converter->periods($year);
            if ($periods === []) {
                $add('error', 'payroll_no_months', "Export neobsahuje zpracované mzdy za rok {$year}.");
            } else {
                $add('info', 'payroll_summary', sprintf('Mzdy za %d měsíců (%s až %s), zaměstnanců v exportu %d.',
                    count($periods), $periods[0], $periods[count($periods) - 1], $converter->employees()), ['months' => count($periods)]);
            }
        } catch (PohodaException $e) {
            $add('error', $e->errorCode, $e->getMessage());
        }
        return $out;
    }

    /**
     * @param (callable(string,int,int):void)|null $progress
     * @param (callable():bool)|null $shouldCancel
     * @param bool $confirmIdentifiers uživatel potvrdil, že OIČ a ID PPV v PAMICA pocházejí z protokolů ČSSZ
     * @param bool $approveTakenOver převzatá docházka a mzdové vstupy se rovnou schválí (viz {@see approveTakenOverInputs()})
     */
    public function run(int $supplierId, int $userId, string $file, int $year, bool $dryRun, ?int $runId = null, ?callable $progress = null, ?callable $shouldCancel = null, bool $confirmIdentifiers = false, bool $approveTakenOver = false): ImportProtocol
    {
        $protocol = new ImportProtocol($dryRun ? 'dry_run' : 'import');
        $protocol->set('kind', 'payroll');
        $preflight = $this->preflight($supplierId, $file, $year);
        $protocol->set('preflight', $preflight);
        $protocol->begin(self::STEP_PREFLIGHT);
        foreach ($preflight as $m) {
            if ($m['level'] === 'error') {
                $protocol->error(self::STEP_PREFLIGHT, $m['code'], $m['message'], $m['context']);
            }
        }
        if ($protocol->hasErrors()) {
            $protocol->fail(self::STEP_PREFLIGHT);
            return $protocol;
        }
        $protocol->finish(self::STEP_PREFLIGHT);

        $converter = PohodaPayrollConverter::read($file);
        $protocol->set('agenda', ['ico' => $converter->ico, 'year' => $year, 'program' => 'POHODA Mzdy', 'exported_at' => null, 'dir' => basename(dirname($file))]);
        $userOrNull = $userId > 0 ? $userId : null;

        $pdo = $this->db->pdo();
        $savepoint = $dryRun && $pdo->inTransaction();
        if ($savepoint) {
            $pdo->exec('SAVEPOINT pohoda_payroll_dry_run');
        } elseif ($dryRun) {
            $pdo->beginTransaction();
        }
        try {
            $months = array_map($converter->month(...), $converter->periods($year));
            // Údaje osob a vztahů se čtou jednou: krok měsíců z nich zapisuje pracoviště
            // průběžně a krok osob pak zbytek.
            $records = PohodaPayrollPeople::read($file, $year);

            $protocol->begin(self::STEP_PROFILE);
            $profile = PohodaPayrollConverter::profile($months);
            $existing = array_values(array_filter(
                $this->profiles->list($supplierId, AttendanceMeaning::SOURCE_SYSTEM),
                static fn (array $p): bool => $p['name'] === $profile['name'],
            ));
            $saved = $this->profiles->save(
                $supplierId,
                AttendanceMeaning::SOURCE_SYSTEM,
                isset($existing[0]['id']) ? (int) $existing[0]['id'] : null,
                $profile['name'],
                AttendanceRules::validate($profile['rules']),
                $userOrNull,
                AttendanceProfileComponents::validate($profile['components']),
            );
            $profileId = (int) ($saved['id'] ?? 0);
            $protocol->count(self::STEP_PROFILE, 'rules', count($profile['rules']));
            $protocol->count(self::STEP_PROFILE, 'components', count($profile['components']));
            // Složka bez zařazení do JMHZ zastaví zmrazení měsíčního hlášení. Zařazení, které
            // plyne z druhu složky, doplní aplikace sama při jejím založení; co z druhu neplyne
            // (přesčas, doplatky), převod nehádá a předá účetní se seznamem kódů a počty vstupů.
            $unclassified = [];
            foreach ($profile['components'] as $component) {
                if (PayrollComponentJmhzMappingDefaults::targetFor($component['code'], $component['kind'], 'one_off', 'included') === null) {
                    $unclassified[$component['code']] = 0;
                }
            }
            foreach ($months as $month) {
                foreach ($month['columns'] as $header => $meta) {
                    $code = (string) ($meta['code'] ?? '');
                    if ($meta['meaning'] !== 'component' || !array_key_exists($code, $unclassified)) {
                        continue;
                    }
                    foreach ($month['rows'] as $row) {
                        $value = $row[$header] ?? null;
                        if (is_numeric($value) && abs((float) $value) > 0.0) {
                            $unclassified[$code]++;
                        }
                    }
                }
            }
            if ($unclassified !== []) {
                arsort($unclassified);
                $list = [];
                foreach ($unclassified as $code => $inputs) {
                    $list[] = "{$code} ({$inputs} vstupů)";
                    $protocol->count(self::STEP_PROFILE, 'components_without_jmhz');
                }
                $protocol->warn(self::STEP_PROFILE, 'components_without_jmhz', sprintf(
                    'Mzdové složky bez zařazení do JMHZ: %s. Zařazení z druhu složky neplyne a převod ho '
                    . 'nehádá, protože chybná hodnota by prošla do hlášení tiše. Zařaďte je v Mzdy → '
                    . 'Mzdové složky, jinak nepůjde zmrazit měsíční hlášení.',
                    implode(', ', $list),
                ));
            }
            $protocol->finish(self::STEP_PROFILE);

            $protocol->begin(self::STEP_MONTHS);
            $done = $this->map->all($supplierId, PohodaImportRepository::KIND_PAYROLL_MONTH);
            $messages = 0;
            // Vynechané údaje osob stačí vypsat jednou, ne v každém měsíci.
            $omitted = [];
            foreach ($months as $month) {
                foreach ($month['omitted'] as $text) {
                    $omitted[$text] = true;
                }
            }
            foreach (array_keys($omitted) as $text) {
                $protocol->count(self::STEP_MONTHS, 'data_omitted');
                if ($messages++ < self::MESSAGE_LIMIT) {
                    $protocol->warn(self::STEP_MONTHS, 'person_data_omitted', ucfirst($text));
                }
            }
            // Srážka, jejíž druh v číselníku PAMICA není, se do sešitu nedostane: bez druhu
            // ji nejde odlišit od exekuce a tichá záměna by ji buď srazila dvakrát, nebo
            // vůbec. Vypíše se proto k ručnímu dořešení, stejně jako složky bez JMHZ.
            $unclassifiedDeductions = [];
            foreach ($months as $month) {
                foreach ($month['unclassified_deductions'] ?? [] as $key => $entry) {
                    $unclassifiedDeductions[$key] ??= ['code' => $entry['code'], 'name' => $entry['name'], 'inputs' => 0];
                    $unclassifiedDeductions[$key]['inputs'] += (int) $entry['inputs'];
                }
            }
            if ($unclassifiedDeductions !== []) {
                uasort($unclassifiedDeductions, static fn (array $a, array $b): int => $b['inputs'] <=> $a['inputs']);
                $protocol->count(self::STEP_MONTHS, 'deductions_without_kind', count($unclassifiedDeductions));
                $list = array_map(
                    static fn (array $e): string => trim($e['code'] . ' ' . $e['name']) . " ({$e['inputs']} vstupů)",
                    array_slice($unclassifiedDeductions, 0, self::MESSAGE_LIMIT),
                );
                $protocol->warn(self::STEP_MONTHS, 'deductions_without_kind', sprintf(
                    'Srážky bez druhu v číselníku PAMICA: %s. Do mzdových vstupů se nepřevedly, protože bez '
                    . 'druhu nejde poznat, jestli jde o dobrovolnou srážku, nebo o exekuci. Doplňte je ručně '
                    . 'v Mzdy → Vstupy, u exekucí v Mzdy → Exekuce a insolvence.',
                    implode(', ', $list),
                ));
            }
            foreach ($months as $index => $month) {
                if ($shouldCancel !== null && $shouldCancel()) {
                    $protocol->fail('cancelled');
                    break;
                }
                if ($progress !== null) {
                    $progress(self::STEP_MONTHS, $index, count($months));
                }
                $period = $month['period'];
                $workbook = PohodaPayrollConverter::workbook($month);
                // Otisk dat měsíce, ne souboru: XLSX nese časová razítka a byl by pokaždé jiný.
                $key = $period . '|' . hash('sha256', (string) json_encode([$month['columns'], $month['rows']], JSON_UNESCAPED_UNICODE));
                if (isset($done[$key])) {
                    $protocol->count(self::STEP_MONTHS, 'existing');
                    // Měsíc, který už jednou prošel, se neimportuje znovu - ale schválení
                    // převzatých podkladů si uživatel mohl vyžádat až teď, takže se dodatečně
                    // dožene nad hotovou dávkou. Jinak by volba u dříve převedené firmy
                    // neudělala nic a běh by pořád stál na blokujících kontrolách.
                    if ($approveTakenOver) {
                        $this->approveTakenOverBatch($supplierId, $userOrNull, $period, $done[$key], $protocol);
                    }
                    continue;
                }
                try {
                    $preview = $this->attendance->preview($supplierId, $period, [$workbook], null, $profileId);
                    $created = 0;
                    foreach (array_chunk(self::personsToCreate($preview['persons'], $month), self::PERSON_CHUNK) as $chunk) {
                        $result = $this->attendance->persons($supplierId, $period, $chunk, $userOrNull, null, 'pohoda-import', [$workbook], null, $profileId);
                        foreach ($result['results'] as $item) {
                            if ($item['status'] === 'created') {
                                $created++;
                            } else {
                                $protocol->count(self::STEP_MONTHS, 'persons_failed');
                                if ($messages++ < self::MESSAGE_LIMIT) {
                                    $protocol->warn(self::STEP_MONTHS, 'person_failed', "{$period}: osobu se nepodařilo založit - {$item['message']}");
                                }
                            }
                        }
                    }
                    $applied = $this->attendance->apply(
                        $supplierId, $period, [$workbook], null, [], true, true, $userOrNull, null, true, $profileId,
                        false, true, true, $approveTakenOver, false, true,
                    );
                    $skipped = count($applied['skipped'] ?? []);
                    if ($approveTakenOver) {
                        $this->approveTakenOverInputs($supplierId, $userOrNull, $period, (int) ($applied['inputs']['import_id'] ?? 0), $protocol);
                    }
                    $this->map->put($supplierId, PohodaImportRepository::KIND_PAYROLL_MONTH, $key, (int) ($applied['batch']['id'] ?? $applied['import_id'] ?? 0), $runId);
                    // Pracoviště a CZ-ISCO ještě v tomhle měsíci, dokud je jeho verze podmínek
                    // ta poslední; další verze si je pak opíší. Po všech měsících už by je
                    // dostala jen verze poslední a starší měsíce by zůstaly bez pracoviště.
                    $this->people->writeWorkplaces($supplierId, $userOrNull, $records, $protocol, self::STEP_MONTHS);
                    $protocol->count(self::STEP_MONTHS, 'months');
                    $protocol->count(self::STEP_MONTHS, 'payslips', $month['totals']['rows']);
                    $protocol->count(self::STEP_MONTHS, 'persons_created', $created);
                    $protocol->count(self::STEP_MONTHS, 'skipped', $skipped);
                    $protocol->info(self::STEP_MONTHS, 'payroll_month', sprintf(
                        '%s: %d mezd, hrubá mzda %s Kč, čistá %s Kč; nově založeno osob %d%s.',
                        $period, $month['totals']['rows'], self::money($month['totals']['gross_minor']), self::money($month['totals']['net_minor']),
                        $created, $skipped > 0 ? ", přeskočeno řádků {$skipped}" : '',
                    ), ['period' => $period]);
                } catch (\InvalidArgumentException|\DomainException|PohodaException $e) {
                    $protocol->error(self::STEP_MONTHS, 'payroll_month_failed', "{$period}: " . $e->getMessage(), ['period' => $period]);
                }
            }
            $protocol->finish(self::STEP_MONTHS);

            // Údaje osob a vztahů, které sešity měsíců nenesou (adresa, OIČ, skončení,
            // podaná hlášení, počáteční stavy). Až po mzdách: osoby a vztahy už existují.
            if (!$protocol->failed()) {
                $protocol->begin(self::STEP_PEOPLE);
                if ($progress !== null) {
                    $progress(self::STEP_PEOPLE, 0, 1);
                }
                $this->people->write($supplierId, $userOrNull, $records, $year, $confirmIdentifiers, $protocol, self::STEP_PEOPLE,
                    PohodaPayrollPeople::institutions($file));
                $this->storeReferenceTotals($supplierId, $file, $year, $protocol);
                $protocol->finish(self::STEP_PEOPLE);
            }

            // Trvalé srážky, exekuce a insolvence z karet zaměstnanců. Až po osobách:
            // exekuční případ i dohoda o srážkách visí na zaměstnanci, který už musí být
            // ve firmě založený.
            if (!$protocol->failed()) {
                $protocol->begin(self::STEP_DEDUCTIONS);
                if ($progress !== null) {
                    $progress(self::STEP_DEDUCTIONS, 0, 1);
                }
                $this->deductions->write($supplierId, $userOrNull, PohodaPayrollDeductions::read($file, $year), $year,
                    $protocol, self::STEP_DEDUCTIONS, $runId);
                $protocol->finish(self::STEP_DEDUCTIONS);
            }

            // Rozpracovaná neschopnost přes první měsíc vedení mezd. Až po osobách:
            // nepřítomnosti z převedených mezd už existují a tenhle krok jim jen dopíše
            // dny okna náhrady mzdy, které vyčerpal předchozí plátce. Bez nich by MyÚčto
            // začalo čtrnáctidenní okno počítat znovu od začátku.
            if (!$protocol->failed()) {
                $protocol->begin(self::STEP_SICKNESS);
                if ($progress !== null) {
                    $progress(self::STEP_SICKNESS, 0, 1);
                }
                $this->sickness->write(
                    $supplierId,
                    $userOrNull,
                    PohodaPayrollSickness::read($file, $year, $this->sickness->startPeriod($supplierId)),
                    $protocol,
                    self::STEP_SICKNESS,
                );
                $protocol->finish(self::STEP_SICKNESS);
            }

            // Návrh kontací mezd z převzatého zaúčtování. Účetní zápisy se NEPŘENÁŠEJÍ:
            // mzdy zaúčtuje MyÚčto vlastní cestou a převzaté zápisy by proti převedeným
            // dokladům vyrobily duplicitu. Ukládá se jen návrh nastavení; do nastavení
            // zaměstnavatele sáhne teprve potvrzení účetní.
            if (!$protocol->failed()) {
                $protocol->begin(self::STEP_POSTING_MAP);
                if ($progress !== null) {
                    $progress(self::STEP_POSTING_MAP, 0, 1);
                }
                $stored = $this->postingMap->refresh(
                    $supplierId,
                    PohodaPayrollPostingMap::read($file, $year),
                    $year,
                    basename($file),
                );
                if ($stored !== null) {
                    $protocol->set('posting_map', $stored['proposal']);
                    $protocol->count(self::STEP_POSTING_MAP, 'posting_map_conflicts',
                        (int) ($stored['proposal']['summary']['conflict'] ?? 0));
                }
                $protocol->finish(self::STEP_POSTING_MAP);
            }
        } finally {
            if ($savepoint) {
                $pdo->exec('ROLLBACK TO SAVEPOINT pohoda_payroll_dry_run');
            } elseif ($dryRun && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
        }
        return $protocol;
    }

    /**
     * Osoby z náhledu, které ve firmě nejsou: jméno a nástup ze sešitu, druh vztahu
     * a úvazek z náhledu. Rodné číslo, datum narození a pojišťovnu doplní import sám
     * ze sešitu.
     *
     * @param list<array<string,mixed>> $persons
     * @param array{period:string, rows:list<array<string,mixed>>} $month
     * @return list<array<string,mixed>>
     */
    private static function personsToCreate(array $persons, array $month): array
    {
        $rows = [];
        foreach ($month['rows'] as $row) {
            $rows[mb_strtoupper((string) $row['Osobní číslo'])] = $row;
        }
        $create = [];
        foreach ($persons as $person) {
            if (($person['match']['status'] ?? '') !== 'not_found') {
                continue;
            }
            $number = (string) ($person['personal_number'] ?? '');
            $row = $rows[mb_strtoupper($number)] ?? [];
            $first = (string) ($row['Jméno'] ?? '');
            $last = (string) ($row['Příjmení'] ?? '');
            $wage = (float) str_replace([' ', ','], ['', '.'], (string) ($person['monthly_wage'] ?? ''));
            $weekly = (string) ($person['weekly_hours'] ?? '');
            $create[] = [
                'person_key' => $person['key'],
                'full_name' => trim($first . ' ' . $last) ?: (string) ($person['display_name'] ?? ''),
                'first_name' => $first,
                'last_name' => $last,
                'birth_number' => null,
                'relation_type' => preg_match('/\bdpp\b/i', (string) ($person['relation_label'] ?? '')) === 1 ? 'dpp' : 'employment',
                'weekly_hours' => $weekly === '' ? null : str_replace(',', '.', $weekly),
                'monthly_gross' => $wage > 0 ? (int) round($wage) : null,
                'planned_start_on' => $person['start_on'] ?? ($row['_start'] ?? null) ?? $month['period'] . '-01',
                'personal_number' => $number === '' ? null : $number,
                'activate' => true,
            ];
        }
        return $create;
    }

    private static function money(int $minor): string
    {
        return number_format($minor / 100, 2, ',', ' ');
    }

    /**
     * Schválení převzatých mzdových vstupů měsíce.
     *
     * Import zakládá vstupy jako koncepty, protože u ručně nahrané docházky je má
     * účetní projít. Tady ale jde o měsíc, který v PAMICA proběhl a je podaný -
     * konceptem by zablokoval mzdový běh kontrolou `draft_inputs_present`, kterou
     * nejde přebít výjimkou (ta je vyhrazená varováním). Schvaluje se výhradně
     * dávka tohoto importu, ne cokoli, co v měsíci leží z jiného zdroje.
     */
    /**
     * Dodatečné schválení měsíce, který už v MyÚčtu jednou prošel: docházka nad hotovou
     * dávkou a pak její mzdové vstupy. `$batchId` je dávka importu docházky z mapy převodu,
     * vstupy visí na vlastní dávce, kterou vrátí až služba importu.
     */
    private function approveTakenOverBatch(int $supplierId, ?int $userId, string $period, int $batchId, ImportProtocol $protocol): void
    {
        if ($batchId <= 0) {
            return;
        }
        try {
            $result = $this->attendance->approveTakenOverBatch($supplierId, $batchId, $userId);
        } catch (\InvalidArgumentException|\DomainException|\RuntimeException $e) {
            $protocol->warn(self::STEP_MONTHS, 'taken_over_approve_failed',
                "{$period}: převzatou docházku se nepodařilo dodatečně schválit - " . $e->getMessage(), ['period' => $period]);
            return;
        }
        $protocol->count(self::STEP_MONTHS, 'time_months_approved', (int) ($result['time']['approved'] ?? 0));
        $this->approveTakenOverInputs($supplierId, $userId, $period, (int) $result['input_import_id'], $protocol);
    }

    private function approveTakenOverInputs(int $supplierId, ?int $userId, string $period, int $importId, ImportProtocol $protocol): void
    {
        if ($importId <= 0) {
            return;
        }
        $filter = new PayrollInputFilter($period . '-01', null, null, null, [], [], [], ['draft'], $importId);
        $approved = 0;
        $failed = 0;
        $afterId = 0;
        // Schvalování běží po dávkách s časovým rozpočtem; pokračuje se od posledního id.
        for ($guard = 0; $guard < 1000; $guard++) {
            $result = $this->inputs->approveByFilter($supplierId, $filter, $userId, $afterId);
            $approved += count($result['approved']);
            $failed += count($result['failed']);
            if ($result['complete'] || $result['next_after_id'] === $afterId) {
                break;
            }
            $afterId = (int) $result['next_after_id'];
        }
        $protocol->count(self::STEP_MONTHS, 'inputs_approved', $approved);
        if ($failed > 0) {
            $protocol->warn(self::STEP_MONTHS, 'inputs_approve_failed',
                "{$period}: {$failed} převzatých mzdových vstupů se nepodařilo schválit, zůstávají jako koncept.", ['period' => $period]);
        }
    }

    /**
     * Úhrny zpracovaných mezd z PAMICA tak, jak je spočítal původní program.
     *
     * Bez nich nejde po přepočtu zjistit, jestli se MyÚčto trefilo do toho, co už bylo
     * podané - a právě to je jediná obrana proti tichému rozejití s hlášeními. Ukládají
     * se při převodu, ne až při generování sestavy: měsíce po převodu už export nikdo
     * po ruce nemá, a přesně tehdy se historický měsíc přepočítává.
     */
    private function storeReferenceTotals(int $supplierId, string $file, int $year, ImportProtocol $protocol): void
    {
        $matched = $this->people->matchedRelations();
        $totals = [];
        foreach (PohodaXml::records($file, 'MZ') as $mz) {
            if ((int) PohodaXml::text($mz, 'Rok') !== $year) {
                continue;
            }
            $pair = $matched[PohodaXml::text($mz, 'RefPomer')] ?? null;
            try {
                $totals[] = PayrollMigrationReferenceTotals::fromPohodaMz(
                    $mz,
                    $year,
                    $pair['employee_id'] ?? null,
                    $pair['employment_id'] ?? null,
                    $pair['relation_type'] ?? null,
                    $pair['activity_code'] ?? null,
                );
            } catch (\InvalidArgumentException) {
                // Mzda bez platného měsíce nebo bez identifikace vztahu: do sestavy nepatří,
                // ale ani kvůli ní nemá padnout celý převod.
                $protocol->count(self::STEP_PEOPLE, 'reference_totals_skipped');
            }
        }
        if ($totals === []) {
            return;
        }
        $protocol->count(self::STEP_PEOPLE, 'reference_totals', $this->referenceTotals->store($supplierId, 'pamica', $totals, basename($file)));
    }

}
