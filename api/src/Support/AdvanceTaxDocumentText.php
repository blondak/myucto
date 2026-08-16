<?php

declare(strict_types=1);

namespace MyInvoice\Support;

/**
 * Rozpozná text, který doklad označuje za DAŇOVÝ DOKLAD K PLATBĚ / ZÁLOZE
 * (DDKP, § 28 ZDPH) — tedy doklad, který nese jen DPH ze zaplacené zálohy
 * (343/314), NE náklad.
 *
 * Rozhoduje KVALIFIKÁTOR, ne slovo „daňový doklad" samo o sobě. Nadpis
 * „Daňový doklad" (a varianty „Faktura — daňový doklad", „Daňový doklad č. …")
 * má úplně obyčejná faktura od operátora, energetiky nebo e-shopu; brát ho jako
 * DDKP znamená zaúčtovat běžný náklad jako 343/314, vyřadit doklad z nákladů,
 * ze závazků i z příkazu k úhradě a nechat 518 prázdné. DDKP je až
 * „daňový doklad K PŘIJATÉ PLATBĚ / K PROVEDENÉ PLATBĚ / K ZÁLOZE".
 *
 * Porovnává se bez ohledu na velikost písmen, diakritiku i interpunkci —
 * dodavatelé píší „k přijaté platbě", „K PRIJATE PLATBE" i „k přijaté  platbě".
 *
 * Jediný zdroj pravdy pro tuhle otázku:
 *   - {@see \MyInvoice\Service\Import\AiPdfExtractor} — přeřazení chybné AI
 *     klasifikace `document_kind='tax_document'` zpět na běžnou fakturu,
 *   - {@see \MyInvoice\Action\PurchaseInvoice\CreatePurchaseInvoiceAction::receiptLooksLikePrepayment()}
 *     — měkké upozornění u účtenky, která je ve skutečnosti doklad k záloze.
 */
final class AdvanceTaxDocumentText
{
    /**
     * Fráze, které z textu dělají doklad k přijaté/provedené platbě nebo k záloze.
     * Zapisují se proti NORMALIZOVANÉMU textu (malá písmena, bez diakritiky, slova
     * oddělená jednou mezerou); koncovky jsou volné, ať projde skloňování i rod
     * („k přijaté platbě" i „přijatá platba").
     */
    private const QUALIFIER_PATTERNS = [
        '/\bprijat\w* (platb|uplat|uhrad)/',
        '/\bproveden\w* (platb|uplat|uhrad)/',
        '/\bposkytnut\w* (platb|uplat|zalo)/',
        '/\bk zaloze\b/',
        '/\bze zalohy\b/',
        '/\bna zalohu\b/',
        '/\bzalohov\w* platb/',
        '/\bzaplacen\w* zalo/',
        '/\bpred uskutecnenim plneni\b/',
        '/\badvance payment/',
        '/\bpayment received/',
        '/\breceived payment/',
        '/\bprepayment/',
    ];

    /**
     * Označuje text doklad k přijaté/provedené platbě nebo k záloze (DDKP)?
     *
     * Samotné „daňový doklad" (v jakémkoli zápisu) vrací VŽDY false — je to
     * běžná hlavička obyčejné faktury.
     */
    public static function indicatesAdvanceTaxDocument(string $text): bool
    {
        $normalized = self::normalize($text);
        if ($normalized === '') {
            return false;
        }
        foreach (self::QUALIFIER_PATTERNS as $pattern) {
            if (preg_match($pattern, $normalized) === 1) {
                return true;
            }
        }
        return false;
    }

    /**
     * Označuje aspoň jeden z textů doklad k platbě/záloze?
     *
     * @param iterable<mixed> $texts
     */
    public static function anyIndicatesAdvanceTaxDocument(iterable $texts): bool
    {
        foreach ($texts as $text) {
            if (is_string($text) && self::indicatesAdvanceTaxDocument($text)) {
                return true;
            }
        }
        return false;
    }

    /** Malá písmena, bez diakritiky, nealfanumerické znaky na jedinou mezeru. */
    private static function normalize(string $text): string
    {
        $lower = mb_strtolower(trim($text), 'UTF-8');
        if ($lower === '') {
            return '';
        }
        $ascii = strtr($lower, [
            'á' => 'a', 'č' => 'c', 'ď' => 'd', 'é' => 'e', 'ě' => 'e', 'í' => 'i',
            'ň' => 'n', 'ó' => 'o', 'ř' => 'r', 'š' => 's', 'ť' => 't', 'ú' => 'u',
            'ů' => 'u', 'ý' => 'y', 'ž' => 'z',
            'ä' => 'a', 'ĺ' => 'l', 'ľ' => 'l', 'ô' => 'o', 'ŕ' => 'r', 'ö' => 'o',
            'ü' => 'u', 'ß' => 'ss',
        ]);
        $spaced = (string) preg_replace('/[^a-z0-9]+/u', ' ', $ascii);
        return trim((string) preg_replace('/\s+/', ' ', $spaced));
    }
}
