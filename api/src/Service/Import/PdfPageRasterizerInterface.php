<?php

declare(strict_types=1);

namespace MyInvoice\Service\Import;

interface PdfPageRasterizerInterface
{
    /**
     * PNG bajty prvních `$maxPages` stránek. Prázdný seznam = rasterizace není
     * k dispozici nebo selhala; volající pak pokračuje jen s textem PDF.
     *
     * @return list<string>
     */
    public function rasterize(string $pdfBytes, int $maxPages): array;
}
