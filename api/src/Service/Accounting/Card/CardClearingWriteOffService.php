<?php

declare(strict_types=1);

namespace MyInvoice\Service\Accounting\Card;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Accounting\Bank\BankPostingService;
use MyInvoice\Service\Accounting\PostingException;
use MyInvoice\Service\Bank\Card\CardPaymentOverview;
use PDO;

/**
 * „Uzavřít bez dokladu" — platba kartou, ke které doklad nebude, se z mezičlenu odúčtuje
 * interním dokladem (`card_writeoff`, source_id = pohyb):
 *
 *   - `expense` → MD 548 (výchozí nedaňová analytika 548.x) / D 378.x — nedaňový náklad
 *     bez DPH,
 *   - `expense_tax` → MD 518 (nebo zvolený 5xx) / D 378.x — daňový náklad bez DPH, jen
 *     s jiným průkazným dokladem (potvrzení obchodníka, interní doklad),
 *   - `holder`  → MD 335.x / D 378.x — k tíži držitele karty (soukromý nákup; u společníka
 *     355, obecně 378 mimo analytiky mezičlenu).
 *
 * Vratka (kladný pohyb na mezičlenu) se uzavírá zrcadlově - strany bere zápis z bankovní
 * nohy mezičlenu. Pohyb kreditní karty bere výchozí účty z nastavení kreditních karet.
 *
 * Bankovní zápis platby zůstává. Dorazí-li doklad později, vypořádání uzavření samo
 * stornuje (viz BankPostingService::syncCardSettlement) — uzavření tedy není konečné.
 */
final class CardClearingWriteOffService
{
    public const TARGETS = ['expense', 'expense_tax', 'holder'];

    /** Povolené účty podle způsobu uzavření (prefix kódu). */
    private const TARGET_PREFIXES = [
        'expense'     => ['5'],
        'expense_tax' => ['5'],
        'holder'      => ['335', '355', '378'],
    ];

    /** Daňový náklad bez dokladu, když ho nastavení nemá (jen s jiným průkazným dokladem). */
    private const DEFAULT_TAX_EXPENSE = '518';

    public function __construct(
        private readonly Connection $db,
        private readonly BankPostingService $bankPosting,
        private readonly CardSettlementService $settlements,
        private readonly CardClearingRegime $regime,
        private readonly CardClearingSettingsService $settingsService,
        private readonly CardPaymentOverview $overview,
        private readonly \MyInvoice\Repository\CreditCardSettingsRepository $creditSettings,
    ) {}

    /** @return array{entry_id:int, account_code:string} */
    public function writeOff(int $supplierId, int $txId, string $target, ?int $accountId, ?int $userId): array
    {
        if (!in_array($target, self::TARGETS, true)) {
            throw new PostingException('invalid_target', 'Neplatný způsob uzavření platby.', 422, ['field' => 'target']);
        }
        $tx = $this->overview->findCardTransaction($supplierId, $txId);
        if ($tx === null) {
            throw new PostingException('not_found', 'Platba kartou nenalezena.', 404);
        }
        $clearing = $this->bankPosting->liveCardClearingLine($supplierId, $txId);
        if ($clearing === null) {
            throw new PostingException('not_card_clearing', 'Platba není zaúčtovaná přes mezičlen karty.', 409);
        }
        if ($this->hasAllocation($supplierId, $txId)
            || $this->settlements->hasLive($supplierId, $txId, CardSettlementService::SOURCE_SETTLEMENT)) {
            throw new PostingException('has_document', 'K platbě už je spárovaný doklad — uzavření bez dokladu nedává smysl.', 409);
        }

        $code = $this->accountCode($supplierId, $target, $accountId, !empty($tx['credit_card']));
        $accountSide = $clearing['side'];
        $clearingSide = $accountSide === 'debit' ? 'credit' : 'debit';
        $clearingLine = ['account_code' => $clearing['code'], 'side' => $clearingSide, 'amount' => $clearing['amount']];
        if ($clearing['currency_code'] !== null) {
            $clearingLine['currency_code'] = $clearing['currency_code'];
            $clearingLine['fx_rate'] = $clearing['fx_rate'];
            $clearingLine['amount_foreign'] = $clearing['amount_foreign'];
        }
        $lines = [
            ['account_code' => $code, 'side' => $accountSide, 'amount' => $clearing['amount']],
            $clearingLine,
        ];
        $res = $this->settlements->sync($supplierId, $txId, CardSettlementService::SOURCE_WRITEOFF, $lines, [
            'txDate'      => substr($tx['posted_at'], 0, 10),
            'description' => match ($target) {
                'holder'      => 'Platba kartou bez dokladu k tíži držitele karty',
                'expense_tax' => 'Platba kartou bez dokladu — daňový náklad',
                default       => 'Platba kartou bez dokladu — nedaňový náklad',
            },
            'document_no' => 'KARTA-' . $txId,
            'user_id'     => $userId,
        ]);
        if (!isset($res['entry_id']) || ($res['action'] ?? '') === 'skipped') {
            throw new PostingException('period_closed', 'Období platby je uzavřené — uzavření nelze zaúčtovat.', 409);
        }
        return ['entry_id' => (int) $res['entry_id'], 'account_code' => $code];
    }

    /** Zrušení uzavření (storno). Vrací id storna, null když uzavření neexistuje. */
    public function cancel(int $supplierId, int $txId, ?int $userId): ?int
    {
        if ($this->overview->findCardTransaction($supplierId, $txId) === null) {
            throw new PostingException('not_found', 'Platba kartou nenalezena.', 404);
        }
        return $this->settlements->reverseLive($supplierId, $txId, CardSettlementService::SOURCE_WRITEOFF, ['user_id' => $userId]);
    }

    /**
     * Účet uzavření: výslovně zvolený, jinak z nastavení. Pohyb kreditní karty bere nejdřív
     * nastavení kreditních karet; nevyplněné pole přebírá nastavení platebních karet.
     */
    private function accountCode(int $supplierId, string $target, ?int $accountId, bool $creditCard = false): string
    {
        if ($accountId !== null && $accountId > 0) {
            $stmt = $this->db->pdo()->prepare(
                'SELECT account_code FROM chart_of_accounts WHERE id = ? AND supplier_id = ? AND is_active = 1'
            );
            $stmt->execute([$accountId, $supplierId]);
            $code = $stmt->fetchColumn();
            if (!is_string($code) || !$this->allowedFor($supplierId, $target, $code)) {
                throw new PostingException('invalid_account', 'Účet se pro uzavření platby nehodí.', 422, ['field' => 'account_id']);
            }
            return $code;
        }
        $credit = $creditCard ? $this->creditSettings->find($supplierId) : [];
        $settings = $this->regime->settings($supplierId);
        return match ($target) {
            'holder'      => (string) (($credit['private_account_code'] ?? null) ?: (($settings['holder_account_code'] ?? null) ?: '335')),
            'expense_tax' => (string) (($credit['writeoff_tax_account_code'] ?? null) ?: self::DEFAULT_TAX_EXPENSE),
            default       => (string) (($credit['writeoff_nontax_account_code'] ?? null)
                ?: (($settings['writeoff_account_code'] ?? null) ?: $this->settingsService->defaultWriteoffCode($supplierId))),
        };
    }

    private function allowedFor(int $supplierId, string $target, string $code): bool
    {
        if ($this->regime->isClearingCode($supplierId, $code)) {
            return false;
        }
        foreach (self::TARGET_PREFIXES[$target] as $prefix) {
            if (str_starts_with($code, $prefix)) {
                return true;
            }
        }
        return false;
    }

    private function hasAllocation(int $supplierId, int $txId): bool
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT 1 FROM payment_matches WHERE supplier_id = ? AND bank_transaction_id = ? LIMIT 1'
        );
        $stmt->execute([$supplierId, $txId]);
        return $stmt->fetchColumn() !== false;
    }
}
