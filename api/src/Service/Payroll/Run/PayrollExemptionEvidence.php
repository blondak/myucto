<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Run;

use MyInvoice\Service\Payroll\Component\PayrollExemptionBasis;
use MyInvoice\Service\Payroll\IncomeTax\TaxEvidenceStatus;

/**
 * Doklad k nezdanění mzdové složky, sestavený ze ZMRAZENÝCH dat vstupu.
 *
 * Jediné místo, kde se rozhoduje, jestli je osvobození doložené. Sestavovač
 * zákonných vstupů i mapper mzdových složek se ptají tady, takže obě brány
 * mluví o témže — dřív každá o něčem jiném: sestavovač uznával zmrazený rozpad
 * koše, kdežto výpočet daně trval na `treatment_evidence_*`, které nikdo
 * nevyplňoval, a osvobozený příjem tak neprošel nikdy.
 *
 * Interval účinnosti je platnost VERZE KLASIFIKACE složky. Doklad je totiž ta
 * verze sama: kdyby se klasifikace k danému měsíci už nevztahovala, není čím
 * osvobození podložit.
 */
final readonly class PayrollExemptionEvidence
{
    public function __construct(
        public PayrollExemptionBasis $basis,
        public TaxEvidenceStatus $status,
        public string $effectiveFrom,
        public ?string $effectiveTo,
        public string $reference,
    ) {
    }

    /**
     * Vrací null, když doklad chybí — složka se pak nesmí osvobodit.
     *
     * @param array<string,mixed> $input Zmrazený mzdový vstup ze snapshotu běhu.
     */
    public static function resolve(array $input): ?self
    {
        $component = $input['component'] ?? null;
        if (!is_array($component) || array_is_list($component)) {
            return null;
        }
        if (($component['tax_treatment'] ?? null) !== 'exempt') {
            return null;
        }
        $basis = self::basis($component);
        if ($basis === null) {
            return null;
        }
        // Zmrazený rozpad koše je u limitovaného osvobození JEDINÝ doklad: bez něj
        // není známé, kolik z plnění se do úhrnu za období ještě vešlo.
        if ($basis->requiresFrozenSplit() && ($input['benefit_basket'] ?? null) === null) {
            return null;
        }
        $validFrom = $component['valid_from'] ?? null;
        if (!is_string($validFrom) || $validFrom === '') {
            return null;
        }
        $validTo = $component['valid_to'] ?? null;
        $code = $component['code'] ?? null;
        if (!is_string($code) || $code === '') {
            return null;
        }
        $reference = $basis->requiresFrozenSplit()
            ? sprintf(
                'payroll-input:%s/benefit-basket:%s',
                (string) ($input['id'] ?? '?'),
                (string) $input['benefit_basket'],
            )
            : sprintf('payroll-component:%s@%s/%s', $code, $validFrom, $basis->value);

        return new self(
            $basis,
            TaxEvidenceStatus::Verified,
            $validFrom,
            is_string($validTo) && $validTo !== '' ? $validTo : null,
            $reference,
        );
    }

    /** @param array<string,mixed> $component */
    private static function basis(array $component): ?PayrollExemptionBasis
    {
        // Snímky složek zmrazené před migrací 1590 klíč nemají vůbec. Chybějící
        // klíč znamená „doklad není" — historická revize se tím nepřepočítá
        // jinak, protože osvobozená složka v ní neprošla tak jako tak.
        $value = $component['exemption_basis'] ?? null;
        if (!is_string($value) || $value === '') {
            return null;
        }

        return PayrollExemptionBasis::tryFrom($value);
    }
}
