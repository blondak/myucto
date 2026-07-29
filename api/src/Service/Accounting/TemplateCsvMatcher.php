<?php

declare(strict_types=1);

namespace MyInvoice\Service\Accounting;

use MyInvoice\Service\Accounting\Codebooks\AbstractCodebookImportService;

/**
 * Napáruje CSV mzdovou/jinou rekapitulaci (2 sloupce: název položky NEBO kód účtu;
 * částka) na řádky šablony ručního zápisu (Fáze F, mzdový můstek). Čistá funkce bez
 * DB — testovatelná přímo na poli řádků šablony. Používá {@see AbstractCodebookImportService}
 * jen pro sdílené statické parsery (normalize/parseDecimal — vzor F5 číselníkových importů),
 * bez závislosti na jeho DB/report infrastruktuře (tady se nic nezapisuje, jen preview).
 *
 * Řádek CSV se považuje za datový, jen když druhý sloupec parsuje jako číslo — jinak
 * (typicky hlavička „Položka;Částka") se tiše přeskočí. Shoda: nejdřív přesný kód účtu
 * (case-insensitive), pak normalizovaný název řádku šablony (diakritika/velikost/mezery
 * sjednocené). Nenapárované řádky CSV se vrací zvlášť, ať je účetní může doplnit ručně.
 */
final class TemplateCsvMatcher
{
    private const MAX_ROWS = 500;

    /**
     * @param list<array{line_no:int,label:?string,account_code:string,side:string,default_amount:?float,cost_center:?string}> $templateLines
     * @return array{
     *     lines:list<array{line_no:int,label:?string,account_code:string,side:string,cost_center:?string,amount:?float}>,
     *     unmatched:list<array{value:string,amount:float}>,
     *     matched_count:int,
     * }
     */
    public function match(array $templateLines, string $csvContent): array
    {
        $rows = $this->readCsv($csvContent);

        $byCode = [];
        $byLabel = [];
        foreach ($templateLines as $line) {
            $byCode[strtoupper(trim($line['account_code']))] = $line['line_no'];
            $label = $line['label'] ?? '';
            if ($label !== '') {
                $byLabel[AbstractCodebookImportService::normalize($label)] = $line['line_no'];
            }
        }

        /** @var array<int,float> $amounts line_no => amount */
        $amounts = [];
        $unmatched = [];
        foreach ($rows as $row) {
            if (count($row) < 2) {
                continue;
            }
            $key = trim((string) $row[0]);
            if ($key === '') {
                continue;
            }
            $amount = AbstractCodebookImportService::parseDecimal((string) $row[1]);
            if ($amount === null) {
                continue; // hlavička nebo neparsovatelný řádek — tiše přeskočit
            }

            $lineNo = $byCode[strtoupper($key)] ?? $byLabel[AbstractCodebookImportService::normalize($key)] ?? null;
            if ($lineNo === null) {
                $unmatched[] = ['value' => $key, 'amount' => $amount];
                continue;
            }
            $amounts[$lineNo] = $amount;
        }

        $lines = [];
        foreach ($templateLines as $line) {
            $lines[] = [
                'line_no'      => $line['line_no'],
                'label'        => $line['label'],
                'account_code' => $line['account_code'],
                'side'         => $line['side'],
                'cost_center'  => $line['cost_center'],
                'amount'       => $amounts[$line['line_no']] ?? $line['default_amount'],
            ];
        }

        return ['lines' => $lines, 'unmatched' => $unmatched, 'matched_count' => count($amounts)];
    }

    /**
     * Zjednodušená verze {@see AbstractCodebookImportService::readCsv} (bez XLSX větve,
     * bez CodebookImportException) — jen BOM strip + detekce oddělovače + fgetcsv.
     *
     * @return list<list<string>>
     */
    private function readCsv(string $content): array
    {
        $content = preg_replace('/^\xEF\xBB\xBF/', '', $content) ?? $content;
        $firstLine = strtok($content, "\r\n") ?: '';
        $delimiter = substr_count($firstLine, ';') >= substr_count($firstLine, ',') ? ';' : ',';

        $rows = [];
        $stream = fopen('php://temp', 'r+');
        fwrite($stream, $content);
        rewind($stream);
        while (($row = fgetcsv($stream, 0, $delimiter, '"', '\\')) !== false) {
            $rows[] = array_map(static fn ($v) => (string) ($v ?? ''), $row);
            if (count($rows) >= self::MAX_ROWS) {
                break;
            }
        }
        fclose($stream);
        return $rows;
    }
}
