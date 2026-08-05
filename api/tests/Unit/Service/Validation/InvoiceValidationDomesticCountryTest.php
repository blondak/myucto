<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Validation;

use MyInvoice\Service\Validation\InvoiceValidation;
use PHPUnit\Framework\TestCase;

/**
 * Kontrola „zahraniční sazbu jen na OSS řádku" musí brát tuzemsko ze země DODAVATELE,
 * ne z natvrdo zapsané 'CZ'.
 *
 * Derivace OSS ({@see \MyInvoice\Service\Oss\OssItemDeriver::domesticCountry()}) tuzemsko
 * odvozuje ze země dodavatele, validace ho měla zadrátované. Dvě definice téhož pojmu
 * znamenají, že dodavateli identifikovanému mimo ČR validace zakáže jeho VLASTNÍ tuzemskou
 * sazbu a naopak mu mlčky pustí českou.
 */
final class InvoiceValidationDomesticCountryTest extends TestCase
{
    public function testSupplierOwnCountryRateIsNotTreatedAsForeign(): void
    {
        $err = InvoiceValidation::invoice($this->invoiceWithPlainItem(), null, [7 => 'SK'], 'SK');

        self::assertArrayNotHasKey('items.0.vat_rate_id', $err);
    }

    public function testCzechRateIsForeignForASlovakSupplier(): void
    {
        $err = InvoiceValidation::invoice($this->invoiceWithPlainItem(), null, [7 => 'CZ'], 'SK');

        self::assertArrayHasKey('items.0.vat_rate_id', $err);
    }

    /**
     * Nezadaná země dodavatele padá na tentýž fallback, jaký používá deriver, když
     * dodavatel zemi vyplněnou nemá — ne na jiný.
     */
    public function testWithoutSupplierCountryTheCheckBehavesAsBefore(): void
    {
        $data = $this->invoiceWithPlainItem();

        self::assertArrayHasKey('items.0.vat_rate_id', InvoiceValidation::invoice($data, null, [7 => 'DE']));
        self::assertArrayNotHasKey('items.0.vat_rate_id', InvoiceValidation::invoice($data, null, [7 => 'CZ']));
    }

    /**
     * `vat_rates.country` je uživatelem editovatelné pole, takže se do něj dostane
     * i ' cz '. Porovnání syrových řetězců by kontrolu na takové hodnotě mlčky spustilo
     * naprázdno — a kontrola, která nekontroluje, je horší než žádná.
     */
    public function testRateCountryIsNormalisedBeforeComparison(): void
    {
        $err = InvoiceValidation::invoice($this->invoiceWithPlainItem(), null, [7 => ' cz '], 'CZ');

        self::assertArrayNotHasKey('items.0.vat_rate_id', $err);
    }

    /**
     * Doklad odpovídající konfiguraci zákazníka z analýzy OSS: sazba pojmenovaná „PL-23",
     * ale se zemí CZ, protože formulář má CZ předvyplněnou. Kontrola postavená nad tímhle
     * polem na ní nezakáže nic — proto se na ni nesmí spoléhat jako na pojistku proti
     * úniku cizí daně do českého přiznání (tou je invariant v OssItemDeriveru).
     */
    public function testForeignRateLabelledAsCzechPassesTheCheck(): void
    {
        $err = InvoiceValidation::invoice($this->invoiceWithPlainItem(), null, [7 => 'CZ'], 'CZ');

        self::assertArrayNotHasKey('items.0.vat_rate_id', $err);
    }

    /** @return array<string,mixed> */
    private function invoiceWithPlainItem(): array
    {
        return [
            'invoice_type' => 'invoice',
            'client_id'    => 1,
            'currency_id'  => 1,
            'issue_date'   => '2026-07-15',
            'due_date'     => '2026-07-29',
            'tax_date'     => '2026-07-15',
            'items' => [[
                'description'            => 'Syntetická služba',
                'quantity'               => 1,
                'unit_price_without_vat' => 100,
                'vat_rate_id'            => 7,
            ]],
        ];
    }
}
