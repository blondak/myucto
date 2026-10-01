<?php

declare(strict_types=1);

namespace MyInvoice\Service\Bank;

/**
 * Pohyby, kterých se dotklo načtení bankovního výpisu (ruční nahrání GPC/PDF, stažení
 * z napojení banky, složka, PDF z e-mailu), rozdělené podle toho, zda je import ZALOŽIL,
 * nebo jen PROPOJIL s pohybem, který už v evidenci byl.
 *
 * Pravidlo: zpracování pohybu (převzetí párování z e-mailového avíza, nahrazení avíza,
 * párování na doklady, zaúčtování, pozorování protistran) patří jen pohybu, který import
 * právě založil. Už evidovaný pohyb ze strojového feedu nebo z dřívějšího výpisu prošel
 * zpracováním při svém vzniku a mezitím na něm mohla vzniknout ruční práce: párování
 * převodu mezi vlastními účty, zaúčtování, ruční spárování. Další výpis k němu jen
 * dodává doklad (příloha výpisu, `bank_transaction_imports`, měsíční výpis). Kdyby ho
 * zpracoval znovu, matcher by převod mezi vlastními účty se shodným VS spároval na fakturu
 * a pozorování protistran by se započítalo podruhé.
 *
 * Dorovnání už evidovaného pohybu (avízo doručené až po výpisu, zpracování přerušené
 * chybou) je vědomý krok uživatele: „Znovu spárovat" u výpisu.
 */
final class StatementImportTransactions
{
    /** @var array<int,bool> id pohybu => založen tímto importem */
    private array $transactions = [];

    public function inserted(int $transactionId): void
    {
        $this->transactions[$transactionId] = true;
    }

    public function linked(int $transactionId): void
    {
        $this->transactions[$transactionId] ??= false;
    }

    /** @return list<int> Všechny pohyby, kterých se import dotkl. */
    public function all(): array
    {
        return array_keys($this->transactions);
    }

    /** @return list<int> Pohyby, které import zpracuje. */
    public function toProcess(): array
    {
        return array_keys(array_filter($this->transactions));
    }
}
