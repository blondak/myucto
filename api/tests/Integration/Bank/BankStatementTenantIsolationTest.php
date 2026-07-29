<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Bank;

use MyInvoice\Action\Bank\BankStatementAction;
use MyInvoice\Action\Settings\SettingsAction;
use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Middleware\SupplierScopeMiddleware;
use MyInvoice\Repository\BankStatementOwnershipResolver;
use PDO;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Http\Message\ResponseInterface;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response as Psr7Response;

/**
 * SEC-01 — regresní test tenantové izolace bankovních výpisů.
 *
 * Scénář z auditu: firma A si do `currencies.account_number` zapíše (z faktur
 * veřejně známé) číslo účtu firmy B. Výpis patří firmě B
 * (`bank_statements.supplier_id`). Firma A nesmí u list / detail / GPC download /
 * PDF download ani u ŽÁDNÉ mutace dostat data a nesmí změnit jediný řádek.
 */
#[Group('integration')]
final class BankStatementTenantIsolationTest extends TestCase
{
    /** Sdílené číslo účtu — právě na něm útok stál. */
    private const ACCOUNT = '9990561177';
    private const BANK_CODE = '2010';
    private const FILE_NAME = '__TEST-SEC01-vypis.gpc';

    private Connection $db;
    private ContainerInterface $container;
    private int $supplierA = 0;
    private int $supplierB = 0;
    private int $userId = 0;
    private int $statementId = 0;
    private int $transactionId = 0;

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
        $this->userId = (int) ($pdo->query('SELECT id FROM users ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        if ($this->supplierA === 0 || $this->userId === 0) {
            $this->markTestSkipped('Chybí supplier/uživatel v DB.');
        }

        $this->cleanup();
        $this->supplierB = $this->cloneSupplier();

        // Obě firmy mají v currencies ZÁMĚRNĚ stejné číslo účtu i kód banky —
        // starý guard by na tomhle vlastnictví „uhodl" a pustil firmu A dovnitř.
        $this->addCurrency($this->supplierA);
        $this->addCurrency($this->supplierB);

        // Výpis s autoritativním supplier_id firmy B + jedna transakce.
        $pdo->prepare(
            "INSERT INTO bank_statements
                (supplier_id, source, file_name, file_hash, file_content, pdf_content, pdf_name,
                 account_number, bank_code, currency, statement_number, statement_date,
                 prev_balance, curr_balance, transaction_count, imported_by)
             VALUES (?, 'gpc', ?, ?, 'RAW GPC BYTES', '%PDF-1.4 test', 'sec01.pdf',
                     ?, ?, 'CZK', '1', '2099-03-31', 0, 1000, 1, NULL)"
        )->execute([
            $this->supplierB,
            self::FILE_NAME,
            hash('sha256', 'sec01-' . uniqid('', true)),
            self::ACCOUNT,
            self::BANK_CODE,
        ]);
        $this->statementId = (int) $pdo->lastInsertId();

        $pdo->prepare(
            "INSERT INTO bank_transactions
                (statement_id, posted_at, amount, currency, variable_symbol, counterparty_account,
                 description, import_fingerprint)
             VALUES (?, '2099-03-15', 1000, 'CZK', '2099000001', '1000000005', 'SEC-01 test', ?)"
        )->execute([$this->statementId, hash('sha256', 'sec01-tx-' . uniqid('', true))]);
        $this->transactionId = (int) $pdo->lastInsertId();
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

    public function testForeignStatementIsInvisibleAndImmutableForOtherTenant(): void
    {
        $action = $this->container->get(BankStatementAction::class);
        $before = $this->statementSnapshot();

        // List — cizí výpis nesmí být mezi položkami ani v nabídce účtů.
        $list = $this->call(fn (): ResponseInterface => $action->list(
            $this->request($this->supplierA, 'GET', '/api/bank-statements'),
            new Psr7Response(),
        ));
        self::assertSame(200, $list['status']);
        self::assertNotContains($this->statementId, array_column($list['body']['items'], 'id'));

        // Detail / GPC download / PDF download — 404, nikoli obsah.
        foreach (['detail', 'download', 'downloadPdf'] as $method) {
            $res = $this->call(fn (): ResponseInterface => $action->{$method}(
                $this->request($this->supplierA, 'GET', '/api/bank-statements/' . $this->statementId),
                new Psr7Response(),
                ['id' => (string) $this->statementId],
            ));
            self::assertSame(404, $res['status'], $method . ' pustil cizí výpis');
            self::assertStringNotContainsString('RAW GPC BYTES', $res['raw'], $method . ' vypsal cizí obsah');
        }

        // Mutace — každá musí skončit 404 a nesmí sáhnout na data.
        $mutations = [
            'deletePdf' => fn (): ResponseInterface => $action->deletePdf(
                $this->request($this->supplierA, 'DELETE', '/api/bank-statements/' . $this->statementId . '/pdf'),
                new Psr7Response(),
                ['id' => (string) $this->statementId],
            ),
            'rematch' => fn (): ResponseInterface => $action->rematch(
                $this->request($this->supplierA, 'POST', '/api/bank-statements/' . $this->statementId . '/rematch'),
                new Psr7Response(),
                ['id' => (string) $this->statementId],
            ),
            'delete' => fn (): ResponseInterface => $action->delete(
                $this->request($this->supplierA, 'DELETE', '/api/bank-statements/' . $this->statementId),
                new Psr7Response(),
                ['id' => (string) $this->statementId],
            ),
            'ignoreTransaction' => fn (): ResponseInterface => $action->ignore(
                $this->request($this->supplierA, 'POST', '/api/bank-transactions/' . $this->transactionId . '/ignore'),
                new Psr7Response(),
                ['id' => (string) $this->transactionId],
            ),
            'unmatchTransaction' => fn (): ResponseInterface => $action->unmatch(
                $this->request($this->supplierA, 'POST', '/api/bank-transactions/' . $this->transactionId . '/unmatch'),
                new Psr7Response(),
                ['id' => (string) $this->transactionId],
            ),
        ];
        foreach ($mutations as $name => $call) {
            $res = $this->call($call);
            self::assertSame(404, $res['status'], $name . ' pustil mutaci cizího výpisu');
        }

        self::assertSame($before, $this->statementSnapshot(), 'Cizí výpis se změnil.');
        self::assertSame(
            1,
            (int) $this->db->pdo()->query(
                'SELECT COUNT(*) FROM bank_transactions WHERE statement_id = ' . $this->statementId
            )->fetchColumn(),
            'Transakce cizího výpisu zmizely.',
        );

        // Sanity: vlastník (firma B) svůj výpis vidí — guard není „deny all".
        $ownDetail = $this->call(fn (): ResponseInterface => $action->detail(
            $this->request($this->supplierB, 'GET', '/api/bank-statements/' . $this->statementId),
            new Psr7Response(),
            ['id' => (string) $this->statementId],
        ));
        self::assertSame(200, $ownDetail['status']);
    }

    /**
     * Legacy řádek bez `supplier_id`: účet mají zaregistrovaný obě firmy, vlastník
     * je tedy nejednoznačný → fail-closed, nevidí ho ANI JEDNA (migrace 1136 takový
     * řádek záměrně nechává NULL k ručnímu dořešení).
     */
    public function testAmbiguousLegacyStatementIsDeniedToBothTenants(): void
    {
        $this->db->pdo()->prepare('UPDATE bank_statements SET supplier_id = NULL WHERE id = ?')
            ->execute([$this->statementId]);

        $resolver = $this->container->get(BankStatementOwnershipResolver::class);
        self::assertFalse($resolver->statementOwned($this->statementId, $this->supplierA));
        self::assertFalse($resolver->statementOwned($this->statementId, $this->supplierB));
        self::assertFalse($resolver->transactionOwned($this->transactionId, $this->supplierA));
        self::assertNotContains($this->statementId, $resolver->ownedStatementIds($this->supplierA));

        // Jakmile je vlastník jednoznačný (druhá firma účet nemá), legacy řádek se zpřístupní.
        $this->db->pdo()->prepare('DELETE FROM currencies WHERE supplier_id = ? AND account_number = ?')
            ->execute([$this->supplierA, self::ACCOUNT]);
        self::assertTrue($resolver->statementOwned($this->statementId, $this->supplierB));
        self::assertFalse($resolver->statementOwned($this->statementId, $this->supplierA));
    }

    /** Jednostranně prázdný kód banky už nefunguje jako wildcard. */
    public function testMissingBankCodeIsNoLongerWildcard(): void
    {
        $pdo = $this->db->pdo();
        $pdo->prepare('UPDATE bank_statements SET supplier_id = NULL, bank_code = NULL WHERE id = ?')
            ->execute([$this->statementId]);
        $pdo->prepare('DELETE FROM currencies WHERE supplier_id = ? AND account_number = ?')
            ->execute([$this->supplierA, self::ACCOUNT]);

        $resolver = $this->container->get(BankStatementOwnershipResolver::class);
        self::assertFalse(
            $resolver->statementOwned($this->statementId, $this->supplierB),
            'Výpis bez kódu banky se spároval s účtem, který kód banky má.',
        );
    }

    /** Nastavení cizího čísla účtu do vlastní měny musí skončit 409, ne uložením. */
    public function testClaimingForeignBankAccountIsRejected(): void
    {
        $settings = $this->container->get(SettingsAction::class);
        $currencyId = (int) $this->db->pdo()->query(
            'SELECT id FROM currencies WHERE supplier_id = ' . $this->supplierA
            . " AND account_number = '" . self::ACCOUNT . "' LIMIT 1"
        )->fetchColumn();
        self::assertGreaterThan(0, $currencyId);

        $request = $this->request($this->supplierA, 'PUT', '/api/settings/currencies/' . $currencyId)
            ->withParsedBody(['account_number' => self::ACCOUNT, 'bank_code' => self::BANK_CODE]);
        $response = $settings->updateCurrency($request, new Psr7Response(), ['id' => (string) $currencyId]);
        $response->getBody()->rewind();

        self::assertSame(409, $response->getStatusCode());
        self::assertStringContainsString('account_claimed', $response->getBody()->getContents());
    }

    private function request(int $supplierId, string $method, string $path): \Psr\Http\Message\ServerRequestInterface
    {
        return (new ServerRequestFactory())
            ->createServerRequest($method, $path)
            ->withAttribute(SupplierScopeMiddleware::ATTR_CURRENT_ID, $supplierId)
            ->withAttribute(AuthMiddleware::ATTR_USER, ['id' => $this->userId, 'role' => 'admin']);
    }

    /**
     * @param callable():ResponseInterface $call
     * @return array{status:int, raw:string, body:array<string,mixed>}
     */
    private function call(callable $call): array
    {
        $response = $call();
        $response->getBody()->rewind();
        $raw = $response->getBody()->getContents();

        return [
            'status' => $response->getStatusCode(),
            'raw'    => $raw,
            'body'   => (array) (json_decode($raw, true) ?: []),
        ];
    }

    /** @return array<string,mixed> */
    private function statementSnapshot(): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT supplier_id, file_name, account_number, bank_code, pdf_name, matched_count,
                    MD5(IFNULL(file_content, "")) AS file_md5, MD5(IFNULL(pdf_content, "")) AS pdf_md5
               FROM bank_statements WHERE id = ?'
        );
        $stmt->execute([$this->statementId]);

        return (array) ($stmt->fetch(PDO::FETCH_ASSOC) ?: []);
    }

    private function addCurrency(int $supplierId): void
    {
        $this->db->pdo()->prepare(
            "INSERT INTO currencies
                (supplier_id, code, label, symbol, name_cs, name_en, decimals, is_active, is_default,
                 account_number, bank_code)
             VALUES (?, 'CZK', '__TEST SEC01', 'Kč', 'Koruna', 'Koruna', 2, 1, 0, ?, ?)"
        )->execute([$supplierId, self::ACCOUNT, self::BANK_CODE]);
    }

    private function cloneSupplier(): int
    {
        $clone = $this->db->pdo()->prepare(
            "INSERT INTO supplier
                (company_name,display_name,street,city,zip,country_id,is_vat_payer,email,
                 default_currency_id,default_vat_rate_id,default_payment_due_days,default_hourly_rate,accounting_mode)
             SELECT '__TEST SEC01 TENANT B','__TEST SEC01 TENANT B',street,city,zip,country_id,0,
                    CONCAT('sec01-', id, '-', UNIX_TIMESTAMP(), '@example.test'),
                    default_currency_id,default_vat_rate_id,default_payment_due_days,default_hourly_rate,accounting_mode
               FROM supplier WHERE id=?"
        );
        $clone->execute([$this->supplierA]);
        $id = (int) $this->db->pdo()->lastInsertId();
        self::assertGreaterThan(0, $id);

        return $id;
    }

    private function cleanup(): void
    {
        $pdo = $this->db->pdo();
        // bank_transactions padají přes ON DELETE CASCADE za hlavičkou výpisu.
        $pdo->prepare('DELETE FROM bank_statements WHERE account_number = ?')->execute([self::ACCOUNT]);
        $pdo->prepare("DELETE FROM currencies WHERE label = '__TEST SEC01' AND account_number = ?")
            ->execute([self::ACCOUNT]);
        $pdo->exec("DELETE FROM supplier WHERE company_name = '__TEST SEC01 TENANT B'");
    }
}
