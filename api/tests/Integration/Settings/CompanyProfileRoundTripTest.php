<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Settings;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\AccountingPeriodRepository;
use MyInvoice\Repository\StatementDefinitionRepository;
use MyInvoice\Service\Accounting\ChartOfAccountsSeeder;
use MyInvoice\Service\Accounting\Dimension\DimensionService;
use MyInvoice\Service\Accounting\PostingService;
use MyInvoice\Service\Accounting\Reports\FinancialStatementService;
use MyInvoice\Service\Accounting\Reports\StatementOverrideService;
use MyInvoice\Service\Settings\CompanyProfile\CompanyProfileException;
use MyInvoice\Service\Settings\CompanyProfile\CompanyProfileExporter;
use MyInvoice\Service\Settings\CompanyProfile\CompanyProfileImporter;
use MyInvoice\Tests\Support\IsolatedSupplierTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Profil firmy přežije nový převod: firma A má ručně vybudované nastavení (výjimky
 * mapování s platností po letech, sloupec minulého období z uzavřeného výkazu,
 * souhrnné vykázání daní vůči FÚ, dimenze, předkontace, pravidlo banky), firma B má
 * stejné účetnictví bez nastavení — tak vypadá firma po smazání a novém převodu.
 * Po nahrání profilu A do B musí výkazy obou firem vyjít stejně.
 */
#[Group('integration')]
final class CompanyProfileRoundTripTest extends TestCase
{
    use IsolatedSupplierTrait;

    private const PREV_YEAR = 2091;
    private const YEAR = 2092;
    private const CLIENT_IC = '10000005';

    private Connection $db;
    private CompanyProfileExporter $exporter;
    private CompanyProfileImporter $importer;
    private FinancialStatementService $statements;
    private StatementOverrideService $overrides;
    private DimensionService $dimensions;
    private PostingService $posting;
    private AccountingPeriodRepository $periods;
    private ChartOfAccountsSeeder $seeder;
    private int $versionId = 0;
    private int $sourceSupplierId = 0;
    private int $userId = 0;
    private bool $inTx = false;

    /** @var array<int,array{prev:int,current:int}> firma => období */
    private array $periodIds = [];

    protected function setUp(): void
    {
        $rootDir = dirname(__DIR__, 4);
        if (!is_file($rootDir . '/cfg.php')) {
            $this->markTestSkipped('cfg.php neexistuje — test vyžaduje DB.');
        }
        try {
            $c = Bootstrap::buildApp()->getContainer();
            $this->db = $c->get(Connection::class);
            $this->exporter = $c->get(CompanyProfileExporter::class);
            $this->importer = $c->get(CompanyProfileImporter::class);
            $this->statements = $c->get(FinancialStatementService::class);
            $this->overrides = $c->get(StatementOverrideService::class);
            $this->dimensions = $c->get(DimensionService::class);
            $this->posting = $c->get(PostingService::class);
            $this->periods = $c->get(AccountingPeriodRepository::class);
            $this->seeder = $c->get(ChartOfAccountsSeeder::class);
            $version = $c->get(StatementDefinitionRepository::class)->findVersion('balance_sheet', self::YEAR . '-12-31');
        } catch (\Throwable $e) {
            $this->markTestSkipped('DI/DB nedostupné: ' . $e->getMessage());
        }
        $pdo = $this->db->pdo();
        $this->sourceSupplierId = (int) ($pdo->query('SELECT id FROM supplier ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        $this->userId = (int) ($pdo->query('SELECT id FROM users ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        if ($this->sourceSupplierId === 0 || $this->userId === 0 || $version === null) {
            $this->markTestSkipped('Chybí supplier / user / verze rozvahy.');
        }
        $this->versionId = (int) $version['id'];
        $pdo->beginTransaction();
        $this->inTx = true;
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

    public function testProfileRestoresStatementsOfReconvertedCompany(): void
    {
        $a = $this->company();
        $this->buildSettings($a);
        $b = $this->company();

        $expected = $this->statementsFingerprint($a);
        self::assertNotSame($expected, $this->statementsFingerprint($b), 'Fixture: bez nastavení vyjdou výkazy jinak.');

        $profile = $this->exporter->export($a);
        self::assertSame('myucto.company-profile', $profile['format']);

        $preview = $this->importer->import($b, $profile, true);
        self::assertTrue($preview['dry_run']);
        self::assertGreaterThan(0, $preview['changed']);
        self::assertSame(1, $preview['sections']['statement_overrides']['created']);
        self::assertNotSame($expected, $this->statementsFingerprint($b), 'Zkouška nanečisto nic nezapíše.');
        self::assertSame(0, $this->rowCount('statement_account_overrides', $b));

        $result = $this->importer->import($b, $profile, false, null, $this->userId);
        self::assertSame($preview['changed'], $result['changed'], 'Náhled ukazuje přesně to, co udělá ostré nahrání.');
        self::assertSame($expected, $this->statementsFingerprint($b), 'Po nahrání profilu jsou výkazy shodné.');

        $again = $this->importer->import($b, $profile, false, null, $this->userId);
        self::assertSame(0, $again['changed'], 'Opakované nahrání nic nezmění.');

        $exportB = $this->exporter->export($b);
        self::assertSame($profile['sections'], $exportB['sections'], 'Profil firmy B po nahrání odpovídá profilu firmy A.');

        $rule = $this->db->pdo()->prepare('SELECT mode, mode_set_manually_at FROM bank_posting_rules WHERE supplier_id = ?');
        $rule->execute([$b]);
        $row = $rule->fetch(\PDO::FETCH_ASSOC);
        self::assertSame('auto', $row['mode'], 'Automatika pravidla se obnoví auditovaným povýšením.');
        self::assertNotNull($row['mode_set_manually_at']);
    }

    public function testProfileOfForeignFormatOrNewerVersionIsRejected(): void
    {
        $b = $this->company();
        foreach ([
            [['format' => 'jiny-program', 'version' => 1, 'sections' => []], 'invalid_profile'],
            [['format' => 'myucto.company-profile', 'version' => 99, 'sections' => []], 'unsupported_version'],
        ] as [$profile, $code]) {
            try {
                $this->importer->import($b, $profile, false);
                self::fail('Neplatný profil musí být odmítnut.');
            } catch (CompanyProfileException $e) {
                self::assertSame($code, $e->errorCode);
            }
        }
    }

    public function testInvalidItemRollsBackWholeImport(): void
    {
        $b = $this->company();
        $profile = [
            'format' => 'myucto.company-profile',
            'version' => 1,
            'sections' => [
                'company' => ['dimensions_enabled' => true],
                'statement_overrides' => [[
                    'statement_type' => 'balance_sheet',
                    'version_code' => $this->versionCode(),
                    'items' => [['account_prefix' => '351', 'row_code' => 'NEEXISTUJE']],
                ]],
            ],
        ];
        try {
            $this->importer->import($b, $profile, false);
            self::fail('Neplatná výjimka musí nahrání zastavit.');
        } catch (CompanyProfileException $e) {
            self::assertSame('statement_overrides', $e->section);
        }
        $stmt = $this->db->pdo()->prepare('SELECT dimensions_enabled FROM supplier WHERE id = ?');
        $stmt->execute([$b]);
        self::assertSame(0, (int) $stmt->fetchColumn(), 'Chyba v pozdější sekci vrátí i dřívější sekce.');
    }

    // ── fixture ─────────────────────────────────────────────────────────────────

    /** Firma se stejným účetnictvím, jako vznikne převodem (osnova, období, zápisy, klient). */
    private function company(): int
    {
        $pdo = $this->db->pdo();
        $id = $this->createIsolatedSupplier($pdo, $this->sourceSupplierId);
        $pdo->prepare('UPDATE supplier SET dimensions_enabled = 0, stock_enabled = 0 WHERE id = ?')->execute([$id]);
        $this->seeder->seedForSupplier($id);
        $this->periodIds[$id] = [
            'prev' => $this->periods->create($id, self::PREV_YEAR, self::PREV_YEAR . '-01-01', self::PREV_YEAR . '-12-31'),
            'current' => $this->periods->create($id, self::YEAR, self::YEAR . '-01-01', self::YEAR . '-12-31'),
        ];
        $pdo->prepare(
            "INSERT INTO accounting_supplier_settings (supplier_id, statement_scope_override) VALUES (?, 'full')
             ON DUPLICATE KEY UPDATE statement_scope_override = 'full'"
        )->execute([$id]);
        $this->analytic($id, '351', '351.100', 'Dlouhodobá pohledávka za ovládanou osobou', 'asset', 'debit');

        $this->post($id, self::PREV_YEAR, '351.100', '602', 200_000.00);
        $this->post($id, self::YEAR, '311', '602', 100_000.00);
        $this->post($id, self::YEAR, '343', '602', 30_000.00);
        $this->post($id, self::YEAR, '591', '341', 20_000.00);

        $currency = (int) $pdo->query('SELECT id FROM currencies ORDER BY id LIMIT 1')->fetchColumn();
        $country = (int) $pdo->query('SELECT id FROM countries ORDER BY id LIMIT 1')->fetchColumn();
        $pdo->prepare(
            'INSERT INTO clients (supplier_id, company_name, street, city, zip, country_id, currency_default_id, ic)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
        )->execute([$id, 'Odběratel Test s.r.o.', 'Testovací 1', 'Praha', '11000', $country, $currency, self::CLIENT_IC]);

        return $id;
    }

    /** Ručně vybudované nastavení firmy A. */
    private function buildSettings(int $id): void
    {
        $pdo = $this->db->pdo();
        $this->overrides->save($id, $this->versionId, [
            ['account_prefix' => '351.100', 'row_code' => 'C.II.1.5.4.', 'valid_from_year' => self::YEAR, 'note' => 'Splatnost prodloužena'],
        ], $this->userId);
        $pdo->prepare(
            'UPDATE accounting_supplier_settings
                SET comparative_from_prior_year = 1, tax_authority_offset = 1, tax_authority_offset_from_year = ?
              WHERE supplier_id = ?'
        )->execute([self::YEAR, $id]);
        $pdo->prepare('UPDATE supplier SET stock_enabled = 1, tax_investment_incentive = 1 WHERE id = ?')->execute([$id]);

        $this->dimensions->setEnabled($id, true);
        $type = $this->dimensions->createType($id, ['code' => 'lokalita', 'name' => 'Lokalita', 'kind' => 'location']);
        $north = $this->dimensions->createValue($id, (int) $type['id'], ['code' => 'SEVER', 'name' => 'Sever']);
        $this->dimensions->createValue($id, (int) $type['id'], ['code' => 'SEVER-1', 'name' => 'Sever první', 'parent_id' => $north['id'], 'note' => 'Pobočka']);
        $closed = $this->dimensions->createValue($id, (int) $type['id'], ['code' => 'JIH', 'name' => 'Jih']);
        $this->dimensions->updateValue($id, (int) $closed['id'], ['is_active' => false]);
        $client = $pdo->prepare('SELECT id FROM clients WHERE supplier_id = ? AND ic = ?');
        $client->execute([$id, self::CLIENT_IC]);
        $this->dimensions->saveEntityDefaults($id, 'client', (int) $client->fetchColumn(), [(int) $type['id'] => (int) $north['id']]);

        $pdo->prepare(
            "INSERT INTO posting_rules (supplier_id, rule_key, description, debit_account_code, credit_account_code, priority, is_active)
             VALUES (?, 'profile.test.rule', 'Testovací předkontace', '501', '321', 100, 0)"
        )->execute([$id]);
        $pdo->prepare(
            "INSERT INTO bank_posting_rules (supplier_id, name, direction, message_contains, debit_account_code, credit_account_code,
                                             mode, mode_set_manually_at, priority)
             VALUES (?, 'Poplatek za vedení účtu', 'outgoing', 'poplatek', '568', '221', 'auto', NOW(), 40)"
        )->execute([$id]);
    }

    /** Hodnoty řádků rozvahy (oba sloupce) a výsledovky běžného roku. */
    private function statementsFingerprint(int $supplierId): string
    {
        $period = $this->periodIds[$supplierId]['current'];
        $asOf = self::YEAR . '-12-31';
        $sheet = $this->statements->balanceSheet($supplierId, $period, $asOf, 'full');
        $income = $this->statements->incomeStatement($supplierId, $period, $asOf, 'full');
        $keep = array_flip(['row_code', 'gross', 'correction', 'net', 'prev_net', 'amount', 'prev_amount']);
        $rows = static fn (array $list): array => array_map(
            static fn (array $r): array => array_map(
                static fn (mixed $v): mixed => is_float($v) || is_int($v) ? round((float) $v, 2) : $v,
                array_intersect_key($r, $keep),
            ),
            $list,
        );

        return (string) json_encode([
            'assets' => $rows($sheet['assets']),
            'liabilities' => $rows($sheet['liabilities']),
            'income' => $rows($income['rows']),
        ]);
    }

    private function versionCode(): string
    {
        $stmt = $this->db->pdo()->prepare('SELECT version_code FROM statement_versions WHERE id = ?');
        $stmt->execute([$this->versionId]);

        return (string) $stmt->fetchColumn();
    }

    private function rowCount(string $table, int $supplierId): int
    {
        $stmt = $this->db->pdo()->prepare("SELECT COUNT(*) FROM {$table} WHERE supplier_id = ?");
        $stmt->execute([$supplierId]);

        return (int) $stmt->fetchColumn();
    }

    private function analytic(int $supplierId, string $parentCode, string $code, string $name, string $type, string $side): void
    {
        $pdo = $this->db->pdo();
        $parent = $pdo->prepare('SELECT id FROM chart_of_accounts WHERE supplier_id = ? AND account_code = ?');
        $parent->execute([$supplierId, $parentCode]);
        $pdo->prepare(
            'INSERT INTO chart_of_accounts (supplier_id, account_code, name, account_type, normal_side, is_synthetic, parent_id, is_active)
             VALUES (?, ?, ?, ?, ?, 0, ?, 1)'
        )->execute([$supplierId, $code, $name, $type, $side, (int) $parent->fetchColumn()]);
    }

    private function post(int $supplierId, int $year, string $debit, string $credit, float $amount): void
    {
        $this->posting->postDocument($supplierId, 'manual', null, [
            ['account_code' => $debit, 'side' => 'debit', 'amount' => $amount],
            ['account_code' => $credit, 'side' => 'credit', 'amount' => $amount],
        ], [
            'entry_date' => $year . '-06-30',
            'description' => 'Test profilu firmy ' . $debit . '/' . $credit,
            'posted' => true,
            'user_id' => $this->userId,
        ]);
    }
}
