<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Document;

use Mpdf\Mpdf;
use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Config\RuntimePaths;
use MyInvoice\Service\Pdf\MpdfFontConfig;
use MyInvoice\Service\Pdf\TwigCache;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;

final class PayrollSheetPdfRenderer
{
    public const VERSION = 'mz-16-payroll-sheet-v3';

    private ?Environment $twig = null;

    public function render(PayrollSheetDocumentData $data): PayrollArtifact
    {
        $template = $data->toTemplateData();
        $template['renderer_version'] = self::VERSION;
        $body = $this->twig()->render('payroll-sheet.twig', $template);
        $tmpDir = RuntimePaths::storage('cache/mpdf');
        if (!is_dir($tmpDir)) {
            @mkdir($tmpDir, 0755, true);
        }
        $mpdf = new Mpdf([
            'mode' => 'utf-8',
            'format' => 'A4',
            'orientation' => 'L',
            'margin_left' => 9,
            'margin_right' => 9,
            'margin_top' => 9,
            'margin_bottom' => 13,
            'tempDir' => $tmpDir,
            ...MpdfFontConfig::options(),
        ]);
        $mpdf->SetTitle('Mzdový list ' . $data->taxYear);
        $mpdf->SetSubject('Roční mzdový list ze schválených mzdových revizí');
        $mpdf->SetKeywords('mzdy, mzdový list, ' . self::VERSION);
        $mpdf->SetCreator('MyÚčto.cz');
        $mpdf->AddCustomProperty(
            'PayrollSourceSnapshotHMACSHA256',
            $data->sourceSnapshotSha256,
        );
        $mpdf->AddCustomProperty('PayrollRendererVersion', self::VERSION);
        $mpdf->SetHTMLFooter(
            '<table style="width:100%;border-top:0.3pt solid #DEC8D4;'
            . 'font-family:montserrat,dejavusans,sans-serif;font-size:7pt;color:#6B5C66">'
            . '<tr><td>Mzdový list ' . $data->taxYear . '</td>'
            . '<td style="text-align:center">Strana {PAGENO} / {nbpg}</td>'
            . '<td style="text-align:right">MyÚčto.cz</td></tr></table>',
        );
        $mpdf->WriteHTML($body);
        $pdf = $mpdf->Output('', 'S');
        if (!is_string($pdf) || !str_starts_with($pdf, '%PDF-')) {
            throw new \UnexpectedValueException('mPDF nevytvořilo platný mzdový list.');
        }
        $fileHash = hash('sha256', $pdf);
        return new PayrollArtifact(
            PayrollDocumentKind::PayrollSheet,
            $pdf,
            'application/pdf',
            sprintf(
                'mzdovy-list-%d-%s.pdf',
                $data->taxYear,
                substr($fileHash, 0, 12),
            ),
            $data->sourceSnapshotSha256,
            PayrollSheetSnapshotBuilder::SCHEMA_VERSION,
            self::VERSION,
        );
    }

    private function twig(): Environment
    {
        if ($this->twig === null) {
            $this->twig = new Environment(
                new FilesystemLoader([Bootstrap::rootDir() . '/api/templates/payroll']),
                [
                    'autoescape' => 'html',
                    'strict_variables' => true,
                ] + TwigCache::options('payroll'),
            );
            $this->twig->addFilter(new \Twig\TwigFilter(
                'minor_money',
                static function (int $minorUnits): string {
                    $sign = $minorUnits < 0 ? '-' : '';
                    $absolute = abs($minorUnits);
                    return $sign
                        . number_format(intdiv($absolute, 100), 0, ',', ' ')
                        . ','
                        . str_pad((string) ($absolute % 100), 2, '0', STR_PAD_LEFT);
                },
            ));
        }
        return $this->twig;
    }
}
