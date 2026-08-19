<?php

declare(strict_types=1);

namespace MyInvoice\Support;

/**
 * Rozpozná text, který popisuje POPLATEK ORGÁNU VEŘEJNÉ MOCI — soudní a správní
 * poplatky, kolky, poplatky katastru, evropský platební rozkaz.
 *
 * Orgán veřejné moci při výkonu působnosti v oblasti veřejné správy NENÍ osobou
 * povinnou k dani (§ 5 odst. 4 ZDPH, čl. 13 směrnice 2006/112/ES). Poplatek proto
 * není úplatou za zdanitelné plnění: příjemci nevzniká povinnost přiznat daň —
 * ani u zahraničního soudu či úřadu, kde by se jinak uplatnilo samovyměření
 * u přijaté služby dle § 9 odst. 1. Takový doklad tedy NEPATŘÍ do žádného řádku
 * přiznání ani do kontrolního hlášení; je to jen účetní náklad (typicky 538).
 *
 * Rozhoduje KVALIFIKÁTOR — poplatek ve spojení s konkrétním soudem/úřadem, ne
 * samotné slovo „poplatek": bankovní, licenční nebo servisní poplatek je běžné
 * zdanitelné plnění a zaměnit ho za plnění mimo předmět daně by znamenalo tiše
 * neodvést daň ze samovyměření.
 *
 * Porovnává se bez ohledu na velikost písmen, diakritiku i interpunkci.
 *
 * Jediný zdroj pravdy pro tuhle otázku:
 *   - {@see \MyInvoice\Repository\PurchaseInvoiceRepository::defaultClassificationCode()}
 *     — nulová sazba od zahraničního „dodavatele" nedostane RC kód (24e/24),
 *   - {@see \MyInvoice\Service\Validation\PurchaseInvoiceValidation::warnings()}
 *     — měkké upozornění, proč doklad zůstal bez klasifikace.
 */
final class PublicAuthorityFeeText
{
    /**
     * Fráze, které z textu dělají poplatek orgánu veřejné moci. Zapisují se proti
     * NORMALIZOVANÉMU textu (malá písmena, bez diakritiky, slova oddělená jednou
     * mezerou); koncovky jsou volné, ať projde skloňování.
     */
    private const PATTERNS = [
        '/\bsoudn\w* poplat/',
        '/\bpoplat\w* soudu\b/',
        '/\bspravn\w* poplat/',
        '/\bplatebni rozkaz/',
        '/\bkolkov\w* znam/',
        '/\bkolek\b/',
        '/\bkatastr\w* poplat/',
        '/\bpoplat\w* katastr/',
        '/\bcourt fee/',
        '/\bjudicial fee/',
        '/\bfiling fee/',
        '/\bstamp duty/',
        '/\bgerichtskosten/',
        '/\bgerichtsgebuhr/',
        '/\bmahngericht/',
        '/\bmahnverfahren/',
    ];

    /** Popisuje text poplatek orgánu veřejné moci (mimo předmět daně)? */
    public static function indicatesPublicAuthorityFee(string $text): bool
    {
        $normalized = self::normalize($text);
        if ($normalized === '') {
            return false;
        }
        foreach (self::PATTERNS as $pattern) {
            if (preg_match($pattern, $normalized) === 1) {
                return true;
            }
        }
        return false;
    }

    /**
     * Popisuje aspoň jeden z textů poplatek orgánu veřejné moci?
     *
     * @param iterable<mixed> $texts
     */
    public static function anyIndicatesPublicAuthorityFee(iterable $texts): bool
    {
        foreach ($texts as $text) {
            if (is_string($text) && self::indicatesPublicAuthorityFee($text)) {
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
