<?php

declare(strict_types=1);

namespace MyInvoice\Service\Backup\Company;

/** Oddělená historie zaniklých pohybů; runtime používá jen last_rejected_tx_id. */
final class CompanyBackupBankRuleHistory
{
    public const COLUMN = 'archived_rejected_transactions';

    /**
     * @param array<string,mixed> $row
     * @param callable(int):?int $owner NULL pouze pro neexistující pohyb
     * @return array<string,mixed>
     */
    public static function detachMissing(array $row, string $backupId, callable $owner): array
    {
        self::assertRow($row);
        if (!self::uuid($backupId) || !is_int($row['supplier_id'] ?? null) || $row['supplier_id'] < 1) {
            throw new \InvalidArgumentException('Neplatný zdroj historie pravidla.');
        }
        $id = $row['last_rejected_tx_id'];
        if ($id === null) {
            return $row;
        }
        $supplier = $owner($id);
        if ($supplier !== null) {
            if ($supplier !== $row['supplier_id']) {
                throw new \InvalidArgumentException('Odmítnutý pohyb patří jinému vlastníkovi.');
            }
            return $row;
        }
        $history = self::history($row);
        $history[] = ['id' => $id, 'supplier_id' => $row['supplier_id'], 'backup_id' => $backupId];
        $row[self::COLUMN] = json_encode(['version' => 1, 'transactions' => $history], JSON_THROW_ON_ERROR);
        $row['last_rejected_tx_id'] = null;
        self::assertRow($row);
        return $row;
    }

    /** @param array<string,mixed> $row */
    public static function assertRow(array $row): void
    {
        if (!array_key_exists('last_rejected_tx_id', $row)
            || ($row['last_rejected_tx_id'] !== null
                && (!is_int($row['last_rejected_tx_id']) || $row['last_rejected_tx_id'] < 1))) {
            throw new \InvalidArgumentException('Neplatný živý odkaz historie pravidla.');
        }
        self::history($row);
    }

    /**
     * @param array<string,mixed> $row
     * @return list<array{id:int,supplier_id:int,backup_id:string}>
     */
    private static function history(array $row): array
    {
        $raw = $row[self::COLUMN] ?? null;
        if ($raw === null) {
            return [];
        }
        if (!is_string($raw)) {
            throw new \InvalidArgumentException('Historie pravidla není JSON.');
        }
        $value = json_decode($raw, true, 16, JSON_THROW_ON_ERROR);
        $keys = is_array($value) ? array_keys($value) : [];
        sort($keys);
        if ($keys !== ['transactions', 'version'] || $value['version'] !== 1
            || !is_array($value['transactions']) || !array_is_list($value['transactions'])
            || $value['transactions'] === [] || count($value['transactions']) > 10000) {
            throw new \InvalidArgumentException('Neplatná obálka historie pravidla.');
        }
        $history = [];
        $seen = [];
        foreach ($value['transactions'] as $transaction) {
            $keys = is_array($transaction) ? array_keys($transaction) : [];
            sort($keys);
            if ($keys !== ['backup_id', 'id', 'supplier_id']
                || !is_int($transaction['id']) || $transaction['id'] < 1
                || !is_int($transaction['supplier_id']) || $transaction['supplier_id'] < 1
                || !self::uuid($transaction['backup_id'])) {
                throw new \InvalidArgumentException('Neplatná archivní identita pohybu.');
            }
            $key = $transaction['backup_id'] . ':' . $transaction['supplier_id'] . ':' . $transaction['id'];
            if (isset($seen[$key])) {
                throw new \InvalidArgumentException('Duplicitní archivní identita pohybu.');
            }
            $seen[$key] = true;
            $history[] = ['id' => $transaction['id'], 'supplier_id' => $transaction['supplier_id'],
                'backup_id' => $transaction['backup_id']];
        }
        return $history;
    }

    private static function uuid(mixed $value): bool
    {
        return is_string($value) && preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/D', $value) === 1;
    }
}
