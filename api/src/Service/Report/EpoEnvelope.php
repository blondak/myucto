<?php

declare(strict_types=1);

namespace MyInvoice\Service\Report;

use DOMDocument;
use DOMElement;

/**
 * Obálka EPO podání — `<Pisemnost nazevSW verzeSW><XXX verzePis>`.
 *
 * Struktura je pro všechna podání identická; liší se jen jméno kořenového elementu
 * a `verzePis`. Přesto existovala v ŠESTI copy-paste kopiích (DPHDP3, DPHKH1, DPHSHV,
 * OSSEI1, DPFDP7, DPPDP9) a čtení souboru `VERSION` v PĚTI.
 *
 * A ty kopie se stihly rozejít: dvě z nich vracely u prázdného `VERSION` `null`
 * (`trim(...) ?: null`), tři prázdný řetězec. Ve výsledku by tatáž instalace poslala
 * do jednoho podání `verzeSW="0"` a do druhého `verzeSW=""` — přesně ta třída
 * divergence duplicit, kvůli které celý audit vznikl, jen v neškodné položce.
 * Sjednoceno na chování s `null`, protože `?? '0'` u volajících s ním počítá.
 */
final class EpoEnvelope
{
    /** Název software v obálce podání. Jediné místo, kde se branding do podání píše. */
    public const NAZEV_SW = 'MyÚčto.cz';

    /**
     * Vytvoří dokument s obálkou a kořenovým elementem podání.
     *
     * @param string  $rootElement jméno kořene písemnosti (DPHDP3, DPHKH1, DPHSHV, OSSEI1, …)
     * @param string  $verzePis    verze písemnosti dle EPO XSD
     * @param ?string $verzeSw     verze software; `null` = načti z `VERSION`
     *
     * @return array{0: DOMDocument,1: DOMElement} [dokument, kořenový element podání]
     */
    public static function create(string $rootElement, string $verzePis, ?string $verzeSw = null): array
    {
        $dom = new DOMDocument('1.0', 'UTF-8');
        // Na programově stavěný dokument nemá `preserveWhiteSpace` vliv (uplatní se až
        // při načítání), ale čtyři z šesti volajících ho nastavovaly — držíme jejich
        // tvar, aby refaktor nebyl ani teoreticky pozorovatelný.
        $dom->preserveWhiteSpace = false;
        $dom->formatOutput = true;

        $pisemnost = $dom->createElement('Pisemnost');
        $pisemnost->setAttribute('nazevSW', self::NAZEV_SW);
        $pisemnost->setAttribute('verzeSW', $verzeSw ?? self::appVersion() ?? '0');
        $dom->appendChild($pisemnost);

        $root = $dom->createElement($rootElement);
        $root->setAttribute('verzePis', $verzePis);
        $pisemnost->appendChild($root);

        return [$dom, $root];
    }

    /**
     * Verze aplikace ze souboru `VERSION` v kořeni repozitáře.
     *
     * Prázdný soubor vrací `null`, ne prázdný řetězec — volající mají fallback `'0'`
     * a ten se má uplatnit i tehdy, když soubor existuje, ale je prázdný.
     */
    public static function appVersion(): ?string
    {
        // __DIR__ = api/src/Service/Report → čtyři úrovně nahoru je kořen repozitáře.
        $verFile = __DIR__ . '/../../../../VERSION';
        if (!is_file($verFile)) {
            return null;
        }

        return trim((string) file_get_contents($verFile)) ?: null;
    }
}
