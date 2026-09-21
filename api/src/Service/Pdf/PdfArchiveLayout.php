<?php

declare(strict_types=1);

namespace MyInvoice\Service\Pdf;

final class PdfArchiveLayout
{
    public static function monthSubdirectory(string $filename): string
    {
        return preg_match('/^(\d{4})(\d{2})\d{2}-/', $filename, $matches) === 1
            ? $matches[1] . '-' . $matches[2]
            : '';
    }
}
