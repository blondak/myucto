<?php

declare(strict_types=1);

namespace MyInvoice\Service\Import;

/**
 * Doklad z Fakturoidu, který import nepřenesl, s klíčem návodu pro přehled úlohy
 * (#129): `vat_rate` sazba DPH mimo číselník, `subject` chybějící subjekt.
 * Ostatní výjimky se v přehledu ukážou s obecným návodem (`generic`).
 */
final class FakturoidDocumentRejected extends \RuntimeException
{
    public function __construct(public readonly string $hint, string $message, ?\Throwable $previous = null)
    {
        parent::__construct($message, 0, $previous);
    }

    public static function hintOf(\Throwable $e): string
    {
        return $e instanceof self ? $e->hint : 'generic';
    }
}
