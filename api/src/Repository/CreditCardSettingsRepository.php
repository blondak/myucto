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
    ];

    public const FIELDS = ['interest', 'fee', 'repayment', 'cash', 'reward'];

    public function __construct(private readonly Connection $db) {}

    /**
     * @return array{configured:bool, interest_account_id:?int, fee_account_id:?int, repayment_account_id:?int,
     *   cash_account_id:?int, reward_account_id:?int, interest_account_code:string, fee_account_code:string,
     *   repayment_account_code:string, cash_account_code:string, reward_account_code:string}
     */
    public function find(int $supplierId): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT s.*, ia.account_code AS interest_code, fa.account_code AS fee_code, ra.account_code AS repayment_code,
                    ca.account_code AS cash_code, wa.account_code AS reward_code
               FROM credit_card_settings s
          LEFT JOIN chart_of_accounts ia ON ia.id = s.interest_account_id AND ia.supplier_id = s.supplier_id
          LEFT JOIN chart_of_accounts fa ON fa.id = s.fee_account_id AND fa.supplier_id = s.supplier_id
          LEFT JOIN chart_of_accounts ra ON ra.id = s.repayment_account_id AND ra.supplier_id = s.supplier_id
          LEFT JOIN chart_of_accounts ca ON ca.id = s.cash_account_id AND ca.supplier_id = s.supplier_id
          LEFT JOIN chart_of_accounts wa ON wa.id = s.reward_account_id AND wa.supplier_id = s.supplier_id
              WHERE s.supplier_id = ?'
        );
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

    /** @param array<string,?int> $ids field_account_id → id účtu nebo null */
    public function save(int $supplierId, array $ids, ?int $userId): void
    {
        $this->db->pdo()->prepare(
            'INSERT INTO credit_card_settings
                (supplier_id, interest_account_id, fee_account_id, repayment_account_id, cash_account_id, reward_account_id, updated_by)
             VALUES (?, ?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE
                interest_account_id = VALUES(interest_account_id),
                fee_account_id = VALUES(fee_account_id),
                repayment_account_id = VALUES(repayment_account_id),
                cash_account_id = VALUES(cash_account_id),
                reward_account_id = VALUES(reward_account_id),
                updated_by = VALUES(updated_by)'
        )->execute([
            $supplierId,
            $ids['interest_account_id'] ?? null,
            $ids['fee_account_id'] ?? null,
            $ids['repayment_account_id'] ?? null,
            $ids['cash_account_id'] ?? null,
            $ids['reward_account_id'] ?? null,
            $userId,
        ]);
    }
}
