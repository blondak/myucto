<?php

declare(strict_types=1);

namespace MyInvoice\Service\Oss;

/**
 * Výsledek odvození OSS pro jeden řádek dokladu.
 *
 * ── Tři možné výsledky, ne dva ──────────────────────────────────────────────────────
 * Vedle „OSS" a „tuzemské plnění" existuje třetí stav: ODMÍTNUTO. Bez něj by invariant
 * proti úniku cizí daně neměl kam ústit. Ten invariant je TOTÁLNÍ ({@see OssItemDeriver}):
 * tuzemské plnění stojí výhradně na POZITIVNÍM potvrzení číselníku členských států, že
 * sazba v zemi dodavatele k datu plnění platí. Každá jiná odpověď — „neplatí", „nevím",
 * chybějící i nečitelné datum — vede k odmítnutí, kdykoli řádek nemůže být ani OSS
 * (odběratel bez země, s DIČ, mimo EU, vypnutý OSS…). Systém v takové situaci nemá
 * pravdivou odpověď, takže místo tiché volby vrací odmítnutí s hláškou, která říká,
 * CO KONKRÉTNĚ doplnit ({@see OssDerivationReason::rejectionRemedy()}).
 *
 * ── Proč hodnotový objekt a ne pole ─────────────────────────────────────────────────
 * Konstruktor VYNUCUJE invariant „OSS řádek má vždy zemi spotřeby a typ plnění".
 * Bez něj by derivace uměla vyrobit stav, který systém sám považuje za neplatný, a import
 * by vytvořil fakturu, kterou nejde uložit ani otevřít v editoru.
 *
 * ── Proč typ sazby v invariantu NENÍ ────────────────────────────────────────────────
 * Typ sazby se odvozuje z číselníku sazeb členských států, a ten nemusí být namigrovaný
 * a nemusí znát nový stát ani ručně doplněnou sazbu. Dřívější podoba proto při neúspěchu
 * doplnila „standard", jenže do podání jde TYP sazby, ne procento
 * ({@see OssXmlExporter::rateTypeCode()}) — odhad tedy nebyl kosmetická vada, ale
 * nesprávně odvedená daň ve státě spotřeby. `null` je proto NAVRŽENÝ stav a musí projít
 * celým řetězem: validace dokladu ho pustí, editor ho nesmí přepsat na „standard"
 * a zastaví se až {@see OssLedgerService} varováním v náhledu a {@see OssXmlExporter} tím,
 * že takový řádek do podání vůbec nepustí. Prázdno, které se pojmenuje, je lepší než
 * hodnota, která lže.
 *
 * ── Rozhodnutí musí umět vysvětlit samo sebe ────────────────────────────────────────
 * Vedle sloupců k zápisu nese objekt i důvod a poznámky, takže report importu i log
 * backfillu čtou z JEDNOHO zdroje. Kdyby si vysvětlení skládal volající, rozejde se
 * s tím, co se skutečně zapsalo.
 */
final readonly class OssItemDecision
{
    /** Typy sazeb, které přijímá `invoice_items.oss_rate_type` i validace dokladu. */
    public const RATE_TYPES = ['standard', 'reduced', 'second_reduced', 'parking'];

    /** Typy plnění, které přijímá `invoice_items.oss_supply_type`. */
    public const SUPPLY_TYPES = ['goods', 'services'];

    /** @param list<OssDerivationReason> $notes */
    private function __construct(
        public bool $applicable,
        public ?string $consumerCountry,
        public ?string $rateType,
        public ?string $supplyType,
        public ?string $vatClassificationCode,
        public OssDerivationReason $reason,
        public array $notes,
        public ?string $rejectionMessage = null,
    ) {
        if (!$applicable) {
            if ($consumerCountry !== null || $rateType !== null || $supplyType !== null) {
                throw new \InvalidArgumentException('Ne-OSS rozhodnutí nesmí nést OSS parametry.');
            }
            return;
        }

        if ($rejectionMessage !== null) {
            throw new \InvalidArgumentException('Odmítnutá položka nemůže být zároveň OSS řádek.');
        }
        if (!$reason->canBeOssReason()) {
            // Bez téhle kontroly by šlo do OSS větve propašovat důvod, který znamená pravý
            // opak (ClientDomestic, RateMatchesDomesticOnly) — a report by pak tvrdil něco
            // jiného než sloupce položky.
            throw new \InvalidArgumentException('Důvod ' . $reason->value . ' nemůže stát u OSS řádku.');
        }
        if ($consumerCountry === null || preg_match('/^[A-Z]{2}$/', $consumerCountry) !== 1) {
            throw new \InvalidArgumentException('OSS řádek musí mít zemi spotřeby jako dvoupísmenný ISO kód.');
        }
        if ($rateType !== null && !in_array($rateType, self::RATE_TYPES, true)) {
            throw new \InvalidArgumentException('Typ OSS sazby musí být platný, nebo nezjištěný (null).');
        }
        if (!in_array($supplyType, self::SUPPLY_TYPES, true)) {
            throw new \InvalidArgumentException('OSS řádek musí mít typ plnění zboží, nebo služba.');
        }
        if ($vatClassificationCode !== null) {
            // OSS plnění se do tuzemského přiznání ani do KH nevykazuje (VatLedgerService
            // i DphPriznaniBuilder ho filtrují přes oss_applicable = 0), takže tuzemský
            // kód by byl mrtvá metadata — a v okamžiku, kdy někdo oss_applicable zhasne
            // (bulk edit, storno, reissue), by se řádek s kódem '1' objevil na ř. 1.
            throw new \InvalidArgumentException('OSS řádek nesmí nést tuzemský kód plnění.');
        }
    }

    /**
     * @param ?string                   $rateType `null` = číselník typ nepotvrdil; NEDOPLŇUJE se odhadem
     * @param list<OssDerivationReason> $notes
     * @param OssDerivationReason       $reason   proč je řádek OSS — vedle čistého případu
     *                                            i nejednoznačnost a neověřitelnost, které
     *                                            se podle asymetrie viditelnosti chyby řeší
     *                                            VE PROSPĚCH OSS
     */
    public static function oss(
        string $country,
        ?string $rateType,
        string $supplyType,
        array $notes = [],
        OssDerivationReason $reason = OssDerivationReason::B2cEuConsumer,
    ): self {
        return new self(
            true,
            strtoupper(trim($country)),
            $rateType,
            $supplyType,
            null,
            $reason,
            array_values($notes),
        );
    }

    /** @param list<OssDerivationReason> $notes */
    public static function notApplicable(OssDerivationReason $reason, array $notes = []): self
    {
        return new self(false, null, null, null, null, $reason, array_values($notes));
    }

    /**
     * Položka, kterou systém odmítá zapsat.
     *
     * Vzniká jedině z invariantu proti úniku: číselník členských států sazbu v zemi
     * dodavatele k datu plnění NEPOTVRDIL (vyloučil ji, neumí odpovědět, nebo doklad nemá
     * použitelné datum plnění), a řádek nemůže být ani OSS. Volající MUSÍ tenhle stav
     * ošetřit dřív, než sáhne po sloupcích — {@see toItemColumns()} na odmítnuté položce
     * schválně vyhodí výjimku, aby se odmítnutí nedalo přehlédnout.
     *
     * @param string                    $message celá hláška včetně toho, co doplnit
     * @param list<OssDerivationReason> $notes
     */
    public static function rejected(OssDerivationReason $reason, string $message, array $notes = []): self
    {
        return new self(false, null, null, null, null, $reason, array_values($notes), $message);
    }

    public function isRejected(): bool
    {
        return $this->rejectionMessage !== null;
    }

    /**
     * Místo plnění je sporné a čeká na člověka — příznak patří i k položce, ne jen do
     * reportu importu, jinak ho po zavření stránky nikdo nedohledá.
     *
     * Čte se DŮVOD i POZNÁMKY, protože spor přichází z obou stran. Když systém místo
     * plnění neurčil, je to důvod a řádek šel do OSS (chyba je vidět v krátkém náhledu
     * podání, kdežto v přiznání k DPH by zmizela mezi stovkami řádků). Když je rozhodnutí
     * jednoznačné, ale doklad si přesto protiřečí — tuzemská sazba na přeshraničním B2C
     * plnění při aktivní registraci do OSS ({@see OssDerivationReason::DomesticRateOnCrossBorderB2c})
     * — je to poznámka u řádku, který zůstal TUZEMSKÝ. Kdyby se četl jen důvod, byl by
     * ten druhý případ tichý: tuzemská větev vlastní důvod „je to podezřelé" nemá a mít
     * nemůže, protože důvod odpovídá na otázku „je řádek OSS".
     */
    public function needsManualReview(): bool
    {
        if ($this->reason->needsManualReview()) {
            return true;
        }
        foreach ($this->notes as $note) {
            if ($note->needsManualReview()) {
                return true;
            }
        }

        return false;
    }

    /**
     * Sloupce k zápisu na `invoice_items`. Klíče se jmenují přesně jako ty, které čte
     * `InvoiceRepository::ossItemParams()`, takže volající může udělat
     * `$item + $decision->toItemColumns()`.
     *
     * `oss_exchange_rate*`, `oss_taxable_amount_return`, `oss_vat_amount_return` ani
     * `oss_original_period` se ZÁMĚRNĚ nevyplňují: přepočet do měny podání dělá až
     * {@see OssLedgerService} kurzem ECB zveřejněným pro POSLEDNÍ DEN zdaňovacího období.
     * Ten se v době importu ještě nemusí znát a je jednotný pro celý kvartál, takže
     * předvyplnit ho na položce nejde — zafixoval by kurz data plnění, tedy jiný.
     *
     * @return array{oss_applicable:int, oss_consumer_country:?string,
     *               oss_rate_type:?string, oss_supply_type:?string,
     *               oss_needs_manual_review:int}
     */
    public function toItemColumns(): array
    {
        if ($this->isRejected()) {
            throw new \LogicException(
                'Odmítnutá položka nemá sloupce k zápisu — volající musí nejdřív ošetřit '
                    . 'OssItemDecision::isRejected() a doklad odmítnout.'
            );
        }

        return [
            'oss_applicable' => $this->applicable ? 1 : 0,
            'oss_consumer_country' => $this->consumerCountry,
            'oss_rate_type' => $this->rateType,
            'oss_supply_type' => $this->supplyType,
            // „K ručnímu posouzení" musí přežít zavření reportu importu: u 1 670 dokladů je
            // kategorie, kterou po zavření stránky nikdo nedohledá, k ničemu.
            'oss_needs_manual_review' => $this->needsManualReview() ? 1 : 0,
        ];
    }

    /**
     * Blok do reportu importu / dry-runu backfillu.
     *
     * Varování se sbírají i z PRIMÁRNÍHO důvodu, nejen z poznámek: nejednoznačná sazba
     * je zároveň důvod („řádek je OSS a nikdo neví proč") i to nejdůležitější, co má
     * uživatel vidět. Kdyby report četl jen poznámky, zůstal by tenhle případ neviditelný.
     *
     * Hláška odmítnutí do `warnings` NEPATŘÍ — je to chyba dokladu, ne poznámka k němu.
     * Volající ji má poslat do své chybové kolekce (u importu `errors`).
     *
     * @return array{applicable:bool, rejected:bool, rejection_message:?string,
     *               needs_manual_review:bool, reason:string, reason_message:string,
     *               notes:list<string>, warnings:list<string>}
     */
    public function toReport(): array
    {
        $warnings = [];
        if ($this->reason->isWarning()) {
            $warnings[] = $this->reason->message();
        }
        foreach ($this->notes as $note) {
            if ($note->isWarning()) {
                $warnings[] = $note->message();
            }
        }

        return [
            'applicable' => $this->applicable,
            'rejected' => $this->isRejected(),
            'rejection_message' => $this->rejectionMessage,
            'needs_manual_review' => $this->needsManualReview(),
            'reason' => $this->reason->value,
            'reason_message' => $this->reason->message(),
            'notes' => array_map(static fn (OssDerivationReason $n): string => $n->value, $this->notes),
            'warnings' => $warnings,
        ];
    }
}
