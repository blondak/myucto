<?php

declare(strict_types=1);

namespace MyInvoice\Service\Migration\Shared;

/**
 * Identita firmy, kterou dávkový převod zakládá: poskládaná ze tří zdrojů v pořadí
 *
 *  1. záloha cizího programu („údaje o firmě", co firma tiskne na doklady),
 *  2. poslední podané přiznání k DPPO (sídlo, NACE, kategorie účetní jednotky, audit),
 *  3. ARES (když ani jedno pole nemá).
 *
 * Plátcovství DPH záloha nenese: rozhoduje ARES / registr plátců, bez nich nápověda
 * zdroje (obraty na účtu DPH v deníku) s upozorněním k ověření.
 */
final class MigrationCompanyIdentity
{
    /**
     * @param array{street:string,city:string,zip:string} $address
     * @param list<string> $notes upozornění k identitě pro protokol
     */
    public function __construct(
        public readonly string $ico,
        public readonly string $name,
        public readonly string $dic,
        public readonly array $address,
        public readonly ?bool $vatPayer,
        public readonly string $vatSource,
        public readonly ?string $taxpayerType,
        public readonly ?string $nace,
        public readonly ?string $category,
        public readonly ?bool $audit,
        public readonly ?string $firstPeriodStart,
        public readonly array $notes,
    ) {}

    /**
     * @param array{name?:string,dic?:string,street?:string,city?:string,zip?:string} $source údaje o firmě ze zálohy
     * @param FiledDppoFiling|null $filing poslední podané přiznání k DPPO
     * @param array<string,mixed>|null $ares normalizovaná data ARES ({@see \MyInvoice\Service\Ares\AresClient::lookup()} `data`)
     * @param bool|null $registryVatPayer plátce podle registru plátců DPH (null = registr nedostupný)
     * @param bool|null $sourceVatHint nápověda zdroje (obraty na účtu DPH), null = zdroj neví
     */
    public static function merge(
        string $ico,
        array $source,
        ?FiledDppoFiling $filing,
        ?array $ares,
        ?bool $registryVatPayer,
        ?bool $sourceVatHint,
        ?string $firstPeriodStart = null,
    ): self {
        $notes = [];
        $sourceAddress = ['street' => trim((string) ($source['street'] ?? '')), 'city' => trim((string) ($source['city'] ?? '')), 'zip' => str_replace(' ', '', (string) ($source['zip'] ?? ''))];
        $filedAddress = $filing?->address ?? ['street' => '', 'city' => '', 'zip' => ''];
        $aresAddress = ['street' => trim((string) ($ares['street'] ?? '')), 'city' => trim((string) ($ares['city'] ?? '')), 'zip' => str_replace(' ', '', (string) ($ares['zip'] ?? ''))];

        if (self::hasAddress($sourceAddress)) {
            $address = $sourceAddress;
            if (self::hasAddress($filedAddress) && self::norm($filedAddress) !== self::norm($sourceAddress)) {
                $notes[] = sprintf('Sídlo v záloze (%s, %s) se liší od posledního podaného přiznání (%s, %s), použito sídlo ze zálohy.',
                    $sourceAddress['street'], $sourceAddress['city'], $filedAddress['street'], $filedAddress['city']);
            }
        } elseif (self::hasAddress($filedAddress)) {
            $address = $filedAddress;
        } else {
            $address = $aresAddress;
        }
        if (!self::hasAddress($address)) {
            $notes[] = 'Sídlo firmy nezná záloha, podané přiznání ani ARES, doplňte ho v nastavení firmy.';
        }

        $name = trim((string) ($source['name'] ?? ''));
        if ($name === '') {
            $name = $filing !== null && $filing->name !== '' ? $filing->name : trim((string) ($ares['company_name'] ?? ''));
        }
        if ($name === '') {
            $name = 'Firma IČO ' . $ico;
            $notes[] = 'Název firmy nezná záloha, podané přiznání ani ARES, doplňte ho v nastavení firmy.';
        }

        $dic = strtoupper(str_replace(' ', '', (string) ($source['dic'] ?? '')));
        if ($dic === '') {
            $dic = $filing?->dic ?? '';
        }
        if ($dic === '') {
            $dic = strtoupper(str_replace(' ', '', (string) ($ares['dic'] ?? '')));
        }

        if ($registryVatPayer !== null) {
            $vatPayer = $registryVatPayer;
            $vatSource = 'registry';
        } elseif ($sourceVatHint !== null) {
            $vatPayer = $sourceVatHint;
            $vatSource = 'source_journal';
            $notes[] = $sourceVatHint
                ? 'Plátcovství DPH se nepodařilo ověřit v registru, podle obratů na účtu DPH v deníku je firma vedená jako plátce. Ověřte to v nastavení firmy.'
                : 'Plátcovství DPH se nepodařilo ověřit v registru, v deníku nejsou obraty na účtu DPH, firma je vedená jako neplátce. Ověřte to v nastavení firmy.';
        } else {
            $vatPayer = null;
            $vatSource = 'unknown';
        }

        $nace = $filing?->nace;
        if ($nace === null && trim((string) ($ares['cz_nace_code'] ?? '')) !== '') {
            $nace = trim((string) $ares['cz_nace_code']);
        }

        return new self(
            $ico,
            $name,
            $dic,
            $address,
            $vatPayer,
            $vatSource,
            $filing !== null ? 'po' : null,
            $nace,
            $filing?->category,
            $filing?->audit,
            $firstPeriodStart,
            $notes,
        );
    }

    /**
     * Vstup pro {@see \MyInvoice\Service\Supplier\SupplierCreator::create()}.
     *
     * @return array<string,mixed>
     */
    public function supplierInput(): array
    {
        $input = [
            'company_name' => $this->name,
            'street' => $this->address['street'],
            'city' => $this->address['city'],
            'zip' => $this->address['zip'],
            'email' => '',
            'ic' => $this->ico,
            'dic' => $this->dic,
        ];
        if ($this->vatPayer !== null) {
            $input['is_vat_payer'] = $this->vatPayer;
        }
        if ($this->taxpayerType !== null) {
            $input['taxpayer_type'] = $this->taxpayerType;
        }
        return $input;
    }

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        return [
            'ico' => $this->ico,
            'name' => $this->name,
            'dic' => $this->dic,
            'address' => $this->address,
            'vat_payer' => $this->vatPayer,
            'vat_source' => $this->vatSource,
            'taxpayer_type' => $this->taxpayerType,
            'nace' => $this->nace,
            'category' => $this->category,
            'audit' => $this->audit,
            'first_period_start' => $this->firstPeriodStart,
            'notes' => $this->notes,
        ];
    }

    /** @param array{street:string,city:string,zip:string} $a */
    private static function hasAddress(array $a): bool
    {
        return $a['street'] !== '' || $a['city'] !== '';
    }

    /** @param array{street:string,city:string,zip:string} $a */
    private static function norm(array $a): string
    {
        return mb_strtolower((string) preg_replace('/[^\p{L}\p{N}]/u', '', $a['street'] . $a['city']));
    }
}
