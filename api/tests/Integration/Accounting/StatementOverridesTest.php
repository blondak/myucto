<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Accounting;

use MyInvoice\Action\Accounting\Reports\StatementOverrideAction;
use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Middleware\SupplierScopeMiddleware;
use MyInvoice\Repository\AccountingPeriodRepository;
use MyInvoice\Repository\StatementDefinitionRepository;
use MyInvoice\Service\Accounting\ChartOfAccountsSeeder;
use MyInvoice\Service\Accounting\PostingService;
use MyInvoice\Service\Accounting\Reports\EntityCategoryService;
use MyInvoice\Service\Accounting\Reports\FinancialStatementService;
use MyInvoice\Service\Accounting\Reports\StatementMapResolver;
use MyInvoice\Service\Accounting\Reports\StatementOverrideService;
use MyInvoice\Service\Accounting\Reports\StatementOverrideSuggester;
use MyInvoice\Tests\Support\IsolatedSupplierTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response as Psr7Response;

/**
 * Výjimky mapování účtů do výkazů pro konkrétní firmu.
 *
 * Scénář: firma má od společníka půjčku na analytice 365.100 (500 000 Kč, splatná za víc
 * než rok) a běžný závazek ke společníkům na 365.200 (30 000 Kč). Globální mapa dá celou
 * syntetiku 365 do krátkodobých závazků (P.C.II.8.1.). Výjimka na analytiku 365.100 ji musí
 * přesunout do dlouhodobých (P.C.I.9.1.) v rozvaze i v příloze přiznání DPPO, u jiné firmy
 * nesmí změnit nic a návrh z podaného přiznání musí přesun sám najít.
 */
#[Group('integration')]
final class StatementOverridesTest extends TestCase
{
    use IsolatedSupplierTrait;

    private const YEAR = 2093;
    private const ENDS_ON = self::YEAR . '-12-31';
    private const LONG_TERM = 'P.C.I.9.1.';
    private const SHORT_TERM = 'P.C.II.8.1.';

    private Connection $db;
    private FinancialStatementService $statements;
    private StatementDefinitionRepository $definitions;
    private StatementOverrideService $overrides;
    private StatementOverrideSuggester $suggester;
    private StatementMapResolver $maps;
    private EntityCategoryService $categories;
    private StatementOverrideAction $action;
    private PostingService $posting;
    private AccountingPeriodRepository $periods;
    private ChartOfAccountsSeeder $seeder;

    private int $sourceSupplierId = 0;
    private int $supplierId = 0;
    private int $periodId = 0;
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
            $this->definitions = $c->get(StatementDefinitionRepository::class);
            $this->overrides   = $c->get(StatementOverrideService::class);
            $this->suggester   = $c->get(StatementOverrideSuggester::class);
            $this->maps        = $c->get(StatementMapResolver::class);
            $this->categories  = $c->get(EntityCategoryService::class);
            $this->action      = $c->get(StatementOverrideAction::class);
            $this->posting     = $c->get(PostingService::class);
            $this->periods     = $c->get(AccountingPeriodRepository::class);
            $this->seeder      = $c->get(ChartOfAccountsSeeder::class);
        } catch (\Throwable $e) {
            $this->markTestSkipped('DI/DB nedostupné: ' . $e->getMessage());
        }

        $pdo = $this->db->pdo();
        $this->sourceSupplierId = (int) ($pdo->query('SELECT id FROM supplier ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        $this->userId = (int) ($pdo->query('SELECT id FROM users ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        if ($this->sourceSupplierId === 0 || $this->userId === 0) {
            $this->markTestSkipped('Chybí supplier / user.');
        }
        $version = $this->definitions->findVersion('balance_sheet', self::ENDS_ON);
        if ($version === null) {
            $this->markTestSkipped('Chybí verze rozvahy.');
        }
        $this->versionId = (int) $version['id'];

        $pdo->beginTransaction();
        $this->inTx = true;
        [$this->supplierId, $this->periodId] = $this->createCompanyWithShareholderLoan();
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

    public function testAnalyticOverrideMovesAmountInBalanceSheetAndDppoAppendix(): void
    {
        $before = $this->liabilities($this->supplierId, $this->periodId);
        self::assertEqualsWithDelta(530_000.0, $before[self::SHORT_TERM], 0.01, 'Bez výjimky je celá 365 krátkodobá.');
        self::assertEqualsWithDelta(0.0, $before[self::LONG_TERM], 0.01);

        $this->overrides->save($this->supplierId, $this->versionId, [
            ['account_prefix' => '365.100', 'row_code' => self::LONG_TERM, 'note' => 'Půjčka splatná za 3 roky'],
        ], $this->userId);

        $sheet = $this->statements->balanceSheet($this->supplierId, $this->periodId, self::ENDS_ON, 'full');
        $after = $this->liabilities($this->supplierId, $this->periodId);
        self::assertEqualsWithDelta(500_000.0, $after[self::LONG_TERM], 0.01, 'Analytika s výjimkou je v dlouhodobých závazcích.');
        self::assertEqualsWithDelta(30_000.0, $after[self::SHORT_TERM], 0.01, 'Zbytek syntetiky zůstává krátkodobý.');
        self::assertEqualsWithDelta($before['PASIVA'], $after['PASIVA'], 0.01, 'Přesun mezi řádky nemění pasiva celkem.');
        self::assertTrue($sheet['checks']['balanced'], 'Rozvaha musí zůstat vyrovnaná.');

        // Příloha přiznání DPPO — stejná cesta jako podání (FinancialStatementService → DppoXmlBuilder).
        $appendix = $this->suggester->appAppendix($this->supplierId, $this->periodId);
        self::assertSame(500, $appendix['VetaUD'][43]['kc_sled'] ?? null, 'VetaUD ř. 43 (C.I.9.1. závazky ke společníkům dlouhodobé).');
        self::assertSame(30, $appendix['VetaUD'][57]['kc_sled'] ?? null, 'VetaUD ř. 57 (C.II.8.1. závazky ke společníkům krátkodobé).');
    }

    public function testCompanyWithoutOverridesUsesTheGlobalMapUnchanged(): void
    {
        $version = (array) $this->definitions->findVersionById($this->versionId);
        $global = array_map(
            static fn (array $m): array => $m + ['source' => 'global'],
            $this->definitions->accountMap($this->versionId),
        );
        self::assertSame($global, $this->maps->accountMap($version, $this->supplierId), 'Bez výjimek je sloučená mapa globální mapou.');

        $rows = $this->liabilities($this->supplierId, $this->periodId);
        self::assertEqualsWithDelta(530_000.0, $rows[self::SHORT_TERM], 0.01, 'Analytiky 365.x se bez výjimky sčítají pod syntetiku.');
    }

    public function testOverridesAreIsolatedPerCompany(): void
    {
        [$otherSupplier, $otherPeriod] = $this->createCompanyWithShareholderLoan();

        $saved = $this->overrides->save($this->supplierId, $this->versionId, [
            ['account_prefix' => '365.100', 'row_code' => self::LONG_TERM],
        ], $this->userId);

        self::assertEqualsWithDelta(500_000.0, $this->liabilities($this->supplierId, $this->periodId)[self::LONG_TERM], 0.01);
        $other = $this->liabilities($otherSupplier, $otherPeriod);
        self::assertEqualsWithDelta(0.0, $other[self::LONG_TERM], 0.01, 'Výjimka jedné firmy se nesmí projevit u jiné.');
        self::assertEqualsWithDelta(530_000.0, $other[self::SHORT_TERM], 0.01);

        $list = $this->call('list', 'GET', 'readonly', ['period_id' => (string) $otherPeriod], [], [], $otherSupplier);
        self::assertSame(200, $list['status']);
        self::assertSame([], $list['body']['overrides'], 'Cizí firma výjimku nevidí.');

        $id = (string) $saved[0]['id'];
        $update = $this->call('update', 'PUT', 'accountant', [], ['id' => $id], ['row_code' => self::SHORT_TERM], $otherSupplier);
        self::assertSame(404, $update['status'], 'Cizí firma výjimku neupraví.');
        $delete = $this->call('delete', 'DELETE', 'accountant', [], ['id' => $id], [], $otherSupplier);
        self::assertSame(404, $delete['status'], 'Cizí firma výjimku nesmaže.');
    }

    public function testAccountListShowsWhereAccountsGoAndWhy(): void
    {
        $this->overrides->save($this->supplierId, $this->versionId, [
            ['account_prefix' => '365.100', 'row_code' => self::LONG_TERM],
        ], $this->userId);

        $res = $this->call('list', 'GET', 'readonly', ['period_id' => (string) $this->periodId, 'statement_type' => 'balance_sheet']);
        self::assertSame(200, $res['status']);
        $accounts = array_column($res['body']['accounts'], null, 'account_code');

        self::assertSame(self::LONG_TERM, $accounts['365.100']['mappings'][0]['row_code']);
        self::assertSame('override', $accounts['365.100']['mappings'][0]['source']);
        self::assertSame(self::SHORT_TERM, $accounts['365.200']['mappings'][0]['row_code']);
        self::assertSame('global', $accounts['365.200']['mappings'][0]['source']);
        self::assertEqualsWithDelta(-500_000.0, $accounts['365.100']['balance'], 0.01);
        self::assertNull($res['body']['filed_return'], 'Firma nemá v evidenci podané DPPO.');
    }

    public function testPreviewShowsImpactWithoutSaving(): void
    {
        $res = $this->call('preview', 'POST', 'accountant', [], [], [
            'period_id' => $this->periodId,
            'statement_type' => 'balance_sheet',
            'overrides' => [['account_prefix' => '365.100', 'row_code' => self::LONG_TERM]],
        ]);
        self::assertSame(200, $res['status'], json_encode($res['body'], JSON_UNESCAPED_UNICODE) ?: '');
        $rows = array_column($res['body']['rows'], null, 'row_code');
        self::assertEqualsWithDelta(500_000.0, $rows[self::LONG_TERM]['delta'], 0.01);
        self::assertEqualsWithDelta(-500_000.0, $rows[self::SHORT_TERM]['delta'], 0.01);
        self::assertArrayNotHasKey('PASIVA', $rows, 'Pasiva celkem se nemění.');
        self::assertTrue($res['body']['balanced_after']);

        self::assertSame([], $this->maps->overridesFor($this->supplierId, $this->versionId), 'Náhled nic neuloží.');
    }

    public function testWritesRequireAccountantAndValidateRows(): void
    {
        $body = ['version_id' => $this->versionId, 'overrides' => [['account_prefix' => '365.100', 'row_code' => self::LONG_TERM]]];

        self::assertSame(403, $this->call('save', 'PUT', 'readonly', [], [], $body)['status'], 'Jen pro čtení nesmí zapisovat.');
        self::assertSame(200, $this->call('save', 'PUT', 'accountant', [], [], $body)['status']);

        $bad = $this->call('save', 'PUT', 'accountant', [], [], [
            'version_id' => $this->versionId,
            'overrides' => [['account_prefix' => '365.100', 'row_code' => 'NEEXISTUJE']],
        ]);
        self::assertSame(422, $bad['status']);

        $dup = $this->call('save', 'PUT', 'accountant', [], [], [
            'version_id' => $this->versionId,
            'overrides' => [
                ['account_prefix' => '365.100', 'row_code' => self::LONG_TERM],
                ['account_prefix' => '365.100', 'row_code' => self::SHORT_TERM, 'balance_condition' => 'credit'],
            ],
        ]);
        self::assertSame(422, $dup['status'], 'Dvě výjimky pro stejnou stranu zůstatku jsou chyba.');
    }

    public function testSuggesterFindsTheMoveFromFiledReturn(): void
    {
        // „Podané" přiznání: příloha, jak vyšla s půjčkou správně v dlouhodobých závazcích.
        $this->overrides->save($this->supplierId, $this->versionId, [
            ['account_prefix' => '365.100', 'row_code' => self::LONG_TERM],
        ], $this->userId);
        $filed = $this->suggester->appAppendix($this->supplierId, $this->periodId);
        $this->overrides->save($this->supplierId, $this->versionId, [], $this->userId);

        $out = $this->suggester->suggestFromXml($this->supplierId, $this->periodId, $this->filedXml($filed));

        $byAccount = array_column($out['suggestions'], null, 'account_code');
        self::assertArrayHasKey('365.100', $byAccount, 'Návrh musí najít analytiku, jejíž zůstatek vysvětluje rozdíl.');
        self::assertSame(self::SHORT_TERM, $byAccount['365.100']['from_row_code']);
        self::assertSame(self::LONG_TERM, $byAccount['365.100']['to_row_code']);
        self::assertSame(500, $byAccount['365.100']['amount_thousands']);
        self::assertFalse($byAccount['365.100']['ambiguous']);
        self::assertArrayNotHasKey('365.200', $byAccount, 'Účet, který rozdíl nevysvětluje, se nenavrhuje.');

        self::assertSame([], $this->maps->overridesFor($this->supplierId, $this->versionId), 'Návrh nic nezapisuje.');
    }

    /**
     * Malá (tady mikro) ÚJ může podat rozvahu v PLNÉM rozsahu. Kdyby aplikace svou přílohu
     * postavila ve zkráceném rozsahu podle kategorie, podrobné řádky by jí vyšly nulové
     * a přesun mezi nimi by nešel najít — přesně to se stalo na převedených datech.
     */
    public function testSmallCompanyWithFullFiledReturnStillFindsTheMove(): void
    {
        $this->db->pdo()->prepare('DELETE FROM accounting_supplier_settings WHERE supplier_id = ?')
            ->execute([$this->supplierId]);
        self::assertNotSame('full', $this->categories->statementScope($this->supplierId, $this->periodId),
            'Fixture musí být malá nebo mikro ÚJ.');

        $this->overrides->save($this->supplierId, $this->versionId, [
            ['account_prefix' => '365.100', 'row_code' => self::LONG_TERM],
        ], $this->userId);
        $filed = $this->suggester->appAppendix($this->supplierId, $this->periodId, 'full');
        $this->overrides->save($this->supplierId, $this->versionId, [], $this->userId);

        $out = $this->suggester->suggestFromXml($this->supplierId, $this->periodId, $this->filedXml($filed));

        self::assertSame('full', $out['scope'], 'Rozsah přílohy aplikace se řídí podáním.');
        $byAccount = array_column($out['suggestions'], null, 'account_code');
        self::assertArrayHasKey('365.100', $byAccount);
        self::assertSame(self::LONG_TERM, $byAccount['365.100']['to_row_code']);
    }

    /**
     * Víc analytik jedné syntetiky přesunutých společně („365.*") a dva přesuny do
     * téhož cílového řádku — žádný jednotlivý účet rozdíl sám nevysvětlí.
     */
    public function testGroupedAnalyticsAndSeveralMovesIntoOneRow(): void
    {
        $this->overrides->save($this->supplierId, $this->versionId, [
            ['account_prefix' => '365.', 'row_code' => self::LONG_TERM],
            ['account_prefix' => '379.400', 'row_code' => self::LONG_TERM],
        ], $this->userId);
        $parent = $this->db->pdo()->prepare("SELECT id FROM chart_of_accounts WHERE supplier_id = ? AND account_code = '379'");
        $parent->execute([$this->supplierId]);
        $this->db->pdo()->prepare(
            "INSERT INTO chart_of_accounts (supplier_id, account_code, name, account_type, normal_side, is_synthetic, parent_id, is_active)
             VALUES (?, '379.400', 'Jiné závazky dlouhodobé', 'liability', 'credit', 0, ?, 1)"
        )->execute([$this->supplierId, (int) $parent->fetchColumn()]);
        $this->post($this->supplierId, '311', '379.400', 70_000.00);

        $filed = $this->suggester->appAppendix($this->supplierId, $this->periodId, 'full');
        $this->overrides->save($this->supplierId, $this->versionId, [], $this->userId);

        $out = $this->suggester->suggestFromXml($this->supplierId, $this->periodId, $this->filedXml($filed));
        $prefixes = [];
        foreach ($out['suggestions'] as $s) {
            foreach ($s['overrides'] as $o) {
                $prefixes[$o['account_prefix']] = $o['row_code'];
            }
        }

        self::assertSame(self::LONG_TERM, $prefixes['365.'] ?? null, 'Obě analytiky 365 jako jeden prefix.');
        self::assertSame(self::LONG_TERM, $prefixes['379.400'] ?? null, 'Druhý přesun do stejného řádku.');
    }

    // ── fixtures ─────────────────────────────────────────────────────────────

    /** @return array{0:int,1:int} supplier, období */
    private function createCompanyWithShareholderLoan(): array
    {
        $pdo = $this->db->pdo();
        $supplierId = $this->createIsolatedSupplier($pdo, $this->sourceSupplierId);
        $this->seeder->seedForSupplier($supplierId);
        $periodId = $this->periods->create($supplierId, self::YEAR, self::YEAR . '-01-01', self::ENDS_ON);
        // Plný rozsah: jinak by malá/mikro ÚJ neposílala podřádky pasiv a přesun by nebyl vidět.
        $pdo->prepare(
            "INSERT INTO accounting_supplier_settings (supplier_id, statement_scope_override) VALUES (?, 'full')
             ON DUPLICATE KEY UPDATE statement_scope_override = 'full'"
        )->execute([$supplierId]);

        $parent = $pdo->prepare("SELECT id FROM chart_of_accounts WHERE supplier_id = ? AND account_code = '365'");
        $parent->execute([$supplierId]);
        $parentId = (int) $parent->fetchColumn();
        self::assertGreaterThan(0, $parentId, 'Osnova musí mít účet 365.');
        $insert = $pdo->prepare(
            "INSERT INTO chart_of_accounts (supplier_id, account_code, name, account_type, normal_side, is_synthetic, parent_id, is_active)
             VALUES (?, ?, ?, 'liability', 'credit', 0, ?, 1)"
        );
        $insert->execute([$supplierId, '365.100', 'Půjčka od společníka', $parentId]);
        $insert->execute([$supplierId, '365.200', 'Ostatní závazky ke společníkům', $parentId]);

        $this->post($supplierId, '311', '365.100', 500_000.00);
        $this->post($supplierId, '311', '365.200', 30_000.00);
        $this->post($supplierId, '311', '602', 100_000.00);

        return [$supplierId, $periodId];
    }

    private function post(int $supplierId, string $debit, string $credit, float $amount): void
    {
        $this->posting->postDocument($supplierId, 'manual', null, [
            ['account_code' => $debit, 'side' => 'debit', 'amount' => $amount],
            ['account_code' => $credit, 'side' => 'credit', 'amount' => $amount],
        ], [
            'entry_date'  => self::YEAR . '-06-30',
            'description' => 'Test výjimek mapování ' . $debit . '/' . $credit,
            'posted'      => true,
            'user_id'     => $this->userId,
        ]);
    }

    /** @return array<string,float> pasiva podle kódu řádku */
    private function liabilities(int $supplierId, int $periodId): array
    {
        $out = [];
        foreach ($this->statements->balanceSheet($supplierId, $periodId, self::ENDS_ON, 'full')['liabilities'] as $r) {
            $out[(string) $r['row_code']] = (float) $r['amount'];
        }

        return $out;
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

    /**
     * @param array<string,string> $query
     * @param array<string,string> $args
     * @param array<string,mixed>  $body
     * @return array{status:int, body:array<string,mixed>}
     */
    private function call(string $method, string $httpMethod, string $role, array $query = [], array $args = [], array $body = [], ?int $supplierId = null): array
    {
        $req = (new ServerRequestFactory())
            ->createServerRequest($httpMethod, '/api/accounting/reports/statement-overrides')
            ->withQueryParams($query)
            ->withAttribute(SupplierScopeMiddleware::ATTR_CURRENT_ID, $supplierId ?? $this->supplierId)
            ->withAttribute(AuthMiddleware::ATTR_USER, ['id' => $this->userId, 'role' => $role]);
        if ($body !== []) {
            $req = $req->withParsedBody($body);
        }
        $resp = $args === []
            ? $this->action->{$method}($req, new Psr7Response())
            : $this->action->{$method}($req, new Psr7Response(), $args);
        $resp->getBody()->rewind();
        $decoded = json_decode((string) $resp->getBody(), true);

        return ['status' => $resp->getStatusCode(), 'body' => is_array($decoded) ? $decoded : []];
    }
}
