<?php

declare(strict_types=1);

namespace MyInvoice\Service\Import;

/**
 * Měkká kontrola dvojice „ISDOC + sourozenecké PDF" spárované podle jména
 * ({@see InboxFileGrouper}). Vrací VAROVÁNÍ do reportu, nikdy neblokuje archivaci.
 *
 * Proč ne podmínka: shodný základ jména v jednom adresáři je v mail-drop inboxu
 * prakticky jistota a obě selhání jsou nesouměrná — připojený cizí obraz je vidět
 * v konceptu a smaže se jedním klikem, kdežto „radši nepřipojím a pošlu na AI"
 * stojí zaplacený call a vyrobí druhý nepřesný koncept. K tomu SKENOVANÉ PDF nemá
 * textovou vrstvu, takže kontrola neumí potvrdit nic zrovna tam, kde by rozhodovala.
 *
 * Hlásíme tedy jen situaci, kdy oba slabé signály ukazují proti sobě:
 *   1. variabilní symbol z ISDOC se v textu PDF NENAJDE (nalezení = silné potvrzení,
 *      po něm se částka už neřeší), A ZÁROVEŇ
 *   2. nejvyšší peněžní hodnota v PDF se liší od `PayableAmount` nad rámec tolerance.
 *
 * Cizí měna se přeskakuje: {@see PdfTotalExtractor} bere MAXIMUM hodnot bez ohledu
 * na měnu, takže u dokladu s CZK i EUR součtem by srovnání s částkou v měně faktury
 * hlásilo rozpor pokaždé.
 */
final class InboxPairVerifier
{
    /** Relativní tolerance částky (MAX heuristika není na haléře přesná). */
    private const REL_TOLERANCE = 0.02;

    /** Absolutní podlaha tolerance v měně dokladu (drobné faktury). */
    private const ABS_TOLERANCE = 1.0;

    public function __construct(
        private readonly PdfTotalExtractor $totals,
    ) {}

    /**
     * @param  array<string,mixed> $isdocInvoice Jedna faktura z {@see IsdocParser::parse()}.
     * @return string|null Text varování pro report, nebo null když je vše v pořádku
     *                     nebo se prostě nedá nic ověřit.
     */
    public function verify(string $pdfBytes, array $isdocInvoice): ?string
    {
        $text = $this->totals->extractText($pdfBytes);
        if ($text === null || trim($text) === '') {
            return null; // skenovaný obraz bez textové vrstvy — nelze ověřit, nehádáme
        }

        $vs = trim((string) ($isdocInvoice['varsymbol'] ?? ''));
        if ($vs !== '' && self::containsNumber($text, $vs)) {
            return null; // VS sedí → dvojice potvrzena
        }

        $currency = strtoupper(trim((string) ($isdocInvoice['currency'] ?? 'CZK')));
        if ($currency !== 'CZK') {
            return null; // viz docblock — u cizí měny nemá porovnání s MAX vypovídací hodnotu
        }

        $expected = $isdocInvoice['payable_amount'] ?? null;
        if (!is_numeric($expected) || (float) $expected <= 0.0) {
            return null;
        }
        $expected = (float) $expected;

        $found = $this->totals->totalFromText($text);
        if ($found === null) {
            return null;
        }

        $tolerance = max(self::ABS_TOLERANCE, $expected * self::REL_TOLERANCE);
        if (abs($found - $expected) <= $tolerance) {
            return null;
        }

        return sprintf(
            'PDF možná nepatří k těmto datům: variabilní symbol %s se v textu PDF nenašel '
                . 'a nejvyšší částka v PDF je %s Kč, kdežto data uvádí %s Kč. Přílohu zkontrolujte.',
            $vs !== '' ? $vs : '(v datech chybí)',
            self::money($found),
            self::money($expected),
        );
    }

    /**
     * Hledá číslo jako samostatný token (ne uvnitř delšího čísla), a to i v textu,
     * kde se do něj při sazbě vloudily mezery („1 234 567").
     */
    private static function containsNumber(string $haystack, string $needle): bool
    {
        $digits = preg_replace('/\D+/', '', $needle) ?? '';
        if ($digits === '') {
            return false;
        }
        $pattern = '/(?<!\d)' . preg_quote($digits, '/') . '(?!\d)/';
        if (preg_match($pattern, $haystack) === 1) {
            return true;
        }
        $squeezed = preg_replace('/[\s\x{00A0}]+/u', '', $haystack) ?? '';
        return preg_match($pattern, $squeezed) === 1;
    }

    private static function money(float $v): string
    {
        return number_format($v, 2, ',', "\u{00A0}");
    }
}
