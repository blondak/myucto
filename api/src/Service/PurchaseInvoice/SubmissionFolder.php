<?php

declare(strict_types=1);

namespace MyInvoice\Service\PurchaseInvoice;

/**
 * Kam v Dokumentech patří originál příchozího dokladu (fronta Nákup → Příchozí doklady):
 * „Příchozí doklady / rok / měsíc" podle data převzetí. Dřív končil v kořeni Dokumentů,
 * kde se desítky faktur z e-mailu a portálu mísily se vším ostatním.
 */
final class SubmissionFolder
{
    public const ROOT = 'Příchozí doklady';

    /** @return list<string> */
    public static function segments(\DateTimeImmutable $receivedAt): array
    {
        $local = $receivedAt->setTimezone(new \DateTimeZone(date_default_timezone_get()));
        return [self::ROOT, $local->format('Y'), $local->format('m')];
    }
}
