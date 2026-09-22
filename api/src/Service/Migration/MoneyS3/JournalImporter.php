<?php

declare(strict_types=1);

namespace MyInvoice\Service\Migration\MoneyS3;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\AccountingPeriodRepository;
use MyInvoice\Repository\JournalEntryRepository;
use MyInvoice\Repository\MoneyS3ImportRepository;
use MyInvoice\Service\Accounting\JournalDescriptionBuilder;
use MyInvoice\Service\Accounting\PostingService;
use MyInvoice\Service\Migration\Shared\MigrationPeriods;
use MyInvoice\Service\Migration\Shared\ReconciliationTolerance;

/**
 * Účetní období a účetní deník z Money.
 *
 * Deník je autoritativní kopie toho, co bylo v Money — zaúčtování se nepřepočítává,
 * doklady se k němu jen připojí ({@see DocumentLinker}). Rekonciliace na haléř stojí
 * právě na tom, že deník je přenesený beze změny.
 *
 * Počáteční stavy (zdroj `XP`) tvoří jeden otevírací zápis k prvnímu dni období se
 * zdrojem `opening` a klíčem = id období. Je to přesně klíč, pod kterým otevírací zápis
 * zakládá uzávěrka MyÚčta ({@see \MyInvoice\Service\Accounting\Closing\ClosingService::openNext()}),
 * takže otevření roku po uzávěrce předchozího převzaté počáteční stavy najde a nezdvojí.
 */
final class JournalImporter
{
    public const STEP = 'journal';

    public function __construct(
        private readonly Connection $db,
        private readonly AccountingPeriodRepository $periods,
        private readonly JournalEntryRepository $journal,
        private readonly MoneyS3ImportRepository $map,
    ) {}

    /**
     * Rozvrh období: rok a jeho hranice pro každý adresář ROK.nnn. Bez zápisu — používá
     * ho i kontrola před převodem.
     *
     * @return list<array{dir:string,year:int,starts_on:string,ends_on:string,has_opening:bool,calendar:bool}>
     */
    public function plan(Ms3Backup $backup, ImportOptions $options): array
    {
        $plan = [];
        foreach ($backup->yearDirs() as $dir) {
            $table = $backup->table('UcDenik', $dir);
            if ($table === null || !$table->hasData()) {
                continue;
            }
            $rows = iterator_to_array($table->rows(), false);
            $year = Ms3Journal::fiscalYear($rows);
            if ($year === null) {
                continue;
            }
            $hasOpening = false;
            $min = null;
            foreach ($rows as $r) {
                if (Ms3Journal::isOpening($r)) {
                    $hasOpening = true;
                    continue;
                }
                $date = (string) ($r['Datum'] ?? '');
                if (str_starts_with($date, (string) $year) && ($min === null || $date < $min)) {
                    $min = $date;
                }
            }
            $plan[] = [
                'dir' => basename($dir),
                'year' => $year,
                'starts_on' => sprintf('%04d-01-01', $year),
                'ends_on' => sprintf('%04d-12-31', $year),
                'has_opening' => $hasOpening,
                'calendar' => Ms3Journal::isCalendarYear($rows, $year),
                'first_entry' => $min,
            ];
        }
        usort($plan, static fn (array $a, array $b): int => $a['year'] <=> $b['year']);
        if ($options->fromYear !== null) {
            $plan = array_values(array_filter($plan, static fn (array $p): bool => $p['year'] >= $options->fromYear));
        }

        // První účetní období firmy založené během roku začíná dnem vzniku, ne 1. 1. —
        // jinak nesedí zdaňovací období proti podanému přiznání. Money den vzniku v záloze
        // nedrží: platí výslovně zadané datum, jinak první zápis deníku, ale JEN když rok
        // nemá počáteční stavy (firma s PS existovala už dřív a její období začíná 1. 1.).
        if ($plan !== []) {
            $first = &$plan[0];
            if ($options->firstPeriodStart !== null) {
                if (str_starts_with($options->firstPeriodStart, (string) $first['year'])) {
                    $first['starts_on'] = $options->firstPeriodStart;
                }
            } elseif (!$first['has_opening'] && $first['first_entry'] !== null) {
                $first['starts_on'] = $first['first_entry'];
            }
            unset($first);
        }
        return array_map(static function (array $p): array {
            unset($p['first_entry']);
            return $p;
        }, $plan);
    }

    public function run(ImportContext $ctx): void
    {
        $p = $ctx->protocol;
        $plan = $this->plan($ctx->backup, $ctx->options);
        if ($plan !== [] && $plan[0]['starts_on'] !== sprintf('%04d-01-01', $plan[0]['year'])) {
            $p->info(self::STEP, 'first_period_start', 'První účetní období začíná ' . $plan[0]['starts_on']
                . ($ctx->options->firstPeriodStart === null ? ' (podle prvního zápisu deníku — ověřte den vzniku firmy).' : '.'));
        }

        $years = [];
        foreach ($plan as $item) {
            $ctx->dirYears[$item['dir']] = $item['year'];
            $period = $this->ensurePeriod($ctx, $item);
            $ctx->periods[$item['year']] = $period;
            if ($period['locked']) {
                $p->info(self::STEP, 'year_locked', "Rok {$item['year']} je v MyÚčtu už uzavřený, deník se do něj znovu nenahrává.", ['year' => $item['year']]);
                continue;
            }
            $years[] = $this->importYear($ctx, $item, $period);
        }
        $p->set('journal', $years);
        $p->finish(self::STEP);
    }

    /**
     * @param array{dir:string,year:int,starts_on:string,ends_on:string,has_opening:bool} $item
     * @return array{id:int,starts_on:string,ends_on:string,status:string,locked:bool}
     */
    private function ensurePeriod(ImportContext $ctx, array $item): array
    {
        $year = $item['year'];
        $remember = fn (int $id) => $this->map->put($ctx->supplierId, MoneyS3ImportRepository::KIND_PERIOD, (string) $year, $id, $ctx->runId);
        // Období založené dřív (ručně nebo jiným převodem) se zapíše do mapy, pokud v ní chybí.
        $adopt = function (int $id) use ($ctx, $year, $remember): void {
            if ($this->map->get($ctx->supplierId, MoneyS3ImportRepository::KIND_PERIOD, (string) $year) === null) {
                $remember($id);
            }
        };
        return (new MigrationPeriods($this->periods))
            ->ensure($ctx->supplierId, $year, $item['starts_on'], $item['ends_on'], $ctx->protocol, self::STEP, $remember, $adopt, true);
    }

    /**
     * @param array{dir:string,year:int,starts_on:string,ends_on:string,has_opening:bool} $item
     * @param array{id:int,starts_on:string,ends_on:string,status:string,locked:bool} $period
     * @return array<string,int|float|string>
     */
    private function importYear(ImportContext $ctx, array $item, array $period): array
    {
        $p = $ctx->protocol;
        $year = $item['year'];
        $table = $ctx->backup->table('UcDenik', $ctx->backup->dir() . DIRECTORY_SEPARATOR . $item['dir']);
        $rows = $table !== null ? iterator_to_array($table->rows(), false) : [];

        [$groups, $closingRows] = self::groupRows($rows);
        if ($closingRows > 0) {
            $p->info(self::STEP, 'year_end_closing_skipped', "Rok {$year}: uzávěrkové zápisy z Money ({$closingRows} řádků) se nepřebírají, rok uzavře průvodce uzávěrkou MyÚčta.", ['year' => $year]);
        }

        $existing = $this->map->all($ctx->supplierId, MoneyS3ImportRepository::KIND_JOURNAL_ENTRY);
        $stats = [
            'year' => $year, 'entries' => 0, 'existing' => 0, 'lines' => 0, 'debit' => 0.0, 'credit' => 0.0,
            'skipped_rows' => 0, 'swapped_rows' => 0, 'deleted_rows' => $table?->skippedDeleted() ?? 0, 'moved' => [],
        ];
        $now = date('Y-m-d H:i:s');
        $done = 0;
        $total = count($groups);

        foreach ($groups as $groupKey => $groupRows) {
            $done++;
            if ($done % 200 === 0) {
                $ctx->report(self::STEP, $done, $total);
            }
            $key = $year . '|' . $groupKey;
            if (isset($existing[$key])) {
                $stats['existing']++;
                continue;
            }
            $isOpening = $groupKey === Ms3Journal::OPENING_SOURCE;
            $first = $groupRows[0];

            $lines = [];
            $lineNo = 0;
            foreach ($groupRows as $r) {
                $effect = Ms3Journal::effect($r);
                if ($effect === null) {
                    $stats['skipped_rows']++;
                    continue;
                }
                if ((float) ($r['Castka'] ?? 0) < 0) {
                    $stats['swapped_rows']++;
                }
                $debitId = $ctx->accountIds[AccountCode::fromMoney($effect['debit']) ?? ''] ?? null;
                $creditId = $ctx->accountIds[AccountCode::fromMoney($effect['credit']) ?? ''] ?? null;
                if ($debitId === null || $creditId === null) {
                    throw new MoneyS3Exception('unknown_account', sprintf(
                        'Doklad %s účtuje na účet %s/%s, který v osnově chybí.',
                        trim((string) ($first['Doklad'] ?? '')) ?: $groupKey, $effect['debit'], $effect['credit']
                    ));
                }
                $amount = number_format($effect['amount'], 2, '.', '');
                $cc = Ms3Journal::costCenter($r);
                $lines[] = ['account_id' => $debitId, 'side' => 'debit', 'amount' => $amount, 'cost_center' => $cc, 'line_no' => ++$lineNo];
                $lines[] = ['account_id' => $creditId, 'side' => 'credit', 'amount' => $amount, 'cost_center' => $cc, 'line_no' => ++$lineNo];
            }
            if ($lines === []) {
                continue;
            }
            PostingService::assertBalanced($lines);

            $entryDate = $isOpening ? $period['starts_on'] : (string) ($first['Datum'] ?? '');
            $documentDate = $isOpening ? null : (((string) ($first['DatPlnDPH'] ?? '')) ?: null);
            if ($entryDate === '') {
                $p->error(self::STEP, 'entry_outside_period', sprintf(
                    'Doklad %s nemá datum zápisu — nepřenesen.',
                    trim((string) ($first['Doklad'] ?? ''))
                ), ['year' => $year, 'document_no' => trim((string) ($first['Doklad'] ?? ''))]);
                continue;
            }
            // Money vede v knize roku i doklad s datem z vedlejšího roku (faktura z 31. 12.
            // zaúčtovaná až v novém roce) a do obratů toho roku ho počítá. MyÚčto zápis mimo
            // hranice období nepřijme, takže jde na první (poslední) den období a původní
            // datum zůstává jako datum dokladu — obraty roku tak sedí na sestavy z Money.
            if ($entryDate < $period['starts_on'] || $entryDate > $period['ends_on']) {
                $documentDate ??= $entryDate;
                $entryDate = $entryDate < $period['starts_on'] ? $period['starts_on'] : $period['ends_on'];
                $stats['moved'][] = trim((string) ($first['Doklad'] ?? '')) ?: $groupKey;
            }
            $docNo = $isOpening ? null : (mb_substr(trim((string) ($first['Doklad'] ?? '')), 0, 50) ?: null);
            // Money veze v řádku deníku jen `Popis`, který je u celé řady dokladů
            // shodný. Do popisu proto jde i agenda a číslo dokladu; protistranu
            // doplní {@see DocumentLinker} po navázání dokladů (source_id) přes
            // {@see \MyInvoice\Service\Accounting\JournalDescriptionRebuilder}.
            $description = $isOpening
                ? 'Počáteční stavy ' . $year . ' (převzato z Money S3)'
                : JournalDescriptionBuilder::composeParts([
                    trim(Ms3Journal::shortLabel((string) ($first['Zdroj'] ?? '')) . ' ' . ($docNo ?? '')),
                    (string) ($first['Popis'] ?? ''),
                ]);
            if ($description === '') {
                $description = 'Účetní zápis z Money S3';
            }

            $entryId = $this->journal->insert([
                'supplier_id' => $ctx->supplierId,
                'period_id' => $period['id'],
                'entry_date' => $entryDate,
                'document_date' => $documentDate,
                'document_no' => $docNo,
                'description' => mb_substr($description, 0, 255),
                'source_type' => $isOpening ? 'opening' : Ms3Journal::sourceType((string) ($first['Zdroj'] ?? '')),
                'source_id' => $isOpening ? $period['id'] : null,
                'posted_at' => $now,
                'posted_by' => $ctx->userId > 0 ? $ctx->userId : null,
            ], $lines);
            $this->map->put($ctx->supplierId, MoneyS3ImportRepository::KIND_JOURNAL_ENTRY, $key, $entryId, $ctx->runId);

            $stats['entries']++;
            $stats['lines'] += count($lines);
            foreach ($lines as $l) {
                $stats[$l['side']] += (float) $l['amount'];
            }
        }
        $ctx->report(self::STEP, $total, $total);

        $stats['debit'] = round($stats['debit'], 2);
        $stats['credit'] = round($stats['credit'], 2);
        $p->count(self::STEP, 'entries', $stats['entries']);
        $p->count(self::STEP, 'existing', $stats['existing']);
        $p->count(self::STEP, 'lines', $stats['lines']);
        if ($stats['swapped_rows'] > 0) {
            $p->info(self::STEP, 'negative_amounts', "Rok {$year}: {$stats['swapped_rows']} řádků se zápornou částkou přeneseno s prohozenými stranami (účetně totéž).", ['year' => $year]);
        }
        $moved = $stats['moved'];
        unset($stats['moved']);
        $stats['moved_entries'] = count($moved);
        if ($moved !== []) {
            $p->warn(self::STEP, 'entry_date_outside_year', sprintf(
                'Rok %d: %d dokladů s datem mimo rok (Money je vede v knize roku %d) zaúčtováno k hranici období, původní datum zůstává jako datum dokladu: %s.',
                $year, count($moved), $year, implode(', ', array_slice($moved, 0, 10)) . (count($moved) > 10 ? ', …' : '')
            ), ['year' => $year, 'documents' => $moved]);
        }
        if (!ReconciliationTolerance::sameCent($stats['debit'], $stats['credit'])) {
            $p->error(self::STEP, 'journal_unbalanced', "Rok {$year}: Σ MD ≠ Σ D.", ['year' => $year]);
        }
        return $stats;
    }

    /**
     * Dimenze z Money po řádcích převedeného deníku: klíč zápisu v mapě převodu
     * (`rok|skupina`) => číslo řádku => středisko a zakázka. Řádky se číslují stejně
     * jako v {@see importYear()} (každý řádek Money = řádek MD a řádek D), takže jde
     * dimenze doplnit i do zápisů z dřívějšího převodu.
     *
     * @return array<string,array<int,array{stred:?string,zakazka:?string}>>
     */
    public function lineDimensions(ImportContext $ctx): array
    {
        $out = [];
        foreach ($ctx->dirYears as $dir => $year) {
            $table = $ctx->backup->table('UcDenik', $ctx->backup->dir() . DIRECTORY_SEPARATOR . $dir);
            if ($table === null || !$table->hasData()) {
                continue;
            }
            [$groups] = self::groupRows(iterator_to_array($table->rows(), false));
            foreach ($groups as $groupKey => $groupRows) {
                $lineNo = 0;
                $lines = [];
                foreach ($groupRows as $r) {
                    if (Ms3Journal::effect($r) === null) {
                        continue;
                    }
                    $dims = ['stred' => Ms3Journal::costCenter($r), 'zakazka' => Ms3Journal::jobCode($r)];
                    $lines[++$lineNo] = $dims;
                    $lines[++$lineNo] = $dims;
                }
                if ($lines !== []) {
                    $out[$year . '|' . $groupKey] = $lines;
                }
            }
        }
        return $out;
    }

    /**
     * Řádky deníku roku po účetních zápisech, bez uzávěrkových zápisů Money.
     *
     * @param list<array<string,mixed>> $rows
     * @return array{0:array<string,list<array<string,mixed>>>,1:int} skupiny a počet vynechaných uzávěrkových řádků
     */
    private static function groupRows(array $rows): array
    {
        $groups = [];
        $closingRows = 0;
        foreach ($rows as $r) {
            if (Ms3Journal::isYearEndClosing($r)) {
                $closingRows++;
                continue;
            }
            $groups[Ms3Journal::groupKey($r)][] = $r;
        }
        return [$groups, $closingRows];
    }

    /**
     * Místa, kde v Money nenavazují roky: konečné stavy roku (počáteční stavy + deník bez
     * uzávěrky XZ) nesedí na počáteční stavy dalšího roku. Money to dovolí (počáteční stavy
     * jdou přepsat ručně, starý rok může být v agendě jen zčásti), uzávěrka MyÚčta ne —
     * rok s rozdílem nepůjde uzavřít a s ním ani žádný pozdější. Porovnávají se rozvahové
     * účty tříd 0–4 bez 43x (výsledek hospodaření přechází do dalšího roku až uzávěrkou)
     * a bez 70x.
     *
     * @return list<array{year:int,next:int,next_has_opening:bool,accounts:array<string,float>}>
     */
    public function chainBreaks(Ms3Backup $backup, ImportOptions $options): array
    {
        $years = [];
        foreach ($this->plan($backup, $options) as $item) {
            $table = $backup->table('UcDenik', $backup->dir() . DIRECTORY_SEPARATOR . $item['dir']);
            $opening = [];
            $closing = [];
            foreach ($table !== null ? $table->rows() : [] as $r) {
                if (Ms3Journal::isYearEndClosing($r)) {
                    continue;
                }
                $effect = Ms3Journal::effect($r);
                if ($effect === null) {
                    continue;
                }
                foreach ([[$effect['debit'], 1], [$effect['credit'], -1]] as [$code, $sign]) {
                    $code = (string) $code;
                    if ($code === '' || $code[0] > '4' || str_starts_with($code, '43')) {
                        continue;
                    }
                    if (Ms3Journal::isOpening($r)) {
                        $opening[$code] = ($opening[$code] ?? 0.0) + $sign * $effect['amount'];
                    }
                    $closing[$code] = ($closing[$code] ?? 0.0) + $sign * $effect['amount'];
                }
            }
            $years[] = ['year' => $item['year'], 'opening' => $opening, 'closing' => $closing, 'has_opening' => $item['has_opening']];
        }

        $breaks = [];
        for ($i = 0, $n = count($years) - 1; $i < $n; $i++) {
            [$cur, $next] = [$years[$i], $years[$i + 1]];
            $diffs = [];
            foreach (array_unique(array_merge(array_keys($cur['closing']), array_keys($next['opening']))) as $code) {
                $diff = round(($cur['closing'][$code] ?? 0.0) - ($next['opening'][$code] ?? 0.0), 2);
                if (!ReconciliationTolerance::isZeroCent($diff)) {
                    $diffs[(string) $code] = $diff;
                }
            }
            if ($diffs !== []) {
                uasort($diffs, static fn (float $a, float $b): int => abs($b) <=> abs($a));
                $breaks[] = ['year' => $cur['year'], 'next' => $next['year'], 'next_has_opening' => $next['has_opening'], 'accounts' => $diffs];
            }
        }
        return $breaks;
    }

    /**
     * Zápisy období, které nevznikly převodem (ani uzávěrkou nad ním) — do rozjeté
     * účetní evidence se deník z Money přimíchat nesmí.
     */
    public function foreignEntryCount(int $supplierId, int $periodId): int
    {
        $stmt = $this->db->pdo()->prepare(
            "SELECT COUNT(*)
               FROM journal_entries e
              WHERE e.supplier_id = ? AND e.period_id = ?
                AND e.source_type NOT IN ('closing', 'fx_revaluation')
                AND NOT EXISTS (
                    SELECT 1 FROM money_s3_import_map m
                     WHERE m.supplier_id = e.supplier_id AND m.kind = 'journal_entry' AND m.target_id = e.id
                )"
        );
        $stmt->execute([$supplierId, $periodId]);
        return (int) $stmt->fetchColumn();
    }
}
