<?php

declare(strict_types=1);

namespace MyInvoice\Service\Migration\Shared;

use MyInvoice\Infrastructure\Database\Connection;

/**
 * Měna, ve které převod zapisuje doklady: koruna firmy (`currencies.code = 'CZK'`, přednost
 * má výchozí), a když ji firma v číselníku nemá, výchozí měna firmy. Převody z Money S3,
 * POHODY a PREMIER zapisují částky v Kč, i když byl doklad vystavený v cizí měně.
 *
 * Volitelný přísný režim vyžaduje CZK výslovně a nepoužije výchozí měnu jako náhradu.
 */
final class MigrationHomeCurrency
{
    /** @var array<string,\PDOStatement> */
    private array $stmts = [];

    public function __construct(private readonly Connection $db) {}

    public function id(int $supplierId, bool $requireCzk = false): int
    {
        $stmt = $this->stmt('czk', "SELECT id FROM currencies WHERE supplier_id = ? AND code = 'CZK' ORDER BY is_default DESC, id LIMIT 1");
        $stmt->execute([$supplierId]);
        $id = (int) $stmt->fetchColumn();
        if ($id === 0 && !$requireCzk) {
            $s = $this->stmt('default', 'SELECT default_currency_id FROM supplier WHERE id = ?');
            $s->execute([$supplierId]);
            $id = (int) $s->fetchColumn();
        }
        return $id;
    }

    /** Přesné ID měny firmy, bez náhrady výchozí měnou. */
    public function idForCode(int $supplierId, string $code): int
    {
        $stmt = $this->stmt('code', 'SELECT id FROM currencies WHERE supplier_id = ? AND code = ? ORDER BY is_default DESC, id LIMIT 1');
        $stmt->execute([$supplierId, $code]);
        return (int) $stmt->fetchColumn();
    }

    private function stmt(string $key, string $sql): \PDOStatement
    {
        $pdo = $this->db->pdo();
        return $this->stmts[spl_object_id($pdo) . '|' . $key] ??= $pdo->prepare($sql);
    }
}
