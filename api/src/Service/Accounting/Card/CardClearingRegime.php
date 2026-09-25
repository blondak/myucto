<?php

declare(strict_types=1);

namespace MyInvoice\Service\Accounting\Card;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\CardClearingSettingsRepository;
use MyInvoice\Service\Bank\Card\CardNumberMask;

/**
 * Platí pro tenhle pohyb režim „platba kartou přes mezičlen"? Jediné místo té otázky.
 *
 * Režim platí, když firma vede podvojné účetnictví, má ho zapnutý, pohyb je z výpisu
 * (avízo se neúčtuje), nese koncovku karty a je datovaný od data účinnosti. Pohyb před
 * datem účinnosti zůstává ve starém režimu navždy — historie se nepřeúčtovává.
 *
 * Výjimka: pohyb z výpisu úvěrového účtu kreditní karty. Tam režim neurčuje nastavení
 * platebních karet ani koncovka, ale režim nákupů úvěrového účtu
 * ({@see creditCardClearingFor()}) - kreditní účet je karta sám a mezičlen má vlastní.
 *
 * Rozhodnutí o konkrétním pohybu dělá {@see \MyInvoice\Service\Accounting\Bank\BankPostingService}
 * (zná zaúčtování a pravidla výběru hotovosti a poplatků); tahle třída odpovídá jen
 * na podmínky firmy a data a dodá analytiku.
 */
final class CardClearingRegime
{
    /** @var array<int, array<string,mixed>> */
    private array $settingsMemo = [];

    /** @var array<string, ?int> účet výpisu → id vlastního účtu druhu credit_card (null = jiný druh) */
    private array $creditMemo = [];

    public function __construct(
        private readonly Connection $db,
        private readonly CardClearingSettingsRepository $settings,
        private readonly CardClearingAccounts $accounts,
        private readonly \MyInvoice\Repository\SupplierBankAccountRepository $bankAccounts,
        private readonly \MyInvoice\Repository\CreditCardAccountRepository $creditCards,
        private readonly \MyInvoice\Repository\CreditCardSettingsRepository $creditSettings,
    ) {}

    /**
     * Úvěrový účet kreditní karty, ze kterého pohyb je (podle účtu výpisu), jinak null.
     *
     * @param array<string,mixed> $tx řádek pohybu s recipient_account / recipient_bank
     * @return array<string,mixed>|null
     */
    public function creditCardAccountFor(int $supplierId, array $tx): ?array
    {
        $account = trim((string) ($tx['recipient_account'] ?? ''));
        if ($account === '') {
            return null;
        }
        $bank = isset($tx['recipient_bank']) && (string) $tx['recipient_bank'] !== '' ? (string) $tx['recipient_bank'] : null;
        // Memo drží jen id vlastního účtu; řádek úvěrového účtu (režim, přidělený suffix
        // mezičlenu) se čte pokaždé čerstvý - uvnitř dávky se mění.
        $key = $supplierId . '|' . $account . '|' . ($bank ?? '');
        if (!array_key_exists($key, $this->creditMemo)) {
            $own = $this->bankAccounts->matchCounterparty($supplierId, $account, $bank);
            $this->creditMemo[$key] = is_array($own) && ($own['kind'] ?? null) === 'credit_card' ? (int) $own['id'] : null;
        }
        $bankAccountId = $this->creditMemo[$key];
        return $bankAccountId === null ? null : $this->creditCards->findByBankAccount($supplierId, $bankAccountId);
    }

    /** Režim nákupů úvěrového účtu: vlastní, jinak výchozí režim firmy. */
    public function creditCardMode(int $supplierId, array $account): string
    {
        $mode = $account['purchase_mode'] ?? null;
        return is_string($mode) && in_array($mode, \MyInvoice\Repository\CreditCardSettingsRepository::MODES, true)
            ? $mode
            : (string) $this->creditSettings->find($supplierId)['purchase_mode'];
    }

    /**
     * Mezičlen nákupu nebo vratky kreditní kartou, pokud pro úvěrový účet platí režim
     * mezičlenu; jinak null. Režim u kreditky určuje úvěrový účet (ne nastavení platebních
     * karet ani koncovka): kreditní účet je karta sám. Úrok, poplatek, splátka, výběr
     * a odměna mezičlenem nikdy nejdou - ty zaúčtuje detektor kreditních karet.
     *
     * @param array<string,mixed> $tx
     * @param array<string,mixed> $account řádek z CreditCardAccountRepository
     * @return array{code:string, card_id:null, credit_card_account_id:int, resolved:bool, pending?:?string}|null
     */
    public function creditCardClearingFor(int $supplierId, array $tx, array $account, bool $create = false): ?array
    {
        if ((string) ($tx['source'] ?? 'statement') !== 'statement'
            || $this->creditCardMode($supplierId, $account) !== \MyInvoice\Repository\CreditCardSettingsRepository::MODE_CLEARING
            || !$this->isDoubleEntry($supplierId)) {
            return null;
        }
        $kind = \MyInvoice\Service\Bank\CreditCard\CreditCardTransactionKind::classify(
            isset($tx['description']) ? (string) $tx['description'] : null,
            (float) ($tx['amount'] ?? 0),
        );
        if (!in_array($kind, [
            \MyInvoice\Service\Bank\CreditCard\CreditCardTransactionKind::PURCHASE,
            \MyInvoice\Service\Bank\CreditCard\CreditCardTransactionKind::REFUND,
        ], true)) {
            return null;
        }
        return $this->accounts->resolveForCreditCard($supplierId, $account, $this->settings($supplierId), $create);
    }

    /** @return array<string,mixed> */
    public function settings(int $supplierId): array
    {
        return $this->settingsMemo[$supplierId] ??= $this->settings->find($supplierId);
    }

    /** Po uložení nastavení (a v testech) — memo by jinak drželo starý stav do konce requestu. */
    public function forget(int $supplierId): void
    {
        unset($this->settingsMemo[$supplierId]);
        $this->creditMemo = [];
    }

    public function isActiveOn(int $supplierId, string $date): bool
    {
        $s = $this->settings($supplierId);
        if (empty($s['enabled']) || $s['effective_from'] === null) {
            return false;
        }
        if (substr($date, 0, 10) < (string) $s['effective_from']) {
            return false;
        }
        return $this->isDoubleEntry($supplierId);
    }

    /**
     * Mezičlen pro pohyb, pokud režim platí; jinak null.
     *
     * @param array<string,mixed> $tx
     * @return array{code:string, card_id:?int, resolved:bool}|null
     */
    public function clearingFor(int $supplierId, array $tx, bool $create = false): ?array
    {
        if ((string) ($tx['source'] ?? 'statement') !== 'statement') {
            return null;
        }
        if (!CardNumberMask::isValidLast4((string) ($tx['card_last4'] ?? ''))) {
            return null;
        }
        if (!$this->isActiveOn($supplierId, (string) ($tx['posted_at'] ?? ''))) {
            return null;
        }
        return $this->accounts->resolveForTransaction($supplierId, $tx, $this->settings($supplierId), $create);
    }

    /** Je kód účtu analytikou mezičlenu karet firmy (pod kteroukoli povolenou syntetikou)? */
    public function isClearingCode(int $supplierId, string $code): bool
    {
        return in_array($code, $this->accounts->allClearingCodes($supplierId), true);
    }

    private function isDoubleEntry(int $supplierId): bool
    {
        $stmt = $this->db->pdo()->prepare('SELECT accounting_mode FROM supplier WHERE id = ?');
        $stmt->execute([$supplierId]);
        return (string) $stmt->fetchColumn() === 'double_entry';
    }
}
