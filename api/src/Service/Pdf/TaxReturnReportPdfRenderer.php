<?php

declare(strict_types=1);

namespace MyInvoice\Service\Pdf;

/**
 * Pracovní PDF sestava přiznání k dani z příjmů (DPPO i DPFO), A4 na výšku.
 *
 * Data = výstup {@see \MyInvoice\Service\Tax\Return\TaxReturnReportBuilder}. Vlastní
 * přehledná sestava MyÚčta, ne napodobenina tiskopisu Finanční správy; na každé straně
 * nese, že není podáním. Čísla se formátují tady (oddělovač tisíců, typografické minus),
 * builder drží surové hodnoty kvůli testům shody s XML.
 */
final class TaxReturnReportPdfRenderer extends ReportPdfRendererBase
{
    /** Pevná mezera jako oddělovač tisíců, ať se částka v úzkém sloupci nezalomí. */
    private const THOUSANDS = "\u{00A0}";
    private const MINUS = "\u{2212}";

    public function render(array $data): string
    {
        $mpdf = $this->mpdf([
            'format' => 'A4',
            'orientation' => 'P',
            'margin_left' => 14,
            'margin_right' => 14,
            'margin_top' => 18,
            'margin_bottom' => 18,
            'margin_header' => 7,
            'margin_footer' => 8,
        ]);
        $header = (array) ($data['header'] ?? []);
        $mpdf->SetTitle(sprintf('%s %s, pracovní sestava', (string) ($data['form_code'] ?? ''), (string) ($data['year'] ?? '')));
        $mpdf->SetHTMLHeader($this->runningHeader($data));
        $mpdf->SetHTMLFooter($this->footer((string) ($header['generated_at'] ?? '')));
        $mpdf->WriteHTML($this->html($data));
        return $mpdf->Output('', 'S');
    }

    /**
     * HTML sestavy před převodem do PDF (veřejné kvůli testům: ověřují, že v sestavě
     * jsou tytéž částky jako v XML).
     *
     * @param array<string,mixed> $data
     */
    public function html(array $data): string
    {
        return $this->renderTemplate('tax_return_report.twig', ['r' => $this->prepare($data)]);
    }

    public static function formatValue(mixed $value, string $format): string
    {
        if ($value === null || $value === '') {
            return $format === 'text' ? '' : "\u{2013}";
        }
        return match ($format) {
            'money' => self::number((float) $value, 0),
            'money2' => self::number((float) $value, 2),
            'decimal' => self::number((float) $value, 2),
            'percent' => self::number((float) $value, 0) . "\u{00A0}%",
            'int' => (string) (int) $value,
            'year' => (int) $value > 0 ? (string) (int) $value : "\u{2013}",
            'date' => self::date((string) $value),
            default => (string) $value,
        };
    }

    private static function number(float $value, int $decimals): string
    {
        $abs = number_format(abs($value), $decimals, ',', self::THOUSANDS);
        return (round($value, $decimals) < 0 ? self::MINUS : '') . $abs;
    }

    private static function date(string $value): string
    {
        $d = \DateTimeImmutable::createFromFormat('!Y-m-d', substr($value, 0, 10));
        return $d === false ? $value : $d->format('d.m.Y');
    }

    private static function isNegative(mixed $value, string $format): bool
    {
        return in_array($format, ['money', 'money2', 'decimal'], true) && is_numeric($value) && (float) $value < 0;
    }

    /**
     * @param array<string,mixed> $data
     * @return array<string,mixed>
     */
    private function prepare(array $data): array
    {
        foreach ((array) ($data['summary']['rows'] ?? []) as $i => $row) {
            $data['summary']['rows'][$i]['text'] = self::formatValue($row['value'], (string) $row['format']);
            $data['summary']['rows'][$i]['neg'] = self::isNegative($row['value'], (string) $row['format']);
        }
        if (isset($data['summary']['result'])) {
            $data['summary']['result']['text'] = self::formatValue($data['summary']['result']['value'], 'money');
        }
        if (is_array($data['summary']['advances'] ?? null)) {
            $data['summary']['advances']['last_known_tax_text'] = self::formatValue($data['summary']['advances']['last_known_tax'], 'money');
            $data['summary']['advances']['amount_text'] = self::formatValue($data['summary']['advances']['amount'], 'money');
            $data['summary']['advances']['filing_deadline_text'] = $data['summary']['advances']['filing_deadline'] !== ''
                ? self::formatValue($data['summary']['advances']['filing_deadline'], 'date')
                : '';
        }
        foreach ((array) ($data['amendment'] ?? []) as $i => $row) {
            $data['amendment'][$i]['text'] = self::formatValue($row['value'], (string) $row['format']);
            $data['amendment'][$i]['neg'] = self::isNegative($row['value'], (string) $row['format']);
        }
        foreach ((array) ($data['lines']['rows'] ?? []) as $i => $row) {
            $data['lines']['rows'][$i]['text'] = self::formatValue($row['value'], (string) $row['format']);
            $data['lines']['rows'][$i]['neg'] = self::isNegative($row['value'], (string) $row['format']);
        }
        foreach ((array) ($data['statements'] ?? []) as $s => $statement) {
            foreach ((array) $statement['rows'] as $i => $row) {
                $data['statements'][$s]['rows'][$i]['texts'] = array_map(
                    static fn ($v): array => ['text' => self::formatValue($v, 'money'), 'neg' => $v < 0],
                    (array) $row['values'],
                );
            }
        }
        foreach ((array) ($data['tables'] ?? []) as $t => $table) {
            foreach ((array) $table['rows'] as $i => $row) {
                foreach ((array) $row['cells'] as $c => $cell) {
                    $data['tables'][$t]['rows'][$i]['cells'][$c]['text'] = self::formatValue($cell['value'], (string) $cell['format']);
                    $data['tables'][$t]['rows'][$i]['cells'][$c]['neg'] = self::isNegative($cell['value'], (string) $cell['format']);
                    $data['tables'][$t]['rows'][$i]['cells'][$c]['align'] = (string) ($table['columns'][$c]['align'] ?? 'left');
                }
            }
        }
        return $data;
    }

    /** @param array<string,mixed> $data */
    private function runningHeader(array $data): string
    {
        $header = (array) ($data['header'] ?? []);
        $left = htmlspecialchars(trim((string) ($header['name'] ?? '')) . ' · ' . (string) ($data['form_code'] ?? '') . ' ' . (string) ($data['year'] ?? ''), ENT_QUOTES);
        return '<table width="100%" style="font-family: montserrat; font-size: 6.5pt; color: #7A748C; border-bottom: 0.2mm solid #E7E3EE;">'
            . '<tr><td width="60%" style="padding-bottom: 1mm;">' . $left . '</td>'
            . '<td width="40%" align="right" style="padding-bottom: 1mm; color: #C68A30; font-weight: bold;">Pracovní sestava, není podáním</td></tr></table>';
    }

    private function footer(string $generatedAt): string
    {
        return '<table width="100%" style="font-family: montserrat; font-size: 6.5pt; color: #7A748C; border-top: 0.2mm solid #E7E3EE;">'
            . '<tr><td width="50%" style="padding-top: 1mm;">Pracovní sestava MyÚčto, není podáním pro finanční úřad</td>'
            . '<td width="20%" align="center" style="padding-top: 1mm;">Strana {PAGENO} / {nbpg}</td>'
            . '<td width="30%" align="right" style="padding-top: 1mm;">Vytvořeno ' . htmlspecialchars($generatedAt, ENT_QUOTES) . '</td></tr></table>';
    }
}
