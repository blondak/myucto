<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Retention;

/**
 * Jedno zákonné retenční pravidlo — lhůta, od čeho se počítá a ČÍM je doložená.
 *
 * `retentionYears === null` znamená „zákonná lhůta není určená", NE „lhůta je nula".
 * Rozdíl je celý smysl téhle třídy: nedoložená lhůta se nesmí odhadnout, protože
 * podle odhadu by někdo smazal. Kategorie bez lhůty se proto k výmazu NIKDY
 * nenavrhne, dokud lhůtu nedodá tenant vlastní politikou.
 *
 * `section` je záměrně nullable. Když víme zákon, ale ne přesné ustanovení,
 * uvedeme zákon a ustanovení necháme prázdné — vymyšlené číslo paragrafu vypadá
 * jako doklad, a přitom je horší než přiznaná mezera.
 */
final readonly class PayrollRetentionRule
{
    /**
     * @param int|null                 $retentionYears  null = zákonná lhůta neurčená
     * @param list<string>             $employeeTables  tabulky vázané na osobu
     * @param list<string>             $employmentTables tabulky vázané na pracovní vztah
     */
    public function __construct(
        public string $category,
        public string $label,
        public ?int $retentionYears,
        public string $basis,
        public string $act,
        public ?string $section,
        public string $sourceStatus,
        public bool $accountingRelevant,
        public array $employeeTables,
        public array $employmentTables,
        public string $note,
    ) {}

    /** Citace do UI a do auditního záznamu — ustanovení, když ho známe, jinak zákon. */
    public function source(): string
    {
        return $this->section ?? $this->act;
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
            'act' => $this->act,
            'section' => $this->section,
            'source' => $this->source(),
            'source_status' => $this->sourceStatus,
            'accounting_relevant' => $this->accountingRelevant,
            'note' => $this->note,
        ];
    }
}
