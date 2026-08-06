<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Accounting;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\AccountingPeriodRepository;
use MyInvoice\Repository\JournalEntryRepository;
use MyInvoice\Service\Accounting\ChartOfAccountsSeeder;
use MyInvoice\Service\Accounting\PostingService;
use MyInvoice\Service\Report\DphPriznaniBuilder;
use MyInvoice\Service\Report\VatCrossCheckService;
use PDO;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * OSS-8 — daň odváděná v režimu jednoho správního místa (§ 110 a násl. ZDPH) se
 * NESMÍ účtovat na 343.
 *
 * Přiznání k DPH i kontrolní hlášení OSS řádky vylučují ({@see \MyInvoice\Service\Report\VatLedgerService}
 * filtruje `oss_applicable = 1`), takže dokud OSS daň seděla na 343, zůstatek účtu
 * se s přiznáním z principu nemohl srovnat — u zákazníka s 850 zahraničními doklady
 * to byl rozdíl v řádu statisíců.
 *
 * Jádrem téhle sady je {@see testAccount343MovesExactlyByVatReturnOutputTax}:
 * pohyb 343 z jednoho dokladu se musí rovnat pohybu daně na výstupu v přiznání.
 * Bez opravy test neprojde ani do fáze porovnání — zaúčtování skončí na
 * `totals_mismatch` (hlavička dokladu obsahuje OSS daň, ledger ji nezná).
 *
 * Vše běží v jedné transakci, kterou tearDown rollbackne. Soft-skip bez cfg.php.
 */
#[Group('integration')]
final class OssPostingTest extends TestCase
{
    private const YEAR = 2097;
    private const ENTRY_DATE = self::YEAR . '-06-15';

    /** Účet, na který patří daň odváděná do jiného členského státu (analytika 345). */
    private const OSS_ACCOUNT = '345.100';

    private Connection $db;
    private PostingService $posting;
    private JournalEntryRepository $journal;
    private AccountingPeriodRepository $periods;
    private DphPriznaniBuilder $dphPriznani;
    private VatCrossCheckService $crossCheck;

    private int $supplierId = 0;
    private int $currencyId = 0;
    private int $vatRateId = 0;
    private int $userId = 0;
    private int $czId = 0;
    private int $deId = 0;
    private bool $inTx = false;

    protected function setUp(): void
    {
        $rootDir = dirname(__DIR__, 4);
        if (!is_file($rootDir . '/cfg.php')) {
            $this->markTestSkipped('cfg.php neexistuje — test vyžaduje DB connection.');
        }
        try {
            $container = Bootstrap::buildApp()->getContainer();
            $this->db          = $container->get(Connection::class);
            $this->posting     = $container->get(PostingService::class);
            $this->journal     = $container->get(JournalEntryRepository::class);
            $this->periods     = $container->get(AccountingPeriodRepository::class);
            $this->dphPriznani = $container->get(DphPriznaniBuilder::class);
            $this->crossCheck  = $container->get(VatCrossCheckService::class);
            $seeder            = $container->get(ChartOfAccountsSeeder::class);
        } catch (\Throwable $e) {
            $this->markTestSkipped('DI nedostupné: ' . $e->getMessage());
        }

        if (!$this->db->hasColumn('invoice_items', 'oss_applicable')) {
            $this->markTestSkipped('Instance bez OSS schématu (migrace 0137) — není co testovat.');
        }

        $pdo = $this->db->pdo();
        $this->supplierId = (int) ($pdo->query('SELECT id FROM supplier ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        $this->currencyId = (int) ($pdo->query("SELECT id FROM currencies WHERE code = 'CZK' ORDER BY id LIMIT 1")->fetchColumn() ?: 0);
        $this->vatRateId  = (int) ($pdo->query('SELECT id FROM vat_rates ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        $this->userId     = (int) ($pdo->query('SELECT id FROM users ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        $this->czId       = (int) ($pdo->query("SELECT id FROM countries WHERE iso2 = 'CZ' LIMIT 1")->fetchColumn() ?: 0);
        $this->deId       = (int) ($pdo->query("SELECT id FROM countries WHERE iso2 = 'DE' LIMIT 1")->fetchColumn() ?: 0);

        if ($this->supplierId === 0 || $this->currencyId === 0 || $this->vatRateId === 0
            || $this->userId === 0 || $this->czId === 0 || $this->deId === 0) {
            $this->markTestSkipped('Chybí základní data (supplier/currency/vat_rate/user/country) v DB.');
        }

        $pdo->beginTransaction();
        $this->inTx = true;
        $seeder->seedForSupplier($this->supplierId);

        if ($this->periods->findForDate($this->supplierId, self::ENTRY_DATE) === null) {
            $this->periods->create($this->supplierId, self::YEAR, self::YEAR . '-01-01', self::YEAR . '-12-31');
        }
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
    }

    /**
     * Smíšená faktura (tuzemský řádek + OSS řádek): daň se musí rozdělit mezi 343
     * a OSS účet, výnos zůstat v jednom kuse a doklad zůstat vyvážený.
     */
    public function testMixedInvoiceSplitsVatBetweenCzechAndOssAccount(): void
    {
        $invoiceId = $this->mixedInvoice('FV-2097-001');
        $byAccount = $this->postAndGroup($invoiceId);

        self::assertEqualsWithDelta(1805.00, $byAccount['311']['debit'], 0.001, '311 MD = celá částka dokladu vč. OSS daně.');
        self::assertEqualsWithDelta(1500.00, $byAccount['602']['credit'], 0.001, '602 D = základ obou řádků (výnos je výnos bez ohledu na stát daně).');
        self::assertEqualsWithDelta(210.00, $byAccount['343']['credit'], 0.001, '343 D = POUZE česká daň na výstupu.');
        self::assertEqualsWithDelta(95.00, $byAccount[self::OSS_ACCOUNT]['credit'], 0.001, 'OSS daň patří na vlastní účet, ne na 343.');
        self::assertArrayNotHasKey('648', $byAccount, 'Žádné haléřové dorovnání — doklad sedí přesně.');
        self::assertArrayNotHasKey('548', $byAccount, 'Žádné haléřové dorovnání — doklad sedí přesně.');
    }

    /**
     * SMYSL CELÉ ZMĚNY: pohyb na 343 z dokladu se musí rovnat pohybu daně na výstupu
     * v přiznání k DPH. Měří se rozdílem před/po, aby test nezávisel na tom, co už
     * v testovací DB za období leží.
     */
    public function testAccount343MovesExactlyByVatReturnOutputTax(): void
    {
        $outputBefore = $this->vatReturnOutputTax();
        $balanceBefore = $this->accountBalance('343');

        $invoiceId = $this->mixedInvoice('FV-2097-002');
        $byAccount = $this->postAndGroup($invoiceId);

        $outputDelta  = round($this->vatReturnOutputTax() - $outputBefore, 2);
        $balanceDelta = round($this->accountBalance('343') - $balanceBefore, 2);

        self::assertEqualsWithDelta(210.00, $outputDelta, 0.001, 'Do přiznání jde jen česká daň — OSS řádek je vyloučený.');
        self::assertEqualsWithDelta(
            $outputDelta,
            -$balanceDelta,
            0.001,
            'Zůstatek 343 (kreditní) se musí pohnout přesně o daň na výstupu z přiznání — jinak se účet s přiznáním nikdy nesrovná.',
        );
        self::assertEqualsWithDelta(95.00, $byAccount[self::OSS_ACCOUNT]['credit'], 0.001, 'Zbytek daně (OSS) leží mimo 343.');
    }

    /**
     * Produkční brána smíru: {@see VatCrossCheckService} porovnává zaúčtovaný obrat 343
     * (včetně analytik pod syntetikou) s daní z přiznání. Prázdný seznam nálezů = účet
     * a přiznání sedí. Právě tahle kontrola u zahraničních dokladů dřív hlásit nemohla,
     * protože se doklad vůbec nezaúčtoval.
     */
    public function testVatCrossCheckFindsNoMismatchOnOssInvoice(): void
    {
        $invoiceId = $this->mixedInvoice('FV-2097-008');
        $this->post($invoiceId);

        $findings = $this->crossCheck->checkAccountBalanceVsReturn($this->supplierId, self::YEAR, 6, 'monthly');

        self::assertSame(
            [],
            $findings,
            'Obrat 343 musí sedět s přiznáním i u dokladu s OSS řádky: ' . json_encode($findings, JSON_UNESCAPED_UNICODE),
        );
    }

    /**
     * Doklad složený výhradně z OSS řádků. Dřív skončil na `document_not_postable`
     * (VatLedgerService pro něj nevrátí žádný řádek), takže se do deníku vůbec nedostal.
     */
    public function testFullyOssInvoiceIsPostableAndLeaves343Untouched(): void
    {
        $invoiceId = $this->ossOnlyInvoice('FV-2097-003');
        $byAccount = $this->postAndGroup($invoiceId);

        self::assertEqualsWithDelta(952.00, $byAccount['311']['debit'], 0.001, '311 MD = základ + OSS daň.');
        self::assertEqualsWithDelta(800.00, $byAccount['602']['credit'], 0.001, '602 D = základ.');
        self::assertEqualsWithDelta(152.00, $byAccount[self::OSS_ACCOUNT]['credit'], 0.001, 'Celá daň na OSS účtu.');
        self::assertArrayNotHasKey('343', $byAccount, 'Doklad bez českého plnění nesmí zanechat stopu na 343.');
    }

    /** Dobropis obrací obě daňové nohy — 343 i OSS. */
    public function testOssCreditNoteReversesBothVatLegs(): void
    {
        $creditNoteId = $this->mixedInvoice('FV-2097-004', creditNote: true);
        $byAccount    = $this->postAndGroup($creditNoteId);

        self::assertEqualsWithDelta(1805.00, $byAccount['311']['credit'], 0.001, 'Dobropis: 311 na straně D.');
        self::assertEqualsWithDelta(1500.00, $byAccount['602']['debit'], 0.001, 'Dobropis: výnos se odúčtuje na MD.');
        self::assertEqualsWithDelta(210.00, $byAccount['343']['debit'], 0.001, 'Dobropis: česká daň zpět na MD.');
        self::assertEqualsWithDelta(95.00, $byAccount[self::OSS_ACCOUNT]['debit'], 0.001, 'Dobropis: OSS daň zpět na MD.');
    }

    /** Storno zápisu (protizápis) musí zrcadlit i OSS nohu. */
    public function testReversalMirrorsOssLeg(): void
    {
        $invoiceId = $this->mixedInvoice('FV-2097-005');
        $entryId   = $this->post($invoiceId);

        $reversalId = $this->posting->reverse($this->supplierId, $entryId, [
            'entry_date' => self::ENTRY_DATE,
            'user_id'    => $this->userId,
            'posted_by'  => $this->userId,
        ]);
        $reversal = $this->journal->find($reversalId, $this->supplierId);
        self::assertNotNull($reversal);
        $byAccount = $this->linesByAccountCode($reversal['lines']);

        self::assertEqualsWithDelta(95.00, $byAccount[self::OSS_ACCOUNT]['debit'], 0.001, 'Protizápis vrací OSS daň na opačnou stranu.');
        self::assertEqualsWithDelta(210.00, $byAccount['343']['debit'], 0.001, 'Protizápis vrací i českou daň.');
        $this->assertBalanced($reversal['lines']);
    }

    /**
     * Účet se čte výhradně z kontace `oss.output.vat`, takže firma, které vyhovuje
     * jiné členění (typicky vlastní analytika k 343), si ho přepne per-tenant
     * override — bez zásahu do kódu.
     */
    public function testTenantOverrideOfOssPostingRuleIsHonoured(): void
    {
        $this->db->pdo()->prepare(
            'INSERT INTO posting_rules
                (supplier_id, rule_key, description, debit_account_code, credit_account_code, priority, is_active)
             VALUES (?, "oss.output.vat", "OSS daň — vlastní volba firmy", NULL, "379", 100, 1)'
        )->execute([$this->supplierId]);

        $invoiceId = $this->mixedInvoice('FV-2097-007');
        $byAccount = $this->postAndGroup($invoiceId);

        self::assertEqualsWithDelta(95.00, $byAccount['379']['credit'], 0.001, 'OSS daň jde na účet z per-tenant kontace.');
        self::assertArrayNotHasKey(self::OSS_ACCOUNT, $byAccount, 'Výchozí OSS účet se při override nepoužije.');
        self::assertEqualsWithDelta(210.00, $byAccount['343']['credit'], 0.001, 'Česká daň zůstává na 343 i při override.');
    }

    /** Regrese: doklad bez OSS řádků se musí účtovat úplně stejně jako dosud. */
    public function testPlainDomesticInvoiceIsUnchanged(): void
    {
        $invoiceId = $this->domesticInvoice('FV-2097-006');
        $byAccount = $this->postAndGroup($invoiceId);

        self::assertEqualsWithDelta(1210.00, $byAccount['311']['debit'], 0.001);
        self::assertEqualsWithDelta(1000.00, $byAccount['602']['credit'], 0.001);
        self::assertEqualsWithDelta(210.00, $byAccount['343']['credit'], 0.001, 'Bez OSS řádků nese 343 celou daň.');
        self::assertArrayNotHasKey(self::OSS_ACCOUNT, $byAccount, 'Bez OSS řádků nesmí vzniknout OSS noha.');
    }

    // ── helpers ──────────────────────────────────────────────────────────────

    /** @return array<string,array{debit:float,credit:float}> */
    private function postAndGroup(int $invoiceId): array
    {
        $entryId = $this->post($invoiceId);
        $entry   = $this->journal->find($entryId, $this->supplierId);
        self::assertNotNull($entry);
        $this->assertBalanced($entry['lines']);

        return $this->linesByAccountCode($entry['lines']);
    }

    private function post(int $invoiceId): int
    {
        $lines = $this->posting->buildFromInvoice($this->supplierId, $invoiceId);

        return $this->posting->postDocument(
            $this->supplierId,
            'invoice',
            $invoiceId,
            $lines,
            ['entry_date' => self::ENTRY_DATE, 'posted_by' => $this->userId, 'user_id' => $this->userId],
        );
    }

    /** Daň na výstupu tak, jak ji vykáže přiznání k DPH za období dokladu. */
    private function vatReturnOutputTax(): float
    {
        $summary = $this->dphPriznani->build($this->supplierId, self::YEAR, 6, 'monthly')['summary'];

        return round((float) ($summary['total_vat_output'] ?? 0.0), 2);
    }

    /** Zůstatek účtu (MD − D) za období roku {@see YEAR}. */
    private function accountBalance(string $accountCode): float
    {
        $stmt = $this->db->pdo()->prepare(
            "SELECT COALESCE(SUM(CASE WHEN jel.side = 'debit' THEN jel.amount ELSE -jel.amount END), 0)
               FROM journal_entry_lines jel
               JOIN journal_entries je ON je.id = jel.entry_id
               JOIN chart_of_accounts coa ON coa.id = jel.account_id
              WHERE je.supplier_id = ? AND coa.account_code = ?
                AND je.entry_date BETWEEN ? AND ?"
        );
        $stmt->execute([$this->supplierId, $accountCode, self::YEAR . '-01-01', self::YEAR . '-12-31']);

        return round((float) $stmt->fetchColumn(), 2);
    }

    private function client(string $name, int $countryId, ?string $dic): int
    {
        $stmt = $this->db->pdo()->prepare(
            'INSERT INTO clients
                (supplier_id, company_name, street, city, zip, country_id, dic, main_email,
                 language, currency_default_id, is_customer, is_vendor)
             VALUES (?, ?, "Test 1", "Praha", "11000", ?, ?, "test@example.com", "cs", ?, 1, 0)'
        );
        $stmt->execute([$this->supplierId, $name, $countryId, $dic, $this->currencyId]);

        return (int) $this->db->pdo()->lastInsertId();
    }

    /** Tuzemský řádek 1 000/210 (21 %) + OSS řádek do DE 500/95 (19 %). */
    private function mixedInvoice(string $varsymbol, bool $creditNote = false): int
    {
        $sign      = $creditNote ? -1.0 : 1.0;
        $clientId  = $this->client('Kupující GmbH', $this->deId, null);
        $invoiceId = $this->invoice($varsymbol, $clientId, $sign * 1500.00, $sign * 305.00, $creditNote);

        $this->insertItem($invoiceId, 'Tuzemská služba', $sign * 1000.00, $sign * 210.00, 21.00, 0, null);
        $this->insertItem($invoiceId, 'Digitální služba DE', $sign * 500.00, $sign * 95.00, 19.00, 1, 'DE');

        return $invoiceId;
    }

    /** Doklad výhradně s OSS řádkem: 800/152 (19 %, DE). */
    private function ossOnlyInvoice(string $varsymbol): int
    {
        $clientId  = $this->client('Kupující GmbH', $this->deId, null);
        $invoiceId = $this->invoice($varsymbol, $clientId, 800.00, 152.00, false);
        $this->insertItem($invoiceId, 'Digitální služba DE', 800.00, 152.00, 19.00, 0, 'DE');

        return $invoiceId;
    }

    /** Běžná tuzemská faktura 1 000/210 — kontrolní vzorek beze změny chování. */
    private function domesticInvoice(string $varsymbol): int
    {
        $clientId  = $this->client('Odběratel s.r.o.', $this->czId, 'CZ12345678');
        $invoiceId = $this->invoice($varsymbol, $clientId, 1000.00, 210.00, false);
        $this->insertItem($invoiceId, 'Tuzemská služba', 1000.00, 210.00, 21.00, 0, null);

        return $invoiceId;
    }

    private function invoice(string $varsymbol, int $clientId, float $base, float $vat, bool $creditNote): int
    {
        $stmt = $this->db->pdo()->prepare(
            'INSERT INTO invoices
                (supplier_id, varsymbol, invoice_type, client_id, issue_date, tax_date, due_date,
                 currency_id, reverse_charge, total_without_vat, total_vat, total_with_vat,
                 status, vat_classification_code, created_by)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, 0, ?, ?, ?, "issued", "1", ?)'
        );
        $stmt->execute([
            $this->supplierId,
            $varsymbol,
            $creditNote ? 'credit_note' : 'invoice',
            $clientId,
            self::ENTRY_DATE,
            self::ENTRY_DATE,
            self::ENTRY_DATE,
            $this->currencyId,
            $base,
            $vat,
            $base + $vat,
            $this->userId,
        ]);

        return (int) $this->db->pdo()->lastInsertId();
    }

    private function insertItem(
        int $invoiceId,
        string $description,
        float $base,
        float $vat,
        float $rate,
        int $orderIndex,
        ?string $ossCountry,
    ): void {
        $stmt = $this->db->pdo()->prepare(
            'INSERT INTO invoice_items
                (invoice_id, description, quantity, unit, unit_price_without_vat, vat_rate_id,
                 vat_rate_snapshot, total_without_vat, total_vat, total_with_vat, order_index,
                 oss_applicable, oss_consumer_country, oss_rate_type, oss_supply_type)
             VALUES (?, ?, 1, "ks", ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $invoiceId,
            $description,
            $base,
            $this->vatRateId,
            $rate,
            $base,
            $vat,
            $base + $vat,
            $orderIndex,
            $ossCountry === null ? 0 : 1,
            $ossCountry,
            $ossCountry === null ? null : 'standard',
            $ossCountry === null ? null : 'services',
        ]);
    }

    /**
     * @param list<array<string,mixed>> $lines
     * @return array<string,array{debit:float,credit:float}>
     */
    private function linesByAccountCode(array $lines): array
    {
        $codeById = [];
        $stmt = $this->db->pdo()->prepare('SELECT id, account_code FROM chart_of_accounts WHERE supplier_id = ?');
        $stmt->execute([$this->supplierId]);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $codeById[(int) $r['id']] = (string) $r['account_code'];
        }
        $out = [];
        foreach ($lines as $l) {
            $code = $codeById[(int) $l['account_id']] ?? '?';
            $out[$code] ??= ['debit' => 0.0, 'credit' => 0.0];
            $out[$code][$l['side']] += (float) $l['amount'];
        }

        return $out;
    }

    /** @param list<array<string,mixed>> $lines */
    private function assertBalanced(array $lines): void
    {
        $debit = 0;
        $credit = 0;
        foreach ($lines as $l) {
            $cents = (int) round((float) $l['amount'] * 100);
            if ($l['side'] === 'debit') {
                $debit += $cents;
            } else {
                $credit += $cents;
            }
        }
        self::assertSame($debit, $credit, 'Σ MD == Σ D (v haléřích).');
    }
}
