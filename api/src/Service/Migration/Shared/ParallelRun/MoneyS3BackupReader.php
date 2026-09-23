<?php

declare(strict_types=1);

namespace MyInvoice\Service\Migration\Shared\ParallelRun;

use MyInvoice\Service\Migration\MoneyS3\AccountCode;
use MyInvoice\Service\Migration\MoneyS3\InvoiceImporter;
use MyInvoice\Service\Migration\MoneyS3\Ms3Backup;
use MyInvoice\Service\Migration\MoneyS3\Ms3Journal;

/**
 * Záloha agendy Money S3 přečtená BEZ zápisu do MyÚčta: obratová předvaha k poslednímu
 * dni měsíce přímo z deníku a počty dokladů měsíce po knihách.
 *
 * Předvaha platí stejná pravidla jako rekonciliace převodu
 * ({@see \MyInvoice\Service\Migration\MoneyS3\MoneyS3Reconciler::moneyTrialBalances()}):
 * počáteční stavy z řádků `XP`, uzávěrka roku `XZ` se nebere, netto účinek podle
 * {@see Ms3Journal::effect()}. Rok adresáře ROK.nnn se určuje stejně jako při převodu
 * ({@see Ms3Journal::fiscalYear()}). Datum dokladu se u knih bere z týchž polí jako
 * při převodu, aby počty odpovídaly dokladům, které převod v měsíci založí.
 */
final class MoneyS3BackupReader
{
    /** Kniha => tabulka Money a pole data v pořadí, v jakém je čte převod. */
    private const BOOKS = [
        'issued_invoices' => ['VFaktury', ['Vystaveno', 'DatUcPr']],
        'purchase_invoices' => ['PFaktury', ['Vystaveno', 'DatUcPr']],
        'cash' => ['PoklKnih', ['DatVyst', 'DatUcPr']],
        'bank' => ['BankKnih', ['DatPlat', 'DatUcPr']],
        'internal' => ['IntDokl', ['DatUcPr', 'DatVyst']],
    ];

    /**
     * @return array{trial_balance:array<string,array{0:float,1:float,2:float}>,document_counts:array<string,int>,year_found:bool}
     */
    public function read(Ms3Backup $backup, int $year, int $month): array
    {
        $monthStart = sprintf('%04d-%02d-01', $year, $month);
        $monthEnd = date('Y-m-t', (int) strtotime($monthStart));

        $yearDir = null;
        foreach ($backup->yearDirs() as $dir) {
            $table = $backup->table('UcDenik', $dir);
            if ($table === null || !$table->hasData()) {
                continue;
            }
            if (Ms3Journal::fiscalYear(iterator_to_array($table->rows(), false)) === $year) {
                $yearDir = $dir;
                break;
            }
        }
        if ($yearDir === null) {
            return ['trial_balance' => [], 'document_counts' => [], 'year_found' => false];
        }

        $tb = [];
        $journal = $backup->table('UcDenik', $yearDir);
        foreach ($journal === null ? [] : $journal->rows() as $r) {
            if (Ms3Journal::isYearEndClosing($r)) {
                continue;
            }
            $opening = Ms3Journal::isOpening($r);
            if (!$opening && substr((string) ($r['Datum'] ?? ''), 0, 10) > $monthEnd) {
                continue;
            }
            $effect = Ms3Journal::effect($r);
            if ($effect === null) {
                continue;
            }
            $slot = $opening ? 0 : 1;
            foreach ([[$effect['debit'], 1], [$effect['credit'], -1]] as [$code, $sign]) {
                $syn = AccountCode::synthetic($code);
                if ($syn === null) {
                    continue;
                }
                $tb[$syn] ??= [0.0, 0.0, 0.0];
                $tb[$syn][$slot] += $sign * $effect['amount'];
            }
        }
        foreach ($tb as $syn => $v) {
            $tb[$syn] = [round($v[0], 2), round($v[1], 2), round($v[0] + $v[1], 2)];
        }
        ksort($tb, SORT_STRING);

        $counts = [];
        foreach (self::BOOKS as $book => [$tableName, $fields]) {
            $table = $backup->table($tableName, $yearDir);
            if ($table === null || !$table->hasData()) {
                continue;
            }
            $n = 0;
            foreach ($table->rows() as $r) {
                if (!empty($r['FlagDel']) || trim((string) ($r['Doklad'] ?? '')) === '') {
                    continue;
                }
                $date = InvoiceImporter::date($r, $fields);
                if ($date !== null && $date >= $monthStart && $date <= $monthEnd) {
                    $n++;
                }
            }
            $counts[$book] = $n;
        }

        return ['trial_balance' => $tb, 'document_counts' => $counts, 'year_found' => true];
    }
}
