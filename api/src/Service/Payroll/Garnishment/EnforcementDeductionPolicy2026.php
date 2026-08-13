<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Garnishment;

use MyInvoice\Service\Payroll\Ruleset\CanonicalJson;
use MyInvoice\Service\Payroll\Ruleset\CzechPayrollRulesets2026;
use MyInvoice\Service\Payroll\Ruleset\PayrollRulesetDomain;
use MyInvoice\Service\Payroll\Ruleset\PayrollRulesetException;
use MyInvoice\Service\Payroll\Ruleset\PayrollRulesetVersion;
use UnexpectedValueException;

/**
 * Definice ALGORITMU exekučních srážek — nikoli jejich hodnot.
 *
 * Do MZ-14-W11 držela `EnforcementRuleset2026` vlastní kopii nezabavitelných
 * částek, hlídanou konstantou `EXPECTED_HASH`. Nařízení vlády č. 595/2006 Sb.
 * ale mění životní minimum i normativní náklady na bydlení několikrát za rok,
 * takže každá taková změna znamenala nasazení nové verze aplikace. Hodnoty
 * proto bydlí v registry rulesetů (doména `enforcement_deductions`) a jsou
 * administrovatelné; výchozí sada z kódu zůstává integritně pinnutá přes
 * {@see \MyInvoice\Service\Payroll\Ruleset\CzechPayrollRulesets2026::ENFORCEMENT_DEDUCTIONS_HASH}.
 *
 * Tahle třída je jen tenká typovaná fasáda nad ÚČINNÝM rulesetem: ověří
 * úplnost parametrů (fail-closed) a přečte je typovaně. Identita rulesetu
 * (`id` + `hash`) se ukládá do výsledku srážky, takže historický běh zůstává
 * reprodukovatelný i po administrátorské změně aktuálních hodnot.
 */
final readonly class EnforcementDeductionPolicy2026
{
    public const ID = 'cz-enforcement-deductions-2026.domain.v1';

    /**
     * Peněžní parametry, bez kterých algoritmus nespočítá nezabavitelnou částku,
     * třetiny, plně zabavitelný zbytek ani paušální náhradu zaměstnavatele.
     *
     * @var list<string>
     */
    private const MONEY_PARAMETERS = [
        'employer_flat_fee.maximum.monthly',
        'energy_flat.monthly',
        'four_enforcement_rule.pension_exception_limit',
        'fully_attachable.threshold.monthly',
        'life_minimum.monthly',
        'normative_rent.monthly',
        'protected_amount.calculation_base.monthly',
        'protected_amount.debtor_base.monthly',
    ];

    /** @var list<string> */
    private const INTEGER_PARAMETERS = [
        'debtor_share.denominator',
        'debtor_share.numerator',
        'dependant_share.denominator',
        'dependant_share.numerator',
        'fully_attachable.factor_denominator',
        'fully_attachable.factor_numerator',
    ];

    /** @var list<string> */
    private const TEXT_PARAMETERS = [
        'employer_flat_fee.order_effective_from',
        'rounding.proportional_allocation',
        'rounding.protected_total',
        'rounding.thirds_base',
    ];

    /** @var list<string> */
    private const SOURCES = [
        'https://exekuce.justice.cz/vypocet-srazek-ze-mzdy/',
        'https://exekuce.justice.cz/srazky-ze-mzdy-a-jinych-prijmu/',
        'https://insolvence.justice.cz/jak-ven-z-dluhove-pasti/oddluzeni/',
        'https://ppropo.mpsv.cz/pdf/XXI4Srazkyzprijmuzpracovnepravni.pdf',
    ];

    private function __construct(public PayrollRulesetVersion $ruleset) {}

    public static function forRuleset(PayrollRulesetVersion $ruleset): self
    {
        if ($ruleset->domain !== PayrollRulesetDomain::EnforcementDeductions) {
            throw new PayrollRulesetException(
                "Ruleset {$ruleset->id} není z domény exekučních srážek.",
            );
        }
        self::assertComplete($ruleset);

        return new self($ruleset);
    }

    /**
     * Výchozí sada z kódu. Slouží jen k tomu, aby i výsledek zastavený na ruční
     * kontrole nesl platnou identitu rulesetu — hodnoty se z ní nepočítají.
     */
    public static function shipped(): self
    {
        foreach (CzechPayrollRulesets2026::provider()->versions() as $version) {
            if ($version->domain === PayrollRulesetDomain::EnforcementDeductions) {
                return self::forRuleset($version);
            }
        }

        throw new PayrollRulesetException('Kód neobsahuje výchozí ruleset exekučních srážek.');
    }

    /**
     * Otisk kontraktu algoritmu: co počítá a které parametry k tomu potřebuje.
     * Hodnoty v něm ZÁMĚRNĚ nejsou — ty nese identita rulesetu, která se ukládá
     * do výsledku vedle něj.
     */
    public static function contractHash(): string
    {
        return hash('sha256', CanonicalJson::encode([
            'id' => self::ID,
            'required_integer_parameters' => self::INTEGER_PARAMETERS,
            'required_money_parameters' => self::MONEY_PARAMETERS,
            'required_text_parameters' => self::TEXT_PARAMETERS,
            'sources' => self::SOURCES,
        ]));
    }

    public function rulesetId(): string
    {
        return $this->ruleset->id;
    }

    public function rulesetHash(): string
    {
        return $this->ruleset->canonicalHash;
    }

    public function effectiveFrom(): string
    {
        return $this->ruleset->effectiveFrom;
    }

    public function effectiveTo(): string
    {
        return $this->ruleset->effectiveTo;
    }

    public function money(string $key): int
    {
        $value = $this->ruleset->parameter($key);
        if ($value->type !== 'money_minor' || !is_int($value->value)) {
            throw new UnexpectedValueException(
                "Enforcement ruleset parameter {$key} is not money.",
            );
        }

        return $value->value;
    }

    public function integer(string $key): int
    {
        $value = $this->ruleset->parameter($key);
        if ($value->type !== 'integer' || !is_int($value->value)) {
            throw new UnexpectedValueException(
                "Enforcement ruleset parameter {$key} is not an integer.",
            );
        }

        return $value->value;
    }

    public function text(string $key): string
    {
        $value = $this->ruleset->parameter($key);
        if ($value->type !== 'text' || !is_string($value->value)) {
            throw new UnexpectedValueException(
                "Enforcement ruleset parameter {$key} is not text.",
            );
        }

        return $value->value;
    }

    /**
     * Kontrola ÚPLNOSTI rulesetu, ne shody s kódem. Sbírá všechny vady najednou,
     * aby administrátor nedoplňoval parametry po jednom.
     */
    private static function assertComplete(PayrollRulesetVersion $ruleset): void
    {
        $problems = [];
        foreach ([
            'money_minor' => self::MONEY_PARAMETERS,
            'integer' => self::INTEGER_PARAMETERS,
            'text' => self::TEXT_PARAMETERS,
        ] as $type => $keys) {
            foreach ($keys as $key) {
                $parameter = $ruleset->parameters[$key] ?? null;
                if ($parameter === null) {
                    $problems[] = "{$key} (chybí)";
                    continue;
                }
                try {
                    $parameter->assertCalculationReady($key);
                } catch (PayrollRulesetException) {
                    $problems[] = "{$key} (vyžaduje ruční kontrolu)";
                    continue;
                }
                if ($parameter->type !== $type) {
                    $problems[] = "{$key} (má typ {$parameter->type}, očekává se {$type})";
                }
            }
        }

        if ($problems !== []) {
            sort($problems, SORT_STRING);

            throw new PayrollRulesetException(
                "Ruleset {$ruleset->id} nemá parametry potřebné pro výpočet exekučních srážek: "
                . implode(', ', $problems) . '.',
            );
        }
    }
}
