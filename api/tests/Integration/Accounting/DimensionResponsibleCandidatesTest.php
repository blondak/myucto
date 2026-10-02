<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Accounting;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Accounting\Dimension\DimensionService;
use PDO;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Výběr odpovědné osoby (schvalovatele) hodnoty dimenze nabízí uživatele přiřazené
 * k firmě i superadmina, který má přístup ke všem firmám bez přiřazení. Dřív
 * superadmin chyběl a firma bez přiřazených uživatelů neměla koho vybrat.
 */
#[Group('integration')]
final class DimensionResponsibleCandidatesTest extends TestCase
{
    private Connection $db;
    private PDO $pdo;
    private DimensionService $dimensions;
    /** @var list<int> */
    private array $userIds = [];
    private int $supplierId = 0;

    protected function setUp(): void
    {
        if (!is_file(dirname(__DIR__, 4) . '/cfg.php')) {
            $this->markTestSkipped('cfg.php neexistuje — test vyžaduje DB.');
        }
        try {
            $c = Bootstrap::buildContainer();
            $this->db = $c->get(Connection::class);
            $this->pdo = $this->db->pdo();
            $this->dimensions = $c->get(DimensionService::class);
        } catch (\Throwable $e) {
            $this->markTestSkipped('DI/DB nedostupné: ' . $e->getMessage());
        }
        $this->supplierId = (int) ($this->pdo->query('SELECT id FROM supplier ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        if ($this->supplierId === 0) {
            $this->markTestSkipped('Chybí dodavatel.');
        }
    }

    protected function tearDown(): void
    {
        if (!isset($this->pdo)) {
            return;
        }
        foreach ($this->userIds as $id) {
            $this->pdo->prepare('DELETE FROM user_suppliers WHERE user_id = ?')->execute([$id]);
            $this->pdo->prepare('DELETE FROM users WHERE id = ?')->execute([$id]);
        }
        $this->db->close();
    }

    public function testSuperadminAndAssignedUsersAreOfferedOthersNot(): void
    {
        $super = $this->user('superadmin', 'TEST Superadmin kandidát');
        $assigned = $this->user('accountant', 'TEST Přiřazená účetní');
        $outsider = $this->user('accountant', 'TEST Cizí účetní');
        $this->pdo->prepare('INSERT INTO user_suppliers (user_id, supplier_id) VALUES (?, ?)')->execute([$assigned, $this->supplierId]);

        $ids = array_column($this->dimensions->responsibleCandidates($this->supplierId), 'id');

        self::assertContains($super, $ids);
        self::assertContains($assigned, $ids);
        self::assertNotContains($outsider, $ids);
    }

    private function user(string $systemKey, string $name): int
    {
        $stmt = $this->pdo->prepare('SELECT id, system_key FROM roles WHERE system_key = ? LIMIT 1');
        $stmt->execute([$systemKey]);
        $role = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($role === false) {
            $this->markTestSkipped("Chybí role {$systemKey}.");
        }
        $this->pdo->prepare(
            'INSERT INTO users (email, password_hash, name, role, role_id, is_active) VALUES (?, ?, ?, ?, ?, 1)'
        )->execute([bin2hex(random_bytes(6)) . '@example.test', 'x', $name, $systemKey === 'superadmin' ? 'admin' : $systemKey, (int) $role['id']]);
        return $this->userIds[] = (int) $this->pdo->lastInsertId();
    }
}
