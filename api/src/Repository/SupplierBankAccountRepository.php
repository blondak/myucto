<?php

declare(strict_types=1);

namespace MyInvoice\Repository;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Bank\AccountNumberNormalizer;
use PDO;

final class SupplierBankAccountRepository
{
    private const COLUMNS = 'id, supplier_id, currency_id, label, account_number, bank_code, iban,
        currency, account_canonical, kind, analytic_suffix, source, is_active, created_at, updated_at';

    public function __construct(
        private readonly Connection $db,
        private readonly BankStatementOwnershipResolver $ownership,
    ) {}

    /** @return list<array<string,mixed>> */
    public function listForSupplier(int $supplierId): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT ' . self::COLUMNS . ' FROM supplier_bank_accounts WHERE supplier_id = ? ORDER BY is_active DESC, label, id'
        );
        $stmt->execute([$supplierId]);
        return array_map(fn (array $row): array => $this->cast($row), $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    /** @return list<array<string,mixed>> */
    public function findActive(int $supplierId): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT ' . self::COLUMNS . ' FROM supplier_bank_accounts WHERE supplier_id = ? AND is_active = 1 ORDER BY id'
        );
        $stmt->execute([$supplierId]);
        return array_map(fn (array $row): array => $this->cast($row), $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    public function find(int $supplierId, int $id): ?array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT ' . self::COLUMNS . ' FROM supplier_bank_accounts WHERE supplier_id = ? AND id = ?'
        );
        $stmt->execute([$supplierId, $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : $this->cast($row);
    }

    public function matchCounterparty(int $supplierId, string $counterpartyAccount, ?string $counterpartyBank): ?array
    {
        $canonical = AccountNumberNormalizer::canonical($counterpartyAccount);
        if ($canonical === null) {
            return null;
        }
        $bank = AccountNumberNormalizer::canonicalBankCode($counterpartyBank, $counterpartyAccount);
        $sql = 'SELECT ' . self::COLUMNS . ' FROM supplier_bank_accounts
                 WHERE supplier_id = ? AND is_active = 1 AND account_canonical = ?';
        $params = [$supplierId, $canonical];
        if ($bank !== null) {
            $sql .= " AND bank_code_norm IN ('', ?) ORDER BY (bank_code_norm = ?) DESC, id ASC";
            $params[] = $bank;
            $params[] = $bank;
        } else {
            $sql .= ' ORDER BY id ASC';
        }
        $stmt = $this->db->pdo()->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if ($rows === []) {
            return null;
        }
        if ($bank === null && count($rows) !== 1) {
            return null;
        }
        return $this->cast($rows[0]);
    }

    /**
     * Zaeviduje vlastní účet, který jsme viděli na výpisu.
     *
     * Dvě pojistky (SEC-01 / R4 — vlastnictví se nesmí odvozovat z čísla účtu):
     *
     *  1. Je-li znám `currencies` řádek, kanonizuje se PROVĚŘENÁ hodnota z něj,
     *     ne syrový řetězec z hlavičky výpisu. Hlavičku plní importovaný soubor,
     *     takže bez tohohle si šlo pod cizí (explicitně zvolený) měnový účet
     *     vyrobit `account_canonical` na libovolné číslo.
     *  2. Claim guard: účet, který v `currencies` drží JINÁ firma, se
     *     nezaevidovává. Jinak by v `supplier_bank_accounts` vznikly dva řádky
     *     se shodným `account_canonical` u dvou tenantů — přesně ta kolize,
     *     ze které těžilo odvozování vlastníka podle čísla účtu.
     */
    public function registerSeen(
        int $supplierId,
        string $accountNumber,
        ?string $bankCode,
        ?string $currency,
        ?int $currencyId,
    ): void {
        $verified = $currencyId !== null ? $this->verifiedAccount($supplierId, $currencyId) : null;
        // Cizí (nebo neexistující) currency_id se do registru nepropíše — vazba by
        // ukazovala mimo tenanta.
        if ($verified === null) {
            $currencyId = null;
        }
        $iban = null;
        if ($verified !== null) {
            $verifiedNumber = trim((string) ($verified['account_number'] ?? ''));
            $iban = trim((string) ($verified['iban'] ?? '')) !== '' ? (string) $verified['iban'] : null;
            if ($verifiedNumber !== '' || $iban !== null) {
                $accountNumber = $verifiedNumber !== '' ? $verifiedNumber : $accountNumber;
                $verifiedBank = trim((string) ($verified['bank_code'] ?? ''));
                if ($verifiedBank !== '') {
                    $bankCode = $verifiedBank;
                }
            }
        }

        $canonical = AccountNumberNormalizer::canonical($accountNumber, $iban);
        if ($canonical === null) {
            return;
        }
        if ($this->ownership->accountClaimedByOtherSupplier($supplierId, $accountNumber, $iban)) {
            return;
        }
        // Kód banky ZÁMĚRNĚ bez IBAN fallbacku: `bank_code_norm` se porovnává s
        // `bank_statements.bank_code`, které import plní z téhož `currencies.bank_code`.
        // Dopočet z IBANu by registru dal kód, jaký výpis nenese, a shoda by se rozešla.
        $bank = AccountNumberNormalizer::canonicalBankCode($bankCode);
        $this->db->pdo()->prepare(
            'INSERT INTO supplier_bank_accounts
                (supplier_id, currency_id, label, account_number, bank_code, bank_code_norm, currency,
                 account_canonical, kind, source, is_active)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, "current", "statement", 1)
             ON DUPLICATE KEY UPDATE
                currency_id = CASE
                    WHEN currency_id IS NULL THEN NULL
                    WHEN VALUES(currency_id) IS NULL OR currency_id = VALUES(currency_id) THEN currency_id
                    ELSE NULL END,
                currency = CASE
                    WHEN currency IS NULL THEN NULL
                    WHEN VALUES(currency) IS NULL OR currency = VALUES(currency) THEN currency
                    ELSE NULL END,
                account_number = COALESCE(account_number, VALUES(account_number)),
                is_active = 1'
        )->execute([
            $supplierId,
            $currencyId,
            'Účet ' . $accountNumber,
            $accountNumber,
            $bank,
            $bank ?? '',
            $currency !== null ? strtoupper($currency) : null,
            $canonical,
        ]);
    }

    /**
     * `currencies` řádek firmy — prověřený zdroj čísla účtu / IBANu pro
     * {@see registerSeen()}. Cizí (nebo neexistující) id vrací null, takže se
     * spadne zpátky na hodnotu od volajícího, kterou ještě prověří claim guard.
     *
     * @return array{account_number:?string,iban:?string,bank_code:?string}|null
     */
    private function verifiedAccount(int $supplierId, int $currencyId): ?array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT account_number, iban, bank_code FROM currencies WHERE id = ? AND supplier_id = ?'
        );
        $stmt->execute([$currencyId, $supplierId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : $row;
    }

    /**
     * Suffixy analytiky 221, které už drží nějaký účet firmy — VČETNĚ neaktivních.
     * Neaktivní účet si číslo drží dál, jinak by nový účet zdědil jeho historii.
     *
     * @return array<string,true>
     */
    public function usedSuffixes(int $supplierId): array
    {
        $stmt = $this->db->pdo()->prepare(
            "SELECT DISTINCT analytic_suffix FROM supplier_bank_accounts
              WHERE supplier_id = ? AND analytic_suffix IS NOT NULL AND analytic_suffix <> ''"
        );
        $stmt->execute([$supplierId]);
        $out = [];
        foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $suffix) {
            $out[(string) $suffix] = true;
        }
        return $out;
    }

    /** Účet firmy držící daný suffix (unikátní přes uq_sba_analytic), nebo null. */
    public function findBySuffix(int $supplierId, string $suffix): ?array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT ' . self::COLUMNS . ' FROM supplier_bank_accounts
              WHERE supplier_id = ? AND analytic_suffix = ? LIMIT 1'
        );
        $stmt->execute([$supplierId, $suffix]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : $this->cast($row);
    }

    /**
     * Přidělí suffix účtu, který ho ještě NEMÁ (existující se nikdy nepřepíše).
     * Vrací false při souběhu — jiný proces stihl přidělit dřív (unique index) —
     * i když účet mezitím suffix získal; volající si ho přečte znovu.
     */
    public function assignSuffix(int $supplierId, int $id, string $suffix): bool
    {
        try {
            $stmt = $this->db->pdo()->prepare(
                "UPDATE supplier_bank_accounts SET analytic_suffix = ?
                  WHERE supplier_id = ? AND id = ? AND (analytic_suffix IS NULL OR analytic_suffix = '')"
            );
            $stmt->execute([$suffix, $supplierId, $id]);
            return $stmt->rowCount() > 0;
        } catch (\PDOException) {
            return false;
        }
    }

    /** @param array{kind?:string,label?:?string,is_active?:bool,analytic_suffix?:?string} $patch */
    public function update(int $supplierId, int $id, array $patch): bool
    {
        $sets = [];
        $params = [];
        foreach (['kind', 'label', 'analytic_suffix'] as $field) {
            if (array_key_exists($field, $patch)) {
                $sets[] = $field . ' = ?';
                $params[] = $patch[$field];
            }
        }
        if (array_key_exists('is_active', $patch)) {
            $sets[] = 'is_active = ?';
            $params[] = $patch['is_active'] ? 1 : 0;
        }
        if ($sets === []) {
            return $this->find($supplierId, $id) !== null;
        }
        $params[] = $supplierId;
        $params[] = $id;
        $stmt = $this->db->pdo()->prepare(
            'UPDATE supplier_bank_accounts SET ' . implode(', ', $sets) . ' WHERE supplier_id = ? AND id = ?'
        );
        $stmt->execute($params);
        return $stmt->rowCount() > 0 || $this->find($supplierId, $id) !== null;
    }

    private function cast(array $row): array
    {
        $row['id'] = (int) $row['id'];
        $row['supplier_id'] = (int) $row['supplier_id'];
        $row['currency_id'] = $row['currency_id'] === null ? null : (int) $row['currency_id'];
        $row['is_active'] = (bool) $row['is_active'];
        return $row;
    }
}
