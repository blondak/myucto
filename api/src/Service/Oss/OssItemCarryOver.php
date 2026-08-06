<?php

declare(strict_types=1);

namespace MyInvoice\Service\Oss;

use MyInvoice\Infrastructure\Database\Connection;

/**
 * PŘENOS OSS sloupců ze zdrojového řádku na řádek ODVOZENÉHO dokladu.
 *
 * ── Proč přenos, a ne derivace znovu ────────────────────────────────────────────────
 * Odvozený doklad (vyúčtovací faktura k proformě, daňový doklad k přijaté platbě,
 * dobropis, klon) není nové plnění: je to TOTÉŽ plnění TÉMUŽ odběrateli v TÉŽE sazbě,
 * jen zapsané druhým dokladem. Místo plnění se tím pádem změnit nemůže — a zdrojový
 * řádek ho už zná přesněji, než by ho uměla zjistit druhá derivace:
 *
 *  - prošel {@see OssItemDeriver} v okamžiku, kdy doklad vznikal, a
 *  - mohl být RUČNĚ OPRAVEN (účetní dohledala typ sazby, který číselník nepotvrdil,
 *    nebo určila typ plnění, který z jednotky odvodit nejde).
 *
 * Druhá derivace by tu ruční opravu tiše zahodila — přesně z tohohle důvodu dává
 * přednost uloženému rozhodnutí i {@see OssTemplateItemPolicy} u opakovaných faktur.
 * Navíc umí SKONČIT ODMÍTNUTÍM (číselník členských států nepotvrdil sazbu v zemi
 * dodavatele), a oba odvozené doklady vznikají BEZ DOZORU — z bankovního párování —
 * takže by neproběhlá migrace 1152 orazítkovala příznakem „k ručnímu posouzení"
 * i řadové české faktury.
 *
 * ── Jeden mechanismus pro všechny přenášející cesty ─────────────────────────────────
 * Sada sloupců i jejich pořadí bydlí TADY, protože kanály, které je přenášejí, jsou
 * čtyři a rozejít se stačí jednomu: nepřenesený `oss_applicable` znamená, že se OSS
 * řádek na odvozeném dokladu změní na tuzemský a cizí daň skončí na ř. 1 českého
 * přiznání. Přesně to review naměřila u vyúčtovací faktury k proformě.
 * Cesty, které tenhle přenos dělají: {@see \MyInvoice\Service\Invoice\FinalFromProformaCreator},
 * {@see \MyInvoice\Service\Invoice\PaymentTaxDocumentCreator} a — zatím ve vlastní inline
 * podobě, protože obě mění hodnoty (dobropis obrací znaménka částek do podání, klon
 * období nepřenáší) — `CancelInvoiceAction` a `BulkReissueAction`.
 *
 * ── Co se NEPŘENÁŠÍ ─────────────────────────────────────────────────────────────────
 * Sloupce podání (`oss_exchange_rate`, `oss_taxable_amount_return`, `oss_vat_amount_return`,
 * `oss_original_period`) jsou vlastnost KONKRÉTNÍHO dokladu v KONKRÉTNÍM podání, ne
 * vlastnost plnění — přepočet i období se počítají k datu odvozeného dokladu. Zkopírovat
 * je znamená vykázat v podání částku a kurz cizího dokladu. Shodně s `BulkReissueAction`.
 *
 * ── Příznak „k ručnímu posouzení" se přenáší NEZÁVISLE na `oss_applicable` ──────────
 * Nese ho i tuzemský řádek smíšeného dokladu (viz `InvoiceRepository::ossItemParams()`),
 * takže by ho vazba na `oss_applicable` u poloviny označených řádků zhasla. Nejistota
 * o místě plnění se odvozením dokladu nevyřeší; naopak se přenese s ním.
 *
 * ── Známá hranice: registrace do OSS, která mezitím skončila ────────────────────────
 * Přenos je BEZPODMÍNEČNÝ — stejně jako u dobropisu a klonu. Skončí-li dodavateli mezi
 * zdrojovým a odvozeným dokladem registrace do OSS (`supplier.oss_valid_to`), spadne
 * přenesený řádek mimo obě evidence: z OSS podání ho vyřadí platnost registrace,
 * z tuzemského přiznání OSS příznak. Je to vlastnost přenosu jako takového, ne těchhle
 * dvou cest, a řeší se v JEDNOM místě — tady — až se řešit bude; vzor odpovědi má
 * {@see OssTemplateItemPolicy::generatedColumns()}, kde na to u šablon (které generují
 * roky, ne dny) došlo dřív. Odvozený doklad vzniká nejvýš o jeden zálohový cyklus
 * později než zdrojový, takže je to řádově užší okno.
 *
 * Beze změny schématu použitelné i na starší instanci: chybí-li sloupce migrace 0137,
 * je {@see columns()} prázdné a volající prostě žádné OSS sloupce nezapisuje. Guard na
 * `oss_needs_manual_review` je VLASTNÍ (migrace 1293 je o řadu verzí dál) — shodně
 * s `InvoiceRepository::supportsOssManualReview()`.
 */
final class OssItemCarryOver
{
    /** Pořadí je závazné: {@see columns()} i {@see values()} ho drží společné. */
    private const CORE = ['oss_applicable', 'oss_consumer_country', 'oss_rate_type', 'oss_supply_type'];

    private const MANUAL_REVIEW = 'oss_needs_manual_review';

    private ?bool $supportsOss = null;

    private ?bool $supportsManualReview = null;

    public function __construct(private readonly Connection $db) {}

    /**
     * Sloupce k přenosu v pořadí, ve kterém {@see values()} vrací hodnoty. Prázdné pole
     * = instance nemá OSS schéma, takže se nezapisuje nic.
     *
     * @return list<string>
     */
    public function columns(): array
    {
        if (!$this->hasOssColumns()) {
            return [];
        }
        $columns = self::CORE;
        if ($this->hasManualReviewColumn()) {
            $columns[] = self::MANUAL_REVIEW;
        }

        return $columns;
    }

    /**
     * Tytéž sloupce pro SELECT nad `invoice_items`, i s čárkou na začátku — aby se
     * seznam sloupců nemusel psát podruhé ručně v SQL odvozeného dokladu.
     *
     * @param string $alias alias tabulky `invoice_items` v dotazu (např. 'ii')
     */
    public function selectList(string $alias): string
    {
        $columns = $this->columns();

        return $columns === []
            ? ''
            : ', ' . implode(', ', array_map(static fn (string $c): string => $alias . '.' . $c, $columns));
    }

    /** Otazníky pro INSERT — počet se nesmí rozejít s {@see columns()}. */
    public function placeholders(): string
    {
        return str_repeat(', ?', count($this->columns()));
    }

    /**
     * Hodnoty k zápisu, v pořadí {@see columns()}.
     *
     * Zdrojem smí být řádek z `InvoiceRepository::find()` (kde je `oss_applicable` bool)
     * i syrový `fetch()` z PDO (kde je to '0'/'1') — proto se normalizuje přes `empty()`.
     * U ne-OSS řádku se ostatní tři sloupce zapisují jako NULL: zbytek OSS profilu bez
     * `oss_applicable` je mrtvá metadata, která by v okamžiku ručního zapnutí OSS na
     * odvozeném dokladu tvrdila něco, co nikdo nerozhodl.
     *
     * @param  array<string,mixed> $source položka zdrojového dokladu
     * @return list<mixed>
     */
    public function values(array $source): array
    {
        if (!$this->hasOssColumns()) {
            return [];
        }

        $applicable = !empty($source['oss_applicable']);
        $values = [
            $applicable ? 1 : 0,
            $applicable ? (OssClientContext::iso2OrNull($source['oss_consumer_country'] ?? null)) : null,
            $applicable ? (trim((string) ($source['oss_rate_type'] ?? '')) ?: null) : null,
            $applicable ? OssClientContext::supplyTypeOrNull($source['oss_supply_type'] ?? null) : null,
        ];
        if ($this->hasManualReviewColumn()) {
            // Bez vazby na $applicable — viz docblock třídy.
            $values[] = !empty($source['oss_needs_manual_review']) ? 1 : 0;
        }

        return $values;
    }

    /**
     * Stát spotřeby zdrojového řádku, nebo `null` u řádku mimo OSS. Statické schválně:
     * je to čtení JEDNOHO pole, ne rozhodnutí, takže na něj volající nepotřebuje ani
     * schéma, ani instanci — a hlavně ať si `strtoupper(trim(...))` nepíše sám.
     *
     * @param array<string,mixed> $source
     */
    public static function consumerCountryOf(array $source): ?string
    {
        return empty($source['oss_applicable'])
            ? null
            : OssClientContext::iso2OrNull($source['oss_consumer_country'] ?? null);
    }

    /**
     * Otisk OSS profilu řádku pro SESKUPOVÁNÍ.
     *
     * Odvozené doklady své řádky agregují po sazbě (daňový doklad k platbě rozděluje
     * úplatu per sazba, vyúčtování odečítá per sazba). Sazba sama o sobě ale řádky
     * rozlišit NESTAČÍ: u zákazníkovy konfigurace je polská 23% sazba vedená v
     * `vat_rates` se zemí CZ, takže OSS řádek a tuzemský řádek můžou mít TOTÉŽ
     * `vat_rate_id` i totéž procento. Sloučily by se do jednoho kbelíku a OSS profil
     * jednoho z nich by zmizel — a to je táž chyba, jakou zavírá samotný přenos.
     *
     * @param array<string,mixed> $source
     */
    public function fingerprint(array $source): string
    {
        return implode('|', array_map(
            static fn (mixed $v): string => $v === null ? '' : (string) $v,
            $this->values($source),
        ));
    }

    private function hasOssColumns(): bool
    {
        return $this->supportsOss ??= $this->db->hasColumn('invoice_items', 'oss_applicable');
    }

    private function hasManualReviewColumn(): bool
    {
        return $this->supportsManualReview ??= $this->hasOssColumns()
            && $this->db->hasColumn('invoice_items', 'oss_needs_manual_review');
    }
}
