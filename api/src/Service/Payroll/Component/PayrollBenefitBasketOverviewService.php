<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Component;

use MyInvoice\Repository\Payroll\PayrollBenefitBasketOverviewRepository;
use MyInvoice\Service\Payroll\Ruleset\PayrollRulesetException;

/**
 * Přehled čerpání ročních košů osvobození za firmu.
 *
 * Náhled jednoho vstupu zavírá past jen tomu, kdo zrovna ten vstup zadává.
 * Účetní se tak o blížícím se limitu dozví typicky v prosinci, kdy už se s tím
 * nedá nic dělat. Tenhle přehled se ptá opačně: kdo z celé firmy je limitu blízko.
 *
 * **Nic se nepřepočítává.** Osvobozená a nadlimitní část se čtou zmrazené
 * z mzdového vstupu, protože pořadí čerpání koše je dané pořadím schválení;
 * dopočet z dnešního rulesetu by se rozešel s tím, co je na výplatní pásce.
 * Z rulesetu se bere jediné číslo — LIMIT koše pro daný rok — a i to fail-closed:
 * bez schválené sady se limit netvrdí a řádek zůstane `limit_unavailable`.
 * Prázdné místo je poctivější než smyšlené číslo.
 */
final class PayrollBenefitBasketOverviewService
{
    public function __construct(
        private readonly PayrollBenefitBasketOverviewRepository $repository,
        private readonly PayrollBenefitBasketService $baskets,
    ) {}

    /**
     * @return array{
     *     items: list<PayrollBenefitBasketUsage>,
     *     total: int,
     *     years: list<int>
     * }
     */
    public function overview(
        int $supplierId,
        int $taxYear,
        ?PayrollBenefitExemptionBasket $basket = null,
        string $search = '',
        int $limit = PayrollBenefitBasketOverviewRepository::LIST_DEFAULT_LIMIT,
        int $offset = 0,
    ): array {
        $page = $this->repository->page(
            $supplierId,
            $taxYear,
            $basket,
            $search,
            $limit,
            $offset,
        );

        /** @var array<string,int|null> $limits */
        $limits = [];
        $items = [];
        foreach ($page['items'] as $row) {
            $rowBasket = PayrollBenefitExemptionBasket::from((string) $row['basket']);
            // Limit se čte jednou za koš, ne za řádek: je to shodné číslo pro
            // celou stránku a ruleset je fail-closed čtení, ne konstanta.
            if (!array_key_exists($rowBasket->value, $limits)) {
                $limits[$rowBasket->value] = $this->limitOrNull($rowBasket, $taxYear);
            }
            $items[] = new PayrollBenefitBasketUsage(
                employeeId: (int) $row['employee_id'],
                employeeName: (string) $row['employee_name'],
                basket: $rowBasket,
                limitMinor: $limits[$rowBasket->value],
                usedMinor: (int) $row['used_minor'],
                exemptMinor: (int) $row['exempt_minor'],
                taxableMinor: (int) $row['taxable_minor'],
                inputCount: (int) $row['input_count'],
                unfrozenCount: (int) $row['unfrozen_count'],
                negativeCount: (int) $row['negative_count'],
                reversedCount: (int) $row['reversed_count'],
                reversedMinor: (int) $row['reversed_minor'],
            );
        }

        return [
            'items' => $items,
            'total' => $page['total'],
            'years' => $this->repository->years($supplierId),
        ];
    }

    /**
     * Limit koše, nebo `null`, když ho ruleset pro dané zdaňovací období netvrdí.
     *
     * Neschválená ani chybějící sada pravidel se nenahrazuje odhadem: bez limitu
     * nelze říct ani „zbývá", ani „blíží se". Nadlimitní část se přesto ukáže —
     * ta je zmrazená ve vstupu a na dnešním rulesetu nezávisí.
     */
    private function limitOrNull(PayrollBenefitExemptionBasket $basket, int $taxYear): ?int
    {
        // Měsíční koš (§ 6 odst. 9 písm. b) a i)) roční limit NEMÁ. Vynásobit ho
        // dvanácti nebo počtem odpracovaných směn za rok by bylo smyšlené číslo —
        // přehled proto u takového řádku limit netvrdí a řekne to stavem
        // `limit_unavailable`. Zmrazená nadlimitní část se ukáže dál.
        if ($basket->accumulatesPerMonth()) {
            return null;
        }
        try {
            return $this->baskets->limitMinor($basket, sprintf('%04d-01-01', $taxYear));
        } catch (PayrollRulesetException) {
            return null;
        }
    }
}
