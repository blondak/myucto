<?php

declare(strict_types=1);

namespace MyInvoice\Service\Accounting\Bank;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\ChartOfAccountsRepository;
use MyInvoice\Repository\SupplierBankAccountRepository;

/**
 * Přidělování analytiky 221<suffix> vlastním bankovním účtům (SSOT).
 *
 * Účetní požadavek: KAŽDÝ bankovní účet firmy má vlastní analytiku (221100, 221200…),
 * ne společné ploché 221. Bez toho leží na jednom účtu několik reálných účtů, zůstatek
 * syntetiky nesedí na žádný výpis, inventarizace k rozvahovému dni (§ 29/30 ZoÚ) se
 * nedá doložit a cizoměnová pozice se navíc promíchá (viz {@see BankAnalyticResolver}).
 *
 * Tahle třída drží JEDINÉ místo, které rozhoduje, JAKÉ číslo účet dostane:
 *   1. tier — násobky sta: 100, 200 … 900  (221100, 221200 … 221900)
 *   2. tier — násobky deseti: 010, 020 … 990
 *   3. tier — zbytek: 001 … 999
 * Bere se první VOLNÝ kandidát, přičemž volný znamená:
 *   - nedrží ho jiný bankovní účet téže firmy (ani neaktivní), a
 *   - v osnově buď vůbec neexistuje, nebo existuje jako aktivní analytika BEZ jediného
 *     řádku v deníku.
 * Druhá podmínka je záměrná pojistka proti adopci cizí historie: analytika, na které
 * už něco leží (typicky ručně vedený termínovaný vklad na 221100), se automaticky
 * NEPŘIDĚLÍ — jinak by bankovní účet zdědil zůstatek, který mu nepatří. Namapovat ji
 * na konkrétní účet jde ručně v nastavení (tam je to vědomé rozhodnutí uživatele).
 *
 * Stejný koncept pro pokladny žije v CashRegisterService::nextFreeCashAnalytic()
 * (211xxx) — odlišnost je jen v syntetice a v tom, že pokladna analytiku dostává
 * povinně až u valutové, kdežto banka ji má mít vždy.
 */
final class BankAnalyticAssigner
{
    /** Syntetický účet banky, pod který analytiky patří. */
    public const BANK_SYNTHETIC = '221';

    /** Povolený tvar suffixu (sdílený s FE i API validací). */
    public const SUFFIX_PATTERN = '/^[0-9]{1,6}$/';

    public function __construct(
        private readonly Connection $db,
        private readonly SupplierBankAccountRepository $bankAccounts,
        private readonly ChartOfAccountsRepository $chart,
    ) {}

    /**
     * Kandidáti v pořadí přidělování. Deterministické a bez závislosti na datech —
     * SQL backfill v migraci 1318 generuje TOTÉŽ pořadí (tier, číslo).
     *
     * @return list<string>
     */
    public static function candidateSuffixes(): array
    {
        $out = [];
        for ($n = 1; $n <= 9; $n++) {
            $out[] = (string) ($n * 100);
        }
        for ($n = 1; $n <= 99; $n++) {
            $out[] = str_pad((string) ($n * 10), 3, '0', STR_PAD_LEFT);
        }
        for ($n = 1; $n <= 999; $n++) {
            $out[] = str_pad((string) $n, 3, '0', STR_PAD_LEFT);
        }
        return array_values(array_unique($out));
    }

    public static function isValidSuffix(mixed $suffix): bool
    {
        return is_string($suffix) && preg_match(self::SUFFIX_PATTERN, $suffix) === 1;
    }

    public static function codeFor(string $suffix): string
    {
        return self::BANK_SYNTHETIC . $suffix;
    }

    /**
     * Suffix bankovního účtu — existující se NIKDY nepřepisuje, chybějící se přidělí.
     * Vrací null jen když nezbylo volné číslo (1000+ analytik).
     *
     * @param array<string,mixed> $account řádek supplier_bank_accounts
     */
    public function ensureSuffix(int $supplierId, array $account): ?string
    {
        $existing = $account['analytic_suffix'] ?? null;
        if (self::isValidSuffix($existing)) {
            /** @var string $existing */
            return $existing;
        }
        $id = (int) ($account['id'] ?? 0);
        if ($id <= 0) {
            return null;
        }
        $suffix = $this->nextFreeSuffix($supplierId);
        if ($suffix === null) {
            return null;
        }
        // Souběh (dva importy nad týmž účtem) řeší unique index (supplier_id, analytic_suffix)
        // + podmínka „jen když je ještě prázdný". Kdo prohraje, přečte si vítězovu hodnotu.
        if ($this->bankAccounts->assignSuffix($supplierId, $id, $suffix)) {
            return $suffix;
        }
        $fresh = $this->bankAccounts->find($supplierId, $id);
        $current = $fresh['analytic_suffix'] ?? null;
        return self::isValidSuffix($current) ? (string) $current : null;
    }

    /**
     * Dohraje analytiku do osnovy, pokud chybí — jinak by postDocument spadl na
     * `unknown_account`. Dědí typ/stranu ze syntetiky 221. Idempotentní.
     */
    public function ensureChartAccount(int $supplierId, string $suffix, string $label = ''): string
    {
        $code = self::codeFor($suffix);
        if ($this->chart->findByCode($supplierId, $code) !== null) {
            return $code;
        }
        $parent = $this->chart->findByCode($supplierId, self::BANK_SYNTHETIC);
        $name = trim($label);
        $this->chart->insert($supplierId, [
            'account_code' => $code,
            'name'         => $name !== '' ? $name : ('Bankovní účet ' . $code),
            'account_type' => $parent['account_type'] ?? 'asset',
            'normal_side'  => $parent['normal_side'] ?? 'debit',
            'is_synthetic' => false,
            'parent_id'    => $parent !== null ? (int) $parent['id'] : null,
            'is_active'    => true,
        ]);
        return $code;
    }

    /**
     * Dohraje analytiku všem aktivním účtům firmy, které ji ještě nemají. Bez syntetiky
     * 221 v osnově (firma bez podvojného účetnictví) nedělá nic — zakládat analytiky
     * účtu, který v osnově není, nedává smysl.
     *
     * @return int počet nově přidělených analytik
     */
    public function ensureAllForSupplier(int $supplierId): int
    {
        if ($this->chart->findByCode($supplierId, self::BANK_SYNTHETIC) === null) {
            return 0;
        }
        $assigned = 0;
        foreach ($this->bankAccounts->findActive($supplierId) as $account) {
            if (self::isValidSuffix($account['analytic_suffix'] ?? null)) {
                continue;
            }
            $suffix = $this->ensureSuffix($supplierId, $account);
            if ($suffix === null) {
                continue;
            }
            $this->ensureChartAccount($supplierId, $suffix, (string) ($account['label'] ?? ''));
            $assigned++;
        }
        return $assigned;
    }

    /** První volný suffix dle pořadí {@see candidateSuffixes()}, nebo null. */
    public function nextFreeSuffix(int $supplierId): ?string
    {
        $taken = $this->bankAccounts->usedSuffixes($supplierId);
        $chart = $this->chartState($supplierId);
        foreach (self::candidateSuffixes() as $suffix) {
            if (isset($taken[$suffix])) {
                continue;
            }
            $state = $chart[self::codeFor($suffix)] ?? null;
            if ($state === null) {
                return $suffix;
            }
            // Existující analytika se smí adoptovat jen když je aktivní a prázdná.
            if ($state['is_active'] && !$state['has_lines']) {
                return $suffix;
            }
        }
        return null;
    }

    /**
     * Stav analytik pod 221 v osnově firmy: kód → [aktivní?, má řádky v deníku?].
     *
     * @return array<string, array{is_active:bool, has_lines:bool}>
     */
    private function chartState(int $supplierId): array
    {
        $stmt = $this->db->pdo()->prepare(
            "SELECT c.account_code,
                    c.is_active,
                    EXISTS (SELECT 1 FROM journal_entry_lines jel
                             WHERE jel.supplier_id = c.supplier_id AND jel.account_id = c.id) AS has_lines
               FROM chart_of_accounts c
              WHERE c.supplier_id = ?
                AND c.account_code LIKE '221%'
                AND c.account_code <> '221'"
        );
        $stmt->execute([$supplierId]);
        $out = [];
        foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $row) {
            $out[(string) $row['account_code']] = [
                'is_active' => (bool) $row['is_active'],
                'has_lines' => (bool) $row['has_lines'],
            ];
        }
        return $out;
    }
}
