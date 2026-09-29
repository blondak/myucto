<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Support;

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

/**
 * Syntetický výpis z obchodního účtu GoPay v rozložení exportu XLSX: hlavička
 * s EVČ a účtem, souhrn zůstatků a tabulka „Pohyby na účtě".
 */
final class GoPayStatementFixture
{
    /**
     * @param list<array{0:string,1:string,2:string,3:float,4:string}> $rows [datum d. m. Y, typ, protistrana, částka, objednávka/VS]
     */
    public static function xlsx(string $from, string $to, float $opening, array $rows, string $currency = 'CZK'): string
    {
        $credited = 0.0;
        $debited = 0.0;
        foreach ($rows as $row) {
            if ($row[3] > 0) {
                $credited += $row[3];
            } else {
                $debited += $row[3];
            }
        }
        $money = static fn (float $amount): string => number_format($amount, 2, ',', ' ') . ' ' . $currency;

        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setCellValue('B2', 'VÝPIS Z OBCHODNÍHO ÚČTU');
        $sheet->setCellValue('B3', 'IDENTIFIKACE VÝPISU:');
        $sheet->setCellValue('D3', 'EVČ: 1000000001  ');
        $sheet->setCellValue('D4', 'ID účtu: 100000001/Účet ' . $currency . ' ');
        $sheet->setCellValue('B5', 'Obchodník');
        $sheet->setCellValue('E5', 'Platební systém GoPay');
        $sheet->setCellValue('B13', 'Datum vytvoření výpisu');
        $sheet->setCellValue('E13', $to);
        $sheet->setCellValue('B14', 'Definované období');
        $sheet->setCellValue('E14', $from . ' - ' . $to);
        $sheet->setCellValue('B15', 'Počáteční zůstatek na účtě');
        $sheet->setCellValue('E15', $money($opening));
        $sheet->setCellValue('B16', 'Připsáno na účet');
        $sheet->setCellValue('E16', $money($credited));
        $sheet->setCellValue('B17', 'Odepsáno z účtu');
        $sheet->setCellValue('E17', $money($debited));
        $sheet->setCellValue('B18', 'Konečný zůstatek na účtě');
        $sheet->setCellValue('E18', $money($opening + $credited + $debited));
        $sheet->setCellValue('B20', 'Pohyby na účtě');
        foreach (['B' => 'Datum', 'C' => 'Typ', 'D' => 'Protistrana', 'E' => 'Částka', 'F' => 'Měna', 'G' => 'ID objednávky/VS'] as $col => $header) {
            $sheet->setCellValue($col . '21', $header);
        }
        foreach ($rows as $index => $row) {
            $line = 22 + $index;
            $sheet->setCellValue('B' . $line, $row[0]);
            $sheet->setCellValue('C' . $line, $row[1]);
            $sheet->setCellValue('D' . $line, $row[2]);
            $sheet->setCellValue('E' . $line, $row[3]);
            $sheet->setCellValue('F' . $line, $currency);
            $sheet->setCellValueExplicit('G' . $line, $row[4], \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
        }

        $tmp = tempnam(sys_get_temp_dir(), 'gopay_fixture_');
        (new Xlsx($spreadsheet))->save($tmp);
        $content = (string) file_get_contents($tmp);
        @unlink($tmp);
        return $content;
    }
}
