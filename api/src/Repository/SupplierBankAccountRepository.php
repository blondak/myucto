<?php

declare(strict_types=1);

namespace MyInvoice\Repository;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Bank\AccountNumberNormalizer;
use PDO;

final class SupplierBankAccountRepository
{
    private const COLUMNS = 'id, supplier_id, currency_id, label, account_number, bank_code, iban,
        currency, account_canonical, kind, analytic_suffix, source, is_active, created_at, updated_at';

    public function __construct(private readonly Connection $db) {}

    /** @return list<array<string,mixed>> */
    public function listForSupplier(int $supplierId): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT ' . self::COLUMNS . ' FROM supplier_bank_accounts WHERE supplier_id = ? ORDER BY is_active DESC, label, id'
        );
        $stmt->execute([$supplierId]);
        return array_map(fn (array $row): array => $this->cast($row), $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    /** @return list<array<string,mixed>> */
    public function findActive(int $supplierId): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT ' . self::COLUMNS . ' FROM supplier_bank_accounts WHERE supplier_id = ? AND is_active = 1 ORDER BY id'
        );
        $stmt->execute([$supplierId]);
        return array_map(fn (array $row): array => $this->cast($row), $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    public function find(int $supplierId, int $id): ?array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT ' . self::COLUMNS . ' FROM supplier_bank_accounts WHERE supplier_id = ? AND id = ?'
        );
        $stmt->execute([$supplierId, $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : $this->cast($row);
    }

    public function matchCounterparty(int $supplierId, string $counterpartyAccount, ?string $counterpartyBank): ?array
    {
        $canonical = AccountNumberNormalizer::canonical($counterpartyAccount);
        if ($canonical === null) {
            return null;
        }
        $bank = AccountNumberNormalizer::canonicalBankCode($counterpartyBank, $counterpartyAccount);
        $sql = 'SELECT ' . self::COLUMNS . ' FROM supplier_bank_accounts
                 WHERE supplier_id = ? AND is_active = 1 AND account_canonical = ?';
        $params = [$supplierId, $canonical];
        if ($bank !== null) {
            $sql .= " AND bank_code_norm IN ('', ?) ORDER BY (bank_code_norm = ?) DESC, id ASC";
            $params[] = $bank;
            $params[] = $bank;
        } else {
            $sql .= ' ORDER BY id ASC';
        }
        $stmt = $this->db->pdo()->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if ($rows === []) {
            return null;
        }
        if ($bank === null && count($rows) !== 1) {
            return null;
        }
        return $this->cast($rows[0]);
    }

    public function registerSeen(
        int $supplierId,
        string $accountNumber,
        ?string $bankCode,
        ?string $currency,
        ?int $currencyId,
    ): void {
        $canonical = AccountNumberNormalizer::canonical($accountNumber);
        if ($canonical === null) {
            return;
        }
        $bank = AccountNumberNormalizer::canonicalBankCode($bankCode);
        $this->db->pdo()->prepare(
            'INSERT INTO supplier_bank_accounts
                (supplier_id, currency_id, label, account_number, bank_code, bank_code_norm, currency,
                 account_canonical, kind, source, is_active)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, "current", "statement", 1)
             ON DUPLICATE KEY UPDATE
                currency_id = CASE
                    WHEN currency_id IS NULL THEN NULL
                    WHEN VALUES(currency_id) IS NULL OR currency_id = VALUES(currency_id) THEN currency_id
                    ELSE NULL END,
                currency = CASE
                    WHEN currency IS NULL THEN NULL
                    WHEN VALUES(currency) IS NULL OR currency = VALUES(currency) THEN currency
                    ELSE NULL END,
                account_number = COALESCE(account_number, VALUES(account_number)),
                is_active = 1'
        )->execute([
            $supplierId,
            $currencyId,
            'Účet ' . $accountNumber,
            $accountNumber,
            $bank,
            $bank ?? '',
            $currency !== null ? strtoupper($currency) : null,
            $canonical,
        ]);
    }

    /** @param array{kind?:string,label?:?string,is_active?:bool,analytic_suffix?:?string} $patch */
    public function update(int $supplierId, int $id, array $patch): bool
    {
        $sets = [];
        $params = [];
        foreach (['kind', 'label', 'analytic_suffix'] as $field) {
            if (array_key_exists($field, $patch)) {
                $sets[] = $field . ' = ?';
                $params[] = $patch[$field];
            }
        }
        if (array_key_exists('is_active', $patch)) {
            $sets[] = 'is_active = ?';
            $params[] = $patch['is_active'] ? 1 : 0;
        }
        if ($sets === []) {
            return $this->find($supplierId, $id) !== null;
        }
        $params[] = $supplierId;
        $params[] = $id;
        $stmt = $this->db->pdo()->prepare(
            'UPDATE supplier_bank_accounts SET ' . implode(', ', $sets) . ' WHERE supplier_id = ? AND id = ?'
        );
        $stmt->execute($params);
        return $stmt->rowCount() > 0 || $this->find($supplierId, $id) !== null;
    }

    private function cast(array $row): array
    {
        $row['id'] = (int) $row['id'];
        $row['supplier_id'] = (int) $row['supplier_id'];
        $row['currency_id'] = $row['currency_id'] === null ? null : (int) $row['currency_id'];
        $row['is_active'] = (bool) $row['is_active'];
        return $row;
    }
}
