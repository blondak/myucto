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

final class AverageEarningsCertificatePdfRenderer
{
    public const VERSION = 'mz-average-earnings-certificate-2026-v1';

    private ?Environment $twig = null;

    public function render(
        AverageEarningsCertificateDocumentData $data,
    ): PayrollArtifact {
        $template = $data->toTemplateData();
        $template['renderer_version'] = self::VERSION;
        $body = $this->twig()->render(
            'average-earnings-certificate.twig',
            $template,
        );
        $tmpDir = RuntimePaths::storage('cache/mpdf');
        if (!is_dir($tmpDir)) {
            @mkdir($tmpDir, 0755, true);
        }
        $mpdf = new Mpdf([
            'mode' => 'utf-8',
            'format' => 'A4',
            'orientation' => 'P',
            'margin_left' => 12,
            'margin_right' => 12,
            'margin_top' => 10,
            'margin_bottom' => 14,
            'tempDir' => $tmpDir,
            ...MpdfFontConfig::options(),
        ]);
        $mpdf->SetTitle(
            'Potvrzení zaměstnavatele pro posouzení nároku na podporu',
        );
        $mpdf->SetSubject(
            'Oddělené potvrzení podle § 313 odst. 2 zákoníku práce',
        );
        $mpdf->SetKeywords(
            'mzdy, průměrný čistý výdělek, podpora v nezaměstnanosti, '
                . self::VERSION,
        );
        $mpdf->SetCreator('MyÚčto.cz');
        $mpdf->AddCustomProperty(
            'PayrollSourceSnapshotSHA256',
            $data->sourceSnapshotSha256,
        );
        $mpdf->AddCustomProperty(
            'PayrollAverageSnapshotSHA256',
            $data->averageSnapshotSha256,
        );
        $mpdf->AddCustomProperty('PayrollRendererVersion', self::VERSION);
        $mpdf->SetHTMLFooter(
            '<table style="width:100%;border-top:0.3pt solid #DEC8D4;'
            . 'font-family:montserrat,dejavusans,sans-serif;font-size:7pt;'
            . 'color:#6B5C66"><tr><td>Potvrzení pro Úřad práce ČR</td>'
            . '<td style="text-align:center">Strana {PAGENO} / {nbpg}</td>'
            . '<td style="text-align:right">MyÚčto.cz</td></tr></table>',
        );
        $mpdf->WriteHTML($body);
        $pdf = $mpdf->Output('', 'S');
        if (!is_string($pdf) || !str_starts_with($pdf, '%PDF-')) {
            throw new \UnexpectedValueException(
                'mPDF nevytvořilo platné potvrzení průměrného výdělku.',
            );
        }
        $fileHash = hash('sha256', $pdf);

        return new PayrollArtifact(
            PayrollDocumentKind::AverageEarningsCertificate,
            $pdf,
            'application/pdf',
            'potvrzeni-pro-podporu-v-nezamestnanosti-'
                . substr($fileHash, 0, 12)
                . '.pdf',
            $data->sourceSnapshotSha256,
            AverageEarningsCertificateDocumentData::SCHEMA_VERSION,
            self::VERSION,
        );
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
                'whole_czk',
                static fn (int $minorUnits): string =>
                    number_format(intdiv($minorUnits, 100), 0, ',', ' '),
            ));
            $this->twig->addFilter(new \Twig\TwigFilter(
                'cz_date',
                static fn (string $date): string =>
                    (new \DateTimeImmutable($date))->format('d.m.Y'),
            ));
        }

        return $this->twig;
    }
}
