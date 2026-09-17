<?php

declare(strict_types=1);

namespace MyInvoice\Service\Migration;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Oss\OssClientContext;
use MyInvoice\Service\Oss\OssItemPlanner;

/**
 * Co převod účetního roku udělá s řádkem VYDANÉHO dokladu, jehož členění DPH stojí MIMO
 * české přiznání, a doklad přesto nese daň.
 *
 * ── Proč to není doklad k ruční kontrole ────────────────────────────────────────────
 * Přesně takhle se v Pohodě i v Money vede prodej zboží na dálku koncovému zákazníkovi
 * do jiného členského státu: účetní jednotka si založí vlastní zkratku členění bez řádku
 * přiznání, na položky dá sazbu státu spotřeby a odběratel je bez DIČ. Do českého
 * přiznání takové plnění nepatří - patří do OSS. U e-shopu to nejsou jednotky dokladů,
 * ale stovky až tisíce za rok, takže „převezmi jako koncept a ať to člověk projde"
 * není průchodná odpověď.
 *
 * ── Rozhoduje TÁŽ autorita jako u všech ostatních kanálů ────────────────────────────
 * Převod si na místo plnění NEODPOVÍDÁ SÁM. Ptá se {@see OssItemPlanner}, tedy téhož
 * plánovače, kterým jde souborový import, iDoklad, Fakturoid, AI extrakce, pravidelná
 * fakturace i veřejné API. Vlastní úvaha „sazba v ČR neplatí, takže je to OSS" by byla
 * druhá implementace pravidla, které už jednou existuje - a rozešla by se s ním přesně
 * u případů, kvůli kterým je pravidlo tak opatrné (registrace platná jen část období,
 * číselník neznající zrovna tuhle sazbu, odběratel s DIČ).
 *
 * Tahle třída tedy nerozhoduje nic. Drží jen POLITIKU PŘEVODU - tedy odpověď na otázku,
 * kterou si každý kanál zodpovídá sám ({@see OssItemPlanner} „Odmítnutí: kanály se liší"):
 * co udělat s řádkem, který plánovač odmítne. Převod ho NESMÍ zahodit (doklad by v
 * převedeném roce chyběl a nesedělo by saldo ani rekonciliace) a nesmí ho ani protlačit
 * do tuzemského přiznání. Zůstává proto dosavadní chování - doklad se převezme jako
 * koncept k ruční kontrole - jen s hláškou, která konečně říká, co doplnit.
 *
 * Existuje jako sdílená třída, protože obě větve převodu (Pohoda, Money S3) mají tentýž
 * problém a dosud ho řešily dvěma kopiemi téhož `reasons[] = "členění DPH … mimo přiznání
 * u dokladu s daní"`. Druhá kopie je přesně to, co má SSOT zarazit.
 */
final class OssMigrationPolicy
{
    /** Řádek zůstává mimo OSS - výchozí hodnoty sloupců položky. */
    public const DOMESTIC_COLUMNS = [
        'oss_applicable' => 0,
        'oss_consumer_country' => null,
        'oss_rate_type' => null,
        'oss_supply_type' => null,
        'oss_needs_manual_review' => 0,
    ];

    private ?bool $ossEnabledCache = null;

    private bool $warned = false;

    public function __construct(
        private readonly Connection $db,
        private readonly OssItemPlanner $planner,
    ) {}

    /**
     * Kontext odběratele z KARTY, přebitý tím, co nese převáděný doklad. Země z dokladu
     * je pravdivější než uložená karta ({@see OssClientContext}), DIČ z dokladu taky -
     * a jedno i druhé rozhoduje o tom, jestli je plnění B2C do jiného členského státu.
     */
    public function clientContext(int $clientId, string $countryIso2, string $dic): OssClientContext
    {
        return $this->planner->clientContext($clientId, ['country_iso2' => $countryIso2, 'dic' => $dic]);
    }

    /**
     * Jedna hlasitá věta na začátek běhu místo tisíce stejných hlášek u tisíce dokladů.
     *
     * Vrací hlášku jen JEDNOU za běh a jen tehdy, když je příčina společná všem dokladům
     * (nespuštěná migrace číselníku, vypnutý režim OSS). Důvody, které se liší doklad od
     * dokladu (chybějící sazba jedné konkrétní země, odběratel s DIČ), sem nepatří - ty
     * nese hláška u dokladu.
     */
    public function runWarning(int $supplierId): ?string
    {
        if ($this->warned) {
            return null;
        }
        $this->warned = true;

        $problem = $this->planner->codebookProblem(
            $supplierId,
            'Doklady, které v převáděné agendě vypadají na režim OSS, proto zůstanou koncepty k ruční kontrole.',
        );
        if ($problem !== null) {
            return $problem;
        }
        if ($this->ossEnabled($supplierId)) {
            return null;
        }

        return 'Převáděná agenda obsahuje vydané doklady s členěním DPH mimo přiznání, které nesou daň. '
            . 'Tak se vede prodej koncovému zákazníkovi do jiného členského státu, tedy režim OSS. '
            . 'Firma ale režim OSS zapnutý nemá, takže se tyhle doklady převezmou jako koncepty '
            . 'k ruční kontrole. Zapněte ho v Nastavení → Daně a účetnictví, v číselníku DPH sazeb '
            . 'doplňte sazby států spotřeby (pozor na sloupec Stát, formulář ho předvyplňuje na CZ) '
            . 'a převod zopakujte. U už převedených dokladů pomůže hromadná akce Nastavit OSS '
            . 'v seznamu faktur.';
    }

    /**
     * Jeden řádek dokladu, jehož členění stojí mimo přiznání.
     *
     * `rate_id` se vrací jen u řádku, který SKUTEČNĚ skončil v OSS - a hledal se ve státě
     * spotřeby, ne v tuzemsku. U všech ostatních zůstává `null` a sazbu si páruje volající
     * po svém (tuzemsky), protože jinou zemi než tuzemsko pro ně nemá čím odůvodnit.
     *
     * @param  ?string $unit               měrná jednotka řádku; signál zboží vs. služba
     * @param  string  $classificationCode zkratka členění z převáděné agendy - jen do hlášky
     * @return array{rate_id:?int,rate_percent:float,columns:array<string,mixed>,reason:?string,manual_review:bool,warnings:list<string>}
     */
    public function planItem(
        int $supplierId,
        OssClientContext $client,
        float $ratePercent,
        ?string $unit,
        string $taxDate,
        string $classificationCode,
    ): array {
        if ($ratePercent <= 0.0) {
            // Osvobozené plnění, vývoz, přenesená povinnost. Číselník nulové sazby nevede,
            // takže se nedá o čem rozhodovat - a bez daně doklad k ruční kontrole ani dosud
            // nešel.
            return $this->outcome(null, $ratePercent, null, false);
        }

        $plan = $this->planner->planIssuedItem($supplierId, $client, $ratePercent, $unit, $taxDate, false);

        if ($plan->isRejected()) {
            return $this->outcome(null, $ratePercent, sprintf(
                'členění DPH „%s“ mimo přiznání u dokladu s daní: %s',
                $classificationCode,
                (string) $plan->errorMessage(),
            ), false);
        }

        if (!$plan->decision->applicable) {
            // Plánovač řekl „tuzemské plnění", členění říká „mimo přiznání". Rozhodnout to
            // za uživatele by znamenalo buď poslat daň na ř. 1 proti jeho vlastnímu členění,
            // nebo ji z přiznání vyjmout proti číselníku. Obojí potichu; tohle je přesně ten
            // případ, pro který koncept k ruční kontrole existuje.
            return $this->outcome(null, $ratePercent, sprintf(
                'členění DPH „%s“ mimo přiznání u dokladu s daní, ale sazba %s %% podle číselníku '
                    . 'sazeb členských států v zemi dodavatele k datu plnění platí - plnění do OSS nepatří',
                $classificationCode,
                self::percent($ratePercent),
            ), false);
        }

        $columns = $plan->itemColumns();

        return [
            'rate_id' => (int) $columns['vat_rate_id'],
            // Autoritativní je procento z číselníku, ne z dokladu - stejně jako v ostatních
            // kanálech. Liší se nanejvýš o toleranci párování.
            'rate_percent' => (float) $columns['vat_rate_snapshot'],
            'columns' => [
                'oss_applicable' => $columns['oss_applicable'],
                'oss_consumer_country' => $columns['oss_consumer_country'],
                'oss_rate_type' => $columns['oss_rate_type'],
                'oss_supply_type' => $columns['oss_supply_type'],
                'oss_needs_manual_review' => $columns['oss_needs_manual_review'],
            ],
            'reason' => null,
            'manual_review' => $plan->needsManualReview(),
            // Typicky „typ plnění se odvodit nedal, doplněna služba" - u e-shopu se zbožím
            // je to špatně a uživatel to musí vidět dřív, než podá OSS přiznání. Rozhodnutí
            // to nemění, proto to není důvod ke konceptu.
            'warnings' => $plan->warnings(),
        ];
    }

    /**
     * @return array{rate_id:?int,rate_percent:float,columns:array<string,mixed>,reason:?string,manual_review:bool,warnings:list<string>}
     */
    private function outcome(?int $rateId, float $ratePercent, ?string $reason, bool $manualReview): array
    {
        return [
            'rate_id' => $rateId,
            'rate_percent' => $ratePercent,
            'columns' => self::DOMESTIC_COLUMNS,
            'reason' => $reason,
            'manual_review' => $manualReview,
            'warnings' => [],
        ];
    }

    private function ossEnabled(int $supplierId): bool
    {
        if ($this->ossEnabledCache !== null) {
            return $this->ossEnabledCache;
        }
        $stmt = $this->db->pdo()->prepare('SELECT oss_enabled FROM supplier WHERE id = ?');
        $stmt->execute([$supplierId]);

        return $this->ossEnabledCache = (int) $stmt->fetchColumn() === 1;
    }

    /** „23", „12,5" - bez koncových nul, ať hláška nevypadá strojově. */
    private static function percent(float $rate): string
    {
        return rtrim(rtrim(number_format($rate, 2, ',', ''), '0'), ',');
    }
}
