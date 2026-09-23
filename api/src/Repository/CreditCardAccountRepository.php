<?php

declare(strict_types=1);

namespace MyInvoice\Repository;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Bank\AccountNumberNormalizer;
use PDO;

/**
 * Úvěrové účty kreditních karet (`credit_card_accounts`). Každý účet je navázaný na řádek
 * registru vlastních účtů (`supplier_bank_accounts`, kind = credit_card) - přes něj ho
 * najde tenant resolution výpisu, detekce vlastních převodů i analytika vlastní nohy zápisu.
 *
 * Všechny dotazy jsou vázané na firmu (supplier_id) - cizí id vrací null.
 */
final class CreditCardAccountRepository
{
    public const ISSUERS = ['kb', 'rb', 'csob', 'erste', 'other'];

    private const SELECT = 'SELECT cca.*, sba.account_canonical, sba.bank_code_norm, sba.is_active AS bank_account_active
                              FROM credit_card_accounts cca
                              JOIN supplier_bank_accounts sba ON sba.id = cca.bank_account_id AND sba.supplier_id = cca.supplier_id';

    public function __construct(private readonly Connection $db) {}

    /** @return list<array<string,mixed>> */
    public function listForSupplier(int $supplierId, bool $includeArchived = false): array
    {
        $stmt = $this->db->pdo()->prepare(
            self::SELECT . ' WHERE cca.supplier_id = ?' . ($includeArchived ? '' : ' AND cca.archived_at IS NULL')
            . ' ORDER BY cca.archived_at IS NOT NULL, cca.label, cca.id'
        );
        $stmt->execute([$supplierId]);
        return array_map(fn (array $r): array => $this->cast($r), $stmt->fetchAll(PDO::FETCH_ASSOC) ?: []);
    }

    /** @return array<string,mixed>|null */
    public function find(int $supplierId, int $id): ?array
    {
        $stmt = $this->db->pdo()->prepare(self::SELECT . ' WHERE cca.supplier_id = ? AND cca.id = ?');
        $stmt->execute([$supplierId, $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : $this->cast($row);
    }

    /** @return array<string,mixed>|null */
    public function findByBankAccount(int $supplierId, int $bankAccountId): ?array
    {
        $stmt = $this->db->pdo()->prepare(self::SELECT . ' WHERE cca.supplier_id = ? AND cca.bank_account_id = ?');
        $stmt->execute([$supplierId, $bankAccountId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : $this->cast($row);
    }

    /**
     * Řádek registru vlastních účtů firmy pro číslo účtu + kód banky (i neaktivní a jiného
     * druhu) - import podle něj pozná, jestli účet už firma eviduje.
     *
     * @return array<string,mixed>|null
     */
    public function findRegistryRow(int $supplierId, string $accountNumber, ?string $bankCode): ?array
    {
        $canonical = AccountNumberNormalizer::canonical($accountNumber);
        if ($canonical === null) {
            return null;
        }
        $bank = AccountNumberNormalizer::canonicalBankCode($bankCode) ?? '';
        $stmt = $this->db->pdo()->prepare(
            'SELECT * FROM supplier_bank_accounts WHERE supplier_id = ? AND account_canonical = ? AND bank_code_norm = ?'
        );
        $stmt->execute([$supplierId, $canonical, $bank]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : $row;
    }

    /**
     * Zaeviduje úvěrový účet: řádek registru vlastních účtů (kind = credit_card) a údaje
     * úvěrového účtu. Existující řádek registru BEZ historie převezme (přepne druh).
     *
     * @param array{issuer:string, label:string, account_number:string, bank_code:?string, currency:string,
     *   credit_limit:?float, repayment_account?:?string, repayment_bank_code?:?string, repayment_vs?:?string,
     *   is_verified:bool} $data
     */
    public function create(int $supplierId, array $data, ?int $userId): int
    {
        $pdo = $this->db->pdo();
        $canonical = AccountNumberNormalizer::canonical($data['account_number']);
        if ($canonical === null) {
            throw new \InvalidArgumentException('Číslo úvěrového účtu nejde rozpoznat.');
        }
        $bank = AccountNumberNormalizer::canonicalBankCode($data['bank_code']);
        $pdo->prepare(
            "INSERT INTO supplier_bank_accounts
                (supplier_id, label, account_number, bank_code, bank_code_norm, currency, account_canonical, kind, source, is_active)
             VALUES (?, ?, ?, ?, ?, ?, ?, 'credit_card', 'statement', 1)
             ON DUPLICATE KEY UPDATE kind = 'credit_card', is_active = 1,
                label = IF(label IS NULL OR label = '' OR label LIKE 'Účet %', VALUES(label), label),
                currency = COALESCE(currency, VALUES(currency))"
        )->execute([
            $supplierId,
            mb_substr($data['label'], 0, 120),
            $data['account_number'],
            $bank,
            $bank ?? '',
            $data['currency'],
            $canonical,
        ]);
        $row = $this->findRegistryRow($supplierId, $data['account_number'], $data['bank_code']);
        if ($row === null) {
            throw new \RuntimeException('Úvěrový účet se nepodařilo zaevidovat.');
        }
        $pdo->prepare(
            'INSERT INTO credit_card_accounts
                (supplier_id, bank_account_id, issuer, label, account_number, bank_code, currency, credit_limit,
                 repayment_account, repayment_bank_code, repayment_vs, is_verified, created_by)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        )->execute([
            $supplierId,
            (int) $row['id'],
            in_array($data['issuer'], self::ISSUERS, true) ? $data['issuer'] : 'other',
            mb_substr($data['label'], 0, 120),
            $data['account_number'],
            $bank,
            $data['currency'],
            $data['credit_limit'],
            $data['repayment_account'] ?? null,
            $data['repayment_bank_code'] ?? null,
            $data['repayment_vs'] ?? null,
            $data['is_verified'] ? 1 : 0,
            $userId,
        ]);
        return (int) $pdo->lastInsertId();
    }

    /**
     * Doplní údaje, které přinesl výpis (limit, účet a VS pro splátku) - jen prázdné,
     * ručně vyplněné hodnoty import nepřepisuje.
     *
     * @param array<string,mixed> $data
     */
    public function fillFromStatement(int $supplierId, int $id, array $data): void
    {
        $this->db->pdo()->prepare(
            'UPDATE credit_card_accounts
                SET credit_limit = COALESCE(credit_limit, ?),
                    repayment_account = COALESCE(repayment_account, ?),
                    repayment_bank_code = COALESCE(repayment_bank_code, ?),
                    repayment_vs = COALESCE(repayment_vs, ?)
              WHERE id = ? AND supplier_id = ?'
        )->execute([
            $data['credit_limit'] ?? null,
            $data['repayment_account'] ?? null,
            $data['repayment_bank_code'] ?? null,
            $data['repayment_vs'] ?? null,
            $id,
            $supplierId,
        ]);
    }

    /**
     * Úprava údajů z formuláře (= ověření účtu člověkem).
     *
     * @param array{label:string, credit_limit:?float, repayment_account:?string, repayment_bank_code:?string,
     *   repayment_vs:?string, note:?string} $data
     */
    public function update(int $supplierId, int $id, array $data): void
    {
        $this->db->pdo()->prepare(
            'UPDATE credit_card_accounts
                SET label = ?, credit_limit = ?, repayment_account = ?, repayment_bank_code = ?, repayment_vs = ?,
                    note = ?, is_verified = 1
              WHERE id = ? AND supplier_id = ?'
        )->execute([
            mb_substr($data['label'], 0, 120),
            $data['credit_limit'],
            $data['repayment_account'],
            $data['repayment_bank_code'],
            $data['repayment_vs'],
            $data['note'],
            $id,
            $supplierId,
        ]);
        $this->db->pdo()->prepare(
            'UPDATE supplier_bank_accounts sba
               JOIN credit_card_accounts cca ON cca.bank_account_id = sba.id AND cca.supplier_id = sba.supplier_id
                SET sba.label = ?
              WHERE cca.id = ? AND cca.supplier_id = ?'
        )->execute([mb_substr($data['label'], 0, 120), $id, $supplierId]);
    }

    public function archive(int $supplierId, int $id): void
    {
        $this->db->pdo()->prepare(
            'UPDATE credit_card_accounts SET archived_at = NOW() WHERE id = ? AND supplier_id = ? AND archived_at IS NULL'
        )->execute([$id, $supplierId]);
    }

    public function restore(int $supplierId, int $id): void
    {
        $this->db->pdo()->prepare('UPDATE credit_card_accounts SET archived_at = NULL WHERE id = ? AND supplier_id = ?')
            ->execute([$id, $supplierId]);
    }

    /** Přidělí suffix analytiky 231, jen když ho účet ještě nemá (souběh řeší unikátní index). */
    public function assignSuffixIfEmpty(int $supplierId, int $id, string $suffix): bool
    {
        try {
            $stmt = $this->db->pdo()->prepare(
                'UPDATE credit_card_accounts SET analytic_suffix = ?
                  WHERE id = ? AND supplier_id = ? AND analytic_suffix IS NULL'
            );
            $stmt->execute([$suffix, $id, $supplierId]);
            return $stmt->rowCount() > 0;
        } catch (\PDOException $e) {
            if (($e->errorInfo[0] ?? null) === '23000') {
                return false;
            }
            throw $e;
        }
    }

    public function setAnalyticSuffix(int $supplierId, int $id, string $suffix): void
    {
        $this->db->pdo()->prepare('UPDATE credit_card_accounts SET analytic_suffix = ? WHERE id = ? AND supplier_id = ?')
            ->execute([$suffix, $id, $supplierId]);
    }

    /** @return array<string,int> suffix → id úvěrového účtu (i archivovaného - číslo si drží dál) */
    public function usedSuffixes(int $supplierId): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT analytic_suffix, id FROM credit_card_accounts WHERE supplier_id = ? AND analytic_suffix IS NOT NULL'
        );
        $stmt->execute([$supplierId]);
        $out = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $r) {
            $out[(string) $r['analytic_suffix']] = (int) $r['id'];
        }
        return $out;
    }

    /**
     * Úvěrové účty firmy, které se splácí na daný účet (RB: sběrný účet banky + VS).
     *
     * @return list<array<string,mixed>>
     */
    public function findByRepaymentTarget(int $supplierId, string $account, ?string $bankCode): array
    {
        $canonical = AccountNumberNormalizer::canonical($account);
        if ($canonical === null) {
            return [];
        }
        $bank = AccountNumberNormalizer::canonicalBankCode($bankCode);
        $out = [];
        foreach ($this->listForSupplier($supplierId) as $row) {
            if ($row['repayment_account'] === null || AccountNumberNormalizer::canonical($row['repayment_account']) !== $canonical) {
                continue;
            }
            $rowBank = AccountNumberNormalizer::canonicalBankCode($row['repayment_bank_code']);
            if ($bank !== null && $rowBank !== null && $bank !== $rowBank) {
                continue;
            }
            $out[] = $row;
        }
        return $out;
    }

    /** @param array<string,mixed> $r */
    private function cast(array $r): array
    {
        return [
            'id'                  => (int) $r['id'],
            'supplier_id'         => (int) $r['supplier_id'],
            'bank_account_id'     => (int) $r['bank_account_id'],
            'issuer'              => (string) $r['issuer'],
            'label'               => (string) $r['label'],
            'account_number'      => (string) $r['account_number'],
            'bank_code'           => $r['bank_code'] !== null ? (string) $r['bank_code'] : null,
            'currency'            => (string) $r['currency'],
            'credit_limit'        => $r['credit_limit'] !== null ? round((float) $r['credit_limit'], 2) : null,
            'analytic_suffix'     => $r['analytic_suffix'] !== null ? (string) $r['analytic_suffix'] : null,
            'repayment_account'   => $r['repayment_account'] !== null ? (string) $r['repayment_account'] : null,
            'repayment_bank_code' => $r['repayment_bank_code'] !== null ? (string) $r['repayment_bank_code'] : null,
            'repayment_vs'        => $r['repayment_vs'] !== null ? (string) $r['repayment_vs'] : null,
            'is_verified'         => (bool) $r['is_verified'],
            'archived'            => $r['archived_at'] !== null,
            'archived_at'         => $r['archived_at'] !== null ? (string) $r['archived_at'] : null,
            'note'                => $r['note'] !== null ? (string) $r['note'] : null,
            'created_at'          => (string) $r['created_at'],
            'account_canonical'   => (string) $r['account_canonical'],
            'bank_code_norm'      => (string) $r['bank_code_norm'],
        ];
    }
}
