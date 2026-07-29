<?php

declare(strict_types=1);

namespace MyInvoice\Service\Stock;

use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx as XlsxWriter;

/**
 * XLSX export skladových sestav (Epic SKLAD, vzor F2 {@see \MyInvoice\Service\Accounting\Reports\ReportXlsxExporter}).
 * Vlastní třída (ne rozšíření F2 exporteru) — Sklad je samostatný opt-in modul.
 */
final class StockReportXlsxExporter
{
    private const MIME = 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet';

    /**
     * @param array<string,mixed> $data výstup StockReportService::status()
     * @return array{bytes:string, filename:string, mime:string}
     */
    public function status(array $data): array
    {
        $ss = new Spreadsheet();
        $sheet = $ss->getActiveSheet();
        $sheet->setTitle('Stav zásob');
        $sheet->setCellValue('A1', 'Stav zásob k ' . (new \DateTimeImmutable())->format('d.m.Y'));
        $sheet->getStyle('A1')->getFont()->setBold(true)->setSize(14);

        $headers = ['Sklad', 'SKU', 'Název', 'Typ', 'MJ', 'Množství', 'Prům. cena', 'Hodnota'];
        $cols = count($headers);
        $head = 3;
        $this->headerRow($sheet, $head, $headers);

        $r = $head + 1;
        foreach ($data['items'] ?? [] as $row) {
            $sheet->setCellValueExplicit([1, $r], (string) $row['warehouse_code'], DataType::TYPE_STRING);
            $sheet->setCellValueExplicit([2, $r], (string) $row['sku'], DataType::TYPE_STRING);
            $sheet->setCellValueExplicit([3, $r], (string) $row['name'], DataType::TYPE_STRING);
            $sheet->setCellValueExplicit([4, $r], (string) $row['item_type'], DataType::TYPE_STRING);
            $sheet->setCellValueExplicit([5, $r], (string) $row['unit'], DataType::TYPE_STRING);
            $sheet->setCellValue([6, $r], (float) $row['qty']);
            $sheet->setCellValue([7, $r], (float) $row['avg_unit_cost']);
            $sheet->setCellValue([8, $r], (float) $row['value_total']);
            $r++;
        }

        $t = $data['totals'] ?? [];
        $sheet->setCellValue([1, $r], 'CELKEM (' . (int) ($t['count'] ?? 0) . ' položek)');
        $sheet->setCellValue([8, $r], (float) ($t['value_total'] ?? 0));
        $this->boldRow($sheet, $r, $cols);

        $this->finishTable($sheet, $head, $r, $cols, 6);

        return $this->out($ss, 'stav-zasob-' . (new \DateTimeImmutable())->format('Y-m-d') . '.xlsx');
    }

    /**
     * @param array<string,mixed> $data výstup StockReportService::valuation()
     * @return array{bytes:string, filename:string, mime:string}
     */
    public function valuation(array $data): array
    {
        $date = (string) ($data['date'] ?? '');
        $ss = new Spreadsheet();
        $sheet = $ss->getActiveSheet();
        $sheet->setTitle('Ocenění zásob');
        $sheet->setCellValue('A1', 'Ocenění zásob k ' . $this->czDate($date));
        $sheet->getStyle('A1')->getFont()->setBold(true)->setSize(14);

        $headers = ['Sklad', 'SKU', 'Název', 'MJ', 'Množství', 'Hodnota'];
        $cols = count($headers);
        $head = 3;
        $this->headerRow($sheet, $head, $headers);

        $r = $head + 1;
        foreach ($data['items'] ?? [] as $row) {
            $sheet->setCellValueExplicit([1, $r], (string) $row['warehouse_code'], DataType::TYPE_STRING);
            $sheet->setCellValueExplicit([2, $r], (string) $row['sku'], DataType::TYPE_STRING);
            $sheet->setCellValueExplicit([3, $r], (string) $row['name'], DataType::TYPE_STRING);
            $sheet->setCellValueExplicit([4, $r], (string) $row['unit'], DataType::TYPE_STRING);
            $sheet->setCellValue([5, $r], (float) $row['qty']);
            $sheet->setCellValue([6, $r], (float) $row['value_total']);
            $r++;
        }

        $t = $data['totals'] ?? [];
        $sheet->setCellValue([1, $r], 'CELKEM (' . (int) ($t['count'] ?? 0) . ' položek)');
        $sheet->setCellValue([6, $r], (float) ($t['value_total'] ?? 0));
        $this->boldRow($sheet, $r, $cols);

        $this->finishTable($sheet, $head, $r, $cols, 5);

        return $this->out($ss, 'oceneni-zasob-' . $date . '.xlsx');
    }

    /**
     * @param array<string,mixed> $item skladová karta {sku,name,unit}
     * @param array<string,mixed> $data výstup StockItemAction::movements() (items, opening_balance)
     * @return array{bytes:string, filename:string, mime:string}
     */
    public function itemMovements(array $item, array $data): array
    {
        $ss = new Spreadsheet();
        $sheet = $ss->getActiveSheet();
        $sheet->setTitle('Skladová karta');
        $sheet->setCellValue('A1', 'Skladová karta ' . (string) ($item['sku'] ?? '') . ' — ' . (string) ($item['name'] ?? ''));
        $sheet->getStyle('A1')->getFont()->setBold(true)->setSize(14);
        $sheet->setCellValue('A2', 'Počáteční stav: ' . (string) ($data['opening_balance'] ?? '0'));

        $headers = ['Datum', 'Doklad', 'Typ', 'Sklad', 'Množství', 'Cena/MJ', 'Hodnota', 'Stav po pohybu'];
        $cols = count($headers);
        $head = 4;
        $this->headerRow($sheet, $head, $headers);

        $r = $head + 1;
        foreach ($data['items'] ?? [] as $row) {
            $sheet->setCellValue([1, $r], $this->czDate((string) $row['doc_date']));
            $sheet->setCellValueExplicit([2, $r], (string) ($row['doc_number'] ?? ''), DataType::TYPE_STRING);
            $sheet->setCellValueExplicit([3, $r], (string) $row['doc_type'], DataType::TYPE_STRING);
            $sheet->setCellValueExplicit([4, $r], (string) $row['warehouse_code'], DataType::TYPE_STRING);
            $sheet->setCellValue([5, $r], (float) $row['qty_signed']);
            $sheet->setCellValue([6, $r], (float) $row['unit_cost']);
            $sheet->setCellValue([7, $r], (float) $row['value_total']);
            $sheet->setCellValue([8, $r], (float) $row['balance_after']);
            $r++;
        }

        $this->finishTable($sheet, $head, $r - 1, $cols, 5);

        return $this->out($ss, 'skladova-karta-' . (string) ($item['sku'] ?? '') . '.xlsx');
    }

    private function headerRow(Worksheet $sheet, int $row, array $headers): void
    {
        foreach ($headers as $i => $h) {
            $sheet->setCellValue([$i + 1, $row], $h);
        }
        $last = Coordinate::stringFromColumnIndex(count($headers));
        $sheet->getStyle("A{$row}:{$last}{$row}")->getFont()->setBold(true);
        $sheet->getStyle("A{$row}:{$last}{$row}")->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('EEEEEE');
    }

    private function boldRow(Worksheet $sheet, int $row, int $cols): void
    {
        $last = Coordinate::stringFromColumnIndex($cols);
        $sheet->getStyle("A{$row}:{$last}{$row}")->getFont()->setBold(true);
    }

    private function finishTable(Worksheet $sheet, int $headRow, int $lastRow, int $cols, int $firstNumCol): void
    {
        if ($lastRow < $headRow) {
            $lastRow = $headRow;
        }
        $last = Coordinate::stringFromColumnIndex($cols);
        $sheet->getStyle("A{$headRow}:{$last}{$lastRow}")->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN);
        $numFirst = Coordinate::stringFromColumnIndex($firstNumCol);
        $sheet->getStyle("{$numFirst}{$headRow}:{$last}{$lastRow}")->getAlignment()->setHorizontal(\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_RIGHT);
        for ($i = 1; $i <= $cols; $i++) {
            $sheet->getColumnDimension(Coordinate::stringFromColumnIndex($i))->setAutoSize(true);
        }
    }

    private function czDate(string $v): string
    {
        if ($v === '') {
            return '';
        }
        try {
            return (new \DateTimeImmutable($v))->format('d.m.Y');
        } catch (\Throwable) {
            return $v;
        }
    }

    /** @return array{bytes:string, filename:string, mime:string} */
    private function out(Spreadsheet $ss, string $filename): array
    {
        $tmp = tempnam(sys_get_temp_dir(), 'stockexp_') . '.xlsx';
        (new XlsxWriter($ss))->save($tmp);
        $bytes = (string) file_get_contents($tmp);
        @unlink($tmp);
        $ss->disconnectWorksheets();
        return ['bytes' => $bytes, 'filename' => $filename, 'mime' => self::MIME];
    }
}
