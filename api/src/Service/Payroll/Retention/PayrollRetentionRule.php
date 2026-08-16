<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Retention;

/**
 * Jedno retenční pravidlo — lhůta, od čeho se počítá, ODKUD POCHÁZÍ a čím je doložená.
 *
 * `retentionYears === null` znamená „lhůta není určená", NE „lhůta je nula".
 * Rozdíl je celý smysl téhle třídy: nedoložená lhůta se nesmí odhadnout, protože
 * podle odhadu by někdo smazal. Kategorie bez lhůty se proto k výmazu NIKDY
 * nenavrhne, dokud lhůtu nedodá tenant vlastní politikou.
 *
 * `origin` odděluje ZÁKONNOU lhůtu od DODANÉ. Číslo, které v žádné sbírce není
 * (zdravotní pojištění), se nesmí tvářit jako paragraf — schvalující výmazu musí
 * v UI i v auditní stopě poznat, jestli za lhůtou stojí zákon, nebo rozhodnutí
 * aplikace. Proto to nese `source()` v textu, ne jen strojový příznak.
 *
 * `section` je záměrně nullable. Když víme zákon, ale ne přesné ustanovení,
 * uvedeme zákon a ustanovení necháme prázdné — vymyšlené číslo paragrafu vypadá
 * jako doklad, a přitom je horší než přiznaná mezera.
 *
 * `amendment` nese novelu, která dnešní číslo (nebo dnešní označení písmene)
 * zavedla. Bez ní se u ustanovení, které se v posledních letech měnilo, nedá při
 * příští kontrole poznat, jestli je citace stará, nebo jen stručná.
 */
final readonly class PayrollRetentionRule
{
    /**
     * @param int|null     $retentionYears   null = lhůta neurčená
     * @param string|null  $alternativeBasis druhá báze téhož ustanovení, kterou posudek NEAPLIKUJE
     * @param string|null  $amendment        novela, která zavedla dnešní číslo/písmeno
     * @param string|null  $verifiedOn       den ověření proti znění předpisu (Y-m-d)
     * @param bool         $closingAgenda    ustanovení zrušené, lhůta jen dobíhá
     * @param list<string> $employeeTables   tabulky vázané na osobu
     * @param list<string> $employmentTables tabulky vázané na pracovní vztah
     */
    public function __construct(
        public string $category,
        public string $label,
        public ?int $retentionYears,
        public string $basis,
        public ?string $alternativeBasis,
        public string $origin,
        public string $act,
        public ?string $section,
        public ?string $amendment,
        public string $sourceStatus,
        public ?string $verifiedOn,
        public bool $accountingRelevant,
        public bool $closingAgenda,
        public array $employeeTables,
        public array $employmentTables,
        public string $note,
    ) {}

    /**
     * Citace do UI a do auditního záznamu — ustanovení, když ho známe, jinak zákon.
     *
     * U dodané lhůty se citace NESMÍ tvářit jako paragraf: vrací se výslovné
     * přiznání, že za číslem stojí aplikace, ne sbírka.
     */
    public function source(): string
    {
        if ($this->origin === PayrollRetentionCatalog::ORIGIN_HOUSE_POLICY) {
            return 'dodaná politika aplikace (v předpisu ' . $this->shortAct()
                . ' uschovávací lhůta není)';
        }

        return $this->section ?? $this->act;
    }

    /** Lhůtu stanoví zákon, ne rozhodnutí aplikace. */
    public function isStatutory(): bool
    {
        return $this->origin === PayrollRetentionCatalog::ORIGIN_STATUTE;
    }

    public function isDetermined(): bool
    {
        return $this->retentionYears !== null;
    }

    /**
     * Poslední den, kdy záznam z roku `$recordYear` ještě musí být uchovaný.
     *
     * Obě modelované báze (kalendářní roky po roce záznamu i roky od konce
     * účetního období) dopadají u mzdové agendy na týž den — mzdové období je
     * vždy kalendářní měsíc, takže „konec období" je 31. 12. téhož roku. Kdyby
     * modul někdy počítal nad hospodářským rokem, musí se tahle metoda rozdělit;
     * proto je báze uložená, ne zahozená.
     *
     * Vrací `null` pro neurčenou lhůtu — „nikdy neexpiruje".
     */
    public function retainedUntil(int $recordYear): ?string
    {
        if ($this->retentionYears === null) {
            return null;
        }

        return sprintf('%04d-12-31', $recordYear + $this->retentionYears);
    }

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        return [
            'category' => $this->category,
            'label' => $this->label,
            'retention_years' => $this->retentionYears,
            'basis' => $this->basis,
            'alternative_basis' => $this->alternativeBasis,
            'origin' => $this->origin,
            'statutory' => $this->isStatutory(),
            'act' => $this->act,
            'section' => $this->section,
            'amendment' => $this->amendment,
            'source' => $this->source(),
            'source_status' => $this->sourceStatus,
            'verified_on' => $this->verifiedOn,
            'accounting_relevant' => $this->accountingRelevant,
            'closing_agenda' => $this->closingAgenda,
            'note' => $this->note,
        ];
    }

    /** Číslo předpisu bez názvu — do citace, která se vejde do jednoho řádku. */
    private function shortAct(): string
    {
        $comma = mb_strpos($this->act, ',');

        return $comma === false ? $this->act : mb_substr($this->act, 0, $comma);
    }
}
