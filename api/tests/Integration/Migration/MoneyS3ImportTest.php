<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Migration;

use MyInvoice\Action\Admin\Import\MoneyS3MigrationAction;
use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Config\Config;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\AccountingPeriodRepository;
use MyInvoice\Middleware\SupplierScopeMiddleware;
use MyInvoice\Repository\AccountingModeRepository;
use MyInvoice\Repository\ImportJobRepository;
use MyInvoice\Security\EffectiveRole;
use MyInvoice\Repository\JournalEntryRepository;
use MyInvoice\Repository\MoneyS3ImportRepository;
use MyInvoice\Service\Accounting\AutoPostingPolicyService;
use MyInvoice\Service\Accounting\ChartOfAccountsSeeder;
use MyInvoice\Service\Accounting\Reports\TrialBalanceService;
use MyInvoice\Service\Migration\MoneyS3\AgendaInfo;
use MyInvoice\Service\Migration\MoneyS3\ImportOptions;
use MyInvoice\Service\Migration\MoneyS3\ImportProtocol;
use MyInvoice\Service\Migration\MoneyS3\MoneyS3Exception;
use MyInvoice\Service\Migration\MoneyS3\MoneyS3Importer;
use MyInvoice\Service\Migration\MoneyS3\MoneyS3ImportJobService;
use MyInvoice\Service\Migration\MoneyS3\Ms3Backup;
use MyInvoice\Service\Report\VatLedgerService;
use MyInvoice\Tests\Fixtures\MoneyS3\SyntheticAgenda;
use PDO;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Slim\Psr7\Factory\ResponseFactory;
use Slim\Psr7\Factory\ServerRequestFactory;

/**
 * Převod syntetické agendy Money S3 do firmy v MyÚčtu — celý řetěz nad skutečnou DB:
 * záloha → deník, doklady, banka, pokladna → vazby a úhrady → uzávěrka 2024 →
 * rekonciliace. Izolovaná firma, transakce s rollbackem v tearDown.
 */
#[Group('integration')]
final class MoneyS3ImportTest extends TestCase
{
    private \Psr\Container\ContainerInterface $container;
    private Connection $db;
    private MoneyS3Importer $importer;
    private MoneyS3ImportRepository $map;
    private AutoPostingPolicyService $policy;
    private string $tmp = '';
    private int $userId = 0;
    private int $anyCurrencyId = 0;
    private int $vatRateId = 0;
    private int $czId = 0;
    private bool $inTx = false;

    protected function setUp(): void
    {
        if (!is_file(dirname(__DIR__, 4) . '/cfg.php')) {
            $this->markTestSkipped('cfg.php neexistuje — test vyžaduje DB connection.');
        }
        try {
            $container = Bootstrap::buildApp()->getContainer();
            $this->container = $container;
            $this->db = $container->get(Connection::class);
            $this->importer = $container->get(MoneyS3Importer::class);
            $this->map = $container->get(MoneyS3ImportRepository::class);
            $this->policy = $container->get(AutoPostingPolicyService::class);
        } catch (\Throwable $e) {
            $this->markTestSkipped('DI nedostupné: ' . $e->getMessage());
        }
        $pdo = $this->db->pdo();
        $this->userId = (int) ($pdo->query('SELECT id FROM users ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        $this->anyCurrencyId = (int) ($pdo->query("SELECT id FROM currencies WHERE code = 'CZK' ORDER BY id LIMIT 1")->fetchColumn() ?: 0);
        $this->vatRateId = (int) ($pdo->query('SELECT id FROM vat_rates ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        $this->czId = (int) ($pdo->query("SELECT id FROM countries WHERE iso2 = 'CZ' LIMIT 1")->fetchColumn() ?: 0);
        if ($this->userId === 0 || $this->anyCurrencyId === 0 || $this->vatRateId === 0 || $this->czId === 0) {
            $this->markTestSkipped('Chybí základní data (user/currency/vat_rate/country) v DB.');
        }

        $this->tmp = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'ms3int_' . bin2hex(random_bytes(5));
        mkdir($this->tmp, 0755, true);
        SyntheticAgenda::writeLz($this->tmp . '/agenda.lz');
        file_put_contents($this->tmp . '/predvaha-2024.csv', SyntheticAgenda::trialBalanceCsv2024());

        $pdo->beginTransaction();
        $this->inTx = true;
    }

    protected function tearDown(): void
    {
        if (isset($this->db) && $this->inTx) {
            $pdo = $this->db->pdo();
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $this->db->close();
        }
        if ($this->tmp !== '' && is_dir($this->tmp)) {
            $it = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($this->tmp, \FilesystemIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::CHILD_FIRST,
            );
            foreach ($it as $f) {
                $f->isDir() ? @rmdir($f->getPathname()) : @unlink($f->getPathname());
            }
            @rmdir($this->tmp);
        }
    }

    public function testRoundTripReconcilesToTheCent(): void
    {
        $supplierId = $this->supplier();
        $protocol = $this->import($supplierId, ImportOptions::MODE_IMPORT, [2024 => $this->tmp . '/predvaha-2024.csv']);

        self::assertFalse($protocol->hasErrors(), $this->explain($protocol));
        $reconciliation = $protocol->get('reconciliation');
        self::assertCount(2, $reconciliation);
        foreach ($reconciliation as $year) {
            self::assertTrue($year['ok'], "Rok {$year['year']} nesedí: " . json_encode($year, JSON_UNESCAPED_UNICODE));
            self::assertSame([], $year['journal_diffs']);
        }
        $checks2024 = array_column($reconciliation[0]['checks'], 'ok', 'key');
        self::assertTrue($checks2024['money_report'], 'Předvaha z Money (ručně spočtená) musí sedět na haléř.');

        // 2024: 4 řádky XP → 1 otevírací zápis (nulový a degenerovaný vypadnou),
        // 7 dokladů; smazaný doklad FP24099 se nepřenese.
        self::assertSame(8, $this->rowCount('journal_entries', $supplierId, "YEAR(entry_date) = 2024 AND source_type <> 'closing'"));
        self::assertSame(0, $this->rowCount('journal_entries', $supplierId, "document_no = 'FP24099'"));
        // Zálohová ZF24001 se převede (nezaúčtovaná), koncept RC-2024-001 z uzavřeného roku ne;
        // přibyla licence z EU FP25005 se samovyměřením.
        self::assertSame(12, $this->rowCount('purchase_invoices', $supplierId));
        // Tři vydané faktury a ostatní pohledávka PH25001, která je v přiznání DPH.
        self::assertSame(4, $this->rowCount('invoices', $supplierId));
        self::assertSame(4, $this->rowCount('cash_documents', $supplierId));
        // Šest partnerů z adresáře a dokladů; zakázky z Money jsou dimenze, klienta nezakládají.
        self::assertSame(7, $this->rowCount('clients', $supplierId));
        // Dva záznamy adresáře se stejným IČO (s vodicími nulami i bez) = jedna karta, doplněná z obou.
        self::assertSame(1, $this->rowCount('clients', $supplierId,
            "ic = '00012346' AND main_email = 'obec@example.invalid' AND street = 'Náměstí 1'"));
        // Značka člena skupiny DPH není DIČ: karta DIČ nemá, ale je plátcem.
        self::assertSame(1, $this->rowCount('clients', $supplierId, "ic = '00087650' AND dic IS NULL AND is_vat_payer = 1"));
        // Zahraniční partner má zemi podle DIČ — jinak by dodání do EU chybělo v souhrnném hlášení.
        self::assertSame(1, $this->rowCount('clients', $supplierId,
            "dic = 'DE123456789' AND country_id = (SELECT id FROM countries WHERE iso2 = 'DE')"));
        // Identifikátor, který není DIČ z EU (rejstříkové číslo), zemi neurčí — tu dá adresář Money.
        self::assertSame(1, $this->rowCount('clients', $supplierId,
            "dic = 'FN123456A' AND country_id = (SELECT id FROM countries WHERE iso2 = 'AT')"));
        // Údaj s číslicemi v poli státu (rodné číslo) zemí není: partner je tuzemský a obsah
        // pole se nevypisuje do protokolu jako neznámý stát.
        $codes = [];
        foreach ($protocol->toArray()['steps'] ?? [] as $step) {
            foreach ($step['messages'] ?? [] as $m) {
                $codes[] = $m['code'];
            }
        }
        self::assertNotContains('country_unknown', $codes, $this->explain($protocol));
        self::assertSame(2, $this->rowCount('payment_matches', $supplierId));
        // Čárový kód z Money (BarCode) je klíč pro připojení naskenovaných příloh.
        self::assertSame(1, $this->rowCount('purchase_invoices', $supplierId, "external_barcode = '90000101'"));
        self::assertSame(1, $this->rowCount('cash_documents', $supplierId, "external_barcode = '90000201'"));
        // FP24002 Money nezaúčtovalo — doklad je, zápis v deníku ne.
        self::assertSame([['purchase_invoice', 2024, 'FP24002']],
            array_map(static fn (array $o): array => [$o['type'], $o['year'], $o['document_no']], $protocol->get('orphans')));
        // DZ25001 Money účtuje jen 343/314 a FP25002 placenou kartou včetně úhrady (321 nulový) —
        // do kontroly dokladů proti 321 nepatří, vykážou se zvlášť.
        $documents2025 = array_column($reconciliation[1]['documents'], null, 'key');
        self::assertSame(2, $documents2025['purchase_invoices']['other_accounts']);

        $tb = $this->container(TrialBalanceService::class)->build($supplierId, $this->periodId($supplierId, 2024), null, null, false);
        $rows = array_column($tb['rows'], null, 'account_code');
        self::assertEqualsWithDelta(62150.0, $rows['221']['ks_md'], 0.001);
        self::assertEqualsWithDelta(20000.0, $rows['602']['turnover_d'], 0.001);
    }

    /**
     * DPH evidence (VatLedgerService, z níž se staví přiznání i KH) dostane z převodu doklady
     * podle druhu a členění DPH z Money: daňový doklad k záloze, dobropis (záporné částky),
     * doklad v cizí měně (Money drží částky v Kč) a krácený odpočet § 76. Zálohová faktura
     * do evidence nejde — DPH vedle konečné faktury by se započetlo dvakrát — a doklad
     * s členěním, které převod nezná (přenesená povinnost na vstupu), zůstane konceptem.
     */
    public function testMoneyVatClassificationDrivesVatLedger(): void
    {
        $supplierId = $this->supplier();
        $protocol = $this->import($supplierId);
        self::assertFalse($protocol->hasErrors(), $this->explain($protocol));

        $ledger = $this->container(VatLedgerService::class);
        $rows = $ledger->rows($supplierId, '2025-01-01', '2025-12-31');
        $vat = ['purchase' => 0.0, 'sale' => 0.0];
        $selfAssessed = [];
        foreach ($rows as $r) {
            if (!empty($r['is_reverse_charge'])) {
                $selfAssessed[] = $r;
                continue;
            }
            $vat[$r['source']] = ($vat[$r['source']] ?? 0.0) + (float) $r['vat_czk'];
        }
        // Licence z EU FP25005: samovyměření z interního dokladu ICH25001 — přijetí služby
        // z EU (ř. 5) a zrcadlový odpočet ve sloupci krácený (ř. 43k), 21 % z 1 000.
        self::assertCount(1, $selfAssessed, json_encode($selfAssessed, JSON_UNESCAPED_UNICODE) ?: '');
        self::assertSame(['24e', '5', '43k'], [$selfAssessed[0]['code'], $selfAssessed[0]['dphdp3_line'], $selfAssessed[0]['dphdp3_line_secondary']]);
        self::assertEqualsWithDelta(210.0, (float) $selfAssessed[0]['vat_czk'], 0.001);
        self::assertSame('2025-07-10', $selfAssessed[0]['tax_date']);
        // FP25001 1 050 + FP25002 210 + FP24001 z roku 2025 63 + pokladna PV25002 21
        // + daňový doklad k záloze DZ25001 210 + doklad v EUR FP25004 525 − dobropis DP25001 105.
        self::assertEqualsWithDelta(1974.0, $vat['purchase'], 0.001, json_encode($rows, JSON_UNESCAPED_UNICODE) ?: '');
        // Vydaná FV25001 210 + ostatní pohledávka PH25001 s DPH 210 (věcné břemeno, kniha KP);
        // zálohová ZV25001 do evidence nejde.
        self::assertEqualsWithDelta(420.0, $vat['sale'], 0.001);
        $receivable = $this->db->pdo()->prepare(
            "SELECT i.id, i.status, (SELECT e.source_type FROM journal_entries e WHERE e.supplier_id = i.supplier_id AND e.source_id = i.id
                      AND e.source_type = 'invoice' LIMIT 1) AS entry_source
               FROM invoices i WHERE i.supplier_id = ? AND i.varsymbol = 'PH25001'"
        );
        $receivable->execute([$supplierId]);
        $receivable = $receivable->fetch(PDO::FETCH_ASSOC);
        self::assertNotFalse($receivable, 'Ostatní pohledávka s DPH se převede jako vydaný doklad.');
        self::assertSame('invoice', $receivable['entry_source'], 'Zápis KP z Money je zápisem dokladu — kontrola 343 ho páruje s evidencí DPH.');

        $stmt = $this->db->pdo()->prepare(
            'SELECT vendor_invoice_number, document_kind, status, booked_at FROM purchase_invoices WHERE supplier_id = ? AND YEAR(issue_date) = 2025'
        );
        $stmt->execute([$supplierId]);
        $byNumber = array_column($stmt->fetchAll(PDO::FETCH_ASSOC), null, 'vendor_invoice_number');
        self::assertSame(['credit_note', 'booked'], [$byNumber['DB-2025-001']['document_kind'], $byNumber['DB-2025-001']['status']]);
        self::assertSame(['tax_document', 'booked'], [$byNumber['DZ-2025-001']['document_kind'], $byNumber['DZ-2025-001']['status']]);
        self::assertSame(['advance', 'received'], [$byNumber['ZF-2025-001']['document_kind'], $byNumber['ZF-2025-001']['status']]);
        self::assertNull($byNumber['ZF-2025-001']['booked_at'], 'Zálohovou fakturu Money neúčtuje.');
        self::assertSame(['invoice', 'draft'], [$byNumber['RC-2025-001']['document_kind'], $byNumber['RC-2025-001']['status']]);
        self::assertNull($byNumber['RC-2025-001']['booked_at'], 'Koncept k ruční kontrole nesmí být zamčený jako zaúčtovaný.');
        self::assertSame('booked', $byNumber['EU-2025-001']['status']);
        self::assertSame(1, $this->rowCount('invoices', $supplierId, "invoice_type = 'proforma' AND status = 'sent' AND booked_at IS NULL AND varsymbol = 'ZV25001'"));
        self::assertSame(0, $this->rowCount('invoices', $supplierId, "status = 'draft'"));

        // Krácený odpočet § 76 z členění pokladního dokladu (19Ř40,41 K).
        $cash = $this->db->pdo()->prepare(
            "SELECT l.vat_deduction FROM cash_document_vat_lines l JOIN cash_documents d ON d.id = l.cash_document_id
              WHERE d.supplier_id = ? AND d.doc_number = 'PV25002'"
        );
        $cash->execute([$supplierId]);
        self::assertSame('reduced', $cash->fetchColumn());

        // Odpočet, který Money přesunulo do února (UcPrvDPH), se uplatní v únoru.
        $claim = $this->db->pdo()->prepare(
            "SELECT received_at, received_at_source FROM purchase_invoices WHERE supplier_id = ? AND vendor_invoice_number = 'DF-2025-003'"
        );
        $claim->execute([$supplierId]);
        $shifted = $claim->fetch(PDO::FETCH_ASSOC);
        self::assertSame(['2025-02-03', 'manual'], [substr((string) $shifted['received_at'], 0, 10), $shifted['received_at_source']]);
        $purchases = static fn (array $rows): array => array_values(array_filter($rows, static fn (array $r): bool => $r['source'] === 'purchase'));
        self::assertSame([], $purchases($ledger->rows($supplierId, '2025-01-01', '2025-01-31')), 'Odpočet FP25001 v lednu není.');
        self::assertCount(1, $purchases($ledger->rows($supplierId, '2025-02-01', '2025-02-28')));

        $steps = array_column($protocol->toArray()['steps'], null, 'key');
        self::assertSame(1, $steps['purchase_invoices']['counts']['review'] ?? 0);
        self::assertSame(0, $steps['issued_invoices']['counts']['review'] ?? 0);
        self::assertSame(1, $steps['purchase_invoices']['counts']['claim_shifted'] ?? 0);
        self::assertContains('needs_review', array_column($steps['purchase_invoices']['messages'], 'code'));
    }

    /**
     * Money čísluje řadu každý rok od začátku. Faktura FP24001 z roku 2025 narazí na
     * unikátní číslo dokladu z roku 2024 — nesmí shodit celý krok přijatých faktur
     * (a s ním pokladnu, banku, vazby a uzávěrku).
     */
    public function testRepeatedMoneyNumberingAcrossYearsDoesNotAbortImport(): void
    {
        $supplierId = $this->supplier();
        $protocol = $this->import($supplierId);

        self::assertFalse($protocol->hasErrors(), $this->explain($protocol));
        self::assertSame(1, $this->rowCount('purchase_invoices', $supplierId, "varsymbol = 'FP24001' AND vendor_invoice_number = 'DF-2024-017'"));
        self::assertSame(1, $this->rowCount('purchase_invoices', $supplierId, "varsymbol = 'FP24001/2025' AND vendor_invoice_number = 'DF-2025-020'"));
        $steps = array_column($protocol->toArray()['steps'], null, 'key');
        foreach (['purchase_invoices', 'cash', 'bank', 'link', 'payments', 'closing'] as $key) {
            self::assertNotSame('error', $steps[$key]['status'] ?? 'missing', "Krok {$key} nesmí selhat.");
        }
    }

    /**
     * Faktura převedená dřív jako neuhrazená a spárovaná s úhradou až v dalším běhu
     * (novější záloha) musí dostat i stav „uhrazeno" — samotný záznam párování nestačí.
     */
    public function testLaterRunMarksInvoicePaidWhenItsPaymentIsLinked(): void
    {
        $supplierId = $this->supplier();
        $this->import($supplierId);
        $pdo = $this->db->pdo();
        $find = $pdo->prepare("SELECT id FROM purchase_invoices WHERE supplier_id = ? AND vendor_invoice_number = 'DF-2024-017'");
        $find->execute([$supplierId]);
        $invoiceId = (int) $find->fetchColumn();
        // Stav po dřívějším běhu, kdy faktura v záloze ještě uhrazená nebyla.
        $pdo->prepare("UPDATE purchase_invoices SET status = 'booked', paid_at = NULL WHERE id = ? AND supplier_id = ?")->execute([$invoiceId, $supplierId]);
        $pdo->prepare('DELETE FROM payment_matches WHERE purchase_invoice_id = ? AND supplier_id = ?')->execute([$invoiceId, $supplierId]);
        $pdo->prepare("DELETE FROM money_s3_import_map WHERE supplier_id = ? AND kind = 'payment' AND money_key = 'p|2024|FP24001'")->execute([$supplierId]);

        $protocol = $this->import($supplierId);

        self::assertFalse($protocol->hasErrors(), $this->explain($protocol));
        self::assertSame(1, $this->rowCount('payment_matches', $supplierId, "purchase_invoice_id = {$invoiceId}"));
        self::assertSame(1, $this->rowCount('purchase_invoices', $supplierId, "id = {$invoiceId} AND status = 'paid' AND paid_at = '2024-02-20'"));
    }

    /**
     * Opakovaný převod novější zálohy existující doklad nepřepisuje (může být už
     * zaúčtovaný nebo upravený v MyÚčtu), ale změnu v Money musí ohlásit.
     */
    public function testChangeInMoneyAfterImportIsReportedNotOverwritten(): void
    {
        $supplierId = $this->supplier();
        $this->import($supplierId);
        $this->db->pdo()->prepare("UPDATE purchase_invoices SET total_with_vat = 6000 WHERE supplier_id = ? AND vendor_invoice_number = 'DF-2025-003'")
            ->execute([$supplierId]);

        $protocol = $this->import($supplierId);

        $messages = array_column($protocol->toArray()['steps'], null, 'key')['purchase_invoices']['messages'];
        $changed = array_values(array_filter($messages, static fn (array $m): bool => $m['code'] === 'changed_in_money'));
        self::assertCount(1, $changed, $this->explain($protocol));
        self::assertSame('FP25001', $changed[0]['context']['document_no']);
        self::assertSame(1, $this->rowCount('purchase_invoices', $supplierId, "vendor_invoice_number = 'DF-2025-003' AND total_with_vat = 6000"));
    }

    public function testMoneyPaymentMethodIsKeptOnPurchaseInvoice(): void
    {
        $supplierId = $this->supplier();
        $this->import($supplierId);

        self::assertSame(1, $this->rowCount('purchase_invoices', $supplierId, "vendor_invoice_number = 'DF-2025-010' AND payment_method = 'card'"));
        self::assertSame(1, $this->rowCount('purchase_invoices', $supplierId, "vendor_invoice_number = 'DF-2025-003' AND payment_method = 'bank_transfer'"));
    }

    public function testNewYearsDayEntryStaysInNewYear(): void
    {
        $supplierId = $this->supplier();
        $this->import($supplierId);

        $stmt = $this->db->pdo()->prepare(
            "SELECT e.entry_date, p.fiscal_year FROM journal_entries e JOIN accounting_periods p ON p.id = e.period_id
              WHERE e.supplier_id = ? AND e.document_no = 'ID25001'"
        );
        $stmt->execute([$supplierId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        self::assertSame(['entry_date' => '2025-01-01', 'fiscal_year' => 2025], ['entry_date' => $row['entry_date'], 'fiscal_year' => (int) $row['fiscal_year']]);
        self::assertSame(1, $this->rowCount('journal_entries', $supplierId, "entry_date = '2024-12-31' AND source_type <> 'closing'"),
            'Z deníku Money patří na 31. 12. 2024 jen ID24001 (uzávěrkový zápis roku je vlastní zápis MyÚčta).');
    }

    /**
     * Smazaná faktura zůstává v souboru Money s příznakem `FlagDel` a její číslo řada
     * přidělí znovu. Převod ji nesmí vzít jako druhý doklad téhož čísla — mapa by hlásila
     * konflikt a zastavila celý převod.
     */
    public function testDeletedInvoiceWithReusedNumberIsSkipped(): void
    {
        $supplierId = $this->supplier();
        $protocol = $this->import($supplierId);

        self::assertFalse($protocol->hasErrors(), $this->explain($protocol));
        self::assertSame(1, $this->rowCount('purchase_invoices', $supplierId, "vendor_invoice_number = 'DF-2025-003'"));
        self::assertSame(0, $this->rowCount('purchase_invoices', $supplierId, "vendor_invoice_number = 'SMAZ-2025-001'"));
    }

    /**
     * Money vede v knize roku i doklad s datem z vedlejšího roku a počítá ho do obratů
     * toho roku. Zápis jde k prvnímu dni období, původní datum zůstává jako datum dokladu.
     */
    public function testEntryDatedOutsideItsBookYearIsPostedAtPeriodBoundary(): void
    {
        $supplierId = $this->supplier();
        $protocol = $this->import($supplierId);

        self::assertFalse($protocol->hasErrors(), $this->explain($protocol));
        $stmt = $this->db->pdo()->prepare(
            "SELECT e.entry_date, e.document_date, p.fiscal_year FROM journal_entries e JOIN accounting_periods p ON p.id = e.period_id
              WHERE e.supplier_id = ? AND e.document_no = 'ID25002'"
        );
        $stmt->execute([$supplierId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        self::assertSame(['entry_date' => '2025-01-01', 'document_date' => '2024-12-31', 'fiscal_year' => 2025],
            ['entry_date' => $row['entry_date'], 'document_date' => $row['document_date'], 'fiscal_year' => (int) $row['fiscal_year']]);
        $journal = array_column($protocol->toArray()['steps'], null, 'key')['journal'];
        self::assertContains('entry_date_outside_year', array_column($journal['messages'], 'code'));
    }

    /**
     * Skupinu 61 osnova od roku 2016 nemá, šablona MyÚčta taky ne. Typ účtu se převezme
     * od sourozence ze stejné třídy (6 = výnosy), jinak by převod staré agendy skončil.
     */
    public function testSyntheticFromRetiredGroupTakesTypeFromAccountClass(): void
    {
        $supplierId = $this->supplier();
        $protocol = $this->import($supplierId);

        self::assertFalse($protocol->hasErrors(), $this->explain($protocol));
        $stmt = $this->db->pdo()->prepare(
            "SELECT account_code, account_type FROM chart_of_accounts WHERE supplier_id = ? AND account_code IN ('602', '613', '613.000') ORDER BY account_code"
        );
        $stmt->execute([$supplierId]);
        $types = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $types[(string) $r['account_code']] = (string) $r['account_type'];
        }
        self::assertSame(['602', '613', '613.000'], array_map('strval', array_keys($types)));
        self::assertSame($types['602'], $types['613']);
        self::assertSame($types['602'], $types['613.000']);
    }

    /**
     * Uzávěrkové zápisy Money (zdroj XZ) převádějí konečné stavy na 702/710. Převzaté by
     * vynulovaly konečné stavy roku a uzávěrka MyÚčta by nesouhlasila s počátečními stavy
     * dalšího roku — rok by zůstal otevřený.
     */
    public function testMoneyYearEndClosingIsNotImported(): void
    {
        $supplierId = $this->supplier();
        $protocol = $this->import($supplierId);

        self::assertFalse($protocol->hasErrors(), $this->explain($protocol));
        self::assertSame('closed', array_column($protocol->get('closing'), null, 'year')[2024]['status']);
        self::assertSame(0, $this->rowCount('journal_entries', $supplierId, "description = 'Účetní závěrka roku 2024'"));
        $journal = array_column($protocol->toArray()['steps'], null, 'key')['journal'];
        self::assertContains('year_end_closing_skipped', array_column($journal['messages'], 'code'));
    }

    /**
     * Záporný pokladní příjem je vratka (peníze odešly) — jde jako výdej, jinak by pokladna
     * nesouhlasila s deníkem. Nulový doklad MyÚčto nepřijme a v pokladně nemá účinek.
     */
    public function testNegativeCashReceiptBecomesExpenseAndZeroIsSkipped(): void
    {
        $supplierId = $this->supplier();
        $protocol = $this->import($supplierId);

        self::assertFalse($protocol->hasErrors(), $this->explain($protocol));
        self::assertSame(1, $this->rowCount('cash_documents', $supplierId, "doc_number = 'PP25001' AND doc_type = 'out' AND total_amount = 200"));
        self::assertSame(0, $this->rowCount('cash_documents', $supplierId, "doc_number = 'PP25002'"));
        $cash = array_column($protocol->toArray()['steps'], null, 'key')['cash'];
        self::assertSame(1, $cash['counts']['zero_amount'] ?? 0);
    }

    /**
     * Money rok uzavřelo i s fakturou, kterou nezaúčtovalo. Průvodce uzávěrkou MyÚčta by
     * kvůli ní historický rok neuzavřel nikdy — u dokladů převzatých z Money proto uzavře
     * knihy s výjimkou a důvodem (audit + závěrkový balíček).
     */
    public function testHistoricalYearClosesDespiteDocumentMoneyLeftUnposted(): void
    {
        $supplierId = $this->supplier();
        $protocol = $this->import($supplierId);

        self::assertFalse($protocol->hasErrors(), $this->explain($protocol));
        self::assertSame('closed', array_column($protocol->get('closing'), null, 'year')[2024]['status']);
        self::assertSame(1, $this->rowCount('purchase_invoices', $supplierId, "vendor_invoice_number = 'DF-2024-099' AND status <> 'draft'"));
    }

    /**
     * Money dovolí ručně přepsat počáteční stavy, takže konečné stavy roku nemusí navazovat
     * na další rok. Uzávěrka MyÚčta takový rok neuzavře a s ním ani žádný pozdější — kontrola
     * před převodem to musí říct předem a doporučit rok, od kterého roky navazují.
     */
    public function testBrokenYearChainIsReportedWithSuggestedStartYear(): void
    {
        $supplierId = $this->supplier();
        SyntheticAgenda::writeLzFiles($this->tmp . '/reclass.lz', SyntheticAgenda::filesWithOpeningReclass());
        $backup = Ms3Backup::extract($this->tmp . '/reclass.lz', $this->tmp . '/reclass');

        $preflight = $this->importer->preflight($supplierId, $backup, AgendaInfo::fromBackup($backup), new ImportOptions());

        $byCode = array_column($preflight, null, 'code');
        self::assertSame('warning', $byCode['opening_chain_break']['level'] ?? null, json_encode($preflight, JSON_UNESCAPED_UNICODE) ?: '');
        self::assertSame(['year' => 2024, 'next' => 2025], array_intersect_key($byCode['opening_chain_break']['context'], ['year' => 0, 'next' => 0]));
        self::assertEqualsWithDelta(100.0, $byCode['opening_chain_break']['context']['accounts']['211000'], 0.001);
        self::assertSame(2025, $byCode['suggested_from_year']['context']['from_year'] ?? null);

        $clean = $this->importer->preflight($supplierId, $this->backup(), AgendaInfo::fromBackup($this->backup()), new ImportOptions());
        self::assertNotContains('opening_chain_break', array_column($clean, 'code'));
    }

    /**
     * „Převést od roku": starší roky i jejich doklady se vynechají, první převedený rok
     * s počátečními stavy začíná 1. 1. a převod je bez chyb.
     */
    public function testFromYearSkipsOlderYearsAndTheirDocuments(): void
    {
        $supplierId = $this->supplier();
        $protocol = $this->importer->run($supplierId, $this->userId, $this->backup(), new ImportOptions(ImportOptions::MODE_IMPORT, true, null, [], [], false, 2025));

        self::assertFalse($protocol->hasErrors(), $this->explain($protocol));
        $stmt = $this->db->pdo()->prepare('SELECT fiscal_year, starts_on FROM accounting_periods WHERE supplier_id = ? ORDER BY fiscal_year');
        $stmt->execute([$supplierId]);
        self::assertSame([['fiscal_year' => 2025, 'starts_on' => '2025-01-01']], array_map(
            static fn (array $r): array => ['fiscal_year' => (int) $r['fiscal_year'], 'starts_on' => (string) $r['starts_on']],
            $stmt->fetchAll(PDO::FETCH_ASSOC)
        ));
        self::assertSame(0, $this->rowCount('purchase_invoices', $supplierId, "vendor_invoice_number IN ('DF-2024-017', 'DF-2024-099')"));
        self::assertSame(0, $this->rowCount('invoices', $supplierId, "varsymbol = 'FV24001'"));
        self::assertSame(1, $this->rowCount('purchase_invoices', $supplierId, "vendor_invoice_number = 'DF-2025-020'"));
    }

    /**
     * Předvaha může sedět na haléř, a přesto výkaz nevyjde: účet, který mapa výkazů nezná
     * (syntetika založená podle osnovy Money), ve výkazech chybí. Rekonciliace to musí
     * odhalit a účet jmenovat.
     */
    public function testUnmappedAccountFailsBalanceSheetCheck(): void
    {
        $supplierId = $this->supplier();
        $this->db->pdo()->exec("DELETE FROM statement_account_map WHERE account_prefix = '325'");

        $protocol = $this->import($supplierId);

        $year2024 = array_column($protocol->get('reconciliation'), null, 'year')[2024];
        self::assertFalse(array_column($year2024['checks'], 'ok', 'key')['balance_sheet_balanced']);
        self::assertSame(['325'], array_column($year2024['unmapped_accounts'], 'account'));
    }

    /**
     * Pokladní nákup s DPH (tankování, PHM) je v Money v přiznání i v deníku na 343. Převzatý
     * bez DPH by v přiznání chyběl a kontrola obratu 343 by přiznání zablokovala.
     */
    public function testCashDocumentVatGoesToVatLedger(): void
    {
        $supplierId = $this->supplier();
        $this->import($supplierId);

        self::assertSame(1, $this->rowCount('cash_documents', $supplierId, "doc_number = 'PV25002' AND vat_mode = 'vat'"));
        $rows = $this->container(VatLedgerService::class)->rows($supplierId, '2025-06-01', '2025-06-30');
        $cash = array_values(array_filter($rows, static fn (array $r): bool => ($r['document_number'] ?? $r['doc_number'] ?? '') === 'PV25002' || str_contains(json_encode($r) ?: '', 'PV25002')));
        self::assertCount(1, $cash, json_encode($rows, JSON_UNESCAPED_UNICODE) ?: '');
        self::assertEqualsWithDelta(21.0, (float) $cash[0]['vat_czk'], 0.001);
        self::assertSame('purchase', $cash[0]['source']);
    }

    /**
     * Pohyby účtu se převedou do měsíčních výpisů (Money čísluje výpisy po dnech) a každý
     * nese počáteční a konečný stav — začátek roku z otevíracího zápisu účtu banky v deníku,
     * dál po pohybech. Převzatý výpis je výpis se zůstatkem: vidí ho záložka Stavy na účtech
     * a jde z něj vytvořit GPC.
     */
    public function testImportedBankStatementsAreMonthlyWithBalances(): void
    {
        $supplierId = $this->supplier();
        $this->import($supplierId);

        $stmt = $this->db->pdo()->prepare(
            "SELECT id, statement_number, currency, prev_balance, curr_balance FROM bank_statements
              WHERE supplier_id = ? AND statement_number LIKE 'BU/2024/%' ORDER BY statement_date, id"
        );
        $stmt->execute([$supplierId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        self::assertSame(['BU/2024/02', 'BU/2024/03', 'BU/2024/06'], array_column($rows, 'statement_number'));
        self::assertSame(
            [[50000.0, 37900.0], [37900.0, 62100.0], [62100.0, 62150.0]],
            array_map(static fn (array $r): array => [(float) $r['prev_balance'], (float) $r['curr_balance']], $rows)
        );
        self::assertSame('CZK', $rows[0]['currency']);

        $snapshot = $this->container(\MyInvoice\Service\Bank\StatementBalanceService::class)->snapshot($supplierId, (int) $rows[1]['id']);
        self::assertSame('confirmed', $snapshot['status'], json_encode($snapshot, JSON_UNESCAPED_UNICODE) ?: '');
        self::assertEqualsWithDelta(62100.0, (float) $snapshot['closing'], 0.001);
        self::assertStringStartsWith('074', $this->container(\MyInvoice\Service\Bank\GpcExporter::class)->export($snapshot));
        // Reference platby kartou z pole VS v Money není variabilní symbol: zůstane v popisu
        // a výpis s ní jde do GPC (pevné pole VS má 10 číslic).
        $tx = $this->db->pdo()->prepare(
            "SELECT COUNT(*) FROM bank_transactions WHERE statement_id = ? AND bank_ref = 'BP24003'
                AND variable_symbol IS NULL AND description LIKE '%ref. 955000000000017%'"
        );
        $tx->execute([(int) $rows[2]['id']]);
        self::assertSame(1, (int) $tx->fetchColumn());
        $juneSnapshot = $this->container(\MyInvoice\Service\Bank\StatementBalanceService::class)->snapshot($supplierId, (int) $rows[2]['id']);
        self::assertStringStartsWith('074', $this->container(\MyInvoice\Service\Bank\GpcExporter::class)->export($juneSnapshot));
        self::assertSame(1, $this->rowCount('currencies', $supplierId, "code = 'CZK' AND account_number = '3000000004'"),
            'Účet firmy se bere z posledního roku agendy (při shodě první účet).');
        // Účet je v evidenci účtů firmy s analytikou podle Money (PrimUcet 221001 → 221.001),
        // jinak záložka Kontace zůstane prázdná a pohyby nemají kam se zaúčtovat.
        self::assertSame(1, $this->rowCount('supplier_bank_accounts', $supplierId,
            "account_number = '3000000004' AND analytic_suffix = '001' AND label = 'Běžný účet' AND currency = 'CZK'"));
    }

    /**
     * Účet v cizí měně: pohyby Money vede ve valutách, ale stav účtu z doby před první
     * knihou zálohy pohybem není. Počáteční stav proto kotví deník — korunový počáteční
     * stav účtu přepočtený kurzem počátečního stavu z Money (`PSKurz`) — a výpisy navazují.
     */
    public function testForeignAccountOpeningIsAnchoredToLedger(): void
    {
        $supplierId = $this->supplier();
        SyntheticAgenda::writeLzFiles($this->tmp . '/foreign.lz', SyntheticAgenda::filesWithForeignAccount());
        $backup = Ms3Backup::extract($this->tmp . '/foreign.lz', $this->tmp . '/foreign');
        $protocol = $this->importer->run($supplierId, $this->userId, $backup, new ImportOptions(ImportOptions::MODE_IMPORT, true));
        self::assertFalse($protocol->hasErrors(), $this->explain($protocol));

        $stmt = $this->db->pdo()->prepare(
            "SELECT statement_number, currency, prev_balance, curr_balance FROM bank_statements
              WHERE supplier_id = ? AND statement_number LIKE 'BE/%' ORDER BY statement_date, id"
        );
        $stmt->execute([$supplierId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        self::assertSame(['BE/2024/05', 'BE/2025/03'], array_column($rows, 'statement_number'));
        self::assertSame('EUR', $rows[0]['currency']);
        // 100 EUR z doby před zálohou + 40 EUR v roce 2024 − 20 EUR v roce 2025.
        self::assertSame(
            [[100.0, 140.0], [140.0, 120.0]],
            array_map(static fn (array $r): array => [(float) $r['prev_balance'], (float) $r['curr_balance']], $rows)
        );
        self::assertSame(1, $this->rowCount('supplier_bank_accounts', $supplierId,
            "account_number = '5000000003' AND analytic_suffix = '003' AND currency = 'EUR' AND label = 'Devizový účet'"));
    }

    /**
     * Storno výdeje v bance (vrácený poplatek) má v Money `Vydej` = 1 a zápornou částku —
     * peníze na účet přišly, pohyb je kladný. Rozdíl dokladu proti deníku, který je už
     * v samotném Money (kurzový rozdíl zaúčtovaný mimo účet banky, dobropis s jinou částkou
     * v deníku), rekonciliace vysvětlí rozdílem v Money a převod kvůli němu neselže.
     */
    public function testBankStornoIsIncomingAndDifferencesAlreadyInMoneyAreExplained(): void
    {
        $supplierId = $this->supplier();
        SyntheticAgenda::writeLzFiles($this->tmp . '/diff.lz', SyntheticAgenda::filesWithMoneyDifferences());
        $backup = Ms3Backup::extract($this->tmp . '/diff.lz', $this->tmp . '/diff');
        $protocol = $this->importer->run($supplierId, $this->userId, $backup, new ImportOptions(ImportOptions::MODE_IMPORT, true));
        self::assertFalse($protocol->hasErrors(), $this->explain($protocol));

        $amount = $this->db->pdo()->prepare(
            'SELECT t.amount FROM bank_transactions t JOIN bank_statements s ON s.id = t.statement_id WHERE s.supplier_id = ? AND t.source_ref = ?'
        );
        $amount->execute([$supplierId, 'BV25002']);
        self::assertSame(30.0, (float) $amount->fetchColumn());

        $year2025 = array_column($protocol->get('reconciliation'), null, 'year')[2025];
        self::assertTrue($year2025['ok'], (string) json_encode($year2025, JSON_UNESCAPED_UNICODE));
        $documents = array_column($year2025['documents'], null, 'key');
        self::assertSame([['document_no' => 'BV25003', 'difference' => -0.72]], $documents['bank']['source_differences'] ?? null);
        self::assertSame([['document_no' => 'DV25001', 'difference' => 10.0]], $documents['issued_invoices']['source_differences'] ?? null);

        // Opakovaný převod z téže zálohy nehlásí nic jako změněné v Money — ani konečnou
        // fakturu po odpočtu zálohy, kde `CelkemSDPH` nese celou cenu a doklad jen doplatek.
        $again = $this->importer->run($supplierId, $this->userId, $backup, new ImportOptions(ImportOptions::MODE_IMPORT, true));
        self::assertFalse($again->hasErrors(), $this->explain($again));
        $changed = [];
        foreach ($again->toArray()['steps'] ?? [] as $step) {
            foreach ($step['messages'] ?? [] as $m) {
                if ($m['code'] === 'changed_in_money') {
                    $changed[] = $m['text'];
                }
            }
        }
        self::assertSame([], $changed);
    }

    /**
     * Doklad k ruční kontrole, který Money v historickém roce vůbec nezaúčtovalo, se nepřevádí —
     * v uzavřeném roce by jen visel jako koncept. V posledním roce (RC-2025-001) zůstává ke
     * kontrole. Zálohová faktura (ZF24001) koncept není: převede se jako nezaúčtovaná záloha.
     */
    public function testUnpostedReviewDocumentFromHistoricalYearIsSkipped(): void
    {
        $supplierId = $this->supplier();
        $protocol = $this->import($supplierId);

        self::assertSame(0, $this->rowCount('purchase_invoices', $supplierId, "vendor_invoice_number = 'RC-2024-001'"));
        self::assertSame(1, $this->rowCount('purchase_invoices', $supplierId, "vendor_invoice_number = 'RC-2025-001' AND status = 'draft'"));
        self::assertSame(1, $this->rowCount('purchase_invoices', $supplierId, "vendor_invoice_number = 'ZF-2024-001' AND document_kind = 'advance' AND booked_at IS NULL"));
        $steps = array_column($protocol->toArray()['steps'], null, 'key');
        self::assertSame(1, $steps['purchase_invoices']['counts']['unposted_review_skipped'] ?? 0);
        self::assertNotContains('ZF24001', array_column($protocol->get('orphans'), 'document_no'),
            'Zálohovou fakturu Money neúčtuje, zápis v deníku u ní převod nečeká.');
    }

    public function testRepeatedImportCreatesNothingNew(): void
    {
        $supplierId = $this->supplier();
        $this->import($supplierId);
        $before = $this->snapshotCounts($supplierId);

        $second = $this->import($supplierId);

        self::assertFalse($second->hasErrors(), $this->explain($second));
        self::assertSame($before, $this->snapshotCounts($supplierId));
        $journal = array_column($second->toArray()['steps'], null, 'key')['journal'];
        self::assertSame(0, $journal['counts']['entries'] ?? 0);
    }

    public function testMoneyCostCentreAndJobBecomeDimensions(): void
    {
        $supplierId = $this->supplier();
        $pdo = $this->db->pdo();
        // Vůz z knihy jízd se značkou zapsanou jinak než v Money (bez mezery).
        $pdo->prepare("INSERT INTO cars (supplier_id, registration, name) VALUES (?, '1AB2345', 'Dodávka')")->execute([$supplierId]);
        $carId = (int) $pdo->lastInsertId();

        $protocol = $this->import($supplierId);
        self::assertFalse($protocol->hasErrors(), $this->explain($protocol));

        $enabled = $pdo->prepare('SELECT dimensions_enabled FROM supplier WHERE id = ?');
        $enabled->execute([$supplierId]);
        self::assertSame(1, (int) $enabled->fetchColumn(), 'Převod dimenzí sekci Dimenze zapne.');

        $values = $pdo->prepare(
            'SELECT t.kind, t.supplier_group_id, v.id, v.code, v.name, v.is_active, v.car_id, cc.code AS cost_center_code
               FROM dimension_values v
               JOIN dimension_types t ON t.id = v.type_id
          LEFT JOIN cost_centers cc ON cc.id = v.cost_center_id
              WHERE v.supplier_id = ?'
        );
        $values->execute([$supplierId]);
        $byCode = [];
        foreach ($values->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $byCode[(string) $row['code']] = $row;
        }
        ksort($byCode);
        self::assertSame(['1AB 2345', 'REZIE', 'ZAK01', 'ZAK02'], array_keys($byCode));
        self::assertSame('cost_center', $byCode['REZIE']['kind']);
        self::assertSame('REZIE', $byCode['REZIE']['cost_center_code'], 'Středisko je navázané na číselník středisek.');
        self::assertSame('vehicle', $byCode['1AB 2345']['kind']);
        self::assertSame($carId, (int) $byCode['1AB 2345']['car_id'], 'Vozidlo je navázané na knihu jízd.');
        self::assertSame('1AB 2345 - Dodávka', $byCode['1AB 2345']['name']);
        self::assertSame('project', $byCode['ZAK01']['kind']);
        self::assertNull($byCode['ZAK01']['supplier_group_id'], 'Firma bez skupiny má projekt firemní.');
        // Zakázky použil jen rok 2024, poslední převáděný rok je 2025.
        self::assertSame(0, (int) $byCode['ZAK01']['is_active']);
        $projects = $pdo->prepare('SELECT COUNT(*) FROM projects p JOIN clients c ON c.id = p.client_id WHERE c.supplier_id = ?');
        $projects->execute([$supplierId]);
        self::assertSame(0, (int) $projects->fetchColumn(), 'Zakázka z Money nezakládá fakturační zakázku.');

        $line = $pdo->prepare(
            "SELECT jel.cost_center, GROUP_CONCAT(v.code ORDER BY v.code SEPARATOR ',') AS dims
               FROM journal_entry_lines jel
               JOIN journal_entries je ON je.id = jel.entry_id
               JOIN chart_of_accounts a ON a.id = jel.account_id
          LEFT JOIN journal_entry_line_dimensions jd ON jd.line_id = jel.id
          LEFT JOIN dimension_values v ON v.id = jd.dimension_value_id
              WHERE je.supplier_id = ? AND je.document_no = ? AND a.account_code LIKE ?
              GROUP BY jel.id"
        );
        foreach ([['FP24001', '518%', 'REZIE,ZAK01'], ['PV24001', '501%', '1AB 2345,REZIE']] as [$doc, $account, $dims]) {
            $line->execute([$supplierId, $doc, $account]);
            $row = $line->fetch(PDO::FETCH_ASSOC);
            self::assertSame(['REZIE', $dims], [$row['cost_center'], $row['dims']], $doc);
        }

        $purchase = $pdo->prepare(
            "SELECT GROUP_CONCAT(v.code ORDER BY v.code SEPARATOR ',')
               FROM purchase_invoices pi
               JOIN document_dimensions d ON d.supplier_id = pi.supplier_id AND d.doc_type = 'purchase_invoice'
                AND d.doc_id = pi.id AND d.item_no = 0
               JOIN dimension_values v ON v.id = d.dimension_value_id
              WHERE pi.supplier_id = ? AND pi.vendor_invoice_number = 'DF-2024-017'"
        );
        $purchase->execute([$supplierId]);
        self::assertSame('REZIE,ZAK01', $purchase->fetchColumn(), 'Doklad přebírá dimenze ze svých řádků.');

        $second = $this->import($supplierId);
        $step = array_column($second->toArray()['steps'], null, 'key')['dimensions'];
        self::assertSame(0, $step['counts']['lines'] ?? 0, 'Opakovaný převod dimenze nemění.');
        self::assertSame(0, $step['counts']['documents'] ?? 0);
        self::assertSame(4, $step['counts']['values_existing'] ?? 0);
        self::assertSame(0, $step['counts']['values_created'] ?? 0);
    }

    public function testMoneyJobIsGlobalProjectOfSupplierGroup(): void
    {
        $supplierId = $this->supplier();
        $pdo = $this->db->pdo();
        $pdo->prepare("INSERT INTO supplier_groups (name) VALUES ('Syntetická skupina')")->execute();
        $groupId = (int) $pdo->lastInsertId();
        $pdo->prepare('UPDATE supplier SET supplier_group_id = ? WHERE id = ?')->execute([$groupId, $supplierId]);

        $protocol = $this->import($supplierId);
        self::assertFalse($protocol->hasErrors(), $this->explain($protocol));

        $types = $pdo->prepare(
            'SELECT kind, supplier_id, supplier_group_id FROM dimension_types
              WHERE supplier_id = ? OR supplier_group_id = ? ORDER BY kind'
        );
        $types->execute([$supplierId, $groupId]);
        $byKind = [];
        foreach ($types->fetchAll(PDO::FETCH_ASSOC) as $t) {
            $byKind[$t['kind']] = $t;
        }
        self::assertSame($groupId, (int) $byKind['project']['supplier_group_id'], 'Projekt firmy ve skupině je globální.');
        self::assertNull($byKind['project']['supplier_id']);
        self::assertSame($supplierId, (int) $byKind['cost_center']['supplier_id'], 'Středisko zůstává firemní.');
        self::assertSame($supplierId, (int) $byKind['vehicle']['supplier_id'], 'Vozidlo zůstává firemní.');
        $global = $pdo->prepare('SELECT COUNT(*) FROM dimension_values WHERE supplier_group_id = ?');
        $global->execute([$groupId]);
        self::assertSame(2, (int) $global->fetchColumn(), 'ZAK01 a ZAK02 jsou hodnoty skupiny.');
    }

    public function testAssetRegisterBecomesAssetCardsWithMigratedDepreciation(): void
    {
        SyntheticAgenda::writeLzFiles($this->tmp . '/agenda.lz', SyntheticAgenda::filesWithAssets());
        $supplierId = $this->supplier();
        $protocol = $this->import($supplierId);
        self::assertFalse($protocol->hasErrors(), $this->explain($protocol));
        $steps = array_column($protocol->toArray()['steps'], null, 'key');
        self::assertSame(1, $steps['assets']['counts']['created'] ?? 0, $this->explain($protocol));
        self::assertSame(1, $steps['assets']['counts']['helper_cards'] ?? 0, 'Pomocná karta bez majetkového účtu se nepřevádí.');
        self::assertContains('ledger_match', array_column($steps['assets']['messages'], 'code'), 'Karta sedí na 022 a 082.');

        $pdo = $this->db->pdo();
        $card = $pdo->prepare('SELECT * FROM assets WHERE supplier_id = ?');
        $card->execute([$supplierId]);
        $assets = $card->fetchAll(PDO::FETCH_ASSOC);
        self::assertCount(1, $assets);
        $a = $assets[0];
        self::assertSame(['DM-001', 'in_use', 'straight', 2, '022.100', '082.100', '2023-07-01'],
            [$a['inventory_number'], $a['status'], $a['tax_method'], (int) $a['tax_group'], $a['asset_account_code'], $a['accumulated_account_code'], $a['put_into_use_date']]);
        self::assertEqualsWithDelta(120000.0, (float) $a['input_price'], 0.001);
        // Účetně před převodem srpen až prosinec 2023, daňově první rok 2023 (11 % ve 2. skupině).
        self::assertSame([5, 10000.0, 1, 13200.0, 60],
            [(int) $a['opening_acc_months'], (float) $a['opening_acc_amount'], (int) $a['opening_tax_years'], (float) $a['opening_tax_amount'], (int) $a['acc_useful_life_months']]);

        $entries = $pdo->prepare('SELECT kind, fiscal_year, amount, status, detail FROM depreciation_entries WHERE asset_id = ? ORDER BY kind, fiscal_year');
        $entries->execute([(int) $a['id']]);
        $rows = array_map(static fn (array $r): array => [$r['kind'], (int) $r['fiscal_year'], (float) $r['amount'], $r['status']], $entries->fetchAll(PDO::FETCH_ASSOC));
        self::assertSame([
            // Daňový odpis 2. roku 22,25 %; otevřený rok 2025 daňový řádek zatím nemá.
            ['tax', 2024, 26700.0, 'confirmed'],
            ['accounting', 2024, 24000.0, 'posted'],
            ['accounting', 2025, 24000.0, 'posted'],
        ], $rows);
        $entries->execute([(int) $a['id']]);
        foreach ($entries->fetchAll(PDO::FETCH_ASSOC) as $r) {
            if ($r['kind'] === 'accounting') {
                self::assertTrue(\MyInvoice\Repository\DepreciationEntryRepository::isBookedByMigratedJournal($r), 'Odpis je v převzatém deníku, znovu se neúčtuje.');
            }
        }

        $small = $pdo->prepare('SELECT inventory_number, status, price, location, disposed_at FROM small_assets WHERE supplier_id = ? ORDER BY inventory_number');
        $small->execute([$supplierId]);
        self::assertSame([
            ['DR-001', 'in_use', 15000.0, 'Kancelář Brno', null],
            ['DR-002', 'disposed', 8000.0, null, '2025-02-01'],
        ], array_map(static fn (array $r): array => [$r['inventory_number'], $r['status'], (float) $r['price'], $r['location'], $r['disposed_at']], $small->fetchAll(PDO::FETCH_ASSOC)));

        $second = $this->import($supplierId);
        $steps = array_column($second->toArray()['steps'], null, 'key');
        self::assertSame(1, $steps['assets']['counts']['existing'] ?? 0);
        self::assertSame(2, $steps['small_assets']['counts']['existing'] ?? 0);
        self::assertSame(1, $this->rowCount('assets', $supplierId));
        self::assertSame(2, $this->rowCount('small_assets', $supplierId));
    }

    public function testHistoricalYearIsClosedWithoutDoublingOpeningBalances(): void
    {
        $supplierId = $this->supplier();
        $protocol = $this->import($supplierId);

        $closing = array_column($protocol->get('closing'), null, 'year');
        self::assertSame('closed', $closing[2024]['status'], $this->explain($protocol));
        self::assertSame('open', $closing[2025]['status']);

        $period2025 = $this->periodId($supplierId, 2025);
        self::assertSame(1, $this->rowCount('journal_entries', $supplierId, "source_type = 'opening' AND period_id = {$period2025}"));
        $tb = $this->container(TrialBalanceService::class)->build($supplierId, $period2025, null, null, false);
        $rows = array_column($tb['rows'], null, 'account_code');
        self::assertEqualsWithDelta(8250.0, $rows['431']['ps_d'], 0.001);
        self::assertEqualsWithDelta(62150.0, $rows['221']['ps_md'], 0.001);
        self::assertTrue($tb['checks']['opening_balanced']);

        $stmt = $this->db->pdo()->prepare(
            "SELECT status FROM accounting_periods WHERE supplier_id = ? AND fiscal_year = 2024"
        );
        $stmt->execute([$supplierId]);
        self::assertSame('closed', $stmt->fetchColumn());
    }

    /**
     * Uzavření knih a otevření dalšího roku jsou dva kroky průvodce. Selže-li otevření
     * (nebo kontrola po něm), rok zůstane uzavřený s nedokončeným „open_next" — další
     * běh ho nesmí přeskočit jako „už uzavřený", jinak se další rok nikdy neotevře.
     */
    public function testClosedYearWithPendingOpenNextIsFinishedOnRerun(): void
    {
        $supplierId = $this->supplier();
        $this->import($supplierId);
        $period2024 = $this->periodId($supplierId, 2024);
        $period2025 = $this->periodId($supplierId, 2025);
        $this->db->pdo()->prepare(
            "UPDATE accounting_closing_steps SET status = 'pending', done_at = NULL
              WHERE supplier_id = ? AND period_id = ? AND step_key = 'open_next'"
        )->execute([$supplierId, $period2024]);

        $protocol = $this->import($supplierId);

        self::assertFalse($protocol->hasErrors(), $this->explain($protocol));
        $closing = array_column($protocol->get('closing'), null, 'year');
        self::assertSame('next_opened', $closing[2024]['status']);
        $step = $this->db->pdo()->prepare(
            "SELECT status FROM accounting_closing_steps WHERE supplier_id = ? AND period_id = ? AND step_key = 'open_next'"
        );
        $step->execute([$supplierId, $period2024]);
        self::assertSame('done', $step->fetchColumn());
        self::assertSame(1, $this->rowCount('journal_entries', $supplierId, "source_type = 'opening' AND period_id = {$period2025}"));
    }

    public function testBankEntriesCarryTransactionIdAndStatementSourceIsImport(): void
    {
        $supplierId = $this->supplier();
        $this->import($supplierId);

        $stmt = $this->db->pdo()->prepare(
            "SELECT e.source_type, e.source_id, t.id AS tx_id, s.source
               FROM journal_entries e
               JOIN bank_transactions t ON t.source_ref = e.document_no
               JOIN bank_statements s ON s.id = t.statement_id AND s.supplier_id = e.supplier_id
              WHERE e.supplier_id = ? AND e.document_no = 'BV24001'"
        );
        $stmt->execute([$supplierId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        self::assertSame('bank', $row['source_type']);
        self::assertSame((int) $row['tx_id'], (int) $row['source_id']);
        self::assertSame('import', $row['source']);
    }

    /**
     * Každý bankovní účet má v Money vlastní číselnou řadu, takže stejné číslo dokladu
     * na dvou účtech je běžné. Klíč jen „rok|číslo" by druhý pohyb zahodil (nebo shodil
     * celý krok banky) a oba pohyby by se navázaly na oba zápisy deníku.
     */
    public function testSameBankDocumentNumberOnTwoAccountsIsImportedTwice(): void
    {
        $supplierId = $this->supplier();
        $protocol = $this->import($supplierId);

        self::assertFalse($protocol->hasErrors(), $this->explain($protocol));
        $stmt = $this->db->pdo()->prepare(
            "SELECT t.amount, COUNT(k.entry_id) AS links
               FROM bank_transactions t
               JOIN bank_statements s ON s.id = t.statement_id
               LEFT JOIN journal_entry_document_links k ON k.supplier_id = s.supplier_id AND k.doc_type = 'bank' AND k.doc_id = t.id
              WHERE s.supplier_id = ? AND t.source_ref = 'BV25001'
              GROUP BY t.id, t.amount
              ORDER BY t.amount"
        );
        $stmt->execute([$supplierId]);
        self::assertSame([['amount' => -100.0, 'links' => 1], ['amount' => -40.0, 'links' => 1]],
            array_map(static fn (array $r): array => ['amount' => (float) $r['amount'], 'links' => (int) $r['links']], $stmt->fetchAll(PDO::FETCH_ASSOC)));
    }

    /**
     * Každý vlastní účet s pohyby má v tabu Účty svůj řádek (měnu s číslem účtu) navázaný
     * na evidenci účtů firmy; jen první doplní prázdnou výchozí měnu. Opakovaný převod
     * řádky nezdvojí.
     */
    public function testEveryUsedBankAccountGetsItsOwnCompanyAccount(): void
    {
        $supplierId = $this->supplier();
        $protocol = $this->import($supplierId);
        self::assertFalse($protocol->hasErrors(), $this->explain($protocol));

        $stmt = $this->db->pdo()->prepare(
            'SELECT a.account_number, c.account_number AS currency_account
               FROM supplier_bank_accounts a
               LEFT JOIN currencies c ON c.id = a.currency_id AND c.supplier_id = a.supplier_id
              WHERE a.supplier_id = ?'
        );
        $stmt->execute([$supplierId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        self::assertNotEmpty($rows);
        foreach ($rows as $row) {
            self::assertSame((string) $row['account_number'], (string) $row['currency_account'], 'Účet ' . $row['account_number'] . ' nemá řádek v tabu Účty.');
        }
        $czk = $this->rowCount('currencies', $supplierId, "code = 'CZK'");
        self::assertSame(count($rows), $czk);

        $this->import($supplierId);
        self::assertSame($czk, $this->rowCount('currencies', $supplierId, "code = 'CZK'"));
    }

    /**
     * Pohyb, který Money zaúčtovalo bez faktury (poplatek, vratka), je vyřízený - výpis
     * nesvítí jako nedopárovaný. Úhrady faktur zůstávají spárované.
     */
    public function testBankTransactionsBookedWithoutInvoiceAreResolved(): void
    {
        $supplierId = $this->supplier();
        $protocol = $this->import($supplierId);
        self::assertFalse($protocol->hasErrors(), $this->explain($protocol));

        $stmt = $this->db->pdo()->prepare(
            "SELECT t.match_status, t.match_reason,
                    EXISTS (SELECT 1 FROM journal_entry_document_links k WHERE k.supplier_id = s.supplier_id AND k.doc_type = 'bank' AND k.doc_id = t.id) AS booked
               FROM bank_transactions t JOIN bank_statements s ON s.id = t.statement_id
              WHERE s.supplier_id = ?"
        );
        $stmt->execute([$supplierId]);
        $byStatus = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $key = $row['match_status'] . '|' . ($row['match_reason'] ?? '') . '|' . $row['booked'];
            $byStatus[$key] = ($byStatus[$key] ?? 0) + 1;
        }
        self::assertArrayNotHasKey('unmatched||1', $byStatus, json_encode($byStatus));
        self::assertGreaterThan(0, $byStatus['ignored|money_s3_booked|1'] ?? 0, json_encode($byStatus));
        self::assertGreaterThan(0, $byStatus['manual||1'] ?? 0, json_encode($byStatus));
    }

    /** Pokladní doklad hradící dvě faktury si ponechá vazbu na první, druhá ji nepřepíše. */
    public function testCashDocumentPayingTwoInvoicesKeepsFirstLink(): void
    {
        SyntheticAgenda::writeLzFiles($this->tmp . '/agenda.lz', SyntheticAgenda::filesWithCashPayingTwoInvoices());
        $supplierId = $this->supplier();
        $protocol = $this->import($supplierId);

        $stmt = $this->db->pdo()->prepare(
            "SELECT pi.vendor_invoice_number FROM cash_documents c JOIN purchase_invoices pi ON pi.id = c.purchase_invoice_id
              WHERE c.supplier_id = ? AND c.description LIKE 'Kancelářské%'"
        );
        $stmt->execute([$supplierId]);
        self::assertSame('DF-2025-110', $stmt->fetchColumn(), $this->explain($protocol));
        $payments = array_column($protocol->toArray()['steps'], null, 'key')['payments']['counts'] ?? [];
        self::assertSame(1, $payments['cash_multi_invoice'] ?? 0, json_encode($payments));
    }

    /** Pozdější záznam daňové evidence v historii režimů nesmí převedené roky vrátit do DE. */
    public function testLaterTaxEvidenceRecordDoesNotOverrideConvertedYears(): void
    {
        $supplierId = $this->supplier();
        $modes = $this->container(AccountingModeRepository::class);
        $modes->record($supplierId, '2025-06-01', 'tax_evidence');

        $protocol = $this->import($supplierId);

        self::assertFalse($protocol->hasErrors(), $this->explain($protocol));
        self::assertSame('double_entry', $modes->forYear($supplierId, 2025));
    }

    public function testAccountingModeIsRecordedInBothPlaces(): void
    {
        $supplierId = $this->supplier();
        $this->import($supplierId);

        $s = $this->db->pdo()->prepare('SELECT accounting_mode, accounting_enabled, accounting_starts_on FROM supplier WHERE id = ?');
        $s->execute([$supplierId]);
        self::assertSame(['accounting_mode' => 'double_entry', 'accounting_enabled' => 1, 'accounting_starts_on' => '2024-01-01'],
            array_map(static fn ($v) => is_numeric($v) ? (int) $v : $v, $s->fetch(PDO::FETCH_ASSOC)));
        $h = $this->db->pdo()->prepare('SELECT accounting_mode FROM supplier_accounting_modes WHERE supplier_id = ? AND effective_from = ?');
        $h->execute([$supplierId, '2024-01-01']);
        self::assertSame('double_entry', $h->fetchColumn());
    }

    public function testAutomationIsOffDuringImportAndRestoredAfterwards(): void
    {
        $supplierId = $this->supplier(SyntheticAgenda::ICO, 'double_entry');
        $this->policy->applyPreset($supplierId, 'assisted', $this->userId);

        $protocol = $this->import($supplierId);

        $automation = $protocol->get('automation');
        self::assertSame('off', $automation['during']);
        self::assertTrue($automation['restored']);
        self::assertSame('assisted', $automation['after']);
    }

    /**
     * Spadlý worker nezapíše protokol a řádek běhu zůstane „running". Další běh musí
     * automatiku vrátit na stav PŘED prvním během, ne na „vypnuto" po pádu.
     */
    public function testCrashedImportRestoresAutomationFromBeforeTheCrash(): void
    {
        $supplierId = $this->supplier(SyntheticAgenda::ICO, 'double_entry');
        $this->policy->applyPreset($supplierId, 'assisted', $this->userId);
        $crashed = $this->map->startRun($supplierId, null, 'import', [], $this->userId);
        try {
            $this->importer->run($supplierId, $this->userId, $this->backup(), new ImportOptions(ImportOptions::MODE_IMPORT), $crashed,
                static function (string $step): void {
                    if ($step === 'partners') {
                        throw new \RuntimeException('worker spadl');
                    }
                });
            self::fail('Běh měl spadnout.');
        } catch (\RuntimeException $e) {
            self::assertSame('worker spadl', $e->getMessage());
        }
        self::assertSame('off', $this->policy->listPolicy($supplierId)['automation_level']);

        self::assertSame(1, $this->map->closeInterruptedRuns($supplierId));
        self::assertSame('failed', $this->map->findRun($crashed, $supplierId)['status']);
        $next = $this->map->startRun($supplierId, null, 'import', [], $this->userId);
        $protocol = $this->importer->run($supplierId, $this->userId, $this->backup(), new ImportOptions(ImportOptions::MODE_IMPORT), $next);

        self::assertFalse($protocol->hasErrors(), $this->explain($protocol));
        self::assertSame('assisted', $protocol->get('automation')['after']);
        self::assertNull($this->map->pendingAutomationSnapshot($supplierId), 'Po obnovení nesmí čekat žádný snímek.');
    }

    public function testMapRefusesSecondTargetForTheSameMoneyRecord(): void
    {
        $supplierId = $this->supplier();
        $this->map->put($supplierId, MoneyS3ImportRepository::KIND_INVOICE, '2025|FV1', 10, null);

        $this->expectException(MoneyS3Exception::class);
        $this->map->put($supplierId, MoneyS3ImportRepository::KIND_INVOICE, '2025|FV1', 11, null);
    }

    /** Druhý worker téže firmy (třeba po úklidu „mrtvého" jobu zkoušky) se nespustí. */
    public function testSecondWorkerForTheSameCompanyIsRefused(): void
    {
        $supplierId = $this->supplier();
        $other = Connection::withoutSharedTestConnection(fn (): Connection => new Connection($this->container->get(Config::class)));
        $otherRuns = new MoneyS3ImportRepository($other);
        self::assertTrue($otherRuns->acquireLock($supplierId));
        try {
            self::assertFalse($this->map->isLockFree($supplierId));
            $jobs = $this->container(ImportJobRepository::class);
            $jobId = $jobs->create($supplierId, MoneyS3ImportJobService::SOURCE, ['token' => str_repeat('a', 16), 'mode' => 'dry_run'], $this->userId);

            $this->container(MoneyS3ImportJobService::class)->run($jobId);

            $job = $jobs->find($jobId, $supplierId);
            self::assertSame('failed', $job['status']);
            self::assertStringContainsString('už běží', (string) $job['last_error']);
            self::assertSame([], $this->map->listRuns($supplierId));
        } finally {
            $otherRuns->releaseLock($supplierId);
            $other->close();
        }
        self::assertTrue($this->map->isLockFree($supplierId));
    }

    public function testFirstActivationGetsAccountingUnitDefaultsAfterImport(): void
    {
        $supplierId = $this->supplier();
        $protocol = $this->import($supplierId);

        self::assertSame('off', $protocol->get('automation')['during']);
        self::assertSame('full', $protocol->get('automation')['after']);
    }

    public function testDryRunLeavesNothingBehind(): void
    {
        $supplierId = $this->supplier();
        $protocol = $this->import($supplierId, ImportOptions::MODE_DRY_RUN);

        self::assertFalse($protocol->hasErrors(), $this->explain($protocol));
        self::assertTrue($protocol->get('reconciliation')[0]['ok']);
        self::assertSame(0, $this->rowCount('journal_entries', $supplierId));
        self::assertSame(0, $this->rowCount('accounting_periods', $supplierId));
        self::assertSame(0, $this->map->countAll($supplierId));
        $s = $this->db->pdo()->prepare('SELECT accounting_mode FROM supplier WHERE id = ?');
        $s->execute([$supplierId]);
        self::assertSame('tax_evidence', $s->fetchColumn());
    }

    /**
     * Zkouška nanečisto drží jednu transakci po celou dobu běhu. Kdyby v ní vypínala
     * automatiku zápisem do řádku firmy, držela by na něm zámek a běžná práce firmy
     * (každý nový doklad kontroluje cizí klíč na firmu) by čekala na konec zkoušky.
     */
    public function testDryRunDoesNotWriteCompanyAutomationWhileRunning(): void
    {
        $supplierId = $this->supplier(SyntheticAgenda::ICO, 'double_entry');
        $this->policy->applyPreset($supplierId, 'assisted', $this->userId);
        $this->db->pdo()->prepare('UPDATE supplier SET auto_post_invoices = 1 WHERE id = ?')->execute([$supplierId]);
        $seen = [];
        $protocol = $this->importer->run($supplierId, $this->userId, $this->backup(), new ImportOptions(ImportOptions::MODE_DRY_RUN), null,
            function (string $step) use ($supplierId, &$seen): void {
                if ($step !== 'purchase_invoices') {
                    return;
                }
                $flag = $this->db->pdo()->prepare('SELECT auto_post_invoices FROM supplier WHERE id = ?');
                $flag->execute([$supplierId]);
                $seen = ['level' => $this->policy->listPolicy($supplierId)['automation_level'], 'auto_post_invoices' => (int) $flag->fetchColumn()];
            });

        self::assertFalse($protocol->hasErrors(), $this->explain($protocol));
        self::assertSame(['level' => 'assisted', 'auto_post_invoices' => 1], $seen);
        self::assertSame('off', $protocol->get('automation')['during'], 'Protokol ukazuje, co udělá ostrý převod.');
        self::assertSame('closed', array_column($protocol->get('closing'), null, 'year')[2024]['status'], $this->explain($protocol));
    }

    public function testDifferenceAgainstMoneyReportIsReported(): void
    {
        $supplierId = $this->supplier();
        file_put_contents($this->tmp . '/chybna.csv', str_replace('10 300,00;0,00', '10 301,00;0,00', SyntheticAgenda::trialBalanceCsv2024()));
        $protocol = $this->import($supplierId, ImportOptions::MODE_DRY_RUN, [2024 => $this->tmp . '/chybna.csv']);

        $year = $protocol->get('reconciliation')[0];
        self::assertFalse($year['ok']);
        self::assertSame(['518'], array_column($year['money_report']['diffs'], 'account'));
        self::assertTrue($protocol->hasErrors());
    }

    public function testAgendaOfAnotherCompanyIsRejected(): void
    {
        $supplierId = $this->supplier(SyntheticAgenda::VENDOR_ICO);
        $protocol = $this->import($supplierId);

        self::assertTrue($protocol->failed());
        self::assertSame(['ico_mismatch'], array_column(array_filter($protocol->get('preflight'), static fn ($m) => $m['level'] === 'error'), 'code'));
        self::assertSame(0, $this->rowCount('journal_entries', $supplierId));
        self::assertSame(0, $this->map->countAll($supplierId));
    }

    /**
     * Ostrý převod zapisuje deník, přepíná režim účetnictví a automatiku a uzavírá roky —
     * samotné právo na import dat na to nestačí. Zkouška nanečisto nic nezanechá, ta
     * zůstává na `utilities.import`.
     */
    public function testLiveImportRequiresAccountingAndCompanySettingsRights(): void
    {
        $supplierId = $this->supplier();
        $action = $this->container(MoneyS3MigrationAction::class);
        $importOnly = ['utilities.import' => 2];
        $withoutClose = $importOnly + ['accounting.journal.write' => 2, 'settings.company.write' => 2];
        $full = $withoutClose + ['accounting.periods.close' => 2];
        $status = function (array $permissions, array $body) use ($action, $supplierId): int {
            $request = (new ServerRequestFactory())->createServerRequest('POST', '/api/admin/imports/money-s3/uploads/' . str_repeat('a', 16) . '/start')
                ->withAttribute(SupplierScopeMiddleware::ATTR_CURRENT_ID, $supplierId)
                ->withAttribute('auth.effective_role', new EffectiveRole(9, 'Test', 'staff', true, $permissions))
                ->withParsedBody($body);
            return $action->start($request, (new ResponseFactory())->createResponse(), ['token' => str_repeat('a', 16)])->getStatusCode();
        };

        self::assertSame(403, $status($importOnly, ['mode' => 'import', 'close_history' => false]));
        self::assertSame(403, $status($withoutClose, ['mode' => 'import', 'close_history' => true]));
        // Práva sedí → požadavek projde autorizací a narazí až na neexistující zálohu.
        self::assertSame(404, $status($withoutClose, ['mode' => 'import', 'close_history' => false]));
        self::assertSame(404, $status($full, ['mode' => 'import', 'close_history' => true]));
        self::assertSame(404, $status($importOnly, ['mode' => 'dry_run']));
    }

    /**
     * Bez IČO na jedné ze stran nejde ověřit, že záloha patří téhle firmě. Zkouška
     * nanečisto jen upozorní, ostrý převod potřebuje výslovné potvrzení.
     */
    public function testLiveImportWithoutVerifiableIcoNeedsExplicitConfirmation(): void
    {
        $supplierId = $this->supplier('');

        $refused = $this->importer->run($supplierId, $this->userId, $this->backup(), new ImportOptions(ImportOptions::MODE_IMPORT));
        self::assertTrue($refused->failed());
        self::assertSame(['ico_unverified'], array_column(array_filter($refused->get('preflight'), static fn ($m) => $m['level'] === 'error'), 'code'));
        self::assertSame(0, $this->rowCount('journal_entries', $supplierId));

        $dry = $this->importer->run($supplierId, $this->userId, $this->backup(), new ImportOptions(ImportOptions::MODE_DRY_RUN));
        self::assertFalse($dry->hasErrors(), $this->explain($dry));

        $confirmed = $this->importer->run($supplierId, $this->userId, $this->backup(), new ImportOptions(ImportOptions::MODE_IMPORT, true, null, [], [], true));
        self::assertFalse($confirmed->hasErrors(), $this->explain($confirmed));
    }

    public function testImportIsScopedToTheTargetCompany(): void
    {
        $a = $this->supplier();
        $this->import($a);
        $countsA = $this->snapshotCounts($a);

        $b = $this->supplier();
        $protocol = $this->import($b);

        self::assertFalse($protocol->hasErrors(), $this->explain($protocol));
        self::assertSame($countsA, $this->snapshotCounts($a), 'Převod do firmy B nesmí sáhnout na firmu A.');
        self::assertSame($countsA, $this->snapshotCounts($b), 'Firma B dostane vlastní kopii, ne odkaz na data firmy A.');

        $runA = $this->map->startRun($a, null, 'import', [], $this->userId);
        self::assertNull($this->map->findRun($runA, $b), 'Protokol cizí firmy není vidět.');
    }

    public function testExistingBookkeepingIsNotMixedWithMoneyJournal(): void
    {
        $supplierId = $this->supplier(SyntheticAgenda::ICO, 'double_entry');
        $this->container(ChartOfAccountsSeeder::class)->seedForSupplier($supplierId);
        $periodId = $this->container(AccountingPeriodRepository::class)->create($supplierId, 2025, '2025-01-01', '2025-12-31');
        $stmt = $this->db->pdo()->prepare("SELECT id FROM chart_of_accounts WHERE supplier_id = ? AND account_code IN ('518', '321') ORDER BY account_code");
        $stmt->execute([$supplierId]);
        [$liability, $expense] = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
        $this->container(JournalEntryRepository::class)->insert(
            ['supplier_id' => $supplierId, 'period_id' => $periodId, 'entry_date' => '2025-03-01', 'source_type' => 'manual', 'posted_at' => '2025-03-01 10:00:00'],
            [['account_id' => $expense, 'side' => 'debit', 'amount' => '10.00'], ['account_id' => $liability, 'side' => 'credit', 'amount' => '10.00']],
        );

        $protocol = $this->import($supplierId);

        self::assertTrue($protocol->failed());
        self::assertContains('journal_not_empty', array_column($protocol->get('preflight'), 'code'));
        self::assertSame(1, $this->rowCount('journal_entries', $supplierId));
    }

    // ── pomocníci ─────────────────────────────────────────────────────────────

    private function supplier(string $ico = SyntheticAgenda::ICO, string $mode = 'tax_evidence'): int
    {
        $pdo = $this->db->pdo();
        $pdo->prepare(
            'INSERT INTO supplier (company_name, street, city, zip, country_id, email, ic, default_currency_id, default_vat_rate_id, accounting_mode)
             VALUES (?, "Účetní 12", "Brno", "60200", ?, "prevod@example.invalid", ?, ?, ?, ?)'
        )->execute([SyntheticAgenda::NAME, $this->czId, $ico, $this->anyCurrencyId, $this->vatRateId, $mode]);
        $id = (int) $pdo->lastInsertId();
        $pdo->prepare(
            "INSERT INTO currencies (supplier_id, code, label, symbol, name_cs, name_en, decimals, is_active, is_default)
             VALUES (?, 'CZK', 'CZK', 'Kč', 'Česká koruna', 'Czech Koruna', 2, 1, 1)"
        )->execute([$id]);
        $pdo->prepare('UPDATE supplier SET default_currency_id = ? WHERE id = ?')->execute([(int) $pdo->lastInsertId(), $id]);
        return $id;
    }

    /** @param array<int,string> $reports */
    private function import(int $supplierId, string $mode = ImportOptions::MODE_IMPORT, array $reports = []): ImportProtocol
    {
        return $this->importer->run($supplierId, $this->userId, $this->backup(), new ImportOptions($mode, true, null, [], $reports));
    }

    private function backup(): Ms3Backup
    {
        return Ms3Backup::extract($this->tmp . '/agenda.lz', $this->tmp . '/agenda-' . bin2hex(random_bytes(3)));
    }

    private function rowCount(string $table, int $supplierId, string $where = '1 = 1'): int
    {
        $stmt = $this->db->pdo()->prepare("SELECT COUNT(*) FROM {$table} WHERE supplier_id = ? AND {$where}");
        $stmt->execute([$supplierId]);
        return (int) $stmt->fetchColumn();
    }

    /** @return array<string,int> */
    private function snapshotCounts(int $supplierId): array
    {
        $out = [];
        foreach (['journal_entries', 'journal_entry_lines', 'accounting_periods', 'purchase_invoices', 'invoices', 'cash_documents',
            'cash_registers', 'bank_statements', 'clients', 'payment_matches', 'posting_rules', 'journal_entry_document_links'] as $t) {
            $out[$t] = $this->rowCount($t, $supplierId);
        }
        $tx = $this->db->pdo()->prepare('SELECT COUNT(*) FROM bank_transactions t JOIN bank_statements s ON s.id = t.statement_id WHERE s.supplier_id = ?');
        $tx->execute([$supplierId]);
        $out['bank_transactions'] = (int) $tx->fetchColumn();
        return $out;
    }

    private function periodId(int $supplierId, int $year): int
    {
        return (int) $this->container(AccountingPeriodRepository::class)->findByYear($supplierId, $year)['id'];
    }

    /**
     * @template T of object
     * @param class-string<T> $class
     * @return T
     */
    private function container(string $class): object
    {
        return $this->container->get($class);
    }

    private function explain(ImportProtocol $protocol): string
    {
        return json_encode($protocol->toArray(), JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_PARTIAL_OUTPUT_ON_ERROR) ?: '';
    }
}
