<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Settings;

use MyInvoice\Action\Settings\VatStatusHistoryAction;
use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Middleware\SupplierScopeMiddleware;
use MyInvoice\Service\Vat\VatStatusService;
use MyInvoice\Tests\Support\IsolatedSupplierTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response as Psr7Response;

/**
 * Integrační testy správy historie plátcovství DPH (EPIC VH-01).
 *
 * Action volaná přímo z DI kontejneru s ATTR_USER (role admin = superadmin
 * bypass) + ATTR_CURRENT_ID; jedna transakce, rollback v tearDown.
 *
 * Pokrývá: CRUD + upsert po effective_from, validace plátce×IO a paušalisty,
 * retro-guard 409 (podané přiznání / uzamčené období / zámek k datu) +
 * acknowledge flow, přepočet živé cache, budoucí účinnost (cache nemění,
 * aplikuje ji až cron krok applyDueStatuses), ochranu baseline/posledního
 * řádku a tenant scope.
 */
#[Group('integration')]
final class VatStatusHistoryTest extends TestCase
{
    use IsolatedSupplierTrait;

    private Connection $db;
    private VatStatusHistoryAction $action;

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
            $container    = Bootstrap::buildApp()->getContainer();
            $this->db     = $container->get(Connection::class);
            $this->action = $container->get(VatStatusHistoryAction::class);
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

        // Deterministický výchozí stav: plátce od 1900-01-01, žádné IO, žádný
        // paušál, daňová evidence, žádné zámky ani podaná přiznání. Recyklované
        // TINYINT ID může zdědit duchová data po dřívějších bězích (tabulky bez
        // FK na supplier) — retro-guard by pak viděl cizí podání/zámky.
        $pdo->prepare('DELETE FROM tax_submissions WHERE supplier_id = ?')->execute([$this->supplierId]);
        $pdo->prepare('DELETE FROM accounting_supplier_settings WHERE supplier_id = ?')->execute([$this->supplierId]);
        $pdo->prepare('DELETE FROM accounting_periods WHERE supplier_id = ?')->execute([$this->supplierId]);
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

    // ── CRUD + přepočet cache ────────────────────────────────────────────────

    public function testSaveAddsRowAndRecomputesCache(): void
    {
        $r = $this->save(['effective_from' => '2026-01-01', 'is_vat_payer' => false, 'is_identified' => false, 'note' => 'zrušení registrace']);
        self::assertSame(200, $r['status']);

        $rows = $r['body']['vat_status_history'];
        self::assertCount(2, $rows);
        $added = $rows[1];
        self::assertSame('2026-01-01', $added['effective_from']);
        self::assertFalse($added['is_vat_payer']);
        self::assertFalse($added['is_identified']);
        self::assertSame('zrušení registrace', $added['note']);

        // Řádek s účinností <= dnes → živá cache se hned přepočítá.
        self::assertFalse($r['body']['is_vat_payer']);
        self::assertSame([0, 0], $this->liveFlags());
    }

    public function testSaveUpsertsExistingDate(): void
    {
        $this->save(['effective_from' => '2026-01-01', 'is_vat_payer' => false, 'is_identified' => false]);
        $r = $this->save(['effective_from' => '2026-01-01', 'is_vat_payer' => false, 'is_identified' => true, 'note' => 'registrace IO']);
        self::assertSame(200, $r['status']);
        self::assertCount(2, $r['body']['vat_status_history'], 'Stejné datum = upsert, ne nový řádek.');
        self::assertTrue($r['body']['vat_status_history'][1]['is_identified']);
        self::assertSame('registrace IO', $r['body']['vat_status_history'][1]['note']);
        self::assertSame([0, 1], $this->liveFlags());
    }

    public function testDeleteRowRecomputesCacheBack(): void
    {
        $add = $this->save(['effective_from' => '2026-01-01', 'is_vat_payer' => false, 'is_identified' => false]);
        self::assertSame([0, 0], $this->liveFlags());

        $id = (int) $add['body']['vat_status_history'][1]['id'];
        $r = $this->delete($id);
        self::assertSame(200, $r['status']);
        self::assertCount(1, $r['body']['vat_status_history']);
        // Smazáním se stav vrací k baseline (plátce).
        self::assertSame([1, 0], $this->liveFlags());
    }

    // ── Validace ─────────────────────────────────────────────────────────────

    public function testPayerCombinedWithIdentifiedRejected(): void
    {
        $r = $this->save(['effective_from' => '2026-01-01', 'is_vat_payer' => true, 'is_identified' => true]);
        self::assertSame(422, $r['status']);
        self::assertSame('validation_failed', $r['body']['error']['code']);
    }

    public function testInvalidDateRejected(): void
    {
        $r = $this->save(['effective_from' => '2026-13-45', 'is_vat_payer' => true, 'is_identified' => false]);
        self::assertSame(422, $r['status']);
    }

    public function testFlatTaxSupplierCannotBecomePayer(): void
    {
        $this->db->pdo()->prepare("UPDATE supplier SET flat_tax_band = 'band1', is_vat_payer = 0 WHERE id = ?")
            ->execute([$this->supplierId]);
        $r = $this->save(['effective_from' => '2026-01-01', 'is_vat_payer' => true, 'is_identified' => false]);
        self::assertSame(422, $r['status']);
        self::assertSame('validation_failed', $r['body']['error']['code']);
    }

    // ── Retro-guard 409 + acknowledge ────────────────────────────────────────

    public function testRetroGuardSubmittedReturnConflictAndAcknowledge(): void
    {
        $this->insertSubmittedReturn(2026, 3);

        $payload = ['effective_from' => '2026-02-15', 'is_vat_payer' => false, 'is_identified' => false];
        $r = $this->save($payload);
        self::assertSame(409, $r['status']);
        self::assertSame('vat_status_locked_conflict', $r['body']['error']['code']);
        $types = array_column($r['body']['error']['collisions'], 'type');
        self::assertContains('tax_submission', $types);

        // Bez acknowledge se nic nezapsalo.
        self::assertSame(1, $this->historyCount());

        $ok = $this->save($payload + ['acknowledge' => true]);
        self::assertSame(200, $ok['status']);
        self::assertSame(2, $this->historyCount());
    }

    public function testRetroGuardIgnoresReturnsBeforeEffectiveDate(): void
    {
        // Podané přiznání za období končící PŘED účinností nekoliduje.
        $this->insertSubmittedReturn(2026, 3);
        $r = $this->save(['effective_from' => '2026-04-01', 'is_vat_payer' => false, 'is_identified' => false]);
        self::assertSame(200, $r['status']);
    }

    public function testRetroGuardClosedPeriodConflict(): void
    {
        $pdo = $this->db->pdo();
        $pdo->prepare("UPDATE supplier SET accounting_mode = 'double_entry' WHERE id = ?")->execute([$this->supplierId]);
        $pdo->prepare(
            "INSERT INTO accounting_periods (supplier_id, fiscal_year, starts_on, ends_on, status)
             VALUES (?, 2025, '2025-01-01', '2025-12-31', 'closed')"
        )->execute([$this->supplierId]);

        $r = $this->save(['effective_from' => '2025-06-15', 'is_vat_payer' => false, 'is_identified' => false]);
        self::assertSame(409, $r['status']);
        $types = array_column($r['body']['error']['collisions'], 'type');
        self::assertContains('locked_period', $types);
    }

    public function testRetroGuardDateLockConflict(): void
    {
        $this->db->pdo()->prepare(
            "INSERT INTO accounting_supplier_settings (supplier_id, locked_until) VALUES (?, '2026-06-30')
             ON DUPLICATE KEY UPDATE locked_until = VALUES(locked_until)"
        )->execute([$this->supplierId]);

        $r = $this->save(['effective_from' => '2026-05-01', 'is_vat_payer' => false, 'is_identified' => false]);
        self::assertSame(409, $r['status']);
        $types = array_column($r['body']['error']['collisions'], 'type');
        self::assertContains('date_lock', $types);
    }

    public function testDeleteRetroactiveRowRequiresAcknowledgeToo(): void
    {
        $add = $this->save(['effective_from' => '2026-02-15', 'is_vat_payer' => false, 'is_identified' => false]);
        $id = (int) $add['body']['vat_status_history'][1]['id'];

        $this->insertSubmittedReturn(2026, 3);
        $r = $this->delete($id);
        self::assertSame(409, $r['status']);
        self::assertSame('vat_status_locked_conflict', $r['body']['error']['code']);
        self::assertSame(2, $this->historyCount(), 'Bez acknowledge se nesmazalo.');

        $ok = $this->delete($id, ['acknowledge' => true]);
        self::assertSame(200, $ok['status']);
        self::assertSame(1, $this->historyCount());
    }

    // ── Budoucí účinnost ─────────────────────────────────────────────────────

    public function testFutureChangeDoesNotTouchLiveCache(): void
    {
        $future = (new \DateTimeImmutable('+10 days'))->format('Y-m-d');
        $r = $this->save(['effective_from' => $future, 'is_vat_payer' => false, 'is_identified' => false]);
        self::assertSame(200, $r['status']);
        self::assertCount(2, $r['body']['vat_status_history']);
        // Budoucí řádek živou cache nemění — pořád plátce podle baseline.
        self::assertTrue($r['body']['is_vat_payer']);
        self::assertSame([1, 0], $this->liveFlags());

        // Ani cron krok ji před dnem účinnosti nepropíše (idempotence set-based UPDATE).
        VatStatusService::applyDueStatuses($this->db->pdo());
        self::assertSame([1, 0], $this->liveFlags());
    }

    public function testApplyDueStatusesFixesStaleCacheAndIsIdempotent(): void
    {
        $pdo = $this->db->pdo();
        // Řádek s účinností v minulosti + záměrně rozbitá živá cache.
        $pdo->prepare(
            'INSERT INTO supplier_vat_status_history (supplier_id, effective_from, is_vat_payer, is_identified)
             VALUES (?, ?, 0, 1)'
        )->execute([$this->supplierId, '2026-01-01']);
        $pdo->prepare('UPDATE supplier SET is_vat_payer = 1, is_identified = 0 WHERE id = ?')->execute([$this->supplierId]);

        VatStatusService::applyDueStatuses($pdo);
        self::assertSame([0, 1], $this->liveFlags());

        self::assertSame(0, VatStatusService::applyDueStatuses($pdo), 'Druhý běh nemá co měnit.');
        self::assertSame([0, 1], $this->liveFlags());
    }

    // ── Ochrana baseline / posledního řádku + tenant scope ───────────────────

    public function testBaselineRowCannotBeDeleted(): void
    {
        $baselineId = (int) $this->db->pdo()
            ->query("SELECT id FROM supplier_vat_status_history WHERE supplier_id = {$this->supplierId} AND effective_from = '1900-01-01'")
            ->fetchColumn();
        $r = $this->delete($baselineId);
        self::assertSame(409, $r['status']);
        self::assertSame('vat_status_baseline_protected', $r['body']['error']['code']);
        self::assertSame(1, $this->historyCount());
    }

    public function testLastRowCannotBeDeleted(): void
    {
        // Firma bez baseline, jen jeden běžný řádek — poslední řádek nesmí zmizet.
        $pdo = $this->db->pdo();
        $pdo->prepare('DELETE FROM supplier_vat_status_history WHERE supplier_id = ?')->execute([$this->supplierId]);
        $pdo->prepare(
            'INSERT INTO supplier_vat_status_history (supplier_id, effective_from, is_vat_payer) VALUES (?, ?, 1)'
        )->execute([$this->supplierId, '2026-01-01']);
        $id = (int) $pdo->lastInsertId();

        $r = $this->delete($id);
        self::assertSame(409, $r['status']);
        self::assertSame('vat_status_last_row', $r['body']['error']['code']);
        self::assertSame(1, $this->historyCount());
    }

    public function testDeleteIsTenantScoped(): void
    {
        $add = $this->save(['effective_from' => '2026-01-01', 'is_vat_payer' => false, 'is_identified' => false]);
        $id = (int) $add['body']['vat_status_history'][1]['id'];

        $r = $this->delete($id, [], $this->supplierId + 9999);
        self::assertSame(404, $r['status']);
        self::assertSame(2, $this->historyCount());
    }

    public function testNonAdminForbidden(): void
    {
        $r = $this->call('save', 'POST', [
            'body' => ['effective_from' => '2026-01-01', 'is_vat_payer' => false, 'is_identified' => false],
            'role' => 'accountant',
        ]);
        self::assertSame(403, $r['status']);
    }

    // ── helpers ──────────────────────────────────────────────────────────────

    /** @return array{0:int,1:int} [is_vat_payer, is_identified] živé cache */
    private function liveFlags(): array
    {
        $stmt = $this->db->pdo()->prepare('SELECT is_vat_payer, is_identified FROM supplier WHERE id = ?');
        $stmt->execute([$this->supplierId]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC) ?: [];
        return [(int) ($row['is_vat_payer'] ?? -1), (int) ($row['is_identified'] ?? -1)];
    }

    private function historyCount(): int
    {
        $stmt = $this->db->pdo()->prepare('SELECT COUNT(*) FROM supplier_vat_status_history WHERE supplier_id = ?');
        $stmt->execute([$this->supplierId]);
        return (int) $stmt->fetchColumn();
    }

    private function insertSubmittedReturn(int $year, int $month): void
    {
        $this->db->pdo()->prepare(
            "INSERT INTO tax_submissions
                (supplier_id, form_code, period_year, period_month, xml_content, xml_size_bytes, xml_sha256, status, submitted_at)
             VALUES (?, 'dphdp3', ?, ?, '<x/>', 4, ?, 'submitted', NOW())"
        )->execute([$this->supplierId, $year, $month, hash('sha256', 'x')]);
    }

    /**
     * @param array<string,mixed> $body
     * @return array{status:int, body:array<string,mixed>}
     */
    private function save(array $body): array
    {
        return $this->call('save', 'POST', ['body' => $body]);
    }

    /**
     * @param array<string,mixed> $body
     * @return array{status:int, body:array<string,mixed>}
     */
    private function delete(int $id, array $body = [], ?int $supplierId = null): array
    {
        return $this->call('delete', 'DELETE', [
            'args'     => ['id' => (string) $id],
            'body'     => $body,
            'supplier' => $supplierId,
        ]);
    }

    /**
     * @param array<string,mixed> $opts
     * @return array{status:int, body:array<string,mixed>}
     */
    private function call(string $method, string $httpMethod, array $opts = []): array
    {
        $req = (new ServerRequestFactory())
            ->createServerRequest($httpMethod, '/api/settings/vat-status-history')
            ->withAttribute(SupplierScopeMiddleware::ATTR_CURRENT_ID, $opts['supplier'] ?? $this->supplierId)
            ->withAttribute(AuthMiddleware::ATTR_USER, ['id' => $this->userId, 'role' => $opts['role'] ?? 'admin']);
        if (array_key_exists('body', $opts) && $opts['body'] !== []) {
            $req = $req->withParsedBody($opts['body']);
        }
        $args = $opts['args'] ?? [];
        $resp = $args === []
            ? $this->action->{$method}($req, new Psr7Response())
            : $this->action->{$method}($req, new Psr7Response(), $args);
        $resp->getBody()->rewind();
        $decoded = json_decode((string) $resp->getBody(), true);
        return ['status' => $resp->getStatusCode(), 'body' => is_array($decoded) ? $decoded : []];
    }
}
