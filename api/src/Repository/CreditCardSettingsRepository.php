<?php

declare(strict_types=1);

namespace MyInvoice\Repository;

use MyInvoice\Infrastructure\Database\Connection;
use PDO;

/**
 * Nastavení účtování kreditních karet (`credit_card_settings`), jeden řádek na firmu.
 *
 * Firma bez řádku účtuje na výchozí účty ({@see DEFAULT_CODES}) - čtení vrací vždy úplný
 * tvar. Uložený účet, který mezitím zmizel z osnovy, se vrátí jako výchozí (FK SET NULL).
 */
final class CreditCardSettingsRepository
{
    /** Výchozí kódy pro nevyplněné účty. */
    public const DEFAULT_CODES = [
        'interest'  => '562',
        'fee'       => '568',
        'repayment' => '261',
        'cash'      => '261',
        'reward'    => '648',
        'opening'   => '379',
    ];

    public const FIELDS = ['interest', 'fee', 'repayment', 'cash', 'reward', 'opening'];

    public function __construct(private readonly Connection $db) {}

    /**
     * @return array<string,mixed> configured a pro každé pole `<field>_account_id`
     *   (uložené id nebo null) a `<field>_account_code` (uložený kód, jinak výchozí kód)
     */
    public function find(int $supplierId): array
    {
        $joins = '';
        $cols = '';
        foreach (self::FIELDS as $i => $field) {
            $joins .= " LEFT JOIN chart_of_accounts a{$i} ON a{$i}.id = s.{$field}_account_id AND a{$i}.supplier_id = s.supplier_id";
            $cols .= ", a{$i}.account_code AS {$field}_code";
        }
        $stmt = $this->db->pdo()->prepare("SELECT s.*{$cols} FROM credit_card_settings s{$joins} WHERE s.supplier_id = ?");
        $stmt->execute([$supplierId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
        $out = ['configured' => $row !== null];
        foreach (self::FIELDS as $field) {
            $id = $row[$field . '_account_id'] ?? null;
            $code = $row[$field . '_code'] ?? null;
            $out[$field . '_account_id'] = $id !== null && $code !== null ? (int) $id : null;
            $out[$field . '_account_code'] = $code !== null ? (string) $code : self::DEFAULT_CODES[$field];
        }
        return $out;
    }

    /** @param array<string,mixed> $data field_account_id → id účtu nebo null */
    public function save(int $supplierId, array $data, ?int $userId): void
    {
        $columns = ['supplier_id'];
        $values = [$supplierId];
        foreach (self::FIELDS as $field) {
            $columns[] = $field . '_account_id';
            $values[] = $data[$field . '_account_id'] ?? null;
        }
        $columns[] = 'updated_by';
        $values[] = $userId;
        $updates = implode(', ', array_map(
            static fn (string $c): string => "{$c} = VALUES({$c})",
            array_slice($columns, 1),
        ));
        $this->db->pdo()->prepare(
            'INSERT INTO credit_card_settings (' . implode(', ', $columns) . ')
             VALUES (' . implode(', ', array_fill(0, count($columns), '?')) . ')
             ON DUPLICATE KEY UPDATE ' . $updates
        )->execute($values);
    }
}
