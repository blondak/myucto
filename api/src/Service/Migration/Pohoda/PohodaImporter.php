<?php

declare(strict_types=1);

namespace MyInvoice\Service\Migration\Pohoda;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\AccountingPeriodRepository;
use MyInvoice\Repository\PohodaImportRepository;
use MyInvoice\Service\Migration\MoneyS3\AccountingUnitSwitch;
use MyInvoice\Service\Migration\MoneyS3\ImportProtocol;
use PDO;

/**
 * Převod agendy Pohody (XML export jednoho účetního roku) do existující firmy v MyÚčtu.
 *
 * Pořadí: osnova → období a deník → režim účetní jednotky → adresář a předkontace →
 * přijaté a vydané doklady → pokladna a banka → vazby dokladů na deník a úhrady →
 * rekonciliace. Automatika účtování je po celou dobu vypnutá ({@see AccountingUnitSwitch}).
 *
 * **Zkouška nanečisto** běží stejným kódem v jedné transakci, která se na konci vrátí.
 * **Ostrý převod** zapisuje po krocích, každý krok je idempotentní ({@see PohodaImportRepository}):
 * opakovaný běh téhož nebo novějšího exportu nic nezdvojí.
 */
final class PohodaImporter
{
    public const STEP_PREFLIGHT = 'preflight';
    public const STEP_ACCOUNTING_MODE = 'accounting_mode';

    /** Kroky, bez kterých nemá smysl pokračovat. */
    private const CRITICAL_STEPS = [ChartJournalImporter::STEP_CHART, ChartJournalImporter::STEP_JOURNAL, self::STEP_ACCOUNTING_MODE];

    public function __construct(
        private readonly Connection $db,
        private readonly PohodaImportRepository $map,
        private readonly AccountingPeriodRepository $periods,
        private readonly ChartJournalImporter $journal,
        private readonly AccountingUnitSwitch $unit,
        private readonly PartnerImporter $partners,
        private readonly InvoiceImporter $invoices,
        private readonly CashBankImporter $cashBank,
        private readonly DocumentLinker $linker,
        private readonly PohodaReconciler $reconciler,
        private readonly AssetImporter $assets,
        private readonly SmallAssetImporter $smallAssets,
    ) {}

    /** @return list<string> */
    public static function stepKeys(): array
    {
        return [
            ChartJournalImporter::STEP_CHART,
            ChartJournalImporter::STEP_JOURNAL,
            self::STEP_ACCOUNTING_MODE,
            PartnerImporter::STEP_PARTNERS,
            PartnerImporter::STEP_POSTING_RULES,
            InvoiceImporter::STEP_PURCHASE,
            InvoiceImporter::STEP_ISSUED,
            InvoiceImporter::STEP_INTERNAL,
            CashBankImporter::STEP_CASH,
            CashBankImporter::STEP_BANK,
            DocumentLinker::STEP_LINK,
            DocumentLinker::STEP_PAYMENTS,
            AssetImporter::STEP,
            SmallAssetImporter::STEP,
            PohodaReconciler::STEP,
        ];
    }

    /**
     * Kontrola před převodem - nic nezapisuje. Chyba převod zastaví, upozornění ne.
     *
     * @return list<array{level:string,code:string,message:string,context:array<string,mixed>}>
     */
    public function preflight(int $supplierId, PohodaExport $export): array
    {
        $out = [];
        $add = static function (string $level, string $code, string $message, array $context = []) use (&$out): void {
            $out[] = ['level' => $level, 'code' => $code, 'message' => $message, 'context' => $context];
        };
        $stmt = $this->db->pdo()->prepare('SELECT id, ic, company_name, accounting_mode FROM supplier WHERE id = ?');
        $stmt->execute([$supplierId]);
        $supplier = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($supplier === false) {
            $add('error', 'supplier_missing', 'Cílová firma neexistuje.');
            return $out;
        }
        $supplierIco = PartnerImporter::ico((string) ($supplier['ic'] ?? ''));
        if ($export->ico === '' || $supplierIco === '' || $export->ico !== $supplierIco) {
            // Export cizí firmy by se jinak vmíchal do účetnictví téhle.
            $add('error', 'ico_mismatch', "Export je agenda IČO {$export->ico}, firma v MyÚčtu má IČO {$supplierIco}.", ['agenda' => $export->ico, 'supplier' => $supplierIco]);
        }
        foreach ($export->files() as $f) {
            if (!$f['exists']) {
                continue;
            }
            if ($f['ico'] !== '' && $f['ico'] !== $export->ico) {
                $add('error', 'file_ico_mismatch', "Soubor {$f['file']} je z agendy IČO {$f['ico']}, ne {$export->ico}.", ['file' => $f['file']]);
            } elseif ($f['state'] !== 'ok') {
                $add(in_array($f['key'], ['journal', 'chart', 'vat_classes'], true) ? 'error' : 'info', 'file_state',
                    "Soubor {$f['file']}: Pohoda vrátila stav „{$f['state']}“" . ($f['note'] !== '' ? " ({$f['note']})" : '') . '.', ['file' => $f['file']]);
            }
        }
        $period = $this->periods->findByYear($supplierId, $export->year);
        if ($period !== null) {
            $mapped = $this->map->get($supplierId, PohodaImportRepository::KIND_PERIOD, (string) $export->year) !== null
                || $this->map->all($supplierId, PohodaImportRepository::KIND_JOURNAL_ENTRY) !== [];
            $foreign = $this->journal->foreignEntryCount($supplierId, (int) $period['id']);
            if ($foreign > 0) {
                $add('error', 'journal_not_empty', "Účetní období {$export->year} už obsahuje {$foreign} zápisů, které nevznikly převodem z Pohody. Deník z Pohody se do rozjetého účetnictví nepřimíchává.", ['entries' => $foreign]);
            }
            if ((string) $period['status'] !== 'open' && !$mapped) {
                $add('error', 'period_not_open', "Účetní období {$export->year} je v MyÚčtu uzavřené.");
            }
        }
        if (($supplier['accounting_mode'] ?? '') !== 'double_entry') {
            $add('info', 'switch_to_double_entry', 'Firma se převodem přepne do podvojného účetnictví.');
        }
        if ($export->path('payroll') !== null) {
            $add('info', 'payroll_separate', 'Export obsahuje mzdy z datového souboru POHODY (91_mzdy.xml). Převod účetnictví je nepřevádí, zaměstnanci a mzdy se převádějí zvlášť.');
        }
        return $out;
    }

    /**
     * @param (callable(string,int,int):void)|null $progress
     * @param (callable():bool)|null $shouldCancel
     */
    public function run(int $supplierId, int $userId, PohodaExport $export, bool $dryRun, ?int $runId = null, ?callable $progress = null, ?callable $shouldCancel = null): ImportProtocol
    {
        $protocol = new ImportProtocol($dryRun ? 'dry_run' : 'import');
        $protocol->set('agenda', [
            'ico' => $export->ico,
            'year' => $export->year,
            'program' => $export->info['program'],
            'exported_at' => $export->info['timestamp'],
            'dir' => basename($export->dir),
        ]);
        $preflight = $this->preflight($supplierId, $export);
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

        $ctx = new PohodaContext($supplierId, $userId, $export, PohodaVat::fromExport($export), $dryRun, $protocol);
        $ctx->runId = $runId;
        $ctx->progress = $progress;

        $pdo = $this->db->pdo();
        // Zkouška nanečisto uvnitř cizí transakce (testy, vnořené volání) jede přes savepoint -
        // vlastní BEGIN by PDO odmítlo a vnější transakci by nesměla vrátit.
        $savepoint = $dryRun && $pdo->inTransaction();
        if ($savepoint) {
            $pdo->exec('SAVEPOINT pohoda_dry_run');
        } elseif ($dryRun) {
            $pdo->beginTransaction();
        }
        try {
            $snapshot = ($dryRun ? null : $this->map->pendingAutomationSnapshot($supplierId)) ?? $this->unit->snapshot($supplierId);
            if (!$dryRun && $runId !== null) {
                $this->map->saveAutomationSnapshot($runId, $supplierId, $snapshot);
            }
            if (!$dryRun) {
                $this->unit->disableAutomation($supplierId, $ctx->userOrNull());
            }
            $automation = ['before' => $snapshot, 'during' => $dryRun ? 'off' : $this->unit->automationLevel($supplierId), 'restored' => false, 'after' => null];

            $steps = $this->steps($ctx);
            $total = count($steps);
            $index = 0;
            foreach ($steps as $key => $fn) {
                if ($shouldCancel !== null && $shouldCancel()) {
                    $protocol->fail('cancelled');
                    break;
                }
                $ctx->report($key, $index++, $total);
                $protocol->begin($key);
                try {
                    $dryRun ? $fn() : $this->transactional($fn);
                    $protocol->finish($key);
                } catch (\Throwable $e) {
                    if ($e instanceof PohodaException) {
                        $protocol->error($key, $e->errorCode, $e->getMessage());
                    } else {
                        // Text výjimky (SQL, cesty) do protokolu viditelného v UI nepatří.
                        error_log(sprintf('POHODA: krok %s převodu firmy %d selhal: %s', $key, $supplierId, (string) $e));
                        $protocol->error($key, 'unexpected', 'Krok převodu selhal na neočekávané chybě, podrobnosti jsou v logu serveru.');
                    }
                    $protocol->fail($key);
                    break;
                }
                if (in_array($key, self::CRITICAL_STEPS, true) && $this->stepFailed($protocol, $key)) {
                    $protocol->fail($key);
                    break;
                }
            }
            $ctx->report('done', $total, $total);
            if (!$dryRun) {
                $this->invoices->recomputeClientStats($ctx);
            }
            if (!$dryRun && !$protocol->hasErrors()) {
                $this->unit->restoreAutomation($supplierId, $snapshot, $ctx->userOrNull());
                $this->map->markAutomationRestored($supplierId);
                $automation['restored'] = true;
                $automation['after'] = $this->unit->automationLevel($supplierId);
            }
            $protocol->set('automation', $automation);
        } finally {
            if ($savepoint) {
                $pdo->exec('ROLLBACK TO SAVEPOINT pohoda_dry_run');
            } elseif ($dryRun && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
        }
        return $protocol;
    }

    /** @return array<string,callable():void> */
    private function steps(PohodaContext $ctx): array
    {
        return [
            ChartJournalImporter::STEP_CHART => fn () => $this->journal->chart($ctx),
            ChartJournalImporter::STEP_JOURNAL => fn () => $this->journal->journal($ctx),
            self::STEP_ACCOUNTING_MODE => fn () => $this->switchMode($ctx),
            PartnerImporter::STEP_PARTNERS => fn () => $this->partners->importPartners($ctx),
            PartnerImporter::STEP_POSTING_RULES => fn () => $this->partners->importPostingRules($ctx),
            InvoiceImporter::STEP_PURCHASE => fn () => $this->invoices->importPurchases($ctx),
            InvoiceImporter::STEP_ISSUED => fn () => $this->invoices->importIssued($ctx),
            InvoiceImporter::STEP_INTERNAL => fn () => $this->invoices->importInternalTaxDocuments($ctx),
            CashBankImporter::STEP_CASH => fn () => $this->cashBank->importCash($ctx),
            CashBankImporter::STEP_BANK => fn () => $this->cashBank->importBank($ctx),
            DocumentLinker::STEP_LINK => fn () => $this->linker->link($ctx),
            DocumentLinker::STEP_PAYMENTS => fn () => $this->linker->matchPayments($ctx),
            AssetImporter::STEP => fn () => $this->assets->import($ctx),
            SmallAssetImporter::STEP => fn () => $this->smallAssets->import($ctx),
            PohodaReconciler::STEP => fn () => $this->reconciler->run($ctx),
        ];
    }

    private function switchMode(PohodaContext $ctx): void
    {
        if ($ctx->period === null) {
            return;
        }
        $this->unit->switchToDoubleEntry($ctx->supplierId, $ctx->period['starts_on'], !$ctx->dryRun, $ctx->period['ends_on']);
        $ctx->protocol->info(self::STEP_ACCOUNTING_MODE, 'double_entry', 'Podvojné účetnictví od ' . $ctx->period['starts_on'] . '.');
    }

    private function stepFailed(ImportProtocol $protocol, string $key): bool
    {
        foreach ($protocol->toArray()['steps'] as $s) {
            if ($s['key'] === $key) {
                return $s['status'] === 'error';
            }
        }
        return false;
    }

    private function transactional(callable $fn): void
    {
        $pdo = $this->db->pdo();
        $own = !$pdo->inTransaction();
        if ($own) {
            $pdo->beginTransaction();
        }
        try {
            $fn();
            if ($own) {
                $pdo->commit();
            }
        } catch (\Throwable $e) {
            if ($own && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }
}
