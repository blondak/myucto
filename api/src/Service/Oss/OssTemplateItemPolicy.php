<?php

declare(strict_types=1);

namespace MyInvoice\Service\Oss;

/**
 * Co OSS znamená na položce ŠABLONY opakované faktury (migrace 1297) — jak se ukládá
 * a jak se z ní stane řádek vygenerované faktury.
 *
 * ── Proč vlastní třída, a ne pár řádků v generátoru ─────────────────────────────────
 * Pravidlo žije na DVOU místech naráz: repozitář rozhoduje, co se uloží na šablonu,
 * generátor rozhoduje, co z toho vznikne na dokladu. Kdyby si každý napsal svoje,
 * rozejdou se — šablona by přijala kombinaci, se kterou generátor neumí pracovat
 * (typicky `oss_applicable = 1` bez země spotřeby), a cron by pak měsíce vyráběl
 * doklady, které validace odmítne. Navíc: pravidlo schované jako `private` helper
 * uvnitř jedné třídy se okopíruje rychleji, než kdyby neexistovalo.
 *
 * ── Šablona je ROZHODNUTÍ, derivace je NÁVRH ────────────────────────────────────────
 * Uloží-li se na položku šablony OSS, má přednost před {@see OssItemDeriver}: je to
 * rozhodnutí člověka (účetní dohledala typ sazby, který číselník nepotvrdil, nebo určila
 * typ plnění, který z jednotky nejde odvodit). Kdyby ho derivace při každém generování
 * přebila, byla by šablona k ničemu — totéž rozhodnutí by se dělalo znovu na každé
 * vygenerované faktuře. Proto u téhle větve NEVZNIKÁ ani příznak „k ručnímu posouzení":
 * nejistota skončila tím, že se člověk rozhodl.
 *
 * ── Jediná výjimka: REGISTRACE DO OSS ke dni plnění GENEROVANÉHO dokladu ────────────
 * Šablona se založí jednou a generuje roky; registrace do OSS mezitím může skončit
 * (`supplier.oss_valid_to`) nebo se v nastavení vypnout. Uložené rozhodnutí je pak
 * rozhodnutím o JINÉM období, než do kterého vygenerovaný doklad patří — a řádek
 * s `oss_applicable = 1` mimo registraci nespadne do ŽÁDNÉHO přiznání: z OSS podání ho
 * vyřadí platnost registrace, z tuzemského přiznání OSS příznak
 * ({@see \MyInvoice\Service\Report\VatLedgerService} ho filtruje). Daň by tiše zmizela
 * z obou stran, a to tím způsobem, že by na to nikdo nepřišel — cron generuje bez
 * dozoru. Šablonové rozhodnutí se proto v tomhle jediném případě NEPOUŽIJE: řádek jde
 * cestou derivace (tedy do tuzemska, nebo se odmítne) a povinně dostane příznak
 * K RUČNÍMU POSOUZENÍ — přeřazení proti vůli člověka nesmí být tiché.
 *
 * Není to druhá autorita nad registrací: platnost vyhodnocuje výhradně deriver a policy
 * ji jen čte z DŮVODU jeho rozhodnutí ({@see registrationInForce()}).
 */
final class OssTemplateItemPolicy
{
    /**
     * Hodnoty OSS sloupců k ULOŽENÍ na položku šablony, v pořadí sloupců migrace 1297
     * (`oss_applicable`, `oss_consumer_country`, `oss_rate_type`, `oss_supply_type`).
     *
     * Neúplný OSS řádek se ukládá jako NE-OSS. Šablona s `oss_applicable = 1` bez země
     * spotřeby by při každém generování vyrobila položku, kterou validace dokladu
     * odmítne — a protože generuje cron, uživatel by na to přišel až tím, že mu faktury
     * přestaly chodit. Prázdný TYP SAZBY je naproti tomu legitimní stav (číselník ho
     * nemusí znát) a při generování se ho ještě zkusí doplnit derivace.
     *
     * @param  array<string,mixed> $item
     * @return array{0:int, 1:?string, 2:?string, 3:?string}
     */
    public static function storedColumns(array $item): array
    {
        $country = OssClientContext::iso2OrNull($item['oss_consumer_country'] ?? null);
        if (empty($item['oss_applicable']) || $country === null) {
            return [0, null, null, null];
        }

        $rateType = trim((string) ($item['oss_rate_type'] ?? ''));

        return [
            1,
            $country,
            in_array($rateType, OssItemDecision::RATE_TYPES, true) ? $rateType : null,
            OssClientContext::supplyTypeOrNull($item['oss_supply_type'] ?? null),
        ];
    }

    /**
     * OSS sloupce pro řádek VYGENEROVANÉ faktury: uložené rozhodnutí šablony, jinak
     * derivace.
     *
     * @param array<string,mixed> $item     položka šablony
     * @param OssItemDecision     $decision odpověď {@see OssItemDeriver} k datu plnění
     *                                      generovaného dokladu
     * @param bool                $degradeRejection `true` = odmítnutí se převede na řádek
     *                             mimo OSS s příznakem K RUČNÍMU POSOUZENÍ. Cron doklad
     *                             zahodit NESMÍ: chybějící číselník (neproběhlá migrace
     *                             1152) by jinak zastavil zákazníkovi celou fakturaci,
     *                             u KAŽDÉ šablony s nenulovou sazbou včetně ryze české.
     *                             Není to tiché zařazení — bez příznaku by řádek vypadal
     *                             jako rozhodnutý, s ním ho najde hromadná editace.
     *
     * @return array{oss_applicable:int, oss_consumer_country:?string, oss_rate_type:?string,
     *               oss_supply_type:?string, oss_needs_manual_review:int}
     */
    public static function generatedColumns(
        array $item,
        OssItemDecision $decision,
        bool $degradeRejection = true,
    ): array {
        [$applicable, $country, $rateType, $supplyType] = self::storedColumns($item);
        // Registrace se ověřuje k datu plnění GENEROVANÉHO dokladu, ne k datu založení
        // šablony — viz docblock třídy.
        $overriddenByRegistration = $applicable === 1 && !self::registrationInForce($decision);

        if ($applicable === 1 && !$overriddenByRegistration) {
            return [
                'oss_applicable' => 1,
                'oss_consumer_country' => $country,
                // Prázdná pole šablony si smí doplnit derivace, ale jedině když mluví
                // o TÉŽE zemi spotřeby — typ sazby z jiného státu je typ jiné sazby.
                'oss_rate_type' => $rateType ?? ($decision->consumerCountry === $country ? $decision->rateType : null),
                // Poslední příčka je táž jako u derivace („služba"), protože typ plnění
                // je u OSS řádku povinný — bez něj by položka neprošla ani zápisem.
                'oss_supply_type' => $supplyType ?? ($decision->supplyType ?? 'services'),
                'oss_needs_manual_review' => 0,
            ];
        }

        if ($decision->isRejected()) {
            if (!$degradeRejection) {
                throw new \RuntimeException((string) $decision->rejectionMessage);
            }

            return OssItemPlan::manualReviewColumns();
        }

        $columns = $decision->toItemColumns();
        if ($overriddenByRegistration) {
            // Šablona chtěla OSS, registrace k datu plnění neplatí → řádek zůstává
            // tuzemský. Příznak je tu povinný: je to jediné místo, kde systém přebíjí
            // rozhodnutí člověka, a bez něj by se přeřazení nedalo v datech dohledat.
            $columns['oss_needs_manual_review'] = 1;
        }

        return $columns;
    }

    /**
     * Má dodavatel k datu plnění generovaného dokladu registraci do OSS?
     *
     * Čte se DŮVOD rozhodnutí deriveru, ne `supplier` — platnost registrace vyhodnocuje
     * `OssItemDeriver::registrationActiveOn()` a druhá kopie téhle podmínky by se s ní
     * rozešla přesně v hraničních dnech. Oba důvody znamenají totéž („k tomuhle datu
     * firma v režimu OSS není"), jen jeden mluví o vypnutém režimu a druhý o rozsahu
     * platnosti.
     *
     * Ostatní blokující důvody (odběratel má DIČ, je tuzemský, mimo EU…) tuhle výjimku
     * NEZAKLÁDAJÍ: tam je uložené rozhodnutí člověka pořád nadřazené, protože říká něco
     * o dodávce, a ne o tom, jestli firma vůbec smí OSS použít.
     */
    private static function registrationInForce(OssItemDecision $decision): bool
    {
        return $decision->reason !== OssDerivationReason::SupplierOssDisabled
            && $decision->reason !== OssDerivationReason::SupplierOssNotValidOnDate;
    }
}
