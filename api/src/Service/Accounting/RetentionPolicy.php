<?php

declare(strict_types=1);

namespace MyInvoice\Service\Accounting;

/**
 * Retenční lhůty účetních a daňových záznamů — § 31 a § 32 ZoÚ, § 35a ZDPH.
 *
 * Systém tenhle pojem dosud vůbec neznal: řetězce „10 let" ani „5 let" se v kódu
 * nevyskytovaly, `ArchiveService::delete()` mazal archiv bez ohledu na stáří a superadmin
 * mohl fyzicky smazat zaúčtovaný doklad. Přitom UI i manuál tvrdily, že archiv „naplňuje
 * požadavek na uschování dle § 31/§ 32" — tvrzení bez jakékoli implementované lhůty.
 *
 * ── Lhůty ───────────────────────────────────────────────────────────────────────────
 * Všechny běží od KONCE účetního období, kterého se záznam týká (§ 31 odst. 3), ne ode
 * dne vystavení dokladu:
 *
 *   § 31 odst. 2 písm. a) — účetní závěrka a výroční zpráva ......... 10 let
 *   § 31 odst. 2 písm. b) — účetní doklady, účetní knihy, odpisové
 *                           plány, inventurní soupisy, účtový rozvrh ... 5 let
 *   § 35a ZDPH            — daňové doklady ......................... 10 let
 *
 * ── Proč nestačí vzít § 31 a hotovo ─────────────────────────────────────────────────
 * Daňový doklad je SOUČASNĚ účetním dokladem. Podle ZoÚ by stačilo 5 let, podle ZDPH je
 * to 10 — a platí ta delší. Naivní implementace nad samotným § 31 by tedy vydala ke
 * skartaci doklady, které je nutné držet dalších pět let, a udělala by to tiše. Proto je
 * {@see retentionYears()} jediným zdrojem pravdy a u dokladů s DPH vrací 10, ne 5.
 *
 * ── § 32: lhůta se může protáhnout ──────────────────────────────────────────────────
 * Slouží-li záznam jako důkazní prostředek v daňovém řízení, uchovává se po celou dobu,
 * kdy řízení trvá — a to i po uplynutí lhůty podle § 31. Tuhle skutečnost systém z dat
 * nezjistí (kontrola ani soudní spor v účetnictví nefigurují), proto se eviduje jako
 * ručně zadané „legal hold" ({@see \MyInvoice\Repository\RetentionHoldRepository}).
 * Bez něj by brána pustila ke smazání dokumenty, které správce daně právě prověřuje.
 *
 * ── Co tahle třída NEDĚLÁ ───────────────────────────────────────────────────────────
 * Nic nemaže a žádnou skartaci nespouští. Uplynulá lhůta je KONEC POVINNOSTI uchovávat,
 * ne příkaz ke smazání — rozhodnutí zůstává na uživateli. Třída jen říká „do kdy" a slouží
 * jako brána proti PŘEDČASNÉMU smazání.
 */
final class RetentionPolicy
{
    /** § 31 odst. 2 písm. a) — účetní závěrka a výroční zpráva. */
    public const FINANCIAL_STATEMENTS = 'financial_statements';

    /** § 31 odst. 2 písm. b) — účetní doklady, knihy, odpisové plány, inventurní soupisy. */
    public const ACCOUNTING_RECORDS = 'accounting_records';

    /** § 35a ZDPH — daňové doklady (delší lhůta přebíjí § 31 odst. 2 písm. b). */
    public const TAX_DOCUMENTS = 'tax_documents';

    /**
     * Lhůta v letech od konce účetního období, kterého se záznam týká.
     *
     * @var array<string,int>
     */
    private const YEARS = [
        self::FINANCIAL_STATEMENTS => 10,
        self::ACCOUNTING_RECORDS   => 5,
        self::TAX_DOCUMENTS        => 10,
    ];

    /** Lidský popis kategorie i s právním základem — do UI a auditní stopy. */
    private const LABELS = [
        self::FINANCIAL_STATEMENTS => 'Účetní závěrka a výroční zpráva (§ 31 odst. 2 písm. a ZoÚ)',
        self::ACCOUNTING_RECORDS   => 'Účetní doklady, knihy a inventurní soupisy (§ 31 odst. 2 písm. b ZoÚ)',
        self::TAX_DOCUMENTS        => 'Daňové doklady (§ 35a ZDPH)',
    ];

    /** @return list<string> */
    public static function categories(): array
    {
        return array_keys(self::YEARS);
    }

    public static function retentionYears(string $category): int
    {
        if (!isset(self::YEARS[$category])) {
            throw new \InvalidArgumentException('Neznámá retenční kategorie: ' . $category);
        }

        return self::YEARS[$category];
    }

    public static function label(string $category): string
    {
        return self::LABELS[$category] ?? $category;
    }

    /**
     * Kategorie pro účetní doklad. Doklad nesoucí DPH je současně daňovým dokladem, takže
     * platí delší lhůta § 35a — právě tenhle souběh dělá z „5 let podle ZoÚ" past.
     */
    public static function categoryForDocument(bool $hasVat): string
    {
        return $hasVat ? self::TAX_DOCUMENTS : self::ACCOUNTING_RECORDS;
    }

    /**
     * Poslední den povinnosti uchovávat záznam z účetního období končícího `$periodEnd`.
     *
     * Lhůta běží od konce období (§ 31 odst. 3), takže doklad z období končícího
     * 31. 12. 2024 se jako daňový uchovává do 31. 12. 2034.
     */
    public static function retainUntil(string $category, string $periodEnd): string
    {
        $end = new \DateTimeImmutable($periodEnd);

        return $end->modify('+' . self::retentionYears($category) . ' years')->format('Y-m-d');
    }

    /**
     * Je záznam z období končícího `$periodEnd` k datu `$asOf` ještě v retenční lhůtě?
     * Den `retainUntil` do lhůty PATŘÍ — uplynutí nastává až následující den.
     */
    public static function isWithinRetention(string $category, string $periodEnd, ?string $asOf = null): bool
    {
        $until = new \DateTimeImmutable(self::retainUntil($category, $periodEnd));
        $now = new \DateTimeImmutable($asOf ?? date('Y-m-d'));

        return $now <= $until;
    }

    /**
     * Nejdelší lhůta, která se na období vztahuje — účetní období obsahuje současně
     * závěrku (10 let) i doklady, takže brána proti smazání musí počítat s tou nejdelší.
     */
    public static function longestRetainUntil(string $periodEnd): string
    {
        $max = null;
        foreach (self::categories() as $category) {
            $until = self::retainUntil($category, $periodEnd);
            if ($max === null || $until > $max) {
                $max = $until;
            }
        }

        return (string) $max;
    }

    /**
     * Rozpis lhůt pro účetní období — podklad pro přehled v UI.
     *
     * @return list<array{category:string, label:string, years:int, retain_until:string, expired:bool}>
     */
    public static function scheduleFor(string $periodEnd, ?string $asOf = null): array
    {
        $out = [];
        foreach (self::categories() as $category) {
            $out[] = [
                'category'     => $category,
                'label'        => self::label($category),
                'years'        => self::retentionYears($category),
                'retain_until' => self::retainUntil($category, $periodEnd),
                'expired'      => !self::isWithinRetention($category, $periodEnd, $asOf),
            ];
        }

        return $out;
    }
}
