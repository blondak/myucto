<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Support;

use Mpdf\Mpdf;
use MyInvoice\Service\Pdf\MpdfFontConfig;
use MyInvoice\Infrastructure\Config\RuntimePaths;

/**
 * Vyrobí SYNTETICKÉ PDF v layoutu denního výpisu KB („VÝPIS DENNÍ PŘI POHYBU").
 *
 * Proč se generuje a nepřikládá hotový soubor: repozitář je veřejný, takže v něm
 * nesmí ležet skutečný výpis z banky. Účty, jména i částky jsou vymyšlené.
 *
 * Generuje se PDF, ne jen text, protože testovaný řetěz začíná u textové vrstvy
 * (Smalot\PdfParser v {@see \MyInvoice\Service\Bank\Pdf\BankStatementPdfParserRegistry}) —
 * na prostém textu by se neotestovalo právě to místo, kde se výpisy z e-mailu lámou.
 */
final class KbDailyStatementPdfFactory
{
    /**
     * @param list<array{date?:string,description:string,counterparty?:string,account?:string,
     *   vs?:string,ks?:string,amount:string}> $rows Částka jako text KB („7 033,00" / „-181,50").
     */
    public static function build(
        string $accountNumber,
        string $bankCode,
        string $currency,
        string $statementDate,
        string $statementNumber,
        string $prevBalance,
        string $currBalance,
        string $creditTotal,
        string $debitTotal,
        array $rows,
    ): string {
        $lines = [
            'Datum výpisu: ' . $statementDate,
            'Číslo výpisu: ' . $statementNumber,
            'Strana: 1/1',
            'VÝPIS DENNÍ PŘI POHYBU',
            'k účtu: ' . $accountNumber . '/' . $bankCode,
            'typ: Profi účet Gold',
            'měna: ' . $currency,
            'www.kb.cz',
            'BIC / SWIFT kód: KOMBCZPPXXX',
            'Počáteční zůstatek   ' . $prevBalance,
            'Konečný zůstatek   ' . $currBalance,
            'POČÁTEČNÍ ZŮSTATEK   ' . $prevBalance,
            'Datum', 'zúčtování', 'Datum', 'transakce',
            'Popis transakce', 'Identifikace transakce',
            'Název protiúčtu / Číslo a typ karty',
            'Protiúčet a kód banky / Obchodní místo',
            'VS', 'KS', 'SS', 'Připsáno', 'Odepsáno',
        ];
        foreach ($rows as $row) {
            $lines[] = ($row['date'] ?? $statementDate) . $row['description'];
            if (isset($row['counterparty'])) $lines[] = $row['counterparty'];
            if (isset($row['account'])) $lines[] = $row['account'];
            if (isset($row['vs'])) $lines[] = $row['vs'];
            if (isset($row['ks'])) $lines[] = $row['ks'];
            $lines[] = $row['amount'];
        }
        $lines[] = 'KONEČNÝ ZŮSTATEK   ' . $currBalance;
        $lines[] = 'Rekapitulace transakcí na účtu   Připsáno   Odepsáno';
        $lines[] = 'Obraty na účtu   ' . $creditTotal . '   -' . $debitTotal;
        $lines[] = 'Vklad na tomto účtu je pojištěn.';

        $html = '<style>body{font-family:dejavusans;font-size:8pt}div{white-space:pre}</style>';
        foreach ($lines as $line) {
            $html .= '<div>' . htmlspecialchars($line, ENT_QUOTES, 'UTF-8') . '</div>';
        }

        $tmpDir = RuntimePaths::storage('cache/mpdf');
        if (!is_dir($tmpDir)) @mkdir($tmpDir, 0755, true);
        $mpdf = new Mpdf([
            'mode' => 'utf-8', 'format' => 'A4',
            'margin_left' => 10, 'margin_right' => 10, 'margin_top' => 10, 'margin_bottom' => 10,
            'tempDir' => $tmpDir, ...MpdfFontConfig::options(),
        ]);
        $mpdf->WriteHTML($html);
        return (string) $mpdf->Output('', 'S');
    }
}
