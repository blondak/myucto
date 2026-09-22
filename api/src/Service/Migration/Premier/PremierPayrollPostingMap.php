<?php

declare(strict_types=1);

namespace MyInvoice\Service\Migration\Premier;

use MyInvoice\Service\Payroll\Import\Attendance\AttendanceText;
use MyInvoice\Service\Payroll\Migration\PayrollLegacyAccountCode;
use MyInvoice\Service\Payroll\Migration\PayrollLegacyPostingRow;
use MyInvoice\Service\Payroll\Migration\PayrollLegacyPostingSource;

/**
 * Převzaté zaúčtování mezd z deníku PREMIER (`PUB_UCTO`) pro návrh mzdových předkontací
 * ({@see \MyInvoice\Service\Payroll\Migration\PayrollPostingMapProposalService}).
 *
 * ⚠ **Nic to neúčtuje a neukládá.** Mzdové zápisy PREMIER se převádějí 1:1 s deníkem;
 * odsud se bere JEN podklad pro nastavení: který účet stál u kterého mzdového významu.
 *
 * ── Odkud význam ────────────────────────────────────────────────────────────
 * PREMIER, na rozdíl od PAMICA, nemá v záloze číselník předkontací mezd: mzda je
 * v deníku jako obyčejné zápisy. Význam se proto odvozuje ze dvojice účtů (stejné
 * pravidlo jako kontrola mezd proti deníku, {@see PremierPayroll::ledgerTotals()}):
 *
 *  - náklad 52x proti zaměstnanci (331, 333, 366) = hrubý příjem; 366 je společník,
 *    náklad 523 odměna člena orgánu,
 *  - zaměstnanec proti 336 = pojistné zaměstnance, náklad 52x proti 336 = pojistné
 *    zaměstnavatele,
 *  - zaměstnanec proti 342 = daň,
 *  - zaměstnanec proti 379 = srážka (exekuce a insolvence podle textu zápisu).
 *
 * Sociální a zdravotní pojištění sedí obě na 336 a záloha i srážková daň na 342; od
 * sebe je odliší až název analytiky v osnově (`OSNOVA.TEXT`), případně text zápisu.
 * Když ani jedno neříká, o co jde, zůstane řádek bez významu (jen v přehledu) -
 * záměna sociálního a zdravotního pojištění se pozná až při odvodu.
 *
 * Opravné zápisy s prohozenými stranami se otočí do obvyklé orientace a částka se
 * bere v absolutní hodnotě (váha řádku, ne jeho opak), stejně jako u PAMICA.
 */
final class PremierPayrollPostingMap implements PayrollLegacyPostingSource
{
    /**
     * Zdroj návrhu; musí sedět na ENUM `source` tabulky návrhů. PREMIER vlastní hodnotu
     * nemá a převzaté mzdy z něj jsou evidované pod obecným `other` (jako
     * {@see PayrollImporter} u převzatých úhrnů).
     */
    public const SOURCE = 'other';

    private const COST_CENTER_LIMIT = 20;

    /** @param list<PayrollLegacyPostingRow> $rows */
    private function __construct(private readonly array $rows) {}

    public function sourceKey(): string
    {
        return self::SOURCE;
    }

    /** @return list<PayrollLegacyPostingRow> */
    public function postingRows(): array
    {
        return $this->rows;
    }

    /** Srážky exekucí a insolvencí (kódy složek `MZDY_POL`), ostatní srážky jsou dobrovolné. */
    private const ENFORCEMENT_CODES = ['702', '703', '704', '705', '706', '707', '708', '709', '751'];

    public static function fromBackup(PremierBackup $backup, PremierJournal $journal, int $year): self
    {
        return self::fromJournal($journal, $year, self::accountNames($backup, $year), self::componentAccounts($backup, $year));
    }

    /**
     * Účty složek mezd z číselníku `MZDY_POL` (`UCET`, `UCET2`) platného v roce (`PLATNOST`;
     * bez verze roku platí nejbližší starší): účet => kódy složek, které na něj účtují.
     * PREMIER tak u srážky říká, kam ji účtuje: exekuce a insolvence (702-709) na 325333,
     * poplatky a náklady na 379100, stravenky na 213001, záloha a odbory na 335100 (reálná
     * záloha). Deník tu dvojici nese taky, ale bez významu.
     *
     * @return array<string,list<string>>
     */
    public static function componentAccounts(PremierBackup $backup, int $year): array
    {
        $best = [];
        foreach ($backup->rows('MZDY_POL') as $row) {
            $code = trim((string) ($row['KOD'] ?? ''));
            $validity = (int) ($row['PLATNOST'] ?? 0);
            if ($code === '' || ($validity > $year && $validity !== 0)) {
                continue;
            }
            if (!isset($best[$code]) || $validity >= $best[$code][0]) {
                $best[$code] = [$validity, $row];
            }
        }
        $out = [];
        foreach ($best as $code => [, $row]) {
            foreach (['UCET', 'UCET2'] as $column) {
                $account = trim((string) ($row[$column] ?? ''));
                if (preg_match('/^[0-9]{3,6}$/D', $account) === 1 && !in_array((string) $code, $out[$account] ?? [], true)) {
                    $out[$account][] = (string) $code;
                }
            }
        }
        ksort($out);
        return $out;
    }

    /**
     * @param array<string,string> $accountNames účet PREMIER (`336100`) => název z osnovy
     * @param array<string,list<string>> $componentAccounts účet => kódy složek ({@see self::componentAccounts()})
     */
    public static function fromJournal(PremierJournal $journal, int $year, array $accountNames = [], array $componentAccounts = []): self
    {
        $deductions = self::deductionAccounts($componentAccounts);
        /** @var array<string,array<string,mixed>> $buckets */
        $buckets = [];
        foreach ($journal->year($year) as $r) {
            $debit = (string) $r['md'];
            $credit = (string) $r['dal'];
            if ($debit === '' || $credit === '' || $debit === $credit || abs((float) $r['amount']) < 0.005) {
                continue;
            }
            $text = (string) $r['text'];
            $pair = self::pair($debit, $credit, $deductions) ?? self::pair($credit, $debit, $deductions);
            if ($pair === null) {
                continue;
            }
            [$from, $to] = $pair;
            $concept = self::concept($from, $to, $text, $accountNames, $deductions);
            $key = ($concept ?? '') . '|' . $from . '|' . $to;
            $bucket = $buckets[$key] ?? [
                'concept' => $concept,
                'debit' => $from,
                'credit' => $to,
                'texts' => [],
                'lines' => 0,
                'amount' => 0,
                'cost_centers' => [],
            ];
            $bucket['lines']++;
            $bucket['amount'] += (int) abs(round((float) $r['amount'] * 100.0));
            if ($text !== '') {
                $bucket['texts'][$text] = true;
            }
            if ((int) $r['cost_center'] > 0) {
                $bucket['cost_centers'][(string) $r['cost_center']] = true;
            }
            $buckets[$key] = $bucket;
        }

        $rows = [];
        foreach ($buckets as $bucket) {
            $centres = array_map('strval', array_keys($bucket['cost_centers']));
            sort($centres, SORT_STRING);
            $rows[] = new PayrollLegacyPostingRow(
                self::SOURCE,
                'premier:PUB_UCTO:' . $bucket['debit'] . '/' . $bucket['credit'],
                $bucket['concept'],
                self::label($bucket, $accountNames),
                PayrollLegacyAccountCode::normalize($bucket['debit']),
                PayrollLegacyAccountCode::normalize($bucket['credit']),
                $bucket['debit'],
                $bucket['credit'],
                (int) $bucket['lines'],
                (int) $bucket['amount'],
                array_slice($centres, 0, self::COST_CENTER_LIMIT),
            );
        }
        return new self($rows);
    }

    /**
     * Názvy účtů z osnovy PREMIER; přednost má osnova převáděného roku.
     *
     * @return array<string,string>
     */
    public static function accountNames(PremierBackup $backup, int $year): array
    {
        $names = [];
        $exact = [];
        foreach ($backup->rows('OSNOVA') as $row) {
            $code = trim((string) ($row['UCET'] ?? '')) . trim((string) ($row['ANALYT'] ?? ''));
            $name = trim((string) ($row['TEXT'] ?? ''));
            if ($code === '' || $name === '') {
                continue;
            }
            $current = (int) ($row['ROK'] ?? 0) === $year;
            if (!isset($names[$code]) || ($current && !$exact[$code])) {
                $names[$code] = $name;
                $exact[$code] = $current;
            }
        }
        return $names;
    }

    /**
     * Účty, na které PREMIER účtuje jen srážky: účet => `enforcement`, `voluntary` nebo
     * `mixed` (obojí na jednom účtu, význam rozhodne text).
     *
     * @param array<string,list<string>> $componentAccounts
     * @return array<string,string>
     */
    private static function deductionAccounts(array $componentAccounts): array
    {
        $out = [];
        foreach ($componentAccounts as $account => $codes) {
            $deductions = array_intersect($codes, PremierPayroll::DEDUCTION_CODES);
            if ($deductions === [] || count($deductions) !== count($codes)) {
                continue;
            }
            $enforcement = array_intersect($deductions, self::ENFORCEMENT_CODES);
            $out[(string) $account] = match (count($enforcement)) {
                0 => 'voluntary',
                count($deductions) => 'enforcement',
                default => 'mixed',
            };
        }
        return $out;
    }

    /**
     * Je dvojice MD/D v této orientaci mzdový zápis? Vrací ji zpět, jinak `null`.
     *
     * @param array<string,string> $deductions {@see self::deductionAccounts()}
     * @return array{0:string,1:string}|null
     */
    private static function pair(string $debit, string $credit, array $deductions = []): ?array
    {
        $employeeDebit = self::employee($debit);
        $employeeCredit = self::employee($credit);
        $cost = str_starts_with($debit, '52');
        $known = ($cost && ($employeeCredit || str_starts_with($credit, '336')))
            || ($employeeDebit && (str_starts_with($credit, '336') || str_starts_with($credit, '342') || str_starts_with($credit, '379')
                || isset($deductions[$credit])));
        return $known ? [$debit, $credit] : null;
    }

    /**
     * Mzdový význam dvojice v obvyklé orientaci, nebo `null`, když z účtů ani textu
     * neplyne jednoznačně.
     *
     * @param array<string,string> $accountNames
     * @param array<string,string> $deductions {@see self::deductionAccounts()}
     */
    private static function concept(string $debit, string $credit, string $text, array $accountNames, array $deductions = []): ?string
    {
        $partner = str_starts_with($debit, '366') || str_starts_with($credit, '366');
        $hint = AttendanceText::normalize(($accountNames[$credit] ?? '') . ' ' . $text);
        // Účet, na který číselník složek účtuje jen srážky: druh srážky z kódů složek,
        // u účtu se srážkami obou druhů z textu zápisu.
        $kind = self::employee($debit) ? ($deductions[$credit] ?? null) : null;
        if ($kind !== null) {
            $enforcement = $kind === 'enforcement' || ($kind === 'mixed' && preg_match('/exekuc|insolven/', $hint) === 1);
            if ($enforcement) {
                return $partner ? null : 'enforcement_deductions';
            }
            if ($kind === 'voluntary') {
                return $partner ? 'partner_other_deductions' : 'other_deductions';
            }
            // Smíšený účet bez vodítka v textu: rozhodne obecné pravidlo níž (379 = ostatní).
        }
        if (str_starts_with($debit, '52') && self::employee($credit)) {
            if ($partner) {
                return 'partner_gross';
            }
            return str_starts_with($debit, '523') ? 'statutory_gross' : 'employment_gross';
        }
        if (str_starts_with($credit, '336')) {
            $insurance = self::insurance($accountNames[$credit] ?? '', $text);
            if ($insurance === null) {
                return null;
            }
            if (str_starts_with($debit, '52')) {
                return 'employer_' . $insurance;
            }
            return ($partner ? 'partner_employee_' : 'employee_') . $insurance;
        }
        if (str_starts_with($credit, '342')) {
            $tax = match (true) {
                preg_match('/srazk/', $hint) === 1 => 'withholding_tax',
                preg_match('/zaloh|zavisl/', $hint) === 1 => 'advance_tax',
                default => null,
            };
            return $tax === null ? null : ($partner ? 'partner_' . $tax : $tax);
        }
        if (str_starts_with($credit, '379')) {
            if (preg_match('/exekuc|insolven/', $hint) === 1) {
                return $partner ? null : 'enforcement_deductions';
            }
            return $partner ? 'partner_other_deductions' : 'other_deductions';
        }
        return null;
    }

    /**
     * `social` / `health` podle názvu analytiky 336, jinak podle textu zápisu; oba
     * zároveň (nebo ani jeden) = neurčeno.
     */
    private static function insurance(string $accountName, string $text): ?string
    {
        foreach ([$accountName, $text] as $source) {
            $normalized = AttendanceText::normalize($source);
            $social = preg_match('/social/', $normalized) === 1;
            $health = preg_match('/zdravot/', $normalized) === 1;
            if ($social !== $health) {
                return $social ? 'social' : 'health';
            }
        }
        return null;
    }

    private static function employee(string $account): bool
    {
        foreach (PremierPayroll::EMPLOYEE_ACCOUNTS as $prefix) {
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
            return implode(' / ', $texts);
        }
        $name = $accountNames[$bucket['credit']] ?? $accountNames[$bucket['debit']] ?? null;
        $pair = $bucket['debit'] . '/' . $bucket['credit'];
        return $name === null ? 'Zaúčtování ' . $pair : 'Zaúčtování ' . $pair . ' - ' . $name;
    }
}
