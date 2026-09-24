<?php

declare(strict_types=1);

namespace MyInvoice\Service\Anonymization;

/**
 * Zástupné soubory místo příloh, skenů a výpisů.
 *
 * Anonymizovaná kopie nesmí nést obsah originálních dokumentů. Kde aplikace čeká
 * soubor (příloha, PDF faktury, sken), dostane platný, ale prázdný dokument téhož
 * typu — PDF s jednou stránkou, obrázek 1×1 px, text s poznámkou.
 */
final class PlaceholderFiles
{
    public const NOTE = 'Anonymizovany zastupny soubor - obsah originalu byl odstranen.';

    /** Adresáře úložiště, které se do zrcadla nekopírují (cache, dočasné, zálohy, exporty). */
    private const SKIP_TOP_LEVEL = [
        'backup', 'cache', 'instance-exports', 'locks', 'logs', 'mpdf-temp', 'support', 'tmp',
    ];

    private const IMAGE_EXTENSIONS = ['png', 'jpg', 'jpeg', 'gif', 'webp', 'bmp', 'tif', 'tiff', 'heic'];

    public static function content(string $extension): string
    {
        $extension = strtolower($extension);

        return match (true) {
            $extension === 'pdf' => self::pdf(),
            in_array($extension, self::IMAGE_EXTENSIONS, true) => self::png(),
            $extension === 'xml', $extension === 'isdoc' => '<?xml version="1.0" encoding="UTF-8"?>' . "\n<anonymized>" . self::NOTE . "</anonymized>\n",
            $extension === 'json' => '{"anonymized":true}',
            default => self::NOTE . "\n",
        };
    }

    public static function pdf(): string
    {
        $stream = 'BT /F1 14 Tf 72 770 Td (' . self::NOTE . ') Tj ET';
        $objects = [
            '<< /Type /Catalog /Pages 2 0 R >>',
            '<< /Type /Pages /Kids [3 0 R] /Count 1 >>',
            '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 595 842] /Resources << /Font << /F1 4 0 R >> >> /Contents 5 0 R >>',
            '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>',
            '<< /Length ' . strlen($stream) . " >>\nstream\n" . $stream . "\nendstream",
        ];
        $pdf = "%PDF-1.4\n";
        $offsets = [];
        foreach ($objects as $i => $object) {
            $offsets[] = strlen($pdf);
            $pdf .= ($i + 1) . " 0 obj\n" . $object . "\nendobj\n";
        }
        $xref = strlen($pdf);
        $pdf .= "xref\n0 " . (count($objects) + 1) . "\n0000000000 65535 f \n";
        foreach ($offsets as $offset) {
            $pdf .= sprintf("%010d 00000 n \n", $offset);
        }

        return $pdf . "trailer\n<< /Size " . (count($objects) + 1) . " /Root 1 0 R >>\nstartxref\n{$xref}\n%%EOF\n";
    }

    public static function png(): string
    {
        $chunk = static function (string $type, string $data): string {
            return pack('N', strlen($data)) . $type . $data . pack('N', crc32($type . $data));
        };
        // 1×1 px, 8 bit šedá, jeden bílý pixel (filtr 0 + hodnota 255).
        $header = pack('NNCCCCC', 1, 1, 8, 0, 0, 0, 0);

        return "\x89PNG\r\n\x1a\n"
            . $chunk('IHDR', $header)
            . $chunk('IDAT', (string) gzcompress("\x00\xff"))
            . $chunk('IEND', '');
    }

    /**
     * Zrcadlo úložiště: stejná struktura adresářů, lidsky pojmenované úseky cesty
     * přejmenované stejnou funkcí jako cesty v databázi ({@see Pseudonymizer::filePath()}),
     * a místo obsahu zástupný soubor.
     *
     * @param callable(string):void $log
     * @return array{files:int, bytes:int}
     */
    public static function mirror(string $from, string $to, Pseudonymizer $pseudonymizer, callable $log): array
    {
        $fromReal = realpath($from);
        if ($fromReal === false || !is_dir($fromReal)) {
            throw new \RuntimeException("Zdrojové úložiště {$from} neexistuje.");
        }
        if (!is_dir($to) && !mkdir($to, 0775, true) && !is_dir($to)) {
            throw new \RuntimeException("Cílový adresář {$to} nelze založit.");
        }
        $toReal = (string) realpath($to);
        $normalize = static fn (string $path): string => strtolower(rtrim(str_replace('\\', '/', $path), '/')) . '/';
        if (str_starts_with($normalize($toReal), $normalize($fromReal)) || str_starts_with($normalize($fromReal), $normalize($toReal))) {
            throw new \RuntimeException('Cílový adresář zástupných souborů nesmí ležet uvnitř úložiště ani ho obsahovat.');
        }

        $files = 0;
        $bytes = 0;
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($fromReal, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST,
        );
        foreach ($iterator as $item) {
            /** @var \SplFileInfo $item */
            if (!$item->isFile()) {
                continue;
            }
            $relative = str_replace('\\', '/', substr($item->getPathname(), strlen($fromReal) + 1));
            $top = explode('/', $relative, 2)[0];
            if (in_array(strtolower($top), self::SKIP_TOP_LEVEL, true) || str_starts_with($item->getFilename(), '.')) {
                continue;
            }
            $target = $toReal . '/' . $pseudonymizer->filePath($relative);
            $dir = dirname($target);
            if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
                throw new \RuntimeException("Adresář {$dir} nelze založit.");
            }
            $content = self::content(pathinfo($item->getFilename(), PATHINFO_EXTENSION));
            if (file_put_contents($target, $content) === false) {
                throw new \RuntimeException("Zástupný soubor {$target} nelze zapsat.");
            }
            $files++;
            $bytes += strlen($content);
            if ($files % 1000 === 0) {
                $log("  zástupných souborů: {$files}");
            }
        }

        return ['files' => $files, 'bytes' => $bytes];
    }
}
