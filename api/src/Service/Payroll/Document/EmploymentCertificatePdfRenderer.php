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

final class EmploymentCertificatePdfRenderer
{
    public const VERSION = 'mz-employment-certificate-2026-v1';

    private ?Environment $twig = null;

    public function render(
        EmploymentCertificateDocumentData $data,
    ): PayrollArtifact {
        $template = $data->toTemplateData();
        $template['renderer_version'] = self::VERSION;
        $body = $this->twig()->render(
            'employment-certificate.twig',
            $template,
        );
        $mpdf = $this->mpdf();
        $mpdf->SetTitle('Potvrzení o zaměstnání');
        $mpdf->SetSubject(
            'Potvrzení o zaměstnání podle § 313 odst. 1 zákoníku práce',
        );
        $mpdf->SetKeywords(
            'mzdy, potvrzení o zaměstnání, zápočtový list, '
                . self::VERSION,
        );
        $mpdf->SetCreator('MyÚčto.cz');
        $mpdf->AddCustomProperty(
            'PayrollSourceSnapshotSHA256',
            $data->sourceSnapshotSha256,
        );
        $mpdf->AddCustomProperty('PayrollRendererVersion', self::VERSION);
        $mpdf->SetHTMLFooter(self::footer('Potvrzení o zaměstnání'));
        $mpdf->WriteHTML($body);
        $pdf = $mpdf->Output('', 'S');
        if (!is_string($pdf) || !str_starts_with($pdf, '%PDF-')) {
            throw new \UnexpectedValueException(
                'mPDF nevytvořilo platné potvrzení o zaměstnání.',
            );
        }
        $fileHash = hash('sha256', $pdf);

        return new PayrollArtifact(
            PayrollDocumentKind::EmploymentCertificate,
            $pdf,
            'application/pdf',
            'potvrzeni-o-zamestnani-'
                . substr($fileHash, 0, 12)
                . '.pdf',
            $data->sourceSnapshotSha256,
            EmploymentCertificateDocumentData::SCHEMA_VERSION,
            self::VERSION,
        );
    }

    private function mpdf(): Mpdf
    {
        $tmpDir = RuntimePaths::storage('cache/mpdf');
        if (!is_dir($tmpDir)) {
            @mkdir($tmpDir, 0755, true);
        }

        return new Mpdf([
            'mode' => 'utf-8',
            'format' => 'A4',
            'orientation' => 'P',
            'margin_left' => 13,
            'margin_right' => 13,
            'margin_top' => 11,
            'margin_bottom' => 15,
            'tempDir' => $tmpDir,
            ...MpdfFontConfig::options(),
        ]);
    }

    private function twig(): Environment
    {
        if ($this->twig === null) {
            $this->twig = new Environment(
                new FilesystemLoader([
                    Bootstrap::rootDir() . '/api/templates/payroll',
                ]),
                [
                    'autoescape' => 'html',
                    'strict_variables' => true,
                ] + TwigCache::options('payroll'),
            );
            $this->twig->addFilter(new \Twig\TwigFilter(
                'minor_money',
                self::formatMoney(...),
            ));
            $this->twig->addFilter(new \Twig\TwigFilter(
                'cz_date',
                self::formatDate(...),
            ));
        }

        return $this->twig;
    }

    private static function formatMoney(int $minorUnits): string
    {
        $whole = intdiv($minorUnits, 100);
        $minor = $minorUnits % 100;

        return number_format($whole, 0, ',', ' ')
            . ','
            . str_pad((string) $minor, 2, '0', STR_PAD_LEFT);
    }

    private static function formatDate(string $date): string
    {
        return (new \DateTimeImmutable($date))->format('d.m.Y');
    }

    private static function footer(string $title): string
    {
        return '<table style="width:100%;border-top:0.3pt solid #DEC8D4;'
            . 'font-family:montserrat,dejavusans,sans-serif;font-size:7pt;'
            . 'color:#6B5C66"><tr><td>'
            . $title
            . '</td><td style="text-align:center">Strana {PAGENO} / {nbpg}</td>'
            . '<td style="text-align:right">MyÚčto.cz</td></tr></table>';
    }
}
