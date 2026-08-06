<?php

declare(strict_types=1);

namespace MyInvoice\Service\Oss;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Vat\VatRateMatch;
use MyInvoice\Service\Vat\VatRateResolver;

/**
 * Jediná cesta, kterou se KANÁL ptá na OSS. Skládá dvě existující autority — derivaci
 * místa plnění ({@see OssItemDeriver}) a párování sazby ({@see VatRateResolver}) — do
 * jedné odpovědi na otázku „jak mám tenhle řádek zapsat".
 *
 * ── Proč vůbec vzniká, když deriver už existuje ─────────────────────────────────────
 * Deriver odpovídá na otázku „je řádek OSS a s jakými parametry"; sám o sobě ale řádek
 * zapsat nestačí. Musí se k němu přidat `vat_rate_id`, a ten se HLEDÁ V ZEMI, KTEROU
 * URČILO ROZHODNUTÍ — u OSS řádku ve státě spotřeby, u tuzemského v zemi dodavatele.
 * Tohle spřažení je přesně to místo, kde se kanály rozcházejí: iDoklad, Fakturoid i AI
 * extrakce si na sazbu psaly VLASTNÍ „nejbližší procento" napříč celou tabulkou
 * `vat_rates`, bez země, bez platnosti k datu a bez `is_reverse_charge` — tedy tutéž
 * chybu, kterou P0 opravilo v importu (23 % se navázalo na kteroukoli 23% sazbu,
 * 0 % mohlo trefit reverse-charge sazbu). Kdyby se každý kanál na obě autority ptal sám,
 * rozejdou se znovu; proto se ptají přes tenhle jeden objekt.
 *
 * NEJDE o druhou implementaci derivace. Planner nerozhoduje NIC — jen předává odpověď
 * deriveru a doplní k ní sazbu. Kdykoli přibude pravidlo o místě plnění, patří do
 * {@see OssItemDeriver}, ne sem. Totéž platí o třetí autoritě, kterou skládá:
 * soudržnost celého dokladu bydlí v {@see OssDocumentCoherence} a planner ji jen VOLÁ
 * ({@see flagContradiction()}), protože je to jediné místo společné všem kanálům.
 *
 * ── Odmítnutí: kanály se liší, a je to vědomé ───────────────────────────────────────
 * Invariant proti úniku cizí daně umí skončit ODMÍTNUTÍM řádku (číselník členských států
 * sazbu v zemi dodavatele nepotvrdil a OSS být nemůže). Co s tím, ale není otázka OSS —
 * je to otázka kanálu:
 *
 *  - Kanál, který doklad PŘEBÍRÁ ZVENČÍ (iDoklad, Fakturoid, AI extrakce, soubor):
 *    doklad se nevytvoří a odmítnutí se jmenovitě zaloguje. Zdroj pravdy je venku,
 *    takže po opravě příčiny se běh zopakuje a doklad doteče — a dedup podle externího
 *    ID / varsymbolu zajistí, že se doplní právě jen ty odmítnuté.
 *  - Kanál, který doklad GENERUJE Z NAŠÍ VLASTNÍ KONFIGURACE (cron opakovaných faktur):
 *    doklad vzniknout MUSÍ, jinak by chybějící číselník zastavil zákazníkovi fakturaci.
 *    Řádek proto zůstane mimo OSS, ale dostane příznak K RUČNÍMU POSOUZENÍ
 *    ({@see OssItemPlan::manualReviewColumns()}) — nejistota se uloží k položce, místo
 *    aby zmizela. Tohle je jediné povolené „nevím" a je vidět v datech, ne jen v logu.
 *
 * ── Pre-flight číselníku patří sem, ne do jednotlivých kanálů ───────────────────────
 * Bez tabulky `oss_member_state_rates` odpoví číselník „nevím" u KAŽDÉ země, takže by se
 * odmítl každý řádek se sazbou vyšší než 0 % — u synchronizace z iDokladu tedy úplně
 * všechno, včetně běžné české faktury českému odběrateli. Jedna hlasitá věta na začátku
 * běhu je nesrovnatelně lepší než N stejných hlášek u N dokladů, a hlavně pojmenuje
 * skutečnou příčinu (neproběhlá migrace), ne její následek.
 */
final class OssItemPlanner
{
    /** @var array<string, bool> Klíč = ISO2 země dodavatele. */
    private array $domesticRatePresence = [];

    public function __construct(
        private readonly Connection $db,
        private readonly OssItemDeriver $deriver,
        private readonly OssRateCodebook $codebook,
        private readonly VatRateResolver $rates,
    ) {}

    /**
     * Kontext odběratele — delegace na deriver, aby kanál nemusel znát ani
     * {@see OssClientContext}, ani to, že uložený klient se čte přes `clients JOIN countries`.
     *
     * @param ?array<string,mixed> $documentClient klient tak, jak ho nese importovaný
     *                                             doklad; má přednost před uloženým
     */
    public function clientContext(int $clientId, ?array $documentClient = null): OssClientContext
    {
        return $this->deriver->clientContext($clientId, $documentClient);
    }

    /** Tuzemsko pro daného dodavatele — nikdy zadrátované 'CZ'. */
    public function domesticCountry(int $supplierId): string
    {
        return $this->deriver->domesticCountry($supplierId);
    }

    /**
     * Řádek VYDANÉ faktury: rozhodnutí o místě plnění + sazba ve správné zemi.
     *
     * @param float   $ratePercent         sazba z dokladu v procentech (23.0, ne 0.23)
     * @param ?string $unit                měrná jednotka; signál zboží vs. služba
     * @param string  $taxDate             efektivní datum plnění (DUZP s fallbackem na
     *                                     datum vystavení — fallback řeší volající)
     * @param bool    $headerReverseCharge `invoices.reverse_charge` dokladu
     */
    public function planIssuedItem(
        int $supplierId,
        OssClientContext $client,
        float $ratePercent,
        ?string $unit,
        string $taxDate,
        bool $headerReverseCharge,
    ): OssItemPlan {
        $decision = $this->deriver->derive($supplierId, $client, $ratePercent, $unit, $taxDate, $headerReverseCharge);
        if ($decision->isRejected()) {
            // Na sazbu se schválně nedochází: bez rozhodnutí o místě plnění není známá
            // země, ve které by se měla hledat, a „zkusíme tuzemsko" je právě ta úvaha,
            // kvůli které cizí daň končila na ř. 1 přiznání.
            return new OssItemPlan($decision, null);
        }

        $country = $decision->applicable
            ? (string) $decision->consumerCountry
            : $this->domesticCountry($supplierId);

        return new OssItemPlan($decision, $this->rates->resolve($country, $ratePercent, $taxDate));
    }

    /**
     * Celý doklad najednou: k položkám kanálu doplní `vat_rate_id`, `vat_rate_snapshot`
     * a OSS sloupce, aby se výsledek dal rovnou předat
     * `InvoiceRepository::replaceItems()`.
     *
     * Existuje proto, že jinak by tenhle SEDMIŘÁDKOVÝ CYKLUS musel být v každém kanálu
     * zvlášť — a s ním i rozhodnutí „co s odmítnutým řádkem", tedy přesně to, co se
     * mezi kanály rozchází. Klíč `vat_rate` (procento z dokladu) se z položky odebere;
     * `vat_rate_id` do ní přibude.
     *
     * Odmítnutí PRVNÍHO řádku shodí CELÝ doklad výjimkou. Vynechat jen vadný řádek je
     * horší varianta: doklad by v seznamu vypadal kompletní, ale měl by nižší součty —
     * a chybějící řádek by byl zrovna ten se zahraniční sazbou, kvůli kterému tahle
     * vlna existuje. Hláška nese číslo položky, ať uživatel ví, který řádek dokladem
     * pohnul. Kanál, který doklad zahodit NESMÍ (cron), tuhle metodu nepoužívá —
     * viz docblock třídy.
     *
     * Na konci se ptá i na SOUDRŽNOST CELÉHO DOKLADU ({@see OssDocumentCoherence}) —
     * viz {@see flagContradiction()}.
     *
     * @param  list<array<string,mixed>> $lines    položky s klíči `vat_rate` (procento
     *                                             z dokladu, 23.0) a volitelně `unit`
     * @param  list<string>              $warnings out: varování k řádkům i k dokladu
     * @return list<array<string,mixed>>
     * @throws \RuntimeException první odmítnutý řádek, hláškou s návodem
     */
    public function planIssuedItems(
        int $supplierId,
        int $clientId,
        string $taxDate,
        bool $reverseCharge,
        array $lines,
        array &$warnings = [],
    ): array {
        $client = $this->clientContext($clientId);
        $items = [];

        foreach (array_values($lines) as $index => $line) {
            $rate = (float) ($line['vat_rate'] ?? 0);
            $unit = (string) ($line['unit'] ?? '');
            $plan = $this->planIssuedItem($supplierId, $client, $rate, $unit !== '' ? $unit : null, $taxDate, $reverseCharge);

            if ($plan->isRejected()) {
                throw new \RuntimeException(sprintf('Položka č. %d: %s', $index + 1, (string) $plan->errorMessage()));
            }
            foreach ($plan->warnings() as $warning) {
                $warnings[] = sprintf('Položka č. %d: %s', $index + 1, $warning);
            }

            unset($line['vat_rate']);
            $items[] = $line + $plan->itemColumns();
        }

        $this->flagContradiction($supplierId, $items, $warnings);

        return $items;
    }

    /**
     * SOUDRŽNOST DOKLADU: leží týž doklad zároveň v OSS podání i v tuzemském přiznání?
     *
     * Patří sem, a ne do jednotlivých kanálů. Kontrolu dosud volal editor
     * ({@see \MyInvoice\Action\Invoice\CreateInvoiceAction}, `UpdateInvoiceAction`)
     * a souborový import — tedy dvě ze čtyř cest, kterými doklad vzniká; iDoklad,
     * Fakturoid ani AI extrakce o ní nevěděly, přestože jdou přes tenhle plánovač
     * a rozpor umí vyrobit úplně stejně. Plánovač je jediné místo, které VŠECHNY tři
     * sdílejí, a zároveň jediné, kde jsou pohromadě všechny položky dokladu —
     * per položku je rozpor neviditelný.
     *
     * Pravidlo se NEKOPÍRUJE: rozhoduje i značí {@see OssDocumentCoherence::flagItems()},
     * táž metoda, kterou volá editor. Mapu sazeb si plán nese sám (`vat_rate_id` →
     * `vat_rate_snapshot` z {@see OssItemPlan::itemColumns()}), takže se na ni nemusí
     * doptávat do `vat_rates` — a hlavně se ptá přesně na tu sazbu, se kterou se řádek
     * zapíše.
     *
     * @param list<array<string,mixed>> $items    MĚNÍ SE: dotčené řádky dostanou příznaky
     * @param list<string>              $warnings out
     */
    private function flagContradiction(int $supplierId, array &$items, array &$warnings): void
    {
        $rateMap = [];
        foreach ($items as $item) {
            $rateMap[(int) $item['vat_rate_id']] = (float) $item['vat_rate_snapshot'];
        }

        $contradiction = OssDocumentCoherence::flagItems($items, $rateMap);
        if ($contradiction === null) {
            return;
        }

        $warnings[] = $contradiction->warning($this->domesticCountry($supplierId));

        foreach ($contradiction->affectedKeys as $key) {
            // `flagItems()` značí booleanem, protože obsluhuje payload editoru. Tady se
            // vrací hotové SLOUPCE k zápisu, a ty jsou dokumentované jako int — kanál
            // se nemá co ptát, kterou z obou podob zrovna dostal.
            $items[$key]['oss_needs_manual_review'] = 1;
            $items[$key]['oss_document_contradiction'] = 1;
        }
    }

    /**
     * Řádek, který OSS mít nemůže (přijatá faktura, účtenka) — jen napárování sazby
     * v zemi dodavatele.
     *
     * Přijatá strana derivací neprochází: OSS je režim pro plnění, které POSKYTUJEME.
     * Sazbu ale páruje ze stejného číselníku a se stejnými filtry, protože chyba
     * „0 % trefilo reverse-charge sazbu" se jí týká úplně stejně.
     */
    public function resolveDomesticRate(int $supplierId, float $ratePercent, string $onDate): VatRateMatch
    {
        return $this->rates->resolve($this->domesticCountry($supplierId), $ratePercent, $onDate);
    }

    /**
     * Pre-flight číselníku sazeb členských států pro celý běh kanálu: hláška, nebo `null`.
     *
     * Zastavit běh smí jedině stav, který nejde spravit ničím jiným než zásahem do
     * instalace — chybějící tabulka (neproběhla migrace 1152) a číselník, který o zemi
     * DODAVATELE nevede vůbec nic. V obou případech je odpověď „nevím" u každého řádku,
     * takže by neprošlo nic a jedna věta je jediný užitečný výstup. Stav „číselník
     * nepokrývá zrovna tohle období" se sem ZÁMĚRNĚ nepočítá: nastane i na plně
     * namigrované instalaci (historický doklad staršího data, než kam sahá seed) a rada
     * „spusťte migrate.php" by tam poslala uživatele hledat příčinu jinam, než kde je.
     *
     * @param string $consequence věta o následku v daném kanálu (co se s během stalo),
     *                            připojí se na konec — příčina je společná, následek ne
     */
    public function codebookProblem(int $supplierId, string $consequence = ''): ?string
    {
        $tail = $consequence !== '' ? ' ' . $consequence : '';

        if (!$this->codebook->isAvailable()) {
            return 'Chybí číselník sazeb členských států (tabulka oss_member_state_rates, migrace 1152). '
                . 'Bez něj nelze u žádného řádku ověřit, ve které zemi jeho sazba platí, takže by se '
                . 'odmítl každý doklad se sazbou vyšší než 0 %. Spusťte php api/bin/migrate.php.' . $tail;
        }

        $domestic = $this->domesticCountry($supplierId);
        if (!$this->hasAnyDomesticRate($domestic)) {
            return sprintf(
                'Číselník sazeb členských států nevede pro zemi dodavatele (%s) ani jednu sazbu, takže '
                    . 'u žádného řádku nejde ověřit, jestli je jeho sazba tuzemská — odmítl by se každý '
                    . 'doklad se sazbou vyšší než 0 %%. Spusťte php api/bin/migrate.php; je-li firma '
                    . 'identifikovaná mimo pokryté státy, doplňte její sazby do číselníku.%s',
                $domestic,
                $tail,
            );
        }

        return null;
    }

    /**
     * Vede číselník pro tuhle zemi VŮBEC NĚCO? Bez ohledu na datum — na otázku „pokrývá
     * i tohle období" se {@see codebookProblem()} schválně neptá (viz jeho docblock).
     */
    private function hasAnyDomesticRate(string $country): bool
    {
        if (array_key_exists($country, $this->domesticRatePresence)) {
            return $this->domesticRatePresence[$country];
        }

        $stmt = $this->db->pdo()->prepare('SELECT 1 FROM oss_member_state_rates WHERE country = ? LIMIT 1');
        $stmt->execute([$country]);

        return $this->domesticRatePresence[$country] = $stmt->fetchColumn() !== false;
    }
}
