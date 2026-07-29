<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Bank;

use MyInvoice\Action\Settings\SettingsAction;
use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Middleware\SupplierScopeMiddleware;
use MyInvoice\Repository\BankStatementOwnershipResolver;
use MyInvoice\Service\Export\ExportPeriod;
use MyInvoice\Service\Export\MonthlyExportService;
use MyInvoice\Service\Portfolio\PortfolioAggregationService;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response as Psr7Response;

/**
 * SEC-01, 2. kolo — resolver musí platit i mimo BankStatementAction.
 *
 * První kolo nasadilo {@see BankStatementOwnershipResolver} jen na část cest.
 * Adversariální review našlo, že syrové výpisy pořád tečou přes měsíční export
 * a že guard na „nárokování" cizího účtu jde obejít zakládáním nové firmy.
 * Tenhle test hlídá obě díry plus agregace, které leakovaly aspoň existenci
 * cizích řádků.
 *
 * Scénář: výpis (vč. GPC i PDF blobu) patří firmě B — má `bank_statements.supplier_id`.
 * Firma A má TÝŽ účet zapsaný v `currencies` (to je celý útok).
 */
#[Group('integration')]
final class BankStatementOwnershipSweepTest extends TestCase
{
    private const ACCOUNT   = '9990562288';
    private const BANK_CODE = '2010';
    private const FILE_NAME = '__TEST-SEC01B-vypis.gpc';
    private const GPC_BYTES = 'RAW GPC BYTES ROUND TWO';
    private const PDF_BYTES = '%PDF-1.4 sec01b';
    private const SUPPLIER_B_NAME = '__TEST SEC01B TENANT B';
    private const CURRENCY_LABEL = '__TEST SEC01B';

    /** Účet, který zatím nikdo nemá v currencies — pro test squattingu. */
    private const UNCLAIMED_ACCOUNT = '9990563399';

    private Connection $db;
    private ContainerInterface $container;
    private int $supplierA = 0;
    private int $supplierB = 0;
    private int $userId = 0;
    private int $statementId = 0;

    protected function setUp(): void
    {
        $rootDir = dirname(__DIR__, 4);
        if (!is_file($rootDir . '/cfg.php')) {
            $this->markTestSkipped('cfg.php neexistuje — test vyžaduje DB.');
        }
        try {
            $this->container = Bootstrap::buildApp()->getContainer();
            $this->db = $this->container->get(Connection::class);
        } catch (\Throwable $e) {
            $this->markTestSkipped('DI/DB nedostupné: ' . $e->getMessage());
        }

        $pdo = $this->db->pdo();
        $this->supplierA = (int) ($pdo->query('SELECT id FROM supplier ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        $this->userId    = (int) ($pdo->query('SELECT id FROM users ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        if ($this->supplierA === 0 || $this->userId === 0) {
            $this->markTestSkipped('Chybí supplier/uživatel v DB.');
        }

        $this->cleanup();
        $this->supplierB = $this->cloneSupplier();

        // Obě firmy mají v currencies stejné číslo účtu i kód banky — to je útok.
        $this->addCurrency($this->supplierA);
        $this->addCurrency($this->supplierB);

        $pdo->prepare(
            "INSERT INTO bank_statements
                (supplier_id, source, file_name, file_hash, file_content, pdf_content, pdf_name,
                 account_number, bank_code, currency, statement_number, statement_date,
                 prev_balance, curr_balance, transaction_count, imported_at, imported_by)
             VALUES (?, 'gpc', ?, ?, ?, ?, 'sec01b.pdf',
                     ?, ?, 'CZK', '1', '2099-06-30', 0, 1000, 1, '2099-07-01 10:00:00', NULL)"
        )->execute([
            $this->supplierB,
            self::FILE_NAME,
            hash('sha256', 'sec01b-' . uniqid('', true)),
            self::GPC_BYTES,
            self::PDF_BYTES,
            self::ACCOUNT,
            self::BANK_CODE,
        ]);
        $this->statementId = (int) $pdo->lastInsertId();

        $pdo->prepare(
            "INSERT INTO bank_transactions
                (statement_id, posted_at, amount, currency, match_status, source,
                 variable_symbol, counterparty_account, description, import_fingerprint)
             VALUES (?, '2099-06-15', 1000, 'CZK', 'unmatched', 'statement',
                     '2099000002', '1000000005', 'SEC-01B test', ?)"
        )->execute([$this->statementId, hash('sha256', 'sec01b-tx-' . uniqid('', true))]);
    }

    protected function tearDown(): void
    {
        if (!isset($this->db)) {
            return;
        }
        $this->cleanup();
        if ($this->supplierB > 0) {
            $this->db->pdo()->prepare('DELETE FROM supplier WHERE id = ?')->execute([$this->supplierB]);
        }
        $this->db->close();
    }

    // ── HIGH #1: měsíční export ────────────────────────────────────────────────

    /**
     * `/api/reports/monthly-export` je dostupný každému přihlášenému uživateli a
     * balil do ZIPu syrové GPC/PDF výpisy vybrané starým wildcard predikátem.
     */
    public function testMonthlyExportDoesNotLeakForeignStatements(): void
    {
        $export = $this->container->get(MonthlyExportService::class);
        $period = new ExportPeriod('monthly', 2099, 6, null, '2099-06-01', '2099-07-01', '06/2099');

        $rowsA = $this->invokePrivate($export, 'findStatements', [$this->supplierA, $period]);
        self::assertNotContains(
            $this->statementId,
            array_map('intval', array_column($rowsA, 'id')),
            'Měsíční export vydal cizí výpis.',
        );
        self::assertStringNotContainsString(
            self::GPC_BYTES,
            implode('', array_column($rowsA, 'file_content')),
            'Měsíční export vydal syrový GPC obsah cizí firmy.',
        );
        self::assertStringNotContainsString(
            self::PDF_BYTES,
            implode('', array_column($rowsA, 'pdf_content')),
            'Měsíční export vydal PDF výpis cizí firmy.',
        );

        // Ani počty nesmí prozradit, že cizí výpisy existují.
        [$pdfA, $gpcA] = $this->invokePrivate($export, 'countStatementFiles', [$this->supplierA, $period]);
        self::assertSame(0, $pdfA, 'countStatementFiles započítal cizí PDF.');
        self::assertSame(0, $gpcA, 'countStatementFiles započítal cizí GPC.');

        // Sanity: vlastník svůj výpis v exportu má — guard není „deny all".
        $rowsB = $this->invokePrivate($export, 'findStatements', [$this->supplierB, $period]);
        self::assertContains($this->statementId, array_map('intval', array_column($rowsB, 'id')));
        [$pdfB, $gpcB] = $this->invokePrivate($export, 'countStatementFiles', [$this->supplierB, $period]);
        self::assertSame(1, $pdfB);
        self::assertSame(1, $gpcB);
    }

    // ── HIGH #2: obejití guardu přes zakládání firmy ──────────────────────────

    /**
     * Guard byl jen v updateCurrency/createCurrency. `POST /api/suppliers` zapisuje
     * do `currencies` tytéž sloupce, takže stačilo místo PUT (409) založit novou
     * firmu s `bank_account` = účet oběti.
     */
    public function testCreateSupplierRejectsForeignBankAccount(): void
    {
        $settings = $this->container->get(SettingsAction::class);
        $before = $this->supplierCount();

        $request = $this->request($this->supplierA, 'POST', '/api/suppliers')->withParsedBody([
            'company_name' => '__TEST SEC01B ATTACKER',
            'street'       => 'Testovací 1',
            'city'         => 'Praha',
            'zip'          => '11000',
            'email'        => 'sec01b-attacker@example.test',
            'bank_account' => [
                'currency'       => 'CZK',
                'account_number' => self::ACCOUNT,
                'bank_code'      => self::BANK_CODE,
            ],
        ]);
        $response = $settings->createSupplier($request, new Psr7Response());
        $response->getBody()->rewind();
        $raw = $response->getBody()->getContents();

        self::assertSame(409, $response->getStatusCode(), 'createSupplier pustil cizí bankovní účet.');
        self::assertStringContainsString('account_claimed', $raw);
        self::assertSame($before, $this->supplierCount(), 'createSupplier i přes 409 firmu založil.');
    }

    /**
     * Tatáž kontrola, jakou volá {@see \MyInvoice\Action\Auth\SetupAction} —
     * ta zakládá supplier bez id, takže se musí porovnávat proti VŠEM firmám
     * (supplierId = 0 nesmí guard vypnout).
     */
    public function testClaimCheckWithoutSupplierIdComparesAgainstAllCompanies(): void
    {
        $resolver = $this->container->get(BankStatementOwnershipResolver::class);

        self::assertTrue(
            $resolver->accountClaimedByOtherSupplier(0, self::ACCOUNT),
            'Nová firma (bez id) si mohla nárokovat už evidovaný cizí účet.',
        );
        self::assertFalse(
            $resolver->accountClaimedByOtherSupplier(0, self::UNCLAIMED_ACCOUNT),
            'Guard blokuje i účet, který nikdo neeviduje.',
        );
    }

    // ── MEDIUM: squatting ─────────────────────────────────────────────────────

    /**
     * Účet, který zatím nikdo nemá v `currencies`, ale leží k němu výpis firmy B.
     * First-come-first-served by dovolil firmě A si ho zabrat a stát se podle
     * pravidla „jediný kandidát" vlastníkem cizí historie.
     */
    public function testAccountWithForeignStatementsCannotBeSquatted(): void
    {
        $pdo = $this->db->pdo();
        $pdo->prepare(
            "INSERT INTO bank_statements
                (supplier_id, source, file_name, file_hash, account_number, bank_code, currency,
                 statement_number, statement_date, prev_balance, curr_balance, transaction_count)
             VALUES (?, 'gpc', ?, ?, ?, ?, 'CZK', '1', '2099-06-30', 0, 500, 0)"
        )->execute([
            $this->supplierB,
            '__TEST-SEC01B-squat.gpc',
            hash('sha256', 'sec01b-squat-' . uniqid('', true)),
            self::UNCLAIMED_ACCOUNT,
            self::BANK_CODE,
        ]);

        $resolver = $this->container->get(BankStatementOwnershipResolver::class);

        // Nikdo ho nemá v currencies → claim check sám o sobě mlčí…
        self::assertFalse($resolver->accountClaimedByOtherSupplier($this->supplierA, self::UNCLAIMED_ACCOUNT));
        // …ale tie-break podle existujících výpisů firmu A zastaví.
        self::assertTrue(
            $resolver->accountBlockedByForeignStatements($this->supplierA, self::UNCLAIMED_ACCOUNT),
            'Firma A si zabrala účet, ke kterému leží výpisy firmy B.',
        );
        // Vlastník výpisů blokovaný není.
        self::assertFalse(
            $resolver->accountBlockedByForeignStatements($this->supplierB, self::UNCLAIMED_ACCOUNT),
            'Tie-break zablokoval i legitimního vlastníka.',
        );
    }

    /** Účet bez jakýchkoli výpisů v DB se musí dát normálně nastavit. */
    public function testFreshAccountIsNotBlocked(): void
    {
        $resolver = $this->container->get(BankStatementOwnershipResolver::class);
        self::assertFalse(
            $resolver->accountBlockedByForeignStatements($this->supplierA, '9990569999'),
            'Tie-break blokuje i účet, ke kterému žádná data nejsou.',
        );
    }

    // ── MEDIUM: agregace ──────────────────────────────────────────────────────

    /** Agregace prozrazovaly aspoň existenci cizích transakcí/výpisů. */
    public function testAggregationsDoNotCountForeignRows(): void
    {
        $portfolio = $this->container->get(PortfolioAggregationService::class);
        $now = new \DateTimeImmutable('2099-06-20');

        self::assertSame(
            0,
            $this->invokePrivate($portfolio, 'unmatchedBankCount', [$this->supplierA, $now]),
            'Portfolio započítalo nespárovanou transakci cizí firmy.',
        );
        self::assertSame(
            1,
            $this->invokePrivate($portfolio, 'unmatchedBankCount', [$this->supplierB, $now]),
            'Portfolio nezapočítalo vlastní transakci.',
        );

        self::assertNull(
            $this->invokePrivate($portfolio, 'lastBankImportAt', [$this->supplierA]),
            'lastBankImportAt prozradilo import cizího výpisu.',
        );
        self::assertNotNull(
            $this->invokePrivate($portfolio, 'lastBankImportAt', [$this->supplierB]),
        );
    }

    // ── helpers ───────────────────────────────────────────────────────────────

    /** @param list<mixed> $args */
    private function invokePrivate(object $target, string $method, array $args): mixed
    {
        // setAccessible() je od PHP 8.1 bez efektu a od 8.5 deprecated — reflexe
        // na privátní metodu funguje i bez něj.
        $ref = new \ReflectionMethod($target, $method);

        return $ref->invokeArgs($target, $args);
    }

    private function request(int $supplierId, string $method, string $path): \Psr\Http\Message\ServerRequestInterface
    {
        return (new ServerRequestFactory())
            ->createServerRequest($method, $path)
            ->withAttribute(SupplierScopeMiddleware::ATTR_CURRENT_ID, $supplierId)
            ->withAttribute(AuthMiddleware::ATTR_USER, ['id' => $this->userId, 'role' => 'admin']);
    }

    private function supplierCount(): int
    {
        return (int) $this->db->pdo()->query('SELECT COUNT(*) FROM supplier')->fetchColumn();
    }

    private function addCurrency(int $supplierId): void
    {
        $this->db->pdo()->prepare(
            "INSERT INTO currencies
                (supplier_id, code, label, symbol, name_cs, name_en, decimals, is_active, is_default,
                 account_number, bank_code)
             VALUES (?, 'CZK', ?, 'Kč', 'Koruna', 'Koruna', 2, 1, 0, ?, ?)"
        )->execute([$supplierId, self::CURRENCY_LABEL, self::ACCOUNT, self::BANK_CODE]);
    }

    private function cloneSupplier(): int
    {
        $clone = $this->db->pdo()->prepare(
            "INSERT INTO supplier
                (company_name,display_name,street,city,zip,country_id,is_vat_payer,email,
                 default_currency_id,default_vat_rate_id,default_payment_due_days,default_hourly_rate,accounting_mode)
             SELECT ?,?,street,city,zip,country_id,0,
                    CONCAT('sec01b-', id, '-', UNIX_TIMESTAMP(), '@example.test'),
                    default_currency_id,default_vat_rate_id,default_payment_due_days,default_hourly_rate,accounting_mode
               FROM supplier WHERE id=?"
        );
        $clone->execute([self::SUPPLIER_B_NAME, self::SUPPLIER_B_NAME, $this->supplierA]);
        $id = (int) $this->db->pdo()->lastInsertId();
        self::assertGreaterThan(0, $id);

        return $id;
    }

    private function cleanup(): void
    {
        $pdo = $this->db->pdo();
        // bank_transactions padají přes ON DELETE CASCADE za hlavičkou výpisu.
        $del = $pdo->prepare('DELETE FROM bank_statements WHERE account_number = ?');
        $del->execute([self::ACCOUNT]);
        $del->execute([self::UNCLAIMED_ACCOUNT]);
        $pdo->prepare('DELETE FROM currencies WHERE label = ?')->execute([self::CURRENCY_LABEL]);
        $pdo->prepare('DELETE FROM supplier WHERE company_name IN (?, ?)')
            ->execute([self::SUPPLIER_B_NAME, '__TEST SEC01B ATTACKER']);
    }
}
