<?php

declare(strict_types=1);

namespace MyInvoice\Service\Submission;

use MyInvoice\Service\Payroll\Ruleset\PayrollRulesetDomain;
use MyInvoice\Service\Payroll\Ruleset\PayrollRulesetProvider;

/**
 * Lhůty kolem doručování a vad podání — čtené z rulesetu, ne ze zapečených čísel.
 *
 * ═══════════════════════════════════════════════════════════════════════════
 * Proč tahle mezivrstva existuje
 * ═══════════════════════════════════════════════════════════════════════════
 * Termíny a lhůty patří do rulesetu (doména {@see PayrollRulesetDomain::Submissions}),
 * aby šly změnit bez nasazení. Sada `cz-payroll-2026.submissions.v1` ale klíče
 * pro doručování zatím nemá — je čerstvě po velkých změnách a zapisovat do ní
 * napřímo by kolidovalo. Runtime proto klíč ZKUSÍ přečíst z registru a když
 * tam není, použije zákonnou hodnotu z kódu.
 *
 * ⚠️ Tenhle fallback NENÍ „když nevíme, tak něco". Je to opačný případ než
 * fail-closed a je legitimní právě proto, že ta čísla stojí přímo v zákoně
 * a nejsou předmětem volby:
 *
 *   - `10` u fikce doručení je text § 17 odst. 4 zák. 300/2008 Sb.,
 *   - `8` u minimální lhůty je text § 32 odst. 2 daňového řádu.
 *
 * Aby ani tak nešlo přehlédnout, odkud hodnota přišla, vrací se spolu s ní
 * {@see LegalRuleLookup::$source} a ukládá se do
 * `submission_inbox_messages.fiction_days_source`. Ze záznamu je tedy vidět,
 * jestli výpočet vznikl podle rulesetu, nebo podle konstanty v kódu.
 *
 * Co se naopak NEDOPLŇUJE ani ze zákona: **délka lhůty ve výzvě podle § 74 DŘ**.
 * Tu stanoví správce daně a zákon žádnou nepředepisuje ({@see defectNoticeMinimumPeriodDays()}
 * je jen mez pro rozpoznání zjevného překlepu, ne zdroj termínu). Kdyby ji
 * aplikace domyslela, vyrobila by termín, který nikde nestojí.
 *
 * ── Klíče, které tahle třída od rulesetu čeká ───────────────────────────────
 * doména `submissions`:
 *   `isds.delivery_fiction_days`                  integer, dnes 10
 *   `isds.delivery_fiction_shift_to_working_day`  boolean, dnes true
 *   `tax.defect_notice_minimum_period_days`       integer, dnes 8
 */
final class SubmissionLegalRules
{
    /** § 17 odst. 4 zák. 300/2008 Sb. */
    public const STATUTORY_FICTION_DAYS = 10;

    /** § 32 odst. 2 daňového řádu — kratší lhůtu lze stanovit jen zcela výjimečně. */
    public const STATUTORY_MINIMUM_NOTICE_PERIOD_DAYS = 8;

    public const KEY_FICTION_DAYS = 'isds.delivery_fiction_days';
    public const KEY_FICTION_SHIFT = 'isds.delivery_fiction_shift_to_working_day';
    public const KEY_DEFECT_MINIMUM_PERIOD = 'tax.defect_notice_minimum_period_days';

    public const SOURCE_RULESET = 'ruleset';
    public const SOURCE_STATUTE = 'statute';

    public function __construct(private readonly PayrollRulesetProvider $rulesets) {}

    /** Délka lhůty fikce doručení platná k danému dni. */
    public function fictionDays(string $onDate): LegalRuleLookup
    {
        return $this->integer(self::KEY_FICTION_DAYS, $onDate, self::STATUTORY_FICTION_DAYS, 1, 366);
    }

    /**
     * Minimum, pod kterým je lhůta ve výzvě podezřele krátká. Používá se jen
     * k UPOZORNĚNÍ — § 32 odst. 2 DŘ kratší lhůtu nezakazuje, jen ji omezuje na
     * zcela výjimečné případy. Odmítnout ji by znamenalo odmítnout evidovat
     * výzvu, která reálně přišla.
     */
    public function defectNoticeMinimumPeriodDays(string $onDate): LegalRuleLookup
    {
        return $this->integer(
            self::KEY_DEFECT_MINIMUM_PERIOD,
            $onDate,
            self::STATUTORY_MINIMUM_NOTICE_PERIOD_DAYS,
            1,
            366,
        );
    }

    private function integer(string $key, string $onDate, int $fallback, int $min, int $max): LegalRuleLookup
    {
        try {
            $value = $this->rulesets
                ->forDate(PayrollRulesetDomain::Submissions, $onDate)
                ->parameter($key)
                ->value;
        } catch (\Throwable) {
            // Klíč v sadě není (nebo je vedený jako manual_review). Zákonná
            // hodnota z kódu se použije, ale zaznamená se jako `statute`.
            return new LegalRuleLookup($fallback, self::SOURCE_STATUTE, $key);
        }

        if (!is_int($value) || $value < $min || $value > $max) {
            // Nesmyslný override nesmí položit výpočet ani tiše projít.
            return new LegalRuleLookup($fallback, self::SOURCE_STATUTE, $key);
        }

        return new LegalRuleLookup($value, self::SOURCE_RULESET, $key);
    }
}
