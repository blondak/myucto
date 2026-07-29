<?php

declare(strict_types=1);

namespace MyInvoice\Service\Accounting\Bank;

use MyInvoice\Repository\SupplierBankAccountRepository;
use MyInvoice\Service\Bank\AccountNumberNormalizer;

final class OwnTransferDetector
{
    public function __construct(private readonly SupplierBankAccountRepository $accounts) {}

    /** @return array{account:array<string,mixed>,direction:'out'|'in',cross_currency:bool}|null */
    public function detect(int $supplierId, array $tx): ?array
    {
        $counterparty = trim((string) ($tx['counterparty_account'] ?? ''));
        $amount = (float) ($tx['amount'] ?? 0);
        if ($counterparty === '' || abs($amount) < 0.005) {
            return null;
        }
        $counterpartyCanonical = AccountNumberNormalizer::canonical($counterparty);
        if ($counterpartyCanonical === null) {
            return null;
        }
        $account = $this->accounts->matchCounterparty(
            $supplierId,
            $counterparty,
            isset($tx['counterparty_bank']) ? (string) $tx['counterparty_bank'] : null,
        );
        if ($account === null) {
            return null;
        }
        $recipientCanonical = AccountNumberNormalizer::canonical((string) ($tx['recipient_account'] ?? ''));
        if ($recipientCanonical !== null && $recipientCanonical === $counterpartyCanonical) {
            $recipientBank = AccountNumberNormalizer::canonicalBankCode(
                isset($tx['recipient_bank']) ? (string) $tx['recipient_bank'] : null,
                (string) ($tx['recipient_account'] ?? ''),
            );
            $counterpartyBank = AccountNumberNormalizer::canonicalBankCode(
                isset($tx['counterparty_bank']) ? (string) $tx['counterparty_bank'] : null,
                $counterparty,
            );
            if ($recipientBank === null || $counterpartyBank === null || $recipientBank === $counterpartyBank) {
                return null;
            }
        }
        $txCurrency = strtoupper((string) ($tx['currency'] ?? $tx['statement_currency'] ?? 'CZK'));
        $accountCurrency = strtoupper((string) ($account['currency'] ?? ''));
        return [
            'account' => $account,
            'direction' => $amount < 0 ? 'out' : 'in',
            'cross_currency' => $accountCurrency === '' || $txCurrency === '' || $accountCurrency !== $txCurrency,
        ];
    }
}
