<?php

declare(strict_types=1);

namespace MyInvoice\Service\Accounting\CreditCard;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\CreditCardSettingsRepository;
use MyInvoice\Service\Accounting\PostingException;
use PDO;

/**
 * Nastavení účtování kreditních karet (záložka „Nastavení účtování" na stránce Kreditní
 * karty). Změna platí jen pro budoucí zápisy - zaúčtované pohyby se nepřeúčtovávají.
 */
final class CreditCardSettingsService
{
    /** Povolené prefixy účtu pro jednotlivá pole. */
    private const ACCOUNT_RULES = [
        'interest'  => ['56'],
        'fee'       => ['5'],
        'repayment' => ['261', '395'],
        'cash'      => ['261', '211'],
        'reward'    => ['6'],
    ];

    public function __construct(
        private readonly Connection $db,
        private readonly CreditCardSettingsRepository $repo,
    ) {}

    /** @return array<string,mixed> */
    public function settings(int $supplierId): array
    {
        $stmt = $this->db->pdo()->prepare(
            "SELECT id, account_code, name, account_type, is_synthetic
               FROM chart_of_accounts
              WHERE supplier_id = ? AND is_active = 1
                AND (account_code LIKE '5%' OR account_code LIKE '6%' OR account_code LIKE '261%'
                     OR account_code LIKE '395%' OR account_code LIKE '211%')
              ORDER BY account_code"
        );
        $stmt->execute([$supplierId]);
        $options = array_map(static fn (array $a): array => [
            'id'           => (int) $a['id'],
            'account_code' => (string) $a['account_code'],
            'name'         => (string) $a['name'],
            'is_synthetic' => (bool) $a['is_synthetic'],
        ], $stmt->fetchAll(PDO::FETCH_ASSOC) ?: []);

        $mode = $this->db->pdo()->prepare('SELECT accounting_mode FROM supplier WHERE id = ?');
        $mode->execute([$supplierId]);

        return [
            'double_entry'    => (string) $mode->fetchColumn() === 'double_entry',
            'settings'        => $this->repo->find($supplierId),
            'defaults'        => CreditCardSettingsRepository::DEFAULT_CODES,
            'allowed_prefixes' => self::ACCOUNT_RULES,
            'account_options' => $options,
        ];
    }

    /**
     * @param array<string,mixed> $input
     * @return array<string,mixed>
     */
    public function save(int $supplierId, array $input, ?int $userId): array
    {
        $current = $this->repo->find($supplierId);
        $ids = [];
        foreach (self::ACCOUNT_RULES as $field => $prefixes) {
            $key = $field . '_account_id';
            $raw = array_key_exists($key, $input) ? $input[$key] : $current[$key];
            $id = $raw === null || $raw === '' ? null : (int) $raw;
            if ($id !== null && $id > 0) {
                $this->assertAccount($supplierId, $id, $prefixes, $key);
                $ids[$key] = $id;
            } else {
                $ids[$key] = null;
            }
        }
        $this->repo->save($supplierId, $ids, $userId);
        return $this->settings($supplierId);
    }

    /** @param list<string> $prefixes */
    private function assertAccount(int $supplierId, int $id, array $prefixes, string $field): void
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT account_code FROM chart_of_accounts WHERE id = ? AND supplier_id = ? AND is_active = 1'
        );
        $stmt->execute([$id, $supplierId]);
        $code = $stmt->fetchColumn();
        if (!is_string($code)) {
            throw new PostingException('invalid_account', 'Účet nepatří do účtové osnovy firmy.', 422, ['field' => $field]);
        }
        foreach ($prefixes as $prefix) {
            if (str_starts_with($code, $prefix)) {
                return;
            }
        }
        throw new PostingException('invalid_account', 'Účet ' . $code . ' se pro toto pole nehodí.', 422, ['field' => $field]);
    }
}
