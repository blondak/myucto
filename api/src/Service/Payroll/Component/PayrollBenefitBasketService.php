<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Component;

use MyInvoice\Service\Payroll\Ruleset\PayrollRulesetDomain;
use MyInvoice\Service\Payroll\Ruleset\PayrollRulesetException;
use MyInvoice\Service\Payroll\Ruleset\PayrollRulesetProvider;

/**
 * Roční koše osvobození benefitů — jediná čtecí cesta k limitu a k rozpadu.
 *
 * Limit se NEKOPÍRUJE do mzdové složky ani do vstupu: složka nese jen zařazení do
 * koše a částku drží ruleset. Kdyby se kopírovala, změna průměrné mzdy by musela
 * projít číselníkem každé firmy a starý strop by tiše přežil.
 *
 * Ruleset se bere `forCalculation()`, tedy fail-closed: rozpad má daňový dopad,
 * takže ho neschválená sada nesmí tvrdit.
 */
final class PayrollBenefitBasketService
{
    public function __construct(private readonly PayrollRulesetProvider $rulesets) {}

    /**
     * Zákonný limit koše pro dané zdaňovací období.
     *
     * Datum je konec kalendářního roku: limit je „za zdaňovací období", ne za
     * měsíc, a zdaňovacím obdobím poplatníka daně z příjmů fyzických osob je
     * podle § 16b ZDP kalendářní rok. Odečítat ho k měsíci vstupu by znamenalo,
     * že se koš uprostřed roku sám přeloží.
     */
    public function limitMinor(PayrollBenefitExemptionBasket $basket, int $taxYear): int
    {
        $value = $this->rulesets
            ->forCalculation(PayrollRulesetDomain::IncomeTax, sprintf('%04d-12-31', $taxYear))
            ->parameter($basket->rulesetKey())
            ->value;
        if (!is_int($value) || $value <= 0) {
            throw new PayrollRulesetException(
                "Roční limit {$basket->rulesetKey()} není částka v haléřích.",
            );
        }

        return $value;
    }

    /**
     * Rozpad plnění proti dosud vyčerpanému úhrnu koše.
     *
     * Nerovnost je NEOSTRÁ — zákon říká „osvobozena v úhrnu DO VÝŠE …", takže
     * úhrn rovný limitu je celý osvobozený a zdaňuje se teprve to, co limit
     * převyšuje. Osvobození se navíc neztrácí zpětně: dřív poskytnutá část
     * zůstává osvobozená a zdanitelný je jen přebytek.
     *
     * Záporná částka (oprava) koš nečerpá a rozpad se u ní nedělá — vrací se celá
     * jako osvobozená nula a zdanitelná nula, protože zápornou položku odbaví
     * až storno vstupu.
     */
    public function split(
        PayrollBenefitExemptionBasket $basket,
        int $taxYear,
        int $usedBeforeMinor,
        int $amountMinor,
    ): PayrollBenefitBasketSplit {
        $limit = $this->limitMinor($basket, $taxYear);
        $amount = max(0, $amountMinor);
        $headroom = max(0, $limit - max(0, $usedBeforeMinor));
        $exempt = min($amount, $headroom);

        return new PayrollBenefitBasketSplit(
            basket: $basket,
            limitMinor: $limit,
            usedBeforeMinor: max(0, $usedBeforeMinor),
            amountMinor: $amount,
            exemptMinor: $exempt,
            taxableMinor: $amount - $exempt,
        );
    }
}
