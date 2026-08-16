<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Retention;

/**
 * Posouzení jedné osoby: do kdy se musí držet, podle čeho, a co by se s ní stalo.
 *
 * Nese i důvod, PROČ se nenavrhuje — návrh, který osobu jen mlčky vynechá, se
 * nedá zkontrolovat. Uživatel musí vidět rozdíl mezi „ještě běží lhůta",
 * „drží ji zadržení" a „lhůtu nikdo neurčil".
 */
final readonly class PayrollRetentionAssessment
{
    public const ACTION_ERASE = 'erase';
    public const ACTION_ANONYMIZE = 'anonymize';

    public const BLOCK_WITHIN_RETENTION = 'within_retention';
    public const BLOCK_UNDETERMINED = 'undetermined_retention';
    public const BLOCK_HOLD = 'legal_hold';
    public const BLOCK_ALREADY_DONE = 'already_anonymized';
    /**
     * Osoba nemá ANI JEDEN záznam, ze kterého by šlo lhůtu odvodit. Není to totéž
     * co uplynulá lhůta: chybí základ výpočtu, takže se nesmí navrhnout nic.
     * Omylem založená osoba se maže samostatnou funkcí „smazat zaměstnance",
     * ne retencí — ta řeší data, která se nastřádala, ne prázdný záznam.
     */
    public const BLOCK_NO_BASIS = 'no_retention_basis';

    /**
     * @param list<array<string,mixed>> $categories  rozpis lhůt, i těch neurčených
     * @param list<array<string,mixed>> $holds       aktivní zadržení, firemní i osobní
     * @param array<string,int>         $identity    co zmizí
     * @param array<string,int>         $residue     osobní údaj, který ve zmrazeném obsahu zůstane
     */
    public function __construct(
        public int $employeeId,
        public int $lastRecordYear,
        public array $categories,
        public ?string $governingCategory,
        public ?string $governingSource,
        public ?string $governingSourceStatus,
        public ?string $retainedUntil,
        public bool $expired,
        public array $holds,
        /**
         * `null` u zablokované osoby: co by se s ní stalo, se nezjišťuje, dokud
         * se s ní vůbec něco stát smí. Odpověď stojí přes deset dotazů a u osoby
         * v běžící lhůtě není k čemu.
         */
        public ?string $action,
        public array $identity,
        public array $residue,
        public ?string $blockedBy,
    ) {}

    /** Navrhne se k výmazu jen to, co má uplynulou lhůtu a nic ji nedrží. */
    public function isProposable(): bool
    {
        return $this->blockedBy === null;
    }

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        return [
            'employee_id' => $this->employeeId,
            'last_record_year' => $this->lastRecordYear,
            'categories' => $this->categories,
            'governing_category' => $this->governingCategory,
            'governing_source' => $this->governingSource,
            'governing_source_status' => $this->governingSourceStatus,
            'retained_until' => $this->retainedUntil,
            'expired' => $this->expired,
            'holds' => $this->holds,
            'action' => $this->action,
            'identity' => $this->identity,
            'residue' => $this->residue,
            'proposable' => $this->isProposable(),
            'blocked_by' => $this->blockedBy,
        ];
    }
}
