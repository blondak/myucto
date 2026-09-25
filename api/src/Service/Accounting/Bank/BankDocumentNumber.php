<?php

declare(strict_types=1);

namespace MyInvoice\Service\Accounting\Bank;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\BankStatementOwnershipResolver;
use MyInvoice\Repository\SupplierBankAccountRepository;
use PDO;

/**
 * Číslo dokladu bankovního zápisu v deníku (SSOT).
 *
 * Účetní vede bankovní doklad jako „dokladová řada účtu + pořadové číslo měsíčního
 * výpisu": BCR-08 je srpnový výpis běžného CZK účtu, za rok jich je dvanáct. Rok dává
 * účetní období, do čísla se nepíše.
 *
 * Číslo NESMÍ záviset na výpisu, ze kterého pohyb přišel. Výpis jde smazat a načíst
 * znovu, bankovní API tahá pohyby po dnech a později přijde identický měsíční výpis.
 * Pořadové číslo výpisu je proto měsíc data zápisu a řada patří účtu, ne výpisu:
 * stejný pohyb dostane po každém novém importu stejné číslo.
 *
 * Číslo nastavuje {@see \MyInvoice\Service\Accounting\PostingService::postDocument()}
 * každému zápisu se zdrojem z {@see self::SOURCE_TYPES} a id pohybu, bez ohledu na to,
 * co poslal volající. Výjimkou je přeúčtování zápisu převzatého z jiného programu, který
 * si nechává číslo dokladu zdroje. Storno přebírá číslo stornovaného zápisu s předponou STORNO. Původní ID pohybu z banky
 * zůstává v bank_transactions.bank_ref a deník podle něj dál vyhledává.
 *
 * Pohyb, jehož výpis nepatří žádnému evidovanému účtu firmy, dostane dosavadní číslo
 * (ID pohybu z banky, jinak BANK-<id>) — bez účtu není řada, ze které by šlo číslovat.
 */
final class BankDocumentNumber
{
    public const SERIES_PATTERN = '/^[A-Z0-9]{1,10}$/';

    /** Zdroje zápisů, jejichž dokladem je bankovní výpis; source_id je id pohybu. */
    public const SOURCE_TYPES = ['bank'];

    /** Mapy převodů z jiných účetních programů; zápis v nich nese číslo dokladu zdroje. */
    private const TAKEOVER_MAPS = ['money_s3_import_map', 'pohoda_import_map', 'premier_import_map'];

    private const LEGACY_PREFIX = 'BANK-';

    private SupplierBankAccountRepository $accounts;

    /** @var array<string, array<string,mixed>|null> vlastní účet podle výpisu, cache v rámci requestu */
    private array $accountCache = [];

    public function __construct(private readonly Connection $db, ?SupplierBankAccountRepository $accounts = null)
    {
        $this->accounts = $accounts ?? new SupplierBankAccountRepository($db, new BankStatementOwnershipResolver($db));
    }

    public static function numbersSource(string $sourceType): bool
    {
        return in_array($sourceType, self::SOURCE_TYPES, true);
    }

    /**
     * SQL podmínka „zápis převzatý z jiného účetního programu". Převod zapisuje deník
     * s číslem dokladu zdroje a teprve pak zápis naváže na převzatý pohyb (source_id),
     * takže podle zdroje ho od vlastního bankovního zápisu nerozlišíš. Číslo dokladu
     * převzatého zápisu je vazba na doklad v původním programu a nepřečíslovává se.
     */
    public static function takenOverSql(string $alias): string
    {
        return '(' . implode(' OR ', array_map(
            static fn (string $map): string => "EXISTS (SELECT 1 FROM {$map} tom
                WHERE tom.supplier_id = {$alias}.supplier_id AND tom.kind = 'journal_entry' AND tom.target_id = {$alias}.id)",
            self::TAKEOVER_MAPS,
        )) . ')';
    }

    public function isTakenOver(int $supplierId, int $entryId): bool
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT ' . self::takenOverSql('je') . ' FROM journal_entries je WHERE je.id = ? AND je.supplier_id = ?'
        );
        $stmt->execute([$entryId, $supplierId]);
        return (bool) $stmt->fetchColumn();
    }

    public static function isValidSeries(mixed $series): bool
    {
        return is_string($series) && preg_match(self::SERIES_PATTERN, $series) === 1;
    }

    public static function normalizeSeries(string $series): string
    {
        return strtoupper(trim($series));
    }

    /** Číslo dokladu z řady a data zápisu: BCR + 2026-08-14 → BCR-08. */
    public static function format(string $series, string $entryDate): string
    {
        return $series . '-' . substr($entryDate, 5, 2);
    }

    /**
     * Výchozí řada podle druhu a měny účtu. Migrace 1896 počítá totéž v SQL.
     */
    public static function defaultBase(?string $kind, ?string $currency): string
    {
        $currency = strtoupper(trim((string) $currency));
        return match ($kind) {
            'savings'      => 'BCS',
            'term_deposit' => 'BCT',
            'credit_card'  => 'BCK',
            default        => ($currency === '' || $currency === 'CZK') ? 'BCR' : 'BC' . substr($currency, 0, 1),
        };
    }

    /**
     * Dosavadní číslo bankovního zápisu: ID pohybu z banky, jinak BANK-<id>.
     *
     * @param array<string,mixed> $tx
     */
    public static function legacy(array $tx): string
    {
        $ref = trim((string) ($tx['bank_ref'] ?? ''));
        return $ref !== '' ? mb_substr($ref, 0, 50) : self::LEGACY_PREFIX . (int) ($tx['id'] ?? 0);
    }

    /** Id pohybu z technického čísla BANK-<id>, jinak null. */
    public static function legacyTxId(?string $documentNo): ?int
    {
        if ($documentNo === null || preg_match('/^' . self::LEGACY_PREFIX . '([1-9][0-9]*)$/', $documentNo, $m) !== 1) {
            return null;
        }
        return (int) $m[1];
    }

    /** Číslo dokladu zápisu bankovního pohybu k datu zápisu. */
    public function forTransaction(int $supplierId, int $txId, string $entryDate): string
    {
        $tx = $this->loadTx($txId);
        if ($tx === null) {
            return self::LEGACY_PREFIX . $txId;
        }
        return $this->numberForTx($supplierId, $tx, $entryDate, true) ?? self::legacy($tx);
    }

    /**
     * Číslo v řadě účtu, nebo null, když pohyb neexistuje nebo jeho výpis nepatří
     * evidovanému účtu firmy. Bez `$assign` nic nezapisuje: účtu bez řady jen spočítá,
     * jakou by dostal (náhled přečíslování).
     */
    public function seriesNumber(int $supplierId, int $txId, string $entryDate, bool $assign = true): ?string
    {
        $tx = $this->loadTx($txId);
        return $tx === null ? null : $this->numberForTx($supplierId, $tx, $entryDate, $assign);
    }

    /** @param array<string,mixed> $tx */
    private function numberForTx(int $supplierId, array $tx, string $entryDate, bool $assign): ?string
    {
        $series = $this->seriesForStatementAccount(
            $supplierId,
            (string) ($tx['statement_account'] ?? ''),
            $tx['statement_bank'] === null ? null : (string) $tx['statement_bank'],
            $assign,
        );
        return $series === null ? null : self::format($series, $entryDate);
    }

    /**
     * Řada vlastního účtu, na který byl vystaven výpis; chybějící řadu účtu přidělí.
     * Null, když výpis nepatří žádnému evidovanému účtu firmy.
     */
    public function seriesForStatementAccount(int $supplierId, string $accountNumber, ?string $bankCode, bool $assign = true): ?string
    {
        $accountNumber = trim($accountNumber);
        if ($accountNumber === '') {
            return null;
        }
        $bankCode = $bankCode === null || trim($bankCode) === '' ? null : trim($bankCode);
        $key = $supplierId . '|' . $accountNumber . '|' . ($bankCode ?? '');
        if (!array_key_exists($key, $this->accountCache)) {
            // I neaktivní účet: řada patří jeho zápisům dál, jinak by přeúčtování pohybu
            // zrušeného účtu vrátilo zápisu ID pohybu z banky.
            $this->accountCache[$key] = $this->accounts->matchCounterparty($supplierId, $accountNumber, $bankCode, true);
        }
        $account = $this->accountCache[$key];
        if ($account === null) {
            return null;
        }
        if (!$assign && !self::isValidSeries($account['document_series'] ?? null)) {
            return $this->nextFreeSeries($supplierId, self::defaultBase(
                isset($account['kind']) ? (string) $account['kind'] : null,
                isset($account['currency']) ? (string) $account['currency'] : null,
            ));
        }
        $series = $this->ensureSeries($supplierId, $account);
        $this->accountCache[$key]['document_series'] = $series;
        return $series;
    }

    /**
     * Řada účtu — existující se nepřepisuje, chybějící dostane první volnou z výchozí
     * řady (BCR, BCR2 …).
     *
     * @param array<string,mixed> $account řádek supplier_bank_accounts
     */
    public function ensureSeries(int $supplierId, array $account): string
    {
        $existing = $account['document_series'] ?? null;
        if (self::isValidSeries($existing)) {
            return (string) $existing;
        }
        $id = (int) ($account['id'] ?? 0);
        $base = self::defaultBase(
            isset($account['kind']) ? (string) $account['kind'] : null,
            isset($account['currency']) ? (string) $account['currency'] : null,
        );
        for ($attempt = 0; $attempt < 3; $attempt++) {
            $candidate = $this->nextFreeSeries($supplierId, $base);
            if ($this->accounts->assignDocumentSeries($supplierId, $id, $candidate)) {
                return $candidate;
            }
            $fresh = $this->accounts->find($supplierId, $id);
            if (self::isValidSeries($fresh['document_series'] ?? null)) {
                return (string) $fresh['document_series'];
            }
        }
        throw new \RuntimeException('Bankovnímu účtu #' . $id . ' nejde přidělit dokladovou řadu.');
    }

    /**
     * Dohraje řadu všem účtům firmy, které ji ještě nemají.
     *
     * @return int počet nově přidělených řad
     */
    public function ensureAllForSupplier(int $supplierId): int
    {
        $assigned = 0;
        foreach ($this->accounts->listForSupplier($supplierId) as $account) {
            if (self::isValidSeries($account['document_series'] ?? null)) {
                continue;
            }
            $this->ensureSeries($supplierId, $account);
            $assigned++;
        }
        return $assigned;
    }

    public function nextFreeSeries(int $supplierId, string $base): string
    {
        $taken = $this->accounts->usedDocumentSeries($supplierId);
        for ($n = 1; ; $n++) {
            $candidate = $n === 1 ? $base : $base . $n;
            if (!isset($taken[$candidate])) {
                return $candidate;
            }
        }
    }

    /** @return array<string,mixed>|null */
    private function loadTx(int $txId): ?array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT bt.id, bt.bank_ref, bs.account_number AS statement_account, bs.bank_code AS statement_bank
               FROM bank_transactions bt
               JOIN bank_statements bs ON bs.id = bt.statement_id
              WHERE bt.id = ?'
        );
        $stmt->execute([$txId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : $row;
    }
}
