<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Accounting;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\AccountingPeriodRepository;
use MyInvoice\Repository\StatementDefinitionRepository;
use MyInvoice\Service\Accounting\ChartOfAccountsSeeder;
use MyInvoice\Service\Accounting\PostingService;
use MyInvoice\Service\Accounting\Reports\FinancialStatementService;
use MyInvoice\Service\Accounting\Reports\ReportException;
use MyInvoice\Service\Accounting\Reports\StatementNotesService;
use MyInvoice\Service\Accounting\Reports\StatementOverrideService;
use MyInvoice\Service\Accounting\Reports\StatementOverrideSuggester;
use MyInvoice\Tests\Support\IsolatedSupplierTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Prezentace rozvahy: řádky aktiv se záporným netto a zařazení opravných položek.
 *
 * Scénář: obchodní pohledávka 100 000 Kč (311) a opravná položka 150 000 Kč na 391,
 * která ve skutečnosti patří k jiné pohledávce. Globální mapa dá celou 391 do korekce
 * C.II.2.1., takže netto řádku vyjde záporné.
 */
#[Group('integration')]
final class BalanceSheetPresentationTest extends TestCase
{
    use IsolatedSupplierTrait;

    private const PREV_YEAR = 2091;
    private const YEAR = 2092;
    private const ENDS_ON = self::YEAR . '-12-31';

    private Connection $db;
    private FinancialStatementService $statements;
    private StatementOverrideService $overrides;
    private StatementOverrideSuggester $suggester;
    private StatementNotesService $notes;
    private StatementDefinitionRepository $definitions;
    private PostingService $posting;
    private AccountingPeriodRepository $periods;
    private ChartOfAccountsSeeder $seeder;

    private int $supplierId = 0;
    private int $periodId = 0;
    private int $prevPeriodId = 0;
    private int $userId = 0;
    private int $versionId = 0;
    private bool $inTx = false;

    protected function setUp(): void
    {
        $rootDir = dirname(__DIR__, 4);
        if (!is_file($rootDir . '/cfg.php')) {
            $this->markTestSkipped('cfg.php neexistuje — test vyžaduje DB.');
        }
        try {
            $c = Bootstrap::buildApp()->getContainer();
            $this->db          = $c->get(Connection::class);
            $this->statements  = $c->get(FinancialStatementService::class);
            $this->overrides   = $c->get(StatementOverrideService::class);
            $this->suggester   = $c->get(StatementOverrideSuggester::class);
            $this->notes       = $c->get(StatementNotesService::class);
            $this->definitions = $c->get(StatementDefinitionRepository::class);
            $this->posting     = $c->get(PostingService::class);
            $this->periods     = $c->get(AccountingPeriodRepository::class);
            $this->seeder      = $c->get(ChartOfAccountsSeeder::class);
        } catch (\Throwable $e) {
            $this->markTestSkipped('DI/DB nedostupné: ' . $e->getMessage());
        }

        $pdo = $this->db->pdo();
        $sourceSupplierId = (int) ($pdo->query('SELECT id FROM supplier ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        $this->userId = (int) ($pdo->query('SELECT id FROM users ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        if ($sourceSupplierId === 0 || $this->userId === 0) {
            $this->markTestSkipped('Chybí supplier / user.');
        }
        $version = $this->definitions->findVersion('balance_sheet', self::ENDS_ON);
        if ($version === null) {
            $this->markTestSkipped('Chybí verze rozvahy.');
        }
        $this->versionId = (int) $version['id'];

        $pdo->beginTransaction();
        $this->inTx = true;

        $this->supplierId = $this->createIsolatedSupplier($pdo, $sourceSupplierId);
        $this->seeder->seedForSupplier($this->supplierId);
        $this->prevPeriodId = $this->periods->create($this->supplierId, self::PREV_YEAR, self::PREV_YEAR . '-01-01', self::PREV_YEAR . '-12-31');
        $this->periodId = $this->periods->create($this->supplierId, self::YEAR, self::YEAR . '-01-01', self::ENDS_ON);
        $pdo->prepare(
            "INSERT INTO accounting_supplier_settings (supplier_id, statement_scope_override) VALUES (?, 'full')
             ON DUPLICATE KEY UPDATE statement_scope_override = 'full'"
        )->execute([$this->supplierId]);

        $this->analytic('351', '351.100', 'Dlouhodobá pohledávka za ovládanou osobou', 'asset', 'debit');
        $this->analytic('391', '391.100', 'OP k dlouhodobé pohledávce', 'asset', 'credit');
        $this->analytic('391', '391.200', 'OP k obchodním pohledávkám', 'asset', 'credit');
    }

    protected function tearDown(): void
    {
        if (isset($this->db) && $this->inTx) {
            if ($this->db->pdo()->inTransaction()) {
                $this->db->pdo()->rollBack();
            }
            $this->db->close();
        }
    }

    public function testNegativeNetAssetRowIsReportedOnceWhereItArises(): void
    {
        $this->post(self::YEAR, '311', '602', 100_000.00);
        $this->post(self::YEAR, '558', '391.100', 150_000.00);

        $sheet = $this->statements->balanceSheet($this->supplierId, $this->periodId, self::ENDS_ON, 'full');
        $assets = array_column($sheet['assets'], null, 'row_code');
        self::assertEqualsWithDelta(-50_000.0, $assets['C.II.2.1.']['net'], 0.01, 'Fixture: celá 391 v korekci obchodních pohledávek.');

        $negative = $sheet['checks']['negative_net_rows'];
        self::assertCount(1, $negative, 'Mezisoučty nad záporným řádkem se nehlásí znovu.');
        self::assertSame('C.II.2.1.', $negative[0]['row_code']);
        self::assertSame('current', $negative[0]['column']);
        self::assertEqualsWithDelta(100_000.0, $negative[0]['gross'], 0.01);
        self::assertEqualsWithDelta(150_000.0, $negative[0]['correction'], 0.01);
        self::assertEqualsWithDelta(-50_000.0, $negative[0]['net'], 0.01);
        self::assertTrue($sheet['checks']['balanced'], 'Rozvaha se kvůli záporné položce nesmí rozvážit.');
    }

    public function testBalanceSheetWithoutNegativeRowsReportsNothing(): void
    {
        $this->post(self::YEAR, '311', '602', 100_000.00);
        $this->post(self::YEAR, '558', '391.200', 40_000.00);

        $sheet = $this->statements->balanceSheet($this->supplierId, $this->periodId, self::ENDS_ON, 'full');
        self::assertSame([], $sheet['checks']['negative_net_rows']);
    }

    /**
     * Výjimka s platností od roku 2092 nesmí přeřadit účet ve výkazu roku 2091. Sloupec
     * minulého období se ve výchozím stavu přepočítá podle zařazení běžného roku
     * (srovnatelnost), s volbou „převzít z uzavřeného výkazu" jako výkaz minulého roku.
     */
    public function testOverrideValidityByYearAndComparativeColumn(): void
    {
        $this->post(self::PREV_YEAR, '351.100', '602', 200_000.00);
        $this->overrides->save($this->supplierId, $this->versionId, [
            ['account_prefix' => '351.100', 'row_code' => 'C.II.1.5.4.', 'valid_from_year' => self::YEAR, 'note' => 'Splatnost prodloužena'],
        ], $this->userId);

        $prevYear = $this->assets($this->prevPeriodId, self::PREV_YEAR . '-12-31');
        self::assertEqualsWithDelta(200_000.0, $prevYear['C.II.2.2.']['net'], 0.01, 'V roce 2091 výjimka neplatí, účet je podle globální mapy.');
        self::assertEqualsWithDelta(0.0, $prevYear['C.II.1.5.4.']['net'] ?? 0.0, 0.01);

        $current = $this->assets($this->periodId, self::ENDS_ON);
        self::assertEqualsWithDelta(200_000.0, $current['C.II.1.5.4.']['net'], 0.01, 'Od roku 2092 platí výjimka.');
        self::assertEqualsWithDelta(0.0, $current['C.II.2.2.']['net'], 0.01);
        self::assertEqualsWithDelta(200_000.0, $current['C.II.1.5.4.']['prev_net'], 0.01, 'Výchozí: minulé období podle zařazení běžného roku.');
        self::assertEqualsWithDelta(0.0, $current['C.II.2.2.']['prev_net'], 0.01);

        $this->db->pdo()->prepare('UPDATE accounting_supplier_settings SET comparative_from_prior_year = 1 WHERE supplier_id = ?')
            ->execute([$this->supplierId]);
        $comparative = $this->assets($this->periodId, self::ENDS_ON);
        self::assertEqualsWithDelta(200_000.0, $comparative['C.II.1.5.4.']['net'], 0.01, 'Běžné období se volbou nemění.');
        self::assertEqualsWithDelta(200_000.0, $comparative['C.II.2.2.']['prev_net'], 0.01, 'Minulé období shodné s výkazem roku 2091.');
        self::assertEqualsWithDelta(0.0, $comparative['C.II.1.5.4.']['prev_net'], 0.01);
    }

    public function testOverridesWithDisjointYearsCoexistAndOverlapsAreRejected(): void
    {
        $this->post(self::PREV_YEAR, '351.100', '602', 200_000.00);
        $this->overrides->save($this->supplierId, $this->versionId, [
            ['account_prefix' => '351.100', 'row_code' => 'C.II.2.4.6.', 'valid_to_year' => self::PREV_YEAR],
            ['account_prefix' => '351.100', 'row_code' => 'C.II.1.5.4.', 'valid_from_year' => self::YEAR],
        ], $this->userId);

        self::assertEqualsWithDelta(200_000.0, $this->assets($this->prevPeriodId, self::PREV_YEAR . '-12-31')['C.II.2.4.6.']['net'], 0.01);
        self::assertEqualsWithDelta(200_000.0, $this->assets($this->periodId, self::ENDS_ON)['C.II.1.5.4.']['net'], 0.01);

        try {
            $this->overrides->save($this->supplierId, $this->versionId, [
                ['account_prefix' => '351.100', 'row_code' => 'C.II.2.4.6.', 'valid_to_year' => self::YEAR],
                ['account_prefix' => '351.100', 'row_code' => 'C.II.1.5.4.', 'valid_from_year' => self::YEAR],
            ], $this->userId);
            self::fail('Překrývající se platnost pro stejnou stranu zůstatku musí být odmítnuta.');
        } catch (ReportException $e) {
            self::assertSame(422, $e->httpStatus);
            self::assertStringContainsString('351.100', $e->getMessage());
        }
    }

    /**
     * Opravná položka navázaná na pohledávku jde do řádku, kam výkaz zařadí pohledávku,
     * a při jejím přeřazení ji následuje. Uložený řádek výjimky je jen záloha.
     */
    public function testCorrectionFollowsItsReceivable(): void
    {
        $this->postCorrectionScenario();
        $this->overrides->save($this->supplierId, $this->versionId, [
            ['account_prefix' => '351.100', 'row_code' => 'C.II.1.5.4.'],
            ['account_prefix' => '391.100', 'row_code' => 'C.II.2.1.', 'target' => 'correction', 'follows_prefix' => '351.100'],
        ], $this->userId);

        $sheet = $this->statements->balanceSheet($this->supplierId, $this->periodId, self::ENDS_ON, 'full');
        $assets = array_column($sheet['assets'], null, 'row_code');
        self::assertEqualsWithDelta(200_000.0, $assets['C.II.1.5.4.']['gross'], 0.01);
        self::assertEqualsWithDelta(150_000.0, $assets['C.II.1.5.4.']['correction'], 0.01, 'Korekce jde k pohledávce, ne do uloženého řádku.');
        self::assertEqualsWithDelta(80_000.0, $assets['C.II.2.1.']['net'], 0.01, 'U obchodních pohledávek zůstane jen jejich vlastní opravná položka.');
        self::assertSame([], $sheet['checks']['negative_net_rows']);
        self::assertTrue($sheet['checks']['balanced']);

        $this->overrides->save($this->supplierId, $this->versionId, [
            ['account_prefix' => '351.100', 'row_code' => 'C.II.2.4.6.'],
            ['account_prefix' => '391.100', 'row_code' => 'C.II.2.1.', 'target' => 'correction', 'follows_prefix' => '351.100'],
        ], $this->userId);
        $moved = $this->assets($this->periodId, self::ENDS_ON);
        self::assertEqualsWithDelta(50_000.0, $moved['C.II.2.4.6.']['net'], 0.01, 'Po přeřazení pohledávky jde korekce s ní.');
        self::assertEqualsWithDelta(0.0, $moved['C.II.1.5.4.']['net'] ?? 0.0, 0.01);
    }

    public function testCorrectionCannotFollowALiabilityAccount(): void
    {
        $this->expectException(ReportException::class);
        $this->overrides->save($this->supplierId, $this->versionId, [
            ['account_prefix' => '391.100', 'row_code' => 'C.II.2.1.', 'target' => 'correction', 'follows_prefix' => '321'],
        ], $this->userId);
    }

    /**
     * Návrh z podaného přiznání porovná u aktiv brutto a korekci zvlášť: najde přesun
     * pohledávky i její opravné položky do dlouhodobých pohledávek a korekci naváže
     * na pohledávku.
     */
    public function testSuggesterMovesReceivableAndLinksItsCorrection(): void
    {
        $this->postCorrectionScenario();
        $suggester = $this->suggester;
        $this->overrides->save($this->supplierId, $this->versionId, [
            ['account_prefix' => '351.100', 'row_code' => 'C.II.1.5.4.'],
            ['account_prefix' => '391.100', 'row_code' => 'C.II.1.5.4.', 'target' => 'correction'],
        ], $this->userId);
        $filed = $suggester->appAppendix($this->supplierId, $this->periodId, 'full');
        $this->overrides->save($this->supplierId, $this->versionId, [], $this->userId);

        $out = $suggester->suggestFromXml($this->supplierId, $this->periodId, $this->filedXml($filed));
        $byAccount = array_column($out['suggestions'], null, 'account_code');

        self::assertArrayHasKey('351.100', $byAccount, json_encode($out['suggestions'], JSON_UNESCAPED_UNICODE) ?: '');
        self::assertSame('C.II.1.5.4.', $byAccount['351.100']['to_row_code']);
        self::assertSame('gross', $byAccount['351.100']['target']);
        self::assertArrayHasKey('391.100', $byAccount);
        self::assertSame('C.II.1.5.4.', $byAccount['391.100']['to_row_code']);
        self::assertSame('correction', $byAccount['391.100']['target']);
        self::assertSame('351.100', $byAccount['391.100']['overrides'][0]['follows_prefix'], 'Korekce je navázaná na pohledávku.');
        self::assertFalse($byAccount['391.100']['ambiguous']);
    }

    /**
     * § 58 odst. 2 vyhl. 500/2002 Sb.: přeplatek daně z příjmů (341) a nedoplatek DPH (343)
     * vůči finančnímu úřadu. Bez volby jsou v rozvaze zvlášť, s volbou se vykážou souhrnně
     * a příloha dostane větu s částkou.
     */
    public function testTaxAuthorityOffsetIsOptInAndGoesToNotes(): void
    {
        $this->post(self::YEAR, '341', '602', 30_000.00);
        $this->post(self::YEAR, '548', '343', 50_000.00);

        $plain = $this->statements->balanceSheet($this->supplierId, $this->periodId, self::ENDS_ON, 'full');
        $assets = array_column($plain['assets'], null, 'row_code');
        $liabilities = array_column($plain['liabilities'], null, 'row_code');
        self::assertEqualsWithDelta(30_000.0, $assets['C.II.2.4.3.']['net'], 0.01, 'Výchozí: bez kompenzace.');
        self::assertEqualsWithDelta(50_000.0, $liabilities['P.C.II.8.5.']['amount'], 0.01);
        self::assertNull($plain['checks']['tax_authority_offset']);

        $this->db->pdo()->prepare('UPDATE accounting_supplier_settings SET tax_authority_offset = 1 WHERE supplier_id = ?')
            ->execute([$this->supplierId]);
        $offset = $this->statements->balanceSheet($this->supplierId, $this->periodId, self::ENDS_ON, 'full');
        $assets = array_column($offset['assets'], null, 'row_code');
        $liabilities = array_column($offset['liabilities'], null, 'row_code');
        self::assertEqualsWithDelta(0.0, $assets['C.II.2.4.3.']['net'], 0.01, 'Přeplatek se započte s nedoplatkem.');
        self::assertEqualsWithDelta(20_000.0, $liabilities['P.C.II.8.5.']['amount'], 0.01);
        self::assertEqualsWithDelta($plain['checks']['assets_net'] - 30_000.0, $offset['checks']['assets_net'], 0.01);
        self::assertTrue($offset['checks']['balanced']);
        self::assertEqualsWithDelta(30_000.0, $offset['checks']['tax_authority_offset']['current'], 0.01);
        self::assertSame([], $offset['checks']['negative_net_rows'], 'Započtení nesmí žádnou stranu přetočit přes nulu.');

        $notes = $this->notes->build($this->supplierId, $this->periodId);
        $principles = array_column($notes['sections'], null, 'key')['accounting_principles'];
        self::assertStringContainsString('§ 58 odst. 2', (string) $principles['hint']);
        self::assertStringContainsString('30 000,00 Kč', (string) $principles['hint']);
    }

    /**
     * Souhrnné vykázání zavedené od roku 2092: výkaz roku 2091 ho nemá, sloupec minulého
     * období ve výkazu 2092 ho má jen bez převzetí z uzavřeného výkazu (pravidla běžného roku).
     */
    public function testTaxAuthorityOffsetFromYear(): void
    {
        $this->post(self::PREV_YEAR, '341', '602', 30_000.00);
        $this->post(self::PREV_YEAR, '548', '343', 50_000.00);
        $this->db->pdo()->prepare('UPDATE accounting_supplier_settings SET tax_authority_offset = 1, tax_authority_offset_from_year = ? WHERE supplier_id = ?')
            ->execute([self::YEAR, $this->supplierId]);

        $prevYear = $this->statements->balanceSheet($this->supplierId, $this->prevPeriodId, self::PREV_YEAR . '-12-31', 'full');
        self::assertNull($prevYear['checks']['tax_authority_offset'], 'Před rokem zavedení se nezapočítává.');
        self::assertEqualsWithDelta(30_000.0, array_column($prevYear['assets'], null, 'row_code')['C.II.2.4.3.']['net'], 0.01);

        $current = $this->assets($this->periodId, self::ENDS_ON);
        self::assertEqualsWithDelta(0.0, $current['C.II.2.4.3.']['net'], 0.01, 'Od roku zavedení se započítává.');
        self::assertEqualsWithDelta(0.0, $current['C.II.2.4.3.']['prev_net'], 0.01, 'Minulé období podle pravidel běžného roku.');

        $this->db->pdo()->prepare('UPDATE accounting_supplier_settings SET comparative_from_prior_year = 1 WHERE supplier_id = ?')
            ->execute([$this->supplierId]);
        $comparative = $this->assets($this->periodId, self::ENDS_ON);
        self::assertEqualsWithDelta(30_000.0, $comparative['C.II.2.4.3.']['prev_net'], 0.01, 'Minulé období jako uzavřený výkaz roku 2091.');
    }

    /**
     * Podané přiznání nese sloupec minulého období tak, jak byl v uzavřeném výkazu minulého
     * roku. Návrh ho porovná s výkazem minulého období aplikace a navrhne výjimku platnou
     * do minulého roku; běžné období se tím nemění.
     */
    public function testSuggesterProposesOverridesForThePriorPeriodColumn(): void
    {
        $this->post(self::PREV_YEAR, '351.100', '602', 200_000.00);
        $this->db->pdo()->prepare('UPDATE accounting_supplier_settings SET comparative_from_prior_year = 1 WHERE supplier_id = ?')
            ->execute([$this->supplierId]);
        $this->overrides->save($this->supplierId, $this->versionId, [
            ['account_prefix' => '351.100', 'row_code' => 'C.II.1.5.4.', 'valid_to_year' => self::PREV_YEAR],
        ], $this->userId);
        $filed = $this->suggester->appAppendix($this->supplierId, $this->periodId, 'full');
        $this->overrides->save($this->supplierId, $this->versionId, [], $this->userId);

        $out = $this->suggester->suggestFromXml($this->supplierId, $this->periodId, $this->filedXml($filed));

        self::assertNotContains('351.100', array_column($out['suggestions'], 'account_code'), 'Běžné období sedí, návrh pro něj nic nemění.');
        self::assertNotNull($out['prior_period']);
        $prior = array_column($out['prior_period']['suggestions'], null, 'account_code');
        self::assertArrayHasKey('351.100', $prior, json_encode($out['prior_period'], JSON_UNESCAPED_UNICODE) ?: '');
        self::assertSame('C.II.1.5.4.', $prior['351.100']['to_row_code']);
        self::assertSame(self::PREV_YEAR, $prior['351.100']['overrides'][0]['valid_to_year']);

        // Převzetí návrhu: sloupec minulého období je shodný s podaným.
        $this->overrides->save($this->supplierId, $this->versionId, $prior['351.100']['overrides'], $this->userId);
        $assets = $this->assets($this->periodId, self::ENDS_ON);
        self::assertEqualsWithDelta(200_000.0, $assets['C.II.1.5.4.']['prev_net'], 0.01);
        self::assertEqualsWithDelta(200_000.0, $assets['C.II.2.2.']['net'], 0.01, 'Běžné období podle globální mapy.');
    }

    // ── fixtures ─────────────────────────────────────────────────────────────

    /** Obchodní pohledávka 100 000 s OP 20 000, pohledávka za ovládanou osobou 200 000 s OP 150 000. */
    private function postCorrectionScenario(): void
    {
        $this->post(self::YEAR, '311', '602', 100_000.00);
        $this->post(self::YEAR, '351.100', '602', 200_000.00);
        $this->post(self::YEAR, '558', '391.100', 150_000.00);
        $this->post(self::YEAR, '558', '391.200', 20_000.00);
    }

    /**
     * Minimální XML podaného přiznání s přílohou — jen to, co návrh čte.
     *
     * @param array<string, array<int, array<string,int>>> $appendix
     */
    private function filedXml(array $appendix): string
    {
        $dom = new \DOMDocument('1.0', 'UTF-8');
        $root = $dom->appendChild($dom->createElement('Pisemnost'))->appendChild($dom->createElement('DPPDP9'));
        $vetaD = $dom->createElement('VetaD');
        $vetaD->setAttribute('zdobd_od', '01.01.' . self::YEAR);
        $vetaD->setAttribute('zdobd_do', '31.12.' . self::YEAR);
        $vetaD->setAttribute('uv_rozsah_rozv', 'P');
        $root->appendChild($vetaD);
        foreach ($appendix as $sentence => $rows) {
            foreach ($rows as $cRadku => $values) {
                $el = $dom->createElement($sentence);
                $el->setAttribute('c_radku', (string) $cRadku);
                foreach ($values as $attr => $value) {
                    $el->setAttribute($attr, (string) $value);
                }
                $root->appendChild($el);
            }
        }

        return (string) $dom->saveXML();
    }

    /** @return array<string, array<string,mixed>> aktiva podle kódu řádku */
    private function assets(int $periodId, string $asOf): array
    {
        return array_column($this->statements->balanceSheet($this->supplierId, $periodId, $asOf, 'full')['assets'], null, 'row_code');
    }

    private function analytic(string $parentCode, string $code, string $name, string $type, string $side): void
    {
        $pdo = $this->db->pdo();
        $parent = $pdo->prepare('SELECT id FROM chart_of_accounts WHERE supplier_id = ? AND account_code = ?');
        $parent->execute([$this->supplierId, $parentCode]);
        $parentId = (int) $parent->fetchColumn();
        self::assertGreaterThan(0, $parentId, 'Osnova musí mít účet ' . $parentCode . '.');
        $pdo->prepare(
            'INSERT INTO chart_of_accounts (supplier_id, account_code, name, account_type, normal_side, is_synthetic, parent_id, is_active)
             VALUES (?, ?, ?, ?, ?, 0, ?, 1)'
        )->execute([$this->supplierId, $code, $name, $type, $side, $parentId]);
    }

    private function post(int $year, string $debit, string $credit, float $amount): void
    {
        $this->posting->postDocument($this->supplierId, 'manual', null, [
            ['account_code' => $debit, 'side' => 'debit', 'amount' => $amount],
            ['account_code' => $credit, 'side' => 'credit', 'amount' => $amount],
        ], [
            'entry_date'  => $year . '-06-30',
            'description' => 'Test prezentace rozvahy ' . $debit . '/' . $credit,
            'posted'      => true,
            'user_id'     => $this->userId,
        ]);
    }
}
