<?php

declare(strict_types=1);

namespace MyInvoice\Service\Migration\MoneyS3;

use MyInvoice\Service\Payroll\Import\Attendance\AttendanceText;
use MyInvoice\Service\Payroll\Migration\PayrollLegacyPostingRow;
use MyInvoice\Service\Payroll\Migration\PayrollLegacyPostingSource;

/**
 * Převzaté zaúčtování mezd z deníku Money S3 pro návrh mzdových předkontací
 * ({@see \MyInvoice\Service\Payroll\Migration\PayrollPostingMapProposalService}).
 *
 * ⚠ **Nic to neúčtuje a neukládá.** Mzdové zápisy Money přišly převodem deníku 1:1;
 * odsud se bere JEN podklad pro nastavení: který účet stál u kterého mzdového významu.
 *
 * ── Odkud význam ────────────────────────────────────────────────────────────
 * Doklad mzdového modulu nese druh mzdového dokladu (`MZDI_Zauct`, {@see MoneyS3PayrollLedger}).
 * Druh říká, o jakou platbu jde (sociální, nebo zdravotní pojištění; daň; srážky),
 * pár účtů pak stranu (pojistné zaměstnance 331/336, zaměstnavatele 52x/336). Doklady
 * bez druhu (Money ho doplňuje zhruba od roku 2020) se zařadí podle páru účtů stejně
 * jako u PREMIER; sociální a zdravotní pojištění, která sedí obě na 336, rozliší
 * analytika, kterou firma v dokladech s druhem použila pro to které pojištění, jinak
 * název analytiky v osnově. Daň (záloha / srážková) a srážky (exekuce / ostatní)
 * rozliší název účtu nebo text zápisu. Co z toho nejde určit, zůstane bez významu
 * (jen v přehledu) - rozhodne účetní.
 *
 * Přeúčtování mezi analytikami zaměstnanců (331/331) není podklad pro žádnou
 * předkontaci a vynechává se; řádky mzdových dokladů, jejichž pár mzdový není
 * (třeba zákonné pojištění odpovědnosti 548/379), jdou jen do přehledu.
 *
 * ── Který rok ───────────────────────────────────────────────────────────────
 * Návrh vzniká z posledního převáděného roku se mzdami, tedy z toho, jak firma účtuje
 * teď. Starší roky by přidaly analytiky, které firma dávno nepoužívá, a každá by
 * v návrhu vypadala jako rozpor.
 *
 * ── Tvar účtu ───────────────────────────────────────────────────────────────
 * Účty jdou do tvaru, v jakém je převod Money založil v osnově ({@see AccountCode::fromMoney()},
 * `521000` → `521.000`), ne obecným {@see \MyInvoice\Service\Payroll\Migration\PayrollLegacyAccountCode}
 * (`521`): návrh se porovnává s osnovou firmy a ta po převodu z Money nese analytiku `.000`.
 */
final class MoneyS3PayrollPostingMap implements PayrollLegacyPostingSource
{
    /** Zdroj návrhu; musí sedět na ENUM `source` tabulky návrhů. */
    public const SOURCE = 'money_s3';

    /** Druhy mzdového dokladu Money (`MZDI_Zauct`), jak je mzdový modul vyplňuje. */
    public const KIND_NET_WAGE = 1;
    public const KIND_GROSS = 3;
    public const KIND_TAX = 5;
    public const KIND_SOCIAL = 7;
    public const KIND_HEALTH = 10;
    public const KIND_GROSS_OTHER = 13;
    public const KIND_DEDUCTIONS = 14;
    public const KIND_LIABILITY_INSURANCE = 15;
    public const KIND_TRAVEL = 17;

    /** Účty zaměstnanců: 331 mzdy, 333 ostatní závazky vůči zaměstnancům, 366 společníci. */
    private const EMPLOYEE_ACCOUNTS = ['331', '333', '366'];
    private const COST_CENTER_LIMIT = 20;

    /** @param list<PayrollLegacyPostingRow> $rows */
    private function __construct(private readonly array $rows, public readonly ?int $year) {}

    public function sourceKey(): string
    {
        return self::SOURCE;
    }

    /** @return list<PayrollLegacyPostingRow> */
    public function postingRows(): array
    {
        return $this->rows;
    }

    /**
     * @param ?int $year rok, ze kterého návrh vzniká; `null` = poslední rok se mzdami
     */
    public static function fromLedger(MoneyS3PayrollLedger $ledger, ?int $year = null): self
    {
        $insurance = self::insuranceAccounts($ledger);
        $classified = [];
        $years = [];
        foreach ($ledger->lines as $line) {
            $result = self::classify($line, $insurance, $ledger->accountNames);
            if ($result === null) {
                continue;
            }
            $classified[] = [$line, $result];
            if ($result['concept'] !== null) {
                $years[$line['year']] = true;
            }
        }
        $year ??= $years === [] ? null : max(array_keys($years));
        if ($year === null) {
            return new self([], null);
        }

        /** @var array<string,array<string,mixed>> $buckets */
        $buckets = [];
        foreach ($classified as [$line, $result]) {
            if ($line['year'] !== $year) {
                continue;
            }
            $key = ($result['concept'] ?? '') . '|' . $result['debit'] . '|' . $result['credit'];
            $bucket = $buckets[$key] ?? [
                'concept' => $result['concept'],
                'debit' => $result['debit'],
                'credit' => $result['credit'],
                'texts' => [],
                'lines' => 0,
                'amount' => 0,
                'cost_centers' => [],
            ];
            $bucket['lines']++;
            $bucket['amount'] += (int) abs(round($line['amount'] * 100.0));
            if ($line['text'] !== '') {
                $bucket['texts'][$line['text']] = true;
            }
            if (($line['cost_center'] ?? null) !== null) {
                $bucket['cost_centers'][(string) $line['cost_center']] = true;
            }
            $buckets[$key] = $bucket;
        }

        $rows = [];
        foreach ($buckets as $bucket) {
            $centres = array_map('strval', array_keys($bucket['cost_centers']));
            sort($centres, SORT_STRING);
            $rows[] = new PayrollLegacyPostingRow(
                self::SOURCE,
                'money_s3:UcDenik:' . $bucket['debit'] . '/' . $bucket['credit'],
                $bucket['concept'],
                self::label($bucket, $ledger->accountNames),
                AccountCode::fromMoney($bucket['debit']),
                AccountCode::fromMoney($bucket['credit']),
                $bucket['debit'],
                $bucket['credit'],
                (int) $bucket['lines'],
                (int) $bucket['amount'],
                array_slice($centres, 0, self::COST_CENTER_LIMIT),
            );
        }
        return new self($rows, $year);
    }

    /**
     * Mzdový význam řádku deníku, nebo `null`, když řádek mzdový není.
     *
     * Vrací i orientaci, ve které pár mzdový je (`debit`/`credit`), a znaménko vůči
     * řádku (`-1` = storno nebo oprava s prohozenými stranami). `concept` je `null` u
     * mzdového řádku, jehož význam z druhu, účtů ani textu určit nejde.
     *
     * @param array<string,mixed> $line řádek {@see MoneyS3PayrollLedger::$lines}
     * @param array<string,string> $insurance účet 336 => `social`/`health` ({@see self::insuranceAccounts()})
     * @param array<string,string> $accountNames
     * @return array{concept:?string,debit:string,credit:string,sign:int}|null
     */
    public static function classify(array $line, array $insurance = [], array $accountNames = []): ?array
    {
        $debit = (string) $line['debit'];
        $credit = (string) $line['credit'];
        $code = isset($line['code']) ? (int) $line['code'] : null;
        $flagged = (bool) ($line['flagged'] ?? false);
        // Přeúčtování na analytiku zaměstnance (závazek čisté mzdy) není podklad pro předkontaci.
        if (self::employee($debit) && self::employee($credit)) {
            return null;
        }
        foreach ([[$debit, $credit, 1], [$credit, $debit, -1]] as [$from, $to, $sign]) {
            if (str_starts_with($from, '335') && self::employee($to) || str_starts_with($to, '335') && self::employee($from)) {
                $receivable = str_starts_with($from, '335') ? $from : $to;
                $employee = $receivable === $from ? $to : $from;
                return ['concept' => 'employee_receivable', 'debit' => $receivable, 'credit' => $employee, 'sign' => $sign];
            }
            $hint = AttendanceText::normalize(($accountNames[$to] ?? '') . ' ' . ($line['text'] ?? ''));
            $concept = self::pairConcept($from, $to, $code, $hint, $insurance, $accountNames);
            if ($concept !== false) {
                return ['concept' => $concept, 'debit' => $from, 'credit' => $to, 'sign' => $sign];
            }
        }
        // Řádek mzdového dokladu s párem, který mzdový není: jen do přehledu.
        return $flagged ? ['concept' => null, 'debit' => $debit, 'credit' => $credit, 'sign' => 1] : null;
    }

    /**
     * Analytiky 336, které firma v dokladech s druhem použila pro sociální (druh 7)
     * a zdravotní pojištění (druh 10). Analytika použitá pro obojí se nepoužije.
     *
     * @return array<string,string>
     */
    public static function insuranceAccounts(MoneyS3PayrollLedger $ledger): array
    {
        $seen = [];
        foreach ($ledger->lines as $line) {
            $type = match ($line['code']) {
                self::KIND_SOCIAL => 'social',
                self::KIND_HEALTH => 'health',
                default => null,
            };
            if ($type === null) {
                continue;
            }
            foreach ([$line['debit'], $line['credit']] as $account) {
                if (str_starts_with($account, '336')) {
                    $seen[$account][$type] = true;
                }
            }
        }
        $out = [];
        foreach ($seen as $account => $types) {
            if (count($types) === 1) {
                $out[(string) $account] = (string) array_key_first($types);
            }
        }
        return $out;
    }

    /**
     * Význam páru v dané orientaci; `false` = pár v téhle orientaci mzdový není.
     *
     * @param array<string,string> $insurance
     * @param array<string,string> $accountNames
     */
    private static function pairConcept(string $debit, string $credit, ?int $code, string $hint, array $insurance, array $accountNames): string|null|false
    {
        $partner = str_starts_with($debit, '366') || str_starts_with($credit, '366');
        if (str_starts_with($debit, '52') && self::employee($credit)) {
            if ($partner) {
                return 'partner_gross';
            }
            return str_starts_with($debit, '523') ? 'statutory_gross' : 'employment_gross';
        }
        if (str_starts_with($debit, '512') && self::employee($credit)) {
            return 'travel_expense';
        }
        if (str_starts_with($credit, '336') && (self::employee($debit) || str_starts_with($debit, '52'))) {
            $type = match ($code) {
                self::KIND_SOCIAL => 'social',
                self::KIND_HEALTH => 'health',
                default => $insurance[$credit] ?? self::insuranceFromName($accountNames[$credit] ?? ''),
            };
            if ($type === null) {
                return null;
            }
            if (str_starts_with($debit, '52')) {
                return 'employer_' . $type;
            }
            return ($partner ? 'partner_employee_' : 'employee_') . $type;
        }
        if (self::employee($debit) && str_starts_with($credit, '342')) {
            $tax = match (true) {
                preg_match('/srazk/', $hint) === 1 => 'withholding_tax',
                preg_match('/zaloh|zavisl/', $hint) === 1 => 'advance_tax',
                default => null,
            };
            return $tax === null ? null : ($partner ? 'partner_' . $tax : $tax);
        }
        if (self::employee($debit) && str_starts_with($credit, '379')) {
            if (preg_match('/exekuc|insolven/', $hint) === 1) {
                return $partner ? null : 'enforcement_deductions';
            }
            return $partner ? 'partner_other_deductions' : 'other_deductions';
        }
        return false;
    }

    /** `social` / `health` podle názvu analytiky 336; obojí nebo nic = neurčeno. */
    private static function insuranceFromName(string $name): ?string
    {
        $normalized = AttendanceText::normalize($name);
        $social = preg_match('/social/', $normalized) === 1;
        $health = preg_match('/zdravot/', $normalized) === 1;
        if ($social === $health) {
            return null;
        }
        return $social ? 'social' : 'health';
    }

    private static function employee(string $account): bool
    {
        foreach (self::EMPLOYEE_ACCOUNTS as $prefix) {
            if (str_starts_with($account, $prefix)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Popis řádku: texty zápisů deníku (nejvýš tři), jinak dvojice účtů s názvem.
     *
     * @param array<string,mixed> $bucket
     * @param array<string,string> $accountNames
     */
    private static function label(array $bucket, array $accountNames): string
    {
        $texts = array_slice(array_keys($bucket['texts']), 0, 3);
        if ($texts !== []) {
            return implode(' / ', array_map('strval', $texts));
        }
        $name = $accountNames[$bucket['credit']] ?? $accountNames[$bucket['debit']] ?? null;
        $pair = $bucket['debit'] . '/' . $bucket['credit'];
        return $name === null ? 'Zaúčtování ' . $pair : 'Zaúčtování ' . $pair . ' - ' . $name;
    }
}
