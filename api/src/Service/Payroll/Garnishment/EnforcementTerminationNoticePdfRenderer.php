<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Garnishment;

use Mpdf\Mpdf;
use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Config\RuntimePaths;
use MyInvoice\Service\Pdf\MpdfFontConfig;
use MyInvoice\Service\Pdf\TwigCache;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;

/** PDF oznámení o skončení pracovního poměru povinného (§ 295 odst. 2 o. s. ř.). */
final class EnforcementTerminationNoticePdfRenderer
{
    public const VERSION = 'mz-enforcement-termination-notice-2026-v1';

    private const CATEGORY_LABELS = [
        'current_maintenance' => 'běžné výživné',
        'maintenance_arrears' => 'nedoplatek výživného',
        'substitute_maintenance' => 'náhradní výživné',
        'other_priority' => 'jiná přednostní pohledávka',
        'non_priority' => 'nepřednostní pohledávka',
    ];

    private ?Environment $twig = null;

    /** @param array<string,mixed> $snapshot */
    public function render(array $snapshot): string
    {
        $snapshot['category_labels'] = self::CATEGORY_LABELS;
        $snapshot['renderer_version'] = self::VERSION;
        $body = $this->twig()->render('enforcement-termination-notice.twig', $snapshot);
        $tmpDir = RuntimePaths::storage('cache/mpdf');
        if (!is_dir($tmpDir)) {
            @mkdir($tmpDir, 0755, true);
        }
        $mpdf = new Mpdf([
            'mode' => 'utf-8',
            'format' => 'A4',
            'orientation' => 'P',
            'margin_left' => 15,
            'margin_right' => 15,
            'margin_top' => 14,
            'margin_bottom' => 15,
            'tempDir' => $tmpDir,
            ...MpdfFontConfig::options(),
        ]);
        $mpdf->SetTitle('Oznámení o skončení pracovního poměru povinného');
        $mpdf->SetSubject('§ 295 odst. 2 občanského soudního řádu');
        $mpdf->SetCreator('MyÚčto.cz');
        $mpdf->AddCustomProperty('PayrollRendererVersion', self::VERSION);
        $mpdf->WriteHTML($body);
        $pdf = $mpdf->Output('', 'S');
        if (!is_string($pdf) || !str_starts_with($pdf, '%PDF-')) {
            throw new \UnexpectedValueException('mPDF nevytvořilo platné oznámení.');
        }

        return $pdf;
    }

    private function twig(): Environment
    {
        if ($this->twig === null) {
            $this->twig = new Environment(
                new FilesystemLoader([Bootstrap::rootDir() . '/api/templates/payroll']),
                ['autoescape' => 'html', 'strict_variables' => true] + TwigCache::options('payroll'),
            );
            $this->twig->addFilter(new \Twig\TwigFilter(
                'minor_money',
                static fn (int $minor): string => number_format(intdiv($minor, 100), 0, ',', ' ')
                    . ',' . str_pad((string) abs($minor % 100), 2, '0', STR_PAD_LEFT),
            ));
            $this->twig->addFilter(new \Twig\TwigFilter(
                'cz_date',
                static fn (?string $date): string => $date === null
                    ? '—'
                    : (new \DateTimeImmutable($date))->format('j. n. Y'),
            ));
        }

        return $this->twig;
    }
}
