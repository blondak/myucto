<?php

declare(strict_types=1);

namespace MyInvoice\Service\Migration\Pohoda;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\AccountingPeriodRepository;
use MyInvoice\Repository\ChartOfAccountsRepository;
use MyInvoice\Repository\JournalEntryRepository;
use MyInvoice\Repository\PohodaImportRepository;
use MyInvoice\Service\Accounting\ChartOfAccountsSeeder;
use MyInvoice\Service\Accounting\JournalDescriptionBuilder;
use MyInvoice\Service\Accounting\PostingService;
use MyInvoice\Service\Migration\MoneyS3\AccountCode;

/**
 * Účtová osnova, účetní období a účetní deník z Pohody.
 *
 * Osnova: firma má osnovu ze šablony MyÚčta, z Pohody se doplní jen účty, na které deník
 * účtuje (`321001` → `321.001` pod syntetikou `321`), s názvem z osnovy Pohody. Chybějící
 * syntetika dostane typ od sourozence ze skupiny, jinak ze třídy (stejně jako u Money S3).
 *
 * Deník je autoritativní kopie Pohody - zaúčtování se nepřepočítává, doklady se k němu
 * jen připojí ({@see DocumentLinker}). Počáteční stavy tvoří jeden otevírací zápis se
 * zdrojem `opening` a klíčem = id období, pod stejným klíčem ho zakládá uzávěrka MyÚčta.
 */
final class ChartJournalImporter
{
    public const STEP_CHART = 'chart';
    public const STEP_JOURNAL = 'journal';

    public function __construct(
        private readonly Connection $db,
        private readonly ChartOfAccountsRepository $accounts,
        private readonly ChartOfAccountsSeeder $seeder,
        private readonly AccountingPeriodRepository $periods,
        private readonly JournalEntryRepository $journal,
        private readonly PohodaImportRepository $map,
    ) {}

    public function chart(PohodaContext $ctx): void
    {
        $p = $ctx->protocol;
        if ($this->accounts->count($ctx->supplierId) === 0) {
            $p->setCount(self::STEP_CHART, 'seeded', $this->seeder->seedForSupplier($ctx->supplierId));
        }

        $names = [];
        foreach ($ctx->export->records('chart', 'itemAccount') as $r) {
            $code = trim((string) ($r['@code'] ?? ''));
            $name = trim((string) ($r['@name'] ?? ''));
            if ($code !== '' && ctype_digit($code) && $name !== '') {
                $names[$code] = $name;
            }
        }
        $p->setCount(self::STEP_CHART, 'pohoda_accounts', count($names));

        $used = [];
        foreach ($ctx->export->records('journal', 'accountingItem') as $item) {
            if (PohodaJournal::isYearEndClosing($item)) {
                continue;
            }
            foreach (['accounting/credit', 'accounting/debit'] as $path) {
                $code = PohodaXml::text($item, $path);
                if ($code !== '') {
                    $used[$code] = true;
                }
            }
        }
        ksort($used, SORT_STRING);

        $ctx->accountIds = [];
        foreach ($this->accounts->codeToIdMap($ctx->supplierId) as $code => $row) {
            $ctx->accountIds[(string) $code] = (int) $row['id'];
        }

        foreach (array_keys($used) as $raw) {
            $pohodaCode = (string) $raw;
            $target = AccountCode::fromMoney($pohodaCode);
            if ($target === null) {
                $p->error(self::STEP_CHART, 'invalid_account', "Deník Pohody účtuje na účet „{$pohodaCode}\", který není číselný kód účtu.", ['account' => $pohodaCode]);
                continue;
            }
            if (isset($ctx->accountIds[$target])) {
                $p->count(self::STEP_CHART, 'existing');
                continue;
            }
            $synthetic = substr($target, 0, 3);
            $parent = $this->accounts->findByCode($ctx->supplierId, $synthetic) ?? $this->createSynthetic($ctx, $synthetic, $names);
            if ($parent === null) {
                continue;
            }
            $id = $this->accounts->insert($ctx->supplierId, [
                'account_code' => $target,
                'name' => mb_substr($names[$pohodaCode] ?? ('Analytika ' . $pohodaCode), 0, 190),
                'account_type' => (string) $parent['account_type'],
                'normal_side' => $parent['normal_side'] ?? null,
                'is_synthetic' => false,
                'parent_id' => (int) $parent['id'],
                'is_active' => true,
            ]);
            $ctx->accountIds[$target] = $id;
            $this->map->put($ctx->supplierId, PohodaImportRepository::KIND_ACCOUNT, $target, $id, $ctx->runId);
            $p->count(self::STEP_CHART, 'created');
        }
        $p->finish(self::STEP_CHART);
    }

    public function journal(PohodaContext $ctx): void
    {
        $p = $ctx->protocol;
        $year = $ctx->year();
        $period = $this->ensurePeriod($ctx);
        $ctx->period = $period;

        $groups = [];
        $closingRows = 0;
        foreach ($ctx->export->records('journal', 'accountingItem') as $item) {
            if (PohodaJournal::isYearEndClosing($item)) {
                $closingRows++;
                continue;
            }
            $groups[PohodaJournal::groupKey($item)][] = $item;
            if (PohodaJournal::source($item) === PohodaJournal::CASH) {
                foreach (['accounting/credit', 'accounting/debit'] as $path) {
                    $code = PohodaXml::text($item, $path);
                    if (str_starts_with($code, '211')) {
                        $ctx->cashAccountsByNumber[PohodaJournal::number($item)] = (string) AccountCode::fromMoney($code);
                    }
                }
            }
        }
        if ($period['locked']) {
            $p->info(self::STEP_JOURNAL, 'year_locked', "Rok {$year} je v MyÚčtu už uzavřený, deník se do něj znovu nenahrává.", ['year' => $year]);
            $p->finish(self::STEP_JOURNAL);
            return;
        }
        if ($closingRows > 0) {
            $p->info(self::STEP_JOURNAL, 'year_end_closing_skipped', "Uzávěrkové zápisy z Pohody ({$closingRows} řádků) se nepřebírají, rok uzavře průvodce uzávěrkou MyÚčta.");
        }

        $existing = $this->map->all($ctx->supplierId, PohodaImportRepository::KIND_JOURNAL_ENTRY);
        $stats = ['year' => $year, 'entries' => 0, 'existing' => 0, 'lines' => 0, 'debit' => 0.0, 'credit' => 0.0, 'skipped_rows' => 0, 'swapped_rows' => 0];
        $moved = [];
        $now = date('Y-m-d H:i:s');
        $done = 0;
        $total = count($groups);

        foreach ($groups as $groupKey => $rows) {
            if (++$done % 500 === 0) {
                $ctx->report(self::STEP_JOURNAL, $done, $total);
            }
            $key = $year . '|' . $groupKey;
            if (isset($existing[$key])) {
                $stats['existing']++;
                continue;
            }
            $isOpening = $groupKey === PohodaJournal::OPENING_KEY;
            $first = $rows[0];
            $number = PohodaJournal::number($first);

            $lines = [];
            $lineNo = 0;
            foreach ($rows as $item) {
                $effect = PohodaJournal::effect($item);
                if ($effect === null) {
                    $stats['skipped_rows']++;
                    continue;
                }
                if (PohodaXml::num($item, 'homeCurrency/priceSum') < 0) {
                    $stats['swapped_rows']++;
                }
                $debitId = $ctx->accountIds[AccountCode::fromMoney($effect['debit']) ?? ''] ?? null;
                $creditId = $ctx->accountIds[AccountCode::fromMoney($effect['credit']) ?? ''] ?? null;
                if ($debitId === null || $creditId === null) {
                    throw new PohodaException('unknown_account', sprintf(
                        'Doklad %s účtuje na účet %s/%s, který v osnově chybí.', $number ?: $groupKey, $effect['debit'], $effect['credit']
                    ));
                }
                $amount = number_format($effect['amount'], 2, '.', '');
                $lines[] = ['account_id' => $debitId, 'side' => 'debit', 'amount' => $amount, 'line_no' => ++$lineNo];
                $lines[] = ['account_id' => $creditId, 'side' => 'credit', 'amount' => $amount, 'line_no' => ++$lineNo];
            }
            if ($lines === []) {
                continue;
            }
            PostingService::assertBalanced($lines);

            $entryDate = $isOpening ? $period['starts_on'] : (PohodaXml::date($first, 'date') ?? '');
            $documentDate = $isOpening ? null : PohodaXml::date($first, 'dateTax');
            if ($entryDate === '') {
                $p->error(self::STEP_JOURNAL, 'entry_without_date', "Doklad {$number} nemá datum zápisu - nepřenesen.", ['document_no' => $number]);
                continue;
            }
            if ($entryDate < $period['starts_on'] || $entryDate > $period['ends_on']) {
                $documentDate ??= $entryDate;
                $entryDate = $entryDate < $period['starts_on'] ? $period['starts_on'] : $period['ends_on'];
                $moved[] = $number ?: $groupKey;
            }
            // Pohoda veze v řádku deníku JEN volný text (`act:text`), který je u celé
            // řady dokladů shodný („Fakturujeme Vám za …"). Do popisu proto jde i
            // agenda a číslo dokladu, ať se zápisy v deníku dají rozlišit; protistranu
            // doplní {@see DocumentLinker} po navázání dokladů (source_id) přes
            // {@see \MyInvoice\Service\Accounting\JournalDescriptionRebuilder}.
            $description = $isOpening
                ? 'Počáteční stavy ' . $year . ' (převzato z Pohody)'
                : JournalDescriptionBuilder::composeParts([
                    trim(PohodaJournal::shortLabel(PohodaJournal::source($first)) . ' ' . $number),
                    PohodaXml::text($first, 'text'),
                ]);
            if ($description === '') {
                $description = 'Účetní zápis z Pohody';
            }

            $entryId = $this->journal->insert([
                'supplier_id' => $ctx->supplierId,
                'period_id' => $period['id'],
                'entry_date' => $entryDate,
                'document_date' => $documentDate,
                'document_no' => $isOpening ? null : (mb_substr($number, 0, 50) ?: null),
                'description' => mb_substr($description, 0, 255),
                'source_type' => $isOpening ? 'opening' : PohodaJournal::sourceType(PohodaJournal::source($first)),
                'source_id' => $isOpening ? $period['id'] : null,
                'posted_at' => $now,
                'posted_by' => $ctx->userOrNull(),
            ], $lines);
            $this->map->put($ctx->supplierId, PohodaImportRepository::KIND_JOURNAL_ENTRY, $key, $entryId, $ctx->runId);

            $stats['entries']++;
            $stats['lines'] += count($lines);
            foreach ($lines as $l) {
                $stats[$l['side']] += (float) $l['amount'];
            }
        }
        $ctx->report(self::STEP_JOURNAL, $total, $total);

        $stats['debit'] = round($stats['debit'], 2);
        $stats['credit'] = round($stats['credit'], 2);
        $stats['moved_entries'] = count($moved);
        foreach (['entries', 'existing', 'lines', 'skipped_rows'] as $k) {
            $p->setCount(self::STEP_JOURNAL, $k, $stats[$k]);
        }
        if ($stats['swapped_rows'] > 0) {
            $p->info(self::STEP_JOURNAL, 'negative_amounts', "{$stats['swapped_rows']} řádků se zápornou částkou přeneseno s prohozenými stranami (účetně totéž).");
        }
        if ($moved !== []) {
            $p->warn(self::STEP_JOURNAL, 'entry_date_outside_year', sprintf(
                '%d dokladů s datem mimo rok %d zaúčtováno k hranici období, původní datum zůstává jako datum dokladu: %s.',
                count($moved), $year, implode(', ', array_slice($moved, 0, 10)) . (count($moved) > 10 ? ', …' : '')
            ), ['documents' => array_slice($moved, 0, 200)]);
        }
        if (abs($stats['debit'] - $stats['credit']) >= 0.005) {
            $p->error(self::STEP_JOURNAL, 'journal_unbalanced', "Rok {$year}: Σ MD ≠ Σ D.");
        }
        $p->set('journal', [$stats]);
        $p->finish(self::STEP_JOURNAL);
    }

    /**
     * Zápisy období, které nevznikly převodem (ani uzávěrkou nad ním) - do rozjeté
     * účetní evidence se deník z Pohody nepřimíchává.
     */
    public function foreignEntryCount(int $supplierId, int $periodId): int
    {
        $stmt = $this->db->pdo()->prepare(
            "SELECT COUNT(*)
               FROM journal_entries e
              WHERE e.supplier_id = ? AND e.period_id = ?
                AND e.source_type NOT IN ('closing', 'fx_revaluation')
                AND NOT EXISTS (
                    SELECT 1 FROM pohoda_import_map m
                     WHERE m.supplier_id = e.supplier_id AND m.kind = 'journal_entry' AND m.target_id = e.id
                )"
        );
        $stmt->execute([$supplierId, $periodId]);
        return (int) $stmt->fetchColumn();
    }

    /** @return array{id:int,starts_on:string,ends_on:string,status:string,locked:bool} */
    private function ensurePeriod(PohodaContext $ctx): array
    {
        $year = $ctx->year();
        $existing = $this->periods->findByYear($ctx->supplierId, $year);
        if ($existing === null) {
            $starts = sprintf('%04d-01-01', $year);
            $ends = sprintf('%04d-12-31', $year);
            $id = $this->periods->create($ctx->supplierId, $year, $starts, $ends, 'import');
            $this->map->put($ctx->supplierId, PohodaImportRepository::KIND_PERIOD, (string) $year, $id, $ctx->runId);
            $ctx->protocol->count(self::STEP_JOURNAL, 'periods_created');
            return ['id' => $id, 'starts_on' => $starts, 'ends_on' => $ends, 'status' => 'open', 'locked' => false];
        }
        $status = (string) $existing['status'];
        return [
            'id' => (int) $existing['id'],
            'starts_on' => (string) $existing['starts_on'],
            'ends_on' => (string) $existing['ends_on'],
            'status' => $status,
            'locked' => $status !== 'open',
        ];
    }

    /**
     * @param array<string,string> $names
     * @return array<string,mixed>|null
     */
    private function createSynthetic(PohodaContext $ctx, string $synthetic, array $names): ?array
    {
        $sibling = null;
        foreach ([2, 1] as $prefix) {
            foreach ($this->accounts->listForTenant($ctx->supplierId, true) as $row) {
                if (!empty($row['is_synthetic']) && str_starts_with((string) $row['account_code'], substr($synthetic, 0, $prefix))) {
                    $sibling = $row;
                    break 2;
                }
            }
        }
        if ($sibling === null) {
            $ctx->protocol->error(self::STEP_CHART, 'unknown_synthetic', "Syntetický účet {$synthetic} v osnově chybí a nelze odvodit jeho typ. Založte ho v Účetní osnově a spusťte převod znovu.", ['account' => $synthetic]);
            return null;
        }
        $id = $this->accounts->insert($ctx->supplierId, [
            'account_code' => $synthetic,
            'name' => mb_substr($names[$synthetic . '000'] ?? ('Účet ' . $synthetic), 0, 190),
            'account_type' => (string) $sibling['account_type'],
            'normal_side' => $sibling['normal_side'] ?? null,
            'is_synthetic' => true,
            'parent_id' => null,
            'is_active' => true,
        ]);
        $ctx->accountIds[$synthetic] = $id;
        $this->map->put($ctx->supplierId, PohodaImportRepository::KIND_ACCOUNT, $synthetic, $id, $ctx->runId);
        $ctx->protocol->warn(self::STEP_CHART, 'synthetic_created', "Syntetický účet {$synthetic} v osnově chyběl, založen s typem podle účtu {$sibling['account_code']}. Zkontrolujte jeho zařazení do výkazů.", ['account' => $synthetic]);
        return $this->accounts->findById($ctx->supplierId, $id);
    }
}
