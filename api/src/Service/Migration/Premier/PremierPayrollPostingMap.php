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

    public static function fromBackup(PremierBackup $backup, PremierJournal $journal, int $year): self
    {
        return self::fromJournal($journal, $year, self::accountNames($backup, $year));
    }

    /**
     * @param array<string,string> $accountNames účet PREMIER (`336100`) => název z osnovy
     */
    public static function fromJournal(PremierJournal $journal, int $year, array $accountNames = []): self
    {
        /** @var array<string,array<string,mixed>> $buckets */
        $buckets = [];
        foreach ($journal->year($year) as $r) {
            $debit = (string) $r['md'];
            $credit = (string) $r['dal'];
            if ($debit === '' || $credit === '' || $debit === $credit || abs((float) $r['amount']) < 0.005) {
                continue;
            }
            $text = (string) $r['text'];
            $pair = self::pair($debit, $credit) ?? self::pair($credit, $debit);
            if ($pair === null) {
                continue;
            }
            [$from, $to] = $pair;
            $concept = self::concept($from, $to, $text, $accountNames);
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
     * Je dvojice MD/D v této orientaci mzdový zápis? Vrací ji zpět, jinak `null`.
     *
     * @return array{0:string,1:string}|null
     */
    private static function pair(string $debit, string $credit): ?array
    {
        $employeeDebit = self::employee($debit);
        $employeeCredit = self::employee($credit);
        $cost = str_starts_with($debit, '52');
        $known = ($cost && ($employeeCredit || str_starts_with($credit, '336')))
            || ($employeeDebit && (str_starts_with($credit, '336') || str_starts_with($credit, '342') || str_starts_with($credit, '379')));
        return $known ? [$debit, $credit] : null;
    }

    /**
     * Mzdový význam dvojice v obvyklé orientaci, nebo `null`, když z účtů ani textu
     * neplyne jednoznačně.
     *
     * @param array<string,string> $accountNames
     */
    private static function concept(string $debit, string $credit, string $text, array $accountNames): ?string
    {
        $partner = str_starts_with($debit, '366') || str_starts_with($credit, '366');
        $hint = AttendanceText::normalize(($accountNames[$credit] ?? '') . ' ' . $text);
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
