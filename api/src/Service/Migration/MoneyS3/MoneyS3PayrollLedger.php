<?php

declare(strict_types=1);

namespace MyInvoice\Service\Migration\MoneyS3;

/**
 * Mzdové zápisy deníku Money S3 a údaje, které je provázejí - jen to, co ze zálohy
 * čitelně jde.
 *
 * ── Co Money nese a co ne ───────────────────────────────────────────────────
 * Osoby a zpracované mzdy jednotlivých zaměstnanců jsou v tabulkách mzdového modulu
 * (`MZDY`, `ZAMEST`…) jen do doby, kdy je Money přesunul do šifrované databáze agendy
 * (`Agenda.s3db`); odtud je převod nepřečte. Čitelné zůstávají doklady, které mzdový
 * modul vytváří, a měsíční úhrn daně:
 *
 *  - závazky (`KnihZav`, zdroj deníku `KZ`) a interní doklady (`IntDokl`, zdroj `ID`)
 *    s příznakem mzdového dokladu (`MZTyp`), obdobím mezd (`MZRok`, `MZMesic`) a druhem
 *    mzdového dokladu (`MZDI_Zauct`), který Money vyplňuje zhruba od roku 2020,
 *  - jejich řádky v deníku (`UcDenik`),
 *  - `VYUCDPFO`: firemní měsíční úhrn daně z příjmů ze závislé činnosti a jejího odvodu.
 *
 * Starší doklady příznak ani druh nemají; mzdové z nich jsou jen podle páru účtů
 * ({@see MoneyS3PayrollPostingMap}).
 *
 * Ven jdou jen řádky, které se mzdami mohou souviset (doklad s příznakem, nebo účet
 * zaměstnanců, pojistného, daně či srážek na některé straně), ať se celý deník
 * nedrží v paměti.
 */
final class MoneyS3PayrollLedger
{
    /** Syntetické účty, bez kterých řádek mzdový být nemůže (když doklad nemá příznak). */
    private const PAYROLL_SYNTHETICS = ['331', '333', '335', '336', '342', '366', '379'];

    /**
     * @param list<array{year:int,period:?string,flagged:bool,code:?int,debit:string,credit:string,amount:float,signed:float,text:string,cost_center:?string,month:string}> $lines
     * @param array<string,string> $accountNames účet Money (`336100`) => název z osnovy
     * @param array<string,array{dpfo:float,remitted:float}> $taxTotals `YYYY-MM` => úhrn `VYUCDPFO`
     */
    private function __construct(
        public readonly array $lines,
        public readonly array $accountNames,
        public readonly array $taxTotals,
        public readonly ?string $lastDataPeriod,
        public readonly ?string $lastPersonPeriod,
    ) {}

    /**
     * @param array<string,int> $dirYears adresář `ROK.nnn` => účetní rok; čtou se jen tyto roky
     */
    public static function read(Ms3Backup $backup, array $dirYears): self
    {
        $lines = [];
        $names = [];
        $lastData = null;
        $dirs = [];
        foreach ($backup->yearDirs() as $path) {
            $dirs[strtoupper(basename($path))] = $path;
        }
        asort($dirYears);
        foreach ($dirYears as $dir => $year) {
            $path = $dirs[strtoupper((string) $dir)] ?? null;
            if ($path === null) {
                continue;
            }
            foreach (self::rows($backup, 'UcOsnova', $path) as $row) {
                $code = trim((string) ($row['Ucet'] ?? ''));
                $name = trim((string) ($row['Nazev'] ?? ''));
                if ($code !== '' && $name !== '') {
                    $names[$code] = $name;
                }
            }
            $documents = [
                'KZ' => self::documents($backup, 'KnihZav', $path),
                'ID' => self::documents($backup, 'IntDokl', $path),
            ];
            foreach (self::rows($backup, 'UcDenik', $path) as $row) {
                if (Ms3Journal::isOpening($row) || Ms3Journal::isYearEndClosing($row)) {
                    continue;
                }
                $date = (string) ($row['Datum'] ?? '');
                $month = strlen($date) >= 7 ? substr($date, 0, 7) : '';
                if ($month !== '' && ($lastData === null || $month > $lastData)) {
                    $lastData = $month;
                }
                $source = strtoupper(trim((string) ($row['Zdroj'] ?? '')));
                $document = $documents[$source][trim((string) ($row['Doklad'] ?? ''))] ?? null;
                $flagged = $document !== null && $document['flagged'];
                $effect = Ms3Journal::effect($row) ?? ($flagged ? self::sameAccount($row) : null);
                if ($effect === null) {
                    continue;
                }
                if (!$flagged && !self::mayBePayroll($effect['debit'], $effect['credit'])) {
                    continue;
                }
                $lines[] = [
                    'year' => (int) $year,
                    'period' => $flagged ? $document['period'] : null,
                    'flagged' => $flagged,
                    'code' => $flagged ? $document['code'] : null,
                    'debit' => $effect['debit'],
                    'credit' => $effect['credit'],
                    'amount' => $effect['amount'],
                    'signed' => round((float) ($row['Castka'] ?? 0), 2),
                    'text' => trim((string) ($row['Popis'] ?? '')),
                    'cost_center' => Ms3Journal::costCenter($row),
                    'month' => $month,
                ];
            }
        }

        return new self($lines, $names, self::taxTotals($backup), $lastData, self::lastPersonPeriod($backup));
    }

    /**
     * Sestavení z hotových řádků (testy klasifikace bez zálohy).
     *
     * @param list<array<string,mixed>> $lines
     * @param array<string,string> $accountNames
     * @param array<string,array{dpfo:float,remitted:float}> $taxTotals
     */
    public static function fromLines(array $lines, array $accountNames = [], array $taxTotals = [], ?string $lastDataPeriod = null, ?string $lastPersonPeriod = null): self
    {
        $normalized = [];
        foreach ($lines as $line) {
            $normalized[] = [
                'year' => (int) ($line['year'] ?? (int) substr((string) ($line['month'] ?? $line['period'] ?? '0'), 0, 4)),
                'period' => $line['period'] ?? null,
                'flagged' => (bool) ($line['flagged'] ?? ($line['code'] ?? null) !== null),
                'code' => isset($line['code']) ? (int) $line['code'] : null,
                'debit' => (string) $line['debit'],
                'credit' => (string) $line['credit'],
                'amount' => (float) $line['amount'],
                'signed' => (float) ($line['signed'] ?? $line['amount']),
                'text' => (string) ($line['text'] ?? ''),
                'cost_center' => $line['cost_center'] ?? null,
                'month' => (string) ($line['month'] ?? $line['period'] ?? ''),
            ];
        }

        return new self($normalized, $accountNames, $taxTotals, $lastDataPeriod, $lastPersonPeriod);
    }

    /** Má deník převáděných let mzdové doklady s příznakem mzdového modulu? */
    public function hasFlaggedDocuments(): bool
    {
        foreach ($this->lines as $line) {
            if ($line['flagged']) {
                return true;
            }
        }
        return false;
    }

    /**
     * Doklady roku: číslo dokladu => příznak mzdového dokladu, druh a období mezd.
     *
     * @return array<string,array{flagged:bool,code:?int,period:?string}>
     */
    private static function documents(Ms3Backup $backup, string $table, string $path): array
    {
        $out = [];
        foreach (self::rows($backup, $table, $path) as $row) {
            $number = trim((string) ($row['Doklad'] ?? ''));
            if ($number === '') {
                continue;
            }
            $flagged = (int) ($row['MZTyp'] ?? 0) !== 0;
            $year = (int) ($row['MZRok'] ?? 0);
            $month = (int) ($row['MZMesic'] ?? 0);
            $code = (int) ($row['MZDI_Zauct'] ?? 0);
            $out[$number] = [
                'flagged' => $flagged,
                'code' => $flagged && $code > 0 ? $code : null,
                'period' => $flagged && $year >= 1990 && $year <= 2100 && $month >= 1 && $month <= 12 ? sprintf('%04d-%02d', $year, $month) : null,
            ];
        }
        return $out;
    }

    /** @return array<string,array{dpfo:float,remitted:float}> */
    private static function taxTotals(Ms3Backup $backup): array
    {
        $out = [];
        foreach (self::rows($backup, 'VYUCDPFO', null) as $row) {
            $year = (int) ($row['Rok'] ?? 0);
            $month = (int) ($row['Mesic'] ?? 0);
            // Řádek 65535/255 je hlavička tabulky, ne měsíc.
            if ($year < 1990 || $year > 2100 || $month < 1 || $month > 12) {
                continue;
            }
            $out[sprintf('%04d-%02d', $year, $month)] = [
                'dpfo' => round((float) ($row['DPFO'] ?? 0), 2),
                'remitted' => round((float) ($row['Odvod'] ?? 0), 2),
            ];
        }
        ksort($out);
        return $out;
    }

    /** Poslední měsíc zpracovaných mezd osob v čitelném mzdovém modulu (`MZDY`), nebo `null`. */
    private static function lastPersonPeriod(Ms3Backup $backup): ?string
    {
        $last = 0;
        foreach (self::rows($backup, 'MZDY', null) as $row) {
            $year = (int) ($row['Rok'] ?? 0);
            $month = (int) ($row['Mesic'] ?? 0);
            if ($year >= 1990 && $year <= 2100 && $month >= 1 && $month <= 12) {
                $last = max($last, $year * 100 + $month);
            }
        }
        return $last === 0 ? null : sprintf('%04d-%02d', intdiv($last, 100), $last % 100);
    }

    /**
     * Řádek mzdového dokladu se stejným účtem na obou stranách. Účetně nemá účinek,
     * Money jím ale vede závazek čisté mzdy vůči zaměstnanci (331/331, zaměstnance
     * rozliší párový symbol) - pro kontrolní úhrn čisté mzdy je to jediný zdroj.
     *
     * @param array<string,mixed> $row
     * @return array{debit:string,credit:string,amount:float}|null
     */
    private static function sameAccount(array $row): ?array
    {
        $amount = round((float) ($row['Castka'] ?? 0), 2);
        $account = trim((string) ($row['UcMD'] ?? ''));
        if ($amount === 0.0 || $account === '' || $account !== trim((string) ($row['UcD'] ?? ''))) {
            return null;
        }
        return ['debit' => $account, 'credit' => $account, 'amount' => abs($amount)];
    }

    private static function mayBePayroll(string $debit, string $credit): bool
    {
        return in_array(substr($debit, 0, 3), self::PAYROLL_SYNTHETICS, true)
            || in_array(substr($credit, 0, 3), self::PAYROLL_SYNTHETICS, true);
    }

    /** @return \Generator<int,array<string,mixed>> */
    private static function rows(Ms3Backup $backup, string $table, ?string $path): \Generator
    {
        $t = $backup->table($table, $path);
        if ($t === null || !$t->hasData()) {
            return;
        }
        yield from $t->rows();
    }
}
