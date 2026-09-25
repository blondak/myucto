<?php

declare(strict_types=1);

namespace MyInvoice\Service\Accounting\CreditCard;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\ChartOfAccountsRepository;
use MyInvoice\Repository\CreditCardAccountRepository;
use MyInvoice\Service\Accounting\PostingException;
use PDO;

/**
 * Analytiky 231 úvěrových účtů kreditních karet (231.101, 231.102 …) - jediné místo, které
 * rozhoduje, jakou analytiku úvěrový účet dostane a jak se jmenuje.
 *
 * Úvěrový účet kreditní karty je krátkodobý úvěr: dluh vůči bance roste nákupem kartou
 * a klesá splátkou. Vlastní noha každého pohybu z kreditního výpisu proto nejde na banku
 * (221.x), ale sem. Přesměrování dělá {@see \MyInvoice\Service\Accounting\Bank\BankAnalyticResolver}
 * pro účty druhu `credit_card` - pravidla, párování i detektory dál pracují s `221` jako
 * „účtem výpisu", takže se pro kreditní karty nic z bankovní automatiky neduplikuje.
 *
 * Přidělování postupně od 101, analytiku s cizí historií (řádky v deníku) si účet nepřivlastní - na 231
 * typicky už leží bankovní úvěry. Ruční výběr analytiky v detailu úvěrového účtu má přednost.
 */
final class CreditCardAccounts
{
    public const SYNTHETIC = '231';

    public function __construct(
        private readonly Connection $db,
        private readonly CreditCardAccountRepository $accounts,
        private readonly ChartOfAccountsRepository $chart,
    ) {}

    public static function codeFor(string $suffix): string
    {
        return self::SYNTHETIC . '.' . $suffix;
    }

    /** Název analytiky v osnově: „Kreditní karta - <název účtu>". */
    public static function accountName(array $account): string
    {
        $label = trim((string) ($account['label'] ?? ''));
        return 'Kreditní karta' . ($label !== '' ? ' - ' . $label : ' ' . (string) ($account['account_number'] ?? ''));
    }

    /** @return list<string> kandidáti v pořadí přidělování (101 … 998) */
    public static function candidateSuffixes(): array
    {
        return array_map('strval', range(101, 998));
    }

    /**
     * Kód analytiky úvěrového účtu; chybějící suffix přidělí a chybějící účet dohraje do
     * osnovy (obojí idempotentně). Null jen když syntetika 231 v osnově není nebo nezbylo
     * volné číslo.
     *
     * @param array<string,mixed> $account řádek z CreditCardAccountRepository
     */
    public function ensureAnalytic(int $supplierId, array $account): ?string
    {
        if ($this->chart->findByCode($supplierId, self::SYNTHETIC) === null) {
            return null;
        }
        $suffix = $account['analytic_suffix'] ?? null;
        if (!is_string($suffix) || $suffix === '') {
            $suffix = $this->nextFreeSuffix($supplierId);
            if ($suffix === null) {
                return null;
            }
            if (!$this->accounts->assignSuffixIfEmpty($supplierId, (int) $account['id'], $suffix)) {
                $fresh = $this->accounts->find($supplierId, (int) $account['id']);
                $suffix = $fresh['analytic_suffix'] ?? null;
                if (!is_string($suffix) || $suffix === '') {
                    return null;
                }
            }
        }
        return $this->ensureChartAccount($supplierId, $suffix, self::accountName($account));
    }

    /**
     * Analytika vlastní nohy pohybu z výpisu úvěrového účtu (volá BankAnalyticResolver pro
     * řádek registru druhu credit_card). Když účet ještě nemá údaje úvěrového účtu (druh
     * přepnutý jinudy), doplní je. Bez syntetiky 231 v osnově zaúčtování ODMÍTNE - pohyb
     * kreditní karty nesmí propadnout na banku 221.
     *
     * @param array<string,mixed> $bankAccount řádek supplier_bank_accounts
     */
    public function codeForBankAccount(int $supplierId, array $bankAccount): string
    {
        $account = $this->accounts->findByBankAccount($supplierId, (int) $bankAccount['id']);
        if ($account === null) {
            $id = $this->accounts->create($supplierId, [
                'issuer'         => 'other',
                'label'          => (string) (($bankAccount['label'] ?? '') ?: ('Kreditní karta ' . (string) $bankAccount['account_number'])),
                'account_number' => (string) $bankAccount['account_number'],
                'bank_code'      => $bankAccount['bank_code'] !== null ? (string) $bankAccount['bank_code'] : null,
                'currency'       => (string) (($bankAccount['currency'] ?? '') ?: 'CZK'),
                'credit_limit'   => null,
                'is_verified'    => false,
            ], null);
            $account = $this->accounts->find($supplierId, $id);
        }
        $code = $account !== null ? $this->ensureAnalytic($supplierId, $account) : null;
        if ($code === null) {
            throw new PostingException(
                'credit_card_account_unavailable',
                'Pohyb kreditní karty nejde zaúčtovat: v účtové osnově chybí účet 231 (krátkodobé úvěry) nebo volná analytika.',
                422,
            );
        }
        return $code;
    }

    /**
     * Analytika pro NÁHLED - nic nezakládá. Bez přidělené analytiky vrací syntetiku 231,
     * ať náhled neukazuje banku 221.
     *
     * @param array<string,mixed> $bankAccount řádek supplier_bank_accounts
     */
    public function existingCodeForBankAccount(int $supplierId, array $bankAccount): string
    {
        $account = $this->accounts->findByBankAccount($supplierId, (int) $bankAccount['id']);
        $suffix = $account['analytic_suffix'] ?? null;
        return is_string($suffix) && $suffix !== '' ? self::codeFor($suffix) : self::SYNTHETIC;
    }

    /**
     * Ruční výběr analytiky v detailu úvěrového účtu. Existující analytika 231 smí mít cizí
     * historii jen tehdy, když ji uživatel vybere výslovně (mapování na hotovou analytiku).
     */
    public function assignManually(int $supplierId, int $accountId, string $code): string
    {
        $prefix = self::SYNTHETIC . '.';
        $suffix = str_starts_with($code, $prefix) ? substr($code, strlen($prefix)) : '';
        if (preg_match('/^[0-9]{1,6}$/', $suffix) !== 1) {
            throw new PostingException('invalid_analytic', 'Analytika musí být analytikou účtu 231.', 422, ['field' => 'account_code']);
        }
        $owner = $this->accounts->usedSuffixes($supplierId)[$suffix] ?? null;
        if ($owner !== null && $owner !== $accountId) {
            throw new PostingException('analytic_taken', 'Analytiku ' . $code . ' už používá jiný úvěrový účet.', 409, ['field' => 'account_code']);
        }
        $account = $this->accounts->find($supplierId, $accountId);
        if ($account === null) {
            throw new PostingException('not_found', 'Úvěrový účet nenalezen.', 404);
        }
        $old = $account['analytic_suffix'];
        if ($old !== null && $old !== $suffix && abs($this->balance($supplierId, self::codeFor($old))) >= 0.005) {
            throw new PostingException(
                'analytic_has_balance',
                'Stávající analytika ' . self::codeFor($old) . ' má zůstatek - změna by rozdělila dluh na dva účty. Zůstatek nejdřív převeďte.',
                409,
            );
        }
        $this->accounts->setAnalyticSuffix($supplierId, $accountId, $suffix);
        return $this->ensureChartAccount($supplierId, $suffix, self::accountName($account));
    }

    /**
     * Existující analytiky 231 v osnově (nabídka ručního výběru).
     *
     * @return list<array{id:int, account_code:string, name:string, credit_card_account_id:?int}>
     */
    public function analyticOptions(int $supplierId): array
    {
        $used = $this->accounts->usedSuffixes($supplierId);
        $stmt = $this->db->pdo()->prepare(
            "SELECT id, account_code, name FROM chart_of_accounts
              WHERE supplier_id = ? AND is_active = 1 AND account_code LIKE '231.%' ORDER BY account_code"
        );
        $stmt->execute([$supplierId]);
        $out = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $r) {
            $suffix = substr((string) $r['account_code'], 4);
            if (preg_match('/^[0-9]{1,6}$/', $suffix) !== 1) {
                continue;
            }
            $out[] = [
                'id'                     => (int) $r['id'],
                'account_code'           => (string) $r['account_code'],
                'name'                   => (string) $r['name'],
                'credit_card_account_id' => $used[$suffix] ?? null,
            ];
        }
        return $out;
    }

    /** Zůstatek účtu (MD − D) ze zaúčtovaných zápisů k datu (storna se vyruší). */
    public function balance(int $supplierId, string $code, ?string $asOf = null): float
    {
        $stmt = $this->db->pdo()->prepare(
            "SELECT COALESCE(SUM(CASE WHEN l.side = 'debit' THEN l.amount ELSE -l.amount END), 0)
               FROM journal_entry_lines l
               JOIN journal_entries e ON e.id = l.entry_id AND e.supplier_id = l.supplier_id
               JOIN chart_of_accounts a ON a.id = l.account_id AND a.supplier_id = l.supplier_id
              WHERE l.supplier_id = ? AND a.account_code = ? AND e.posted_at IS NOT NULL
                AND (? IS NULL OR e.entry_date <= ?)"
        );
        $stmt->execute([$supplierId, $code, $asOf, $asOf]);
        return round((float) $stmt->fetchColumn(), 2);
    }

    /** První volný suffix, nebo null. */
    public function nextFreeSuffix(int $supplierId): ?string
    {
        $taken = $this->accounts->usedSuffixes($supplierId);
        $stmt = $this->db->pdo()->prepare(
            "SELECT c.account_code, c.is_active,
                    EXISTS (SELECT 1 FROM journal_entry_lines jel
                             WHERE jel.supplier_id = c.supplier_id AND jel.account_id = c.id) AS has_lines
               FROM chart_of_accounts c
              WHERE c.supplier_id = ? AND c.account_code REGEXP '^231[.]?[0-9]{1,6}$'"
        );
        $stmt->execute([$supplierId]);
        $state = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
            $suffix = ltrim(substr((string) $row['account_code'], 3), '.');
            $clean = (bool) $row['is_active'] && !(bool) $row['has_lines'];
            $state[$suffix] = ($state[$suffix] ?? true) && $clean;
        }
        foreach (self::candidateSuffixes() as $suffix) {
            if (isset($taken[$suffix])) {
                continue;
            }
            if (!isset($state[$suffix]) || $state[$suffix]) {
                return $suffix;
            }
        }
        return null;
    }

    private function ensureChartAccount(int $supplierId, string $suffix, string $name): string
    {
        $code = self::codeFor($suffix);
        if ($this->chart->findByCode($supplierId, $code) !== null) {
            return $code;
        }
        $parent = $this->chart->findByCode($supplierId, self::SYNTHETIC);
        $this->chart->insert($supplierId, [
            'account_code' => $code,
            'name'         => mb_substr($name, 0, 190),
            'account_type' => $parent['account_type'] ?? 'liability',
            'normal_side'  => $parent['normal_side'] ?? 'credit',
            'is_synthetic' => false,
            'parent_id'    => $parent !== null ? (int) $parent['id'] : null,
            'is_active'    => true,
        ]);
        return $code;
    }
}
