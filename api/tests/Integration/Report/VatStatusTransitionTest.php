<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Report;

use MyInvoice\Action\Settings\VatStatusHistoryAction;
use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Middleware\SupplierScopeMiddleware;
use MyInvoice\Service\Accounting\Closing\ClosingService;
use MyInvoice\Service\Vat\VatStatusService;
use MyInvoice\Tests\Support\IsolatedSupplierTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response as Psr7Response;

/**
 * EPIC VH-07 — přechodové procesy při vzniku/zániku plátcovství DPH.
 *
 * (a)/(b) POST historie, který je PŘECHODEM (0→1 / 1→0) s účinností <= dnes,
 *         vrací hint suggest_s79 {kind, effective_on} na agendu § 79/§ 79a;
 * (c)     ne-přechod (stejný stav) ani budoucí přechod hint nevrací;
 * (d)     uzávěrkový check vat_status_s79_missing: přechod v roce bez korekce
 *         ř. 45 → warning (ok=false), s korekcí odpovídajícího kind → ok=true,
 *         firma jen s baseline řádkem → check vůbec nevznikne (null).
 *
 * Vzor VatStatusHistoryTest: action z DI kontejneru, transakce + rollback,
 * setUp maže duchová data recyklovaného TINYINT supplier ID (tabulky bez FK).
 */
#[Group('integration')]
final class VatStatusTransitionTest extends TestCase
{
    use IsolatedSupplierTrait;

    private Connection $db;
    private VatStatusHistoryAction $action;
    private ClosingService $closing;

    private int $userId = 0;
    private int $supplierId = 0;
    private bool $inTx = false;

    protected function setUp(): void
    {
        $rootDir = dirname(__DIR__, 4);
        if (!is_file($rootDir . '/cfg.php')) {
            $this->markTestSkipped('cfg.php neexistuje — test vyžaduje DB connection.');
        }
        try {
            $container     = Bootstrap::buildApp()->getContainer();
            $this->db      = $container->get(Connection::class);
            $this->action  = $container->get(VatStatusHistoryAction::class);
            $this->closing = $container->get(ClosingService::class);
        } catch (\Throwable $e) {
            $this->markTestSkipped('DI nedostupné: ' . $e->getMessage());
        }

        $pdo = $this->db->pdo();
        $sourceSupplier = (int) ($pdo->query('SELECT id FROM supplier ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        $this->userId   = (int) ($pdo->query('SELECT id FROM users ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        if ($sourceSupplier === 0 || $this->userId === 0) {
            $this->markTestSkipped('Chybí základní data (supplier/user) v DB.');
        }

        $pdo->beginTransaction();
        $this->inTx = true;

        $this->supplierId = $this->createIsolatedSupplier($pdo, $sourceSupplier);

        // Duchová data recyklovaného TINYINT ID (tabulky bez FK na supplier):
        // retro-guard by viděl cizí podání/zámky a uzávěrkový check cizí korekce.
        $pdo->prepare('DELETE FROM tax_submissions WHERE supplier_id = ?')->execute([$this->supplierId]);
        $pdo->prepare('DELETE FROM accounting_supplier_settings WHERE supplier_id = ?')->execute([$this->supplierId]);
        $pdo->prepare('DELETE FROM accounting_periods WHERE supplier_id = ?')->execute([$this->supplierId]);
        $pdo->prepare('DELETE FROM vat_registration_corrections WHERE supplier_id = ?')->execute([$this->supplierId]);
        $pdo->prepare('DELETE FROM supplier_vat_status_history WHERE supplier_id = ?')->execute([$this->supplierId]);
        $pdo->prepare(
            "UPDATE supplier SET is_vat_payer = 1, is_identified = 0, flat_tax_band = 'none',
                    accounting_mode = 'tax_evidence' WHERE id = ?"
        )->execute([$this->supplierId]);
        VatStatusService::seedInitialStatus($pdo, $this->supplierId, true);
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

    // ── suggest_s79 hint v POST /settings/vat-status-history ─────────────────

    public function testRegistrationTransitionReturnsSuggestS79(): void
    {
        $this->resetBaseline(false);
        $effective = (new \DateTimeImmutable('-30 days'))->format('Y-m-d');

        $r = $this->save(['effective_from' => $effective, 'is_vat_payer' => true, 'is_identified' => false]);
        self::assertSame(200, $r['status']);
        self::assertArrayHasKey('suggest_s79', $r['body']);
        self::assertSame('registration', $r['body']['suggest_s79']['kind']);
        self::assertSame($effective, $r['body']['suggest_s79']['effective_on']);
    }

    public function testDeregistrationTransitionReturnsSuggestS79(): void
    {
        // Baseline plátce (setUp) → 1→0 = zrušení registrace.
        $effective = (new \DateTimeImmutable('-30 days'))->format('Y-m-d');

        $r = $this->save(['effective_from' => $effective, 'is_vat_payer' => false, 'is_identified' => false]);
        self::assertSame(200, $r['status']);
        self::assertArrayHasKey('suggest_s79', $r['body']);
        self::assertSame('deregistration', $r['body']['suggest_s79']['kind']);
        self::assertSame($effective, $r['body']['suggest_s79']['effective_on']);
    }

    public function testNonTransitionReturnsNoHint(): void
    {
        // Plátce → plátce (jen poznámka/duplicitní stav) není přechod.
        $effective = (new \DateTimeImmutable('-30 days'))->format('Y-m-d');

        $r = $this->save(['effective_from' => $effective, 'is_vat_payer' => true, 'is_identified' => false, 'note' => 'beze změny stavu']);
        self::assertSame(200, $r['status']);
        self::assertArrayNotHasKey('suggest_s79', $r['body']);
    }

    public function testFutureTransitionReturnsNoHint(): void
    {
        // Přechod s účinností v budoucnu — korekce § 79/§ 79a se řeší až v období
        // účinnosti, hint by byl předčasný.
        $future = (new \DateTimeImmutable('+10 days'))->format('Y-m-d');

        $r = $this->save(['effective_from' => $future, 'is_vat_payer' => false, 'is_identified' => false]);
        self::assertSame(200, $r['status']);
        self::assertArrayNotHasKey('suggest_s79', $r['body']);
    }

    // ── uzávěrkový check vat_status_s79_missing ──────────────────────────────

    public function testClosingCheckWarnsOnTransitionWithoutCorrection(): void
    {
        $pdo = $this->db->pdo();
        $this->resetBaseline(false);
        $year = (int) date('Y');
        $effective = sprintf('%04d-03-01', $year);
        $this->setVatPayerAt($pdo, $this->supplierId, $effective, true);

        $check = $this->closing->checkVatStatusS79Missing($this->supplierId, sprintf('%04d-01-01', $year), sprintf('%04d-12-31', $year));

        self::assertNotNull($check);
        self::assertSame('vat_status_s79_missing', $check['key']);
        self::assertSame('warning', $check['severity']);
        self::assertFalse($check['ok']);
        self::assertSame('registration', $check['value']['missing'][0]['kind']);
        self::assertSame($effective, $check['value']['missing'][0]['effective_on']);
    }

    public function testClosingCheckOkWhenCorrectionExists(): void
    {
        $pdo = $this->db->pdo();
        $this->resetBaseline(false);
        $year = (int) date('Y');
        $effective = sprintf('%04d-03-01', $year);
        $this->setVatPayerAt($pdo, $this->supplierId, $effective, true);

        $pdo->prepare(
            "INSERT INTO vat_registration_corrections
                (supplier_id, kind, label, acquired_on, effective_on, asset_kind, vat_amount)
             VALUES (?, 'registration', 'Zásoby na skladě ke dni registrace', ?, ?, 'inventory', 2100)"
        )->execute([$this->supplierId, sprintf('%04d-01-15', $year), $effective]);

        $check = $this->closing->checkVatStatusS79Missing($this->supplierId, sprintf('%04d-01-01', $year), sprintf('%04d-12-31', $year));

        self::assertNotNull($check);
        self::assertTrue($check['ok']);
        self::assertSame([], $check['value']['missing']);
        self::assertCount(1, $check['value']['transitions']);
    }

    public function testClosingCheckSilentForBaselineOnlyFirm(): void
    {
        // Jen baseline řádek (1900-01-01) = žádný přechod → check vůbec nevznikne.
        $year = (int) date('Y');

        $check = $this->closing->checkVatStatusS79Missing($this->supplierId, sprintf('%04d-01-01', $year), sprintf('%04d-12-31', $year));

        self::assertNull($check);
    }

    // ── helpers ──────────────────────────────────────────────────────────────

    /** Přenastaví firmu na čistý baseline stav (plátce/neplátce od 1900-01-01). */
    private function resetBaseline(bool $isVatPayer): void
    {
        $pdo = $this->db->pdo();
        $pdo->prepare('DELETE FROM supplier_vat_status_history WHERE supplier_id = ?')->execute([$this->supplierId]);
        $pdo->prepare('UPDATE supplier SET is_vat_payer = ?, is_identified = 0 WHERE id = ?')
            ->execute([$isVatPayer ? 1 : 0, $this->supplierId]);
        VatStatusService::seedInitialStatus($pdo, $this->supplierId, $isVatPayer);
    }

    /**
     * @param array<string,mixed> $body
     * @return array{status:int, body:array<string,mixed>}
     */
    private function save(array $body): array
    {
        $req = (new ServerRequestFactory())
            ->createServerRequest('POST', '/api/settings/vat-status-history')
            ->withAttribute(SupplierScopeMiddleware::ATTR_CURRENT_ID, $this->supplierId)
            ->withAttribute(AuthMiddleware::ATTR_USER, ['id' => $this->userId, 'role' => 'admin'])
            ->withParsedBody($body);
        $resp = $this->action->save($req, new Psr7Response());
        $resp->getBody()->rewind();
        $decoded = json_decode((string) $resp->getBody(), true);
        return ['status' => $resp->getStatusCode(), 'body' => is_array($decoded) ? $decoded : []];
    }
}
