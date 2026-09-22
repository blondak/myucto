<?php

declare(strict_types=1);

namespace MyInvoice\Service\Migration\Shared;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\SupplierBankAccountRepository;
use MyInvoice\Service\Bank\AccountNumberNormalizer;
use MyInvoice\Service\Migration\MoneyS3\ImportProtocol;

/**
 * Vlastní bankovní účty firmy převzaté z cizího účetního programu:
 *  1. evidence účtů firmy (`supplier_bank_accounts`, záložka Kontace) s analytikou 221
 *     ze zdroje — bez ní se pohyby banky po převodu nemají kam zaúčtovat;
 *  2. účty firmy v měnách (`currencies`, tab Účty a zůstatky banky): prázdný řádek
 *     měny doplní první účet, každý další použitý účet dostane vlastní řádek a evidence
 *     účtů se na něj naváže (`supplier_bank_accounts.currency_id`).
 *
 * Kanonický účet zdroje: `number`, `bank`, `iban`, `currency`, `label` (už s náhradním
 * názvem, jaký zdroj používá), `suffix` (analytika 221.xxx bez syntetiky, nebo null).
 */
final class BankAccountRegistrar
{
    public function __construct(
        private readonly Connection $db,
        private readonly SupplierBankAccountRepository $bankAccounts,
    ) {}

    /**
     * Porovnání čísla účtu bez oddělovačů a úvodních nul (`19-123/0100` = `0000190000123`)
     * spolu s kódem banky bez úvodních nul.
     */
    public static function accountKey(string $number, string $bank): string
    {
        return AccountNumberNormalizer::normalize($number) . '/' . ltrim(trim($bank), '0');
    }

    /**
     * Zaeviduje účty do evidence účtů firmy. Účet bez čísla i IBANu se přeskočí; účet
     * s IBANem bez čísla se eviduje podle IBANu.
     *
     * @param array<string,array{number:string,bank:string,iban:string,currency:string,label:string,suffix:?string}> $accounts kód účtu ve zdroji → účet
     * @param string|null $countStep krok protokolu pro počty `accounts_registered` / `accounts_rejected` (null = nepočítat)
     * @return array<string,int> kód účtu ve zdroji → supplier_bank_accounts.id (účet cizí firmy chybí)
     */
    public function register(int $supplierId, array $accounts, ?ImportProtocol $protocol = null, ?string $countStep = null): array
    {
        $registered = [];
        foreach ($accounts as $code => $a) {
            $number = $a['number'] !== '' ? $a['number'] : $a['iban'];
            if ($number === '') {
                continue;
            }
            $id = $this->bankAccounts->registerImported(
                $supplierId,
                $number,
                $a['bank'] !== '' ? $a['bank'] : null,
                $a['iban'] !== '' ? $a['iban'] : null,
                $a['currency'],
                $a['label'],
                $a['suffix'],
            );
            if ($id !== null) {
                $registered[(string) $code] = $id;
            }
            if ($protocol !== null && $countStep !== null) {
                $protocol->count($countStep, $id !== null ? 'accounts_registered' : 'accounts_rejected');
            }
        }
        return $registered;
    }

    /**
     * Doplní číslo účtu na řádek měny firmy, který ho ještě nemá (vyplněný účet se
     * nepřepisuje, IBAN jen doplní). Hodnoty už zkrácené a s NULL místo prázdných.
     */
    public function fillCurrencyAccount(int $supplierId, string $currency, string $number, ?string $bank, ?string $iban): void
    {
        $this->db->pdo()->prepare(
            "UPDATE currencies SET account_number = ?, bank_code = ?, iban = COALESCE(iban, ?)
              WHERE supplier_id = ? AND code = ? AND (account_number IS NULL OR account_number = '')"
        )->execute([$number, $bank, $iban, $supplierId, $currency]);
    }

    /**
     * Každý použitý účet s číslem, který je v evidenci účtů firmy, patří mezi účty firmy
     * v měnách: chybí-li tam (podle {@see accountKey()}), dostane vlastní řádek měny
     * a evidence účtů se na něj naváže. Opakovaný převod řádky nezdvojí.
     *
     * @param array<string,array{number:string,bank:string,iban:string,currency:string,label:string}> $accounts
     * @param list<string> $used kódy účtů s pohyby v převáděném období
     * @param array<string,int> $registered výsledek {@see register()}
     * @param bool $namesFromExistingCurrency symbol a názvy nového řádku z existující měny
     *        téhož kódu (Money S3); false = pevně česká koruna (POHODA převádí jen účty v Kč)
     */
    public function linkCompanyAccounts(int $supplierId, array $accounts, array $used, array $registered, ImportProtocol $protocol, string $step, bool $namesFromExistingCurrency): void
    {
        $pdo = $this->db->pdo();
        $known = [];
        $byCode = [];
        $existing = $pdo->prepare('SELECT id, code, account_number, bank_code, symbol, name_cs, name_en FROM currencies WHERE supplier_id = ?');
        $existing->execute([$supplierId]);
        foreach ($existing->fetchAll(\PDO::FETCH_ASSOC) as $row) {
            $byCode[(string) $row['code']] ??= $row;
            if ((string) ($row['account_number'] ?? '') !== '') {
                $known[self::accountKey((string) $row['account_number'], (string) $row['bank_code'])] = (int) $row['id'];
            }
        }
        $insert = $pdo->prepare(
            'INSERT INTO currencies (supplier_id, code, label, symbol, name_cs, name_en, decimals, is_active, is_default, account_number, bank_code, iban)
             VALUES (?, ?, ?, ?, ?, ?, 2, 1, 0, ?, ?, ?)'
        );
        $link = $pdo->prepare('UPDATE supplier_bank_accounts SET currency_id = ? WHERE id = ? AND supplier_id = ? AND currency_id IS NULL');
        foreach ($used as $code) {
            $a = $accounts[$code] ?? null;
            if ($a === null || $a['number'] === '' || !isset($registered[$code])) {
                continue;
            }
            $key = self::accountKey($a['number'], $a['bank']);
            if (!isset($known[$key])) {
                $base = $namesFromExistingCurrency ? ($byCode[$a['currency']] ?? null) : null;
                $czk = $a['currency'] === 'CZK';
                $insert->execute([
                    $supplierId,
                    $a['currency'],
                    mb_substr($a['label'], 0, 60),
                    $base['symbol'] ?? ($czk ? 'Kč' : $a['currency']),
                    $base['name_cs'] ?? ($czk ? 'Česká koruna' : $a['currency']),
                    $base['name_en'] ?? ($czk ? 'Czech Koruna' : $a['currency']),
                    mb_substr($a['number'], 0, 30),
                    $a['bank'] !== '' ? mb_substr($a['bank'], 0, 4) : null,
                    $a['iban'] !== '' ? mb_substr($a['iban'], 0, 34) : null,
                ]);
                $known[$key] = (int) $pdo->lastInsertId();
                $protocol->count($step, 'accounts_added');
            }
            $link->execute([$known[$key], $registered[$code], $supplierId]);
        }
    }
}
