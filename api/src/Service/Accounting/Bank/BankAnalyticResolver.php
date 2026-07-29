<?php

declare(strict_types=1);

namespace MyInvoice\Service\Accounting\Bank;

use MyInvoice\Repository\ChartOfAccountsRepository;
use MyInvoice\Repository\SupplierBankAccountRepository;

/**
 * Nasměrování bankovní nohy na dedikovanou analytiku vlastního účtu (#35).
 *
 * Účtová osnova vede banku implicitně na plochém syntetickém 221 a měnu nese
 * ŘÁDEK (currency_code / fx_rate / amount_foreign — §4/12 ZoÚ). To je v pořádku
 * pro jednoměnovou firmu, ale jakmile na 221 leží víc účtů v RŮZNÝCH měnách
 * (např. CZK běžný + EUR Creditas + EUR ČSOB), promíchá se cizoměnová pozice
 * a poloautomat přecenění k rozvahovému dni (FxRevaluationService slot 2 /
 * ClosingRepository::bankProposals) takový „plochý" účet vyloučí — Kč zůstatek
 * účtu ≠ Kč hodnota cizoměnových řádků. Cizoměnový zůstatek se pak musí
 * přeceňovat ručně (viz dřívější ruční doúčtování Creditas EUR).
 *
 * Řešení: každý vlastní bankovní účet (supplier_bank_accounts) může mít
 * `analytic_suffix` (1–6 číslic, nastavuje se v FE). Bankovní noha jeho pohybů
 * pak nepadá na holé 221, ale na 221<suffix> (Creditas EUR → 221500, ČSOB EUR →
 * 221510). Tím vznikne ČISTÝ jednoměnový účet, který bankProposals nabídne a
 * FxRevaluationService přecení automaticky per účet, bez míchání měn.
 *
 * Přepisuje se JEN řádek s PŘESNÝM kódem '221' (default bankovní nohy z
 * BankPostingRule). Konkrétní analytiky (221100 termínovaný vklad) zůstávají
 * nedotčené. Bez suffixu / bez shody vlastního účtu = beze změny (no-op).
 */
final class BankAnalyticResolver
{
    /** Syntetický účet banky, jehož default se přesměrovává na analytiku. */
    private const BANK_SYNTHETIC = '221';

    /** @var array<string, array<string,mixed>|null> cache shody vlastního účtu v rámci requestu */
    private array $ownCache = [];

    public function __construct(
        private readonly SupplierBankAccountRepository $bankAccounts,
        private readonly ChartOfAccountsRepository $chart,
    ) {}

    /**
     * Přepíše bankovní nohu (přesně '221') na analytiku vlastního účtu výpisu.
     *
     * @param array<string,mixed> $tx  řádek bank_transactions s klíči
     *                                  recipient_account / recipient_bank (číslo účtu výpisu)
     * @param list<array<string,mixed>> $lines řádky ve formátu postDocument
     * @return list<array<string,mixed>>
     */
    public function apply(int $supplierId, array $tx, array $lines): array
    {
        $code = $this->analyticCodeFor($supplierId, $tx);
        if ($code === null) {
            return $lines;
        }
        foreach ($lines as $i => $line) {
            if (($line['account_code'] ?? null) === self::BANK_SYNTHETIC) {
                $lines[$i]['account_code'] = $code;
            }
        }
        return $lines;
    }

    /**
     * Analytický kód banky pro účet daného výpisu (221<suffix>) nebo null, když
     * účet nemá suffix / nejde jednoznačně dohledat. Vedlejší efekt: dohraje
     * chybějící analytiku do osnovy (idempotentně), aby na ni šlo účtovat.
     *
     * @param array<string,mixed> $tx
     */
    public function analyticCodeFor(int $supplierId, array $tx): ?string
    {
        $account = trim((string) ($tx['recipient_account'] ?? ''));
        if ($account === '') {
            return null;
        }
        $bankRaw = $tx['recipient_bank'] ?? null;
        $bank = ($bankRaw === null || (string) $bankRaw === '') ? null : (string) $bankRaw;

        $own = $this->ownAccount($supplierId, $account, $bank);
        $suffix = is_array($own) ? ($own['analytic_suffix'] ?? null) : null;
        if (!is_string($suffix) || preg_match('/^[0-9]{1,6}$/', $suffix) !== 1) {
            return null;
        }
        $code = self::BANK_SYNTHETIC . $suffix;
        $this->ensureAnalytic($supplierId, $code, (string) ($own['label'] ?? ''));
        return $code;
    }

    /**
     * @return array<string,mixed>|null
     */
    private function ownAccount(int $supplierId, string $account, ?string $bank): ?array
    {
        $key = $supplierId . '|' . $account . '|' . ($bank ?? '');
        if (array_key_exists($key, $this->ownCache)) {
            return $this->ownCache[$key];
        }
        return $this->ownCache[$key] = $this->bankAccounts->matchCounterparty($supplierId, $account, $bank);
    }

    /**
     * Dohraje analytiku banky pod syntetický 221, pokud v osnově chybí — jinak by
     * postDocument spadl na `unknown_account`. Dědí typ/stranu ze syntetiky 221.
     */
    private function ensureAnalytic(int $supplierId, string $code, string $label): void
    {
        if ($this->chart->findByCode($supplierId, $code) !== null) {
            return;
        }
        $parent = $this->chart->findByCode($supplierId, self::BANK_SYNTHETIC);
        $this->chart->insert($supplierId, [
            'account_code' => $code,
            'name'         => $label !== '' ? $label : ('Bankovní účet ' . $code),
            'account_type' => $parent['account_type'] ?? 'asset',
            'normal_side'  => $parent['normal_side'] ?? 'debit',
            'is_synthetic' => false,
            'parent_id'    => $parent !== null ? (int) $parent['id'] : null,
            'is_active'    => true,
        ]);
    }
}
