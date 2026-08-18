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

final class PayslipPdfRenderer
{
    /**
     * ZÁMĚRNĚ se nezvyšuje spolu se šablonou, na rozdíl od mzdového listu.
     *
     * Páska je vázaná na běh, ne na roční revizi, a její archiv klíčuje
     * idempotenci mimo jiné touhle konstantou. Roční archiv umí pro tutéž
     * revizi vydat další verzi dokumentu ({@see PayrollDocumentService::
     * archiveAnnualPdf}), běhový ne — tam by jiná verze při opakovaném
     * spuštění dávky narazila na `uq_payroll_document_revision` a skončila
     * chybou místo vrácení už archivovaného dokladu.
     *
     * Obsahová verze pásky proto žije tam, kde skutečně je: ve snapshotu
     * `payroll-payslip-document.v2`, který je součástí zmrazeného výsledku
     * osoby, a tedy i otisku revize běhu. Nová páska vzniká novým během;
     * archivovaná zůstává bajt na bajt stejná, protože se z uloženého PDF
     * jen vydává.
     */
    public const VERSION = 'mz-16-payslip-v1';

    private ?Environment $twig = null;

    public function render(PayslipDocumentData $data): RenderedPayslipDocument
    {
        $templateData = $data->toTemplateData();
        $templateData['renderer_version'] = self::VERSION;
        $body = $this->twig()->render('payslip.twig', $templateData);

        $tmpDir = RuntimePaths::storage('cache/mpdf');
        if (!is_dir($tmpDir)) {
            @mkdir($tmpDir, 0755, true);
        }

        $mpdf = new Mpdf([
            'mode' => 'utf-8',
            'format' => 'A4',
            'orientation' => 'P',
            'margin_left' => 13,
            'margin_right' => 13,
            'margin_top' => 12,
            'margin_bottom' => 15,
            'tempDir' => $tmpDir,
            'autoPageBreak' => true,
            ...MpdfFontConfig::options(),
        ]);
        $mpdf->SetTitle('Výplatní páska ' . $data->period);
        $mpdf->SetSubject('Výplatní páska, revize ' . $data->revisionId);
        $mpdf->SetKeywords('mzdy, výplatní páska, ' . self::VERSION);
        $mpdf->SetCreator('MyÚčto.cz');
        $mpdf->AddCustomProperty('PayrollRevision', $data->revisionId);
        $mpdf->AddCustomProperty('PayrollSourceSnapshotSHA256', $data->sourceSnapshotSha256);
        $mpdf->AddCustomProperty('PayrollRendererVersion', self::VERSION);
        $footerPeriod = htmlspecialchars($data->period, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $footerRevision = htmlspecialchars($data->revisionId, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $mpdf->SetHTMLFooter(
            '<table style="width:100%; border-collapse:collapse; border-top:0.3pt solid #DEC8D4;'
            . ' font-family:montserrat,dejavusans,sans-serif; font-size:7pt; color:#6B5C66;">'
            . '<tr><td style="padding-top:1.5mm; text-align:left;">Výplatní páska ' . $footerPeriod
            . ' | revize ' . $footerRevision . '</td>'
            . '<td style="padding-top:1.5mm; text-align:center;">Strana {PAGENO} / {nbpg}</td>'
            . '<td style="padding-top:1.5mm; text-align:right;">MyÚčto.cz</td></tr></table>',
        );
        $mpdf->WriteHTML($body);
        $pdfBytes = $mpdf->Output('', 'S');
        if (!is_string($pdfBytes)) {
            throw new \UnexpectedValueException('mPDF did not return payslip bytes.');
        }

        return RenderedPayslipDocument::fromPdf($pdfBytes, $data, self::VERSION);
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
                    $whole = intdiv($absolute, 100);
                    $minor = $absolute % 100;

                    return $sign
                        . number_format($whole, 0, ',', ' ')
                        . ','
                        . str_pad((string) $minor, 2, '0', STR_PAD_LEFT);
                },
            ));
        }

        return $this->twig;
    }
}
