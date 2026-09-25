<?php

declare(strict_types=1);

namespace MyInvoice\Service\Accounting\CreditCard;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\CardClearingSettingsRepository;
use MyInvoice\Repository\CreditCardSettingsRepository;
use MyInvoice\Service\Accounting\Card\CardClearingSettingsService;
use MyInvoice\Service\Accounting\PostingException;
use PDO;

/**
 * Nastavení účtování kreditních karet (záložka „Nastavení účtování" na stránce Kreditní
 * karty): výchozí režim nákupů a účty. Změna platí jen pro budoucí zápisy - zaúčtované
 * pohyby se nepřeúčtovávají.
 *
 * Účty uzavření bez dokladu (nedaňově) a soukromého nákupu nevyplněné přebírají nastavení
 * platebních karet - mezičlen karet je jeden a má jednu sadu výchozích účtů. `inherited`
 * v odpovědi říká, co se pak skutečně použije.
 */
final class CreditCardSettingsService
{
    /** Povolené prefixy účtu pro jednotlivá pole. */
    private const ACCOUNT_RULES = [
        'interest'        => ['56'],
        'fee'             => ['5'],
        'repayment'       => ['261', '395'],
        'cash'            => ['261', '211'],
        'reward'          => ['6'],
        'writeoff_tax'    => ['5'],
        'writeoff_nontax' => ['5'],
        'private'         => ['335', '355', '378'],
        'opening'         => ['3', '4'],
    ];

    public function __construct(
        private readonly Connection $db,
        private readonly CreditCardSettingsRepository $repo,
        private readonly CardClearingSettingsRepository $cardSettings,
        private readonly CardClearingSettingsService $cardSettingsService,
        private readonly \MyInvoice\Service\Accounting\Card\CardClearingAccounts $clearingAccounts,
    ) {}

    /** @return array<string,mixed> */
    public function settings(int $supplierId): array
    {
        $stmt = $this->db->pdo()->prepare(
            "SELECT id, account_code, name, account_type, is_synthetic, tax_deductibility
               FROM chart_of_accounts
              WHERE supplier_id = ? AND is_active = 1 AND account_code REGEXP '^[2-7]'
              ORDER BY account_code"
        );
        $stmt->execute([$supplierId]);
        $options = array_map(static fn (array $a): array => [
            'id'             => (int) $a['id'],
            'account_code'   => (string) $a['account_code'],
            'name'           => (string) $a['name'],
            'is_synthetic'   => (bool) $a['is_synthetic'],
            'non_deductible' => (string) $a['tax_deductibility'] === 'non_deductible',
        ], $stmt->fetchAll(PDO::FETCH_ASSOC) ?: []);

        $mode = $this->db->pdo()->prepare('SELECT accounting_mode FROM supplier WHERE id = ?');
        $mode->execute([$supplierId]);

        return [
            'double_entry'     => (string) $mode->fetchColumn() === 'double_entry',
            'settings'         => $this->repo->find($supplierId),
            'defaults'         => $this->defaults($supplierId),
            'modes'            => CreditCardSettingsRepository::MODES,
            'default_mode'     => CreditCardSettingsRepository::DEFAULT_MODE,
            'clearing_synthetic' => (string) $this->cardSettings->find($supplierId)['clearing_synthetic'],
            'allowed_prefixes' => self::ACCOUNT_RULES,
            'account_options'  => $options,
        ];
    }

    /**
     * Kód, který se použije, když pole zůstane prázdné.
     *
     * @return array<string,string>
     */
    public function defaults(int $supplierId): array
    {
        $out = [];
        foreach (CreditCardSettingsRepository::DEFAULT_CODES as $field => $code) {
            $out[$field] = (string) $code;
        }
        $card = $this->cardSettings->find($supplierId);
        $out['writeoff_nontax'] = (string) (($card['writeoff_account_code'] ?? null) ?: $this->cardSettingsService->defaultWriteoffCode($supplierId));
        $out['private'] = (string) (($card['holder_account_code'] ?? null) ?: CardClearingSettingsRepository::DEFAULT_CODES['holder']);
        return $out;
    }

    /**
     * @param array<string,mixed> $input
     * @return array<string,mixed>
     */
    public function save(int $supplierId, array $input, ?int $userId): array
    {
        $current = $this->repo->find($supplierId);
        $mode = (string) ($input['purchase_mode'] ?? $current['purchase_mode']);
        if (!in_array($mode, CreditCardSettingsRepository::MODES, true)) {
            throw new PostingException('invalid_mode', 'Neplatný režim účtování nákupů.', 422, ['field' => 'purchase_mode']);
        }
        $data = ['purchase_mode' => $mode];
        foreach (self::ACCOUNT_RULES as $field => $prefixes) {
            $key = $field . '_account_id';
            $raw = array_key_exists($key, $input) ? $input[$key] : $current[$key];
            $id = $raw === null || $raw === '' ? null : (int) $raw;
            if ($id !== null && $id > 0) {
                $this->assertAccount($supplierId, $id, $prefixes, $key);
                $data[$key] = $id;
            } else {
                $data[$key] = null;
            }
        }
        $this->repo->save($supplierId, $data, $userId);
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
        // Analytika mezičlenu karty nesmí být cílem uzavření ani protiúčtem - zůstatek
        // mezičlenu by přestal odpovídat nevypořádaným nákupům.
        if (in_array($code, $this->clearingAccounts->allClearingCodes($supplierId), true)) {
            throw new PostingException('invalid_account', 'Účet ' . $code . ' je analytika mezičlenu karty - pro toto pole se nehodí.', 422, ['field' => $field]);
        }
        foreach ($prefixes as $prefix) {
            if (str_starts_with($code, $prefix)) {
                return;
            }
        }
        throw new PostingException('invalid_account', 'Účet ' . $code . ' se pro toto pole nehodí.', 422, ['field' => $field]);
    }
}
