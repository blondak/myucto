<?php

declare(strict_types=1);

namespace MyInvoice\Service\Oss;

use MyInvoice\Service\Vat\VatRateMatch;

/**
 * Hotový řádek vydané faktury tak, jak ho má kanál zapsat: rozhodnutí o místě plnění
 * ({@see OssItemDecision}) plus sazba, na kterou se řádek naváže ({@see VatRateMatch}).
 *
 * ── Proč jsou obě odpovědi v JEDNOM objektu ─────────────────────────────────────────
 * Odpovědi spolu souvisí a rozpojit je znamená chybu: sazba se hledá v TÉ ZEMI, kterou
 * určilo rozhodnutí o místě plnění (stát spotřeby u OSS řádku, země dodavatele u
 * tuzemského). Kdyby si každý kanál skládal dvojici sám, dřív nebo později se v některém
 * z nich rozejde — a přesně tohle je chyba, kterou P0 opravilo v importu: OSS řádek
 * s `vat_rate_id` české sazby a snapshotem 23,00 nejde ani otevřít v editoru.
 *
 * ── Jeden ze dvou důvodů odmítnutí, ale pro volajícího jedna otázka ─────────────────
 * Řádek se odmítá buď proto, že číselník členských států nepotvrdil sazbu v zemi
 * dodavatele a OSS být nemůže (invariant proti úniku cizí daně), nebo proto, že se sazba
 * nenašla v `vat_rates`. Pro kanál je to táž situace — doklad nesmí vzniknout — a hláška
 * v obou případech říká, co konkrétně doplnit. Volající se proto ptá jedinou otázkou
 * {@see isRejected()} a nemusí vědět, který invariant zrovna zabral.
 */
final readonly class OssItemPlan
{
    /**
     * @param ?VatRateMatch $rate `null` = na sazbu se vůbec nedošlo, protože rozhodnutí
     *                            o místě plnění skončilo odmítnutím; bez něj není známá
     *                            země, ve které by se sazba měla hledat
     */
    public function __construct(
        public OssItemDecision $decision,
        public ?VatRateMatch $rate,
    ) {}

    public function isRejected(): bool
    {
        return $this->decision->isRejected() || $this->rate === null || !$this->rate->found();
    }

    /** Celá hláška včetně toho, co doplnit; `null` = řádek je v pořádku. */
    public function errorMessage(): ?string
    {
        if ($this->decision->isRejected()) {
            return $this->decision->rejectionMessage;
        }
        if ($this->rate === null || !$this->rate->found()) {
            return $this->rate?->message ?? 'Sazbu položky se nepodařilo napárovat na číselník sazeb DPH.';
        }

        return null;
    }

    /**
     * Varování k řádku — z rozhodnutí i z párování sazby (shoda mimo platnost).
     *
     * @return list<string>
     */
    public function warnings(): array
    {
        if ($this->decision->isRejected()) {
            // Hláška odmítnutí do varování nepatří (je to chyba dokladu) a ostatní
            // poznámky by u neuloženého řádku jen šuměly.
            return [];
        }

        $warnings = $this->decision->toReport()['warnings'];
        if ($this->rate !== null && $this->rate->found() && $this->rate->message !== '') {
            $warnings[] = $this->rate->message;
        }

        return array_values($warnings);
    }

    public function needsManualReview(): bool
    {
        return !$this->decision->isRejected() && $this->decision->needsManualReview();
    }

    /**
     * Sloupce k doplnění do payloadu položky pro `InvoiceRepository::replaceItems()`:
     * `$item = $item + $plan->itemColumns()`.
     *
     * Klíče se jmenují přesně jako ty, které čte `InvoiceRepository::ossItemParams()`,
     * a přibývá jen `vat_rate_id` — jediné pole, kvůli kterému kanály dosud sázely na
     * vlastní „nejbližší procento".
     *
     * @return array{vat_rate_id:int, vat_rate_snapshot:float, oss_applicable:int,
     *               oss_consumer_country:?string, oss_rate_type:?string,
     *               oss_supply_type:?string, oss_needs_manual_review:int}
     */
    public function itemColumns(): array
    {
        if ($this->isRejected()) {
            // Stejná pojistka jako u {@see OssItemDecision::toItemColumns()}: odmítnutí
            // se nesmí dát přehlédnout tím, že volající sáhne rovnou po sloupcích.
            throw new \LogicException(
                'Odmítnutý řádek nemá sloupce k zápisu — volající musí nejdřív ošetřit '
                    . 'OssItemPlan::isRejected() a doklad odmítnout.'
            );
        }

        /** @var VatRateMatch $rate rejectnutý stav je vyloučený výš */
        $rate = $this->rate;

        return [
            'vat_rate_id' => (int) $rate->id,
            'vat_rate_snapshot' => (float) ($rate->ratePercent ?? 0.0),
        ] + $this->decision->toItemColumns();
    }

    /**
     * Sloupce pro kanál, který odmítnutí NESMÍ přenést na doklad (typicky cron
     * opakovaných faktur, viz {@see OssItemPlanner::planIssuedItem()}): řádek zůstane
     * mimo OSS, ale nese příznak K RUČNÍMU POSOUZENÍ, takže se nejistota uloží k položce
     * místo aby zmizela.
     *
     * @return array{oss_applicable:int, oss_consumer_country:null, oss_rate_type:null,
     *               oss_supply_type:null, oss_needs_manual_review:int}
     */
    public static function manualReviewColumns(): array
    {
        return [
            'oss_applicable' => 0,
            'oss_consumer_country' => null,
            'oss_rate_type' => null,
            'oss_supply_type' => null,
            'oss_needs_manual_review' => 1,
        ];
    }
}
