<?php

declare(strict_types=1);

namespace MyInvoice\Service\Import;

use MyInvoice\Service\Accounting\Expense\ExpenseKind;
use MyInvoice\Service\Accounting\Expense\ExpenseKindClassifier;
use MyInvoice\Service\Accounting\Expense\ExpenseKindSuggestion;
use MyInvoice\Service\Ai\AiPayloadSanitizer;

/**
 * Návrh `expense_kind` z AI extrakce PDF (§DM „AI import"). Pure, bez DB — týž vzor jako
 * {@see ExpenseKindClassifier}.
 *
 * NIC NEÚČTUJE a nic nezapisuje na řádek. Vrací návrh, který se uživateli ukáže k potvrzení;
 * §DM to říká výslovně: „Návrh se ukáže v editoru, uživatel potvrdí — neúčtovat naslepo."
 *
 * VRSTVENÍ (§DM „Dvě vrstvy, v tomhle pořadí"): deterministický klasifikátor je PRVNÍ,
 * AI je až FALLBACK. Když klasifikátor řádek pozná, AI se zahodí — a to i když souhlasí.
 * Deterministický důvod („text obsahuje „tablet"") je vysvětlitelný a reprodukovatelný,
 * kdežto „AI, jistota 40 %" ne. Vedlejší efekt je pojistka zadarmo: NEGATIVNÍ klíčová slova
 * jsou v klasifikátoru, takže „Prodloužená záruka k notebooku" vrátí Service (non-null) a
 * halucinace „drobný majetek" se k uživateli vůbec nedostane.
 *
 * ⚠️ Poučení z `a013406f`, které se tu promítá do dvou nezávislých vrstev:
 *   1. PROMPT nesmí posílat holé kódy/tokeny — {@see InvoiceExtractionPrompt} posílá u každé
 *      hodnoty `expense_kind` i český název a význam. Model si jinak dosadí vlastní představu
 *      (tehdy si k účtu 065 vymyslel název „Pojistné", ačkoli to jsou Dluhové cenné papíry).
 *   2. VALIDACE nesmí promptu věřit — {@see fromAiItem()} kontroluje enum, práh §26/2 ZDP
 *      i deterministický klasifikátor NEZÁVISLE na tom, co model slíbil.
 */
final class AiExpenseKindProposal
{
    /**
     * Tlumení jistoty vrácené modelem — shodně s {@see \MyInvoice\Service\Ai\LlmClassifierRouter}.
     *
     * Model svou jistotu systematicky přeceňuje, takže ji NEBEREME jak přišla. Krom kalibrace to
     * má tvrdý důsledek: 0,40 × 1,0 = 0,40 je pod {@see ExpenseKindClassifier::AUTO_THRESHOLD}
     * (0,9), takže `isAutoApplicable()` je u AI návrhu VŽDY false. Návrh z AI se tím pádem
     * nemůže sám použít ani omylem — v UI vždy spadne do fronty „ke kontrole".
     */
    public const AI_CONFIDENCE_DAMPING = 0.40;

    private const REASONING_MAX = 300;

    /** Popis položky ve varování — volný text dodavatele, jen ořez (viz sanitizeItemText). */
    private const DESCRIPTION_MAX = 80;

    /**
     * Nejlepší dostupný návrh pro JEDEN řádek — jediné místo, kde se rozhoduje mezi vrstvami.
     *
     * Vrací null, kdykoli je čemu nevěřit: mlčení je správná odpověď, protože neurčený řádek
     * se chová jako dosud (518), kdežto špatný návrh svádí ke špatnému účtu (§DM „Nehádej").
     *
     * @param ?ExpenseKindSuggestion $deterministic výsledek {@see ExpenseKindClassifier} pro týž řádek
     * @param array<string,mixed>    $aiItem        řádek z `items[]` AI extrakce
     */
    public static function resolve(
        ?ExpenseKindSuggestion $deterministic,
        array $aiItem,
        float $unitPriceWithoutVat,
        float $fixedAssetLimit,
    ): ?ExpenseKindSuggestion {
        // Vrstva 1 vyhrála → AI se neptáme, ani kdyby souhlasila (viz vrstvení v docblocku třídy).
        // Tady zároveň leží veto negativních klíčových slov: „Prodloužená záruka k notebooku"
        // vrátí z klasifikátoru Service, takže halucinace „drobný majetek" se sem nedostane.
        return $deterministic ?? self::fromAiItem($aiItem, $unitPriceWithoutVat, $fixedAssetLimit);
    }

    /**
     * Normalizace + nezávislá validace toho, co vrátil model. Sama o sobě NEŘEŠÍ vrstvení —
     * volej přes {@see resolve()}.
     *
     * @param array<string,mixed> $aiItem řádek z `items[]` AI extrakce
     */
    public static function fromAiItem(
        array $aiItem,
        float $unitPriceWithoutVat,
        float $fixedAssetLimit,
    ): ?ExpenseKindSuggestion {
        $kind = ExpenseKind::tryFromNullable(self::str($aiItem['expense_kind'] ?? null));
        if ($kind === null) {
            return null; // model vrátil nesmysl / nic → mlčíme
        }

        $confidence = self::confidence($aiItem['expense_kind_confidence'] ?? null);
        if ($confidence <= 0.0) {
            return null; // model sám říká, že neví
        }

        $reason = self::reason($kind, $aiItem['expense_kind_reasoning'] ?? null);

        // §26/2 ZDP: hmotná věc nad limit není drobný, ale dlouhodobý majetek. Vynucujeme
        // NEZÁVISLE na modelu — limit se mění a model zná leda ten ze svých trénovacích dat.
        // Táž pojistka jako v ExpenseKindClassifier::classify(), jen na AI vstupu.
        if ($kind === ExpenseKind::SmallAsset && abs($unitPriceWithoutVat) >= $fixedAssetLimit) {
            return new ExpenseKindSuggestion(
                ExpenseKind::FixedAsset,
                $confidence,
                sprintf(
                    '%s; cena za kus %s Kč ≥ limit %s Kč (§26/2 ZDP) ⇒ dlouhodobý majetek',
                    $reason,
                    number_format(abs($unitPriceWithoutVat), 2, ',', ' '),
                    number_format($fixedAssetLimit, 2, ',', ' '),
                ),
                'ai',
            );
        }

        return new ExpenseKindSuggestion($kind, $confidence, $reason, 'ai');
    }

    /**
     * Návrh pro řádek, který vznikl SLOUČENÍM původních řádků
     * ({@see AiPdfExtractor::collapseToSummaryBaseLine} / `authoritativeRecapBaseLine`).
     *
     * Po sloučení už per-položkový druh výdaje NEEXISTUJE — jeden řádek „1 ks × základ z
     * rekapitulace" nese součet věcně různých plnění. Přenést na něj návrh prvního řádku by
     * bylo tiché nesmyslné zobecnění: u Alzy (notebook + doprava) by doprava skončila jako
     * drobný majetek, a to na částce CELÉHO dokladu.
     *
     * Proto se návrh udrží JEN tehdy, když se všechny původní řádky shodly na jednom druhu —
     * pak sloučení nic neztrácí. Jinak null a doklad zůstane neurčený (= 518, dnešní chování),
     * což je §DM „Nehádej": raději nic než tiché přeúčtování.
     *
     * @param list<ExpenseKindSuggestion|null> $proposals návrhy původních (předsloučených) řádků
     */
    public static function mergeForCollapsedLine(array $proposals): ?ExpenseKindSuggestion
    {
        $known = array_values(array_filter($proposals, static fn (?ExpenseKindSuggestion $p): bool => $p !== null));
        // Byť jediný neurčený řádek stačí: nevíme, co v součtu je, takže tvrdit druh nelze.
        if ($known === [] || count($known) !== count($proposals)) {
            return null;
        }
        $first = $known[0];
        foreach ($known as $p) {
            if ($p->kind !== $first->kind) {
                return null;
            }
        }
        if (count($known) === 1) {
            return $first;
        }
        // Jistota sloučeného řádku = ta NEJNIŽŠÍ z původních. Shoda druhu z nich nedělá
        // silnější důkaz, než má nejslabší článek.
        $confidence = min(array_map(static fn (ExpenseKindSuggestion $p): float => $p->confidence, $known));
        return new ExpenseKindSuggestion(
            $first->kind,
            $confidence,
            sprintf('všech %d řádků dokladu bylo vyhodnoceno stejně (%s); řádky byly sloučeny dle rekapitulace DPH', count($known), $first->reason),
            $first->source,
        );
    }

    /**
     * Lidský popis návrhů pro `extraction_warning` — tudy se návrh dostane k uživateli,
     * protože AI import na řádek nic nezapisuje.
     *
     * @param array<int,ExpenseKindSuggestion> $proposals klíč = order_index řádku
     * @param list<array<string,mixed>>        $items     finální řádky dokladu
     */
    public static function warningText(array $proposals, array $items): ?string
    {
        if ($proposals === []) {
            return null;
        }
        $lines = [];
        foreach ($proposals as $index => $p) {
            $description = AiPayloadSanitizer::sanitizeItemText(
                (string) ($items[$index]['description'] ?? ''),
                self::DESCRIPTION_MAX,
            );
            $lines[] = sprintf(
                '• řádek %d%s: %s (AI, jistota %d %%; %s)',
                $index + 1,
                $description !== '' ? ' „' . $description . '"' : '',
                self::kindLabel($p->kind),
                (int) round($p->confidence * 100),
                $p->reason,
            );
        }
        return 'AI navrhuje druh nákladu u ' . count($proposals) . ' řádků — NENÍ nastaven, '
            . 'potvrďte nebo opravte v editoru u každé položky:' . "\n" . implode("\n", $lines);
    }

    private static function kindLabel(ExpenseKind $kind): string
    {
        return match ($kind) {
            ExpenseKind::Service => 'Služba',
            ExpenseKind::Material => 'Materiál',
            ExpenseKind::SmallAsset => 'Drobný majetek',
            ExpenseKind::FixedAsset => 'Dlouhodobý majetek',
        };
    }

    private static function confidence(mixed $raw): float
    {
        if (!is_numeric($raw)) {
            return 0.0;
        }
        $clamped = max(0.0, min(1.0, (float) $raw));
        return round(self::AI_CONFIDENCE_DAMPING * $clamped, 2);
    }

    /**
     * Zdůvodnění od modelu je volný text — projde PII stripem a stropem délky, NIKDY
     * slovníkovým whitelistem ({@see AiPayloadSanitizer::sanitizeItemText()}). Model v něm
     * běžně cituje popis položky z dokladu, takže by ho whitelist rozsekal na nesmysl.
     */
    private static function reason(ExpenseKind $kind, mixed $rawReasoning): string
    {
        $reasoning = AiPayloadSanitizer::sanitizeItemText((string) (is_scalar($rawReasoning) ? $rawReasoning : ''), self::REASONING_MAX);
        $base = 'AI z dokladu ⇒ ' . self::kindLabel($kind);
        return $reasoning !== '' ? $base . ': ' . $reasoning : $base . ' (bez zdůvodnění, zkontroluj)';
    }

    private static function str(mixed $v): ?string
    {
        if (!is_string($v)) {
            return null;
        }
        $s = trim($v);
        return $s === '' ? null : $s;
    }
}
