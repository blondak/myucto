<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Termination;

use MyInvoice\Service\Payroll\Document\AverageEarningsCertificateDocumentData;

/**
 * Způsob a zákonný důvod skončení pracovního vztahu — jediný zdroj, ze
 * kterého se ODVOZUJE všechno, co o skončení chtějí jednotlivá podání:
 *
 *  - kód důvodu ukončení PPV pro podklady Úřadu práce v odhlášce REGZEC A2
 *    (číselník „CIS Důvod ukončení PPV", atribut 10380),
 *  - druh skončení v potvrzení zaměstnavatele pro Úřad práce (§ 313 odst. 2
 *    ZP; {@see AverageEarningsCertificateDocumentData::TERMINATION_REASONS}),
 *  - nárok na odstupné (§ 67 ZP) a jednorázovou náhradu (§ 271ca ZP),
 *  - příznak skončení úmrtím v A2.
 *
 * Dohoda „z týchž důvodů" má pro odstupné stejné následky jako výpověď
 * (§ 67 odst. 1 a 3 ZP, § 271ca odst. 1 věta druhá). Proto se u dohody důvod
 * zadává zvlášť a do A2 jde jako důvod (4 nebo 5), ne jako prostá dohoda (2):
 * DIS od 13. 8. 2026 přijme údaj o odstupném (10378 a násl.) jen u důvodu
 * 4 nebo 5, takže dohoda z organizačních důvodů by jinak odstupné ohlásit
 * nemohla.
 *
 * Zákonné důvody podle znění ZP po novele zák. č. 120/2025 Sb.:
 * § 52 písm. d) dlouhodobá zdravotní nezpůsobilost, písm. e) nejvyšší
 * přípustná expozice, písm. h) zvlášť hrubé porušení povinnosti podle § 301a
 * (režim dočasně práce neschopného).
 */
final readonly class PayrollTerminationReason
{
    public const METHODS = [
        'employer_notice',
        'agreement',
        'employee_notice',
        'employer_immediate',
        'employee_immediate',
        'probation_employer',
        'probation_employee',
        'fixed_term_expiry',
        'death',
        'foreigner_permit',
        'other',
    ];

    public const GROUNDS = [
        'none',
        'organizational',
        'health_long_term',
        'health_work_injury',
        'max_exposure',
        'requirements_unmet',
        'breach_gross',
        'breach_serious',
        'breach_minor_repeated',
        'sickness_regime',
        'criminal_conviction',
        'health_no_transfer',
        'wage_not_paid',
    ];

    /** @var array<string,list<string>> způsob → přípustné důvody */
    public const ALLOWED_GROUNDS = [
        'employer_notice' => [
            'organizational',
            'health_long_term',
            'health_work_injury',
            'max_exposure',
            'requirements_unmet',
            'breach_gross',
            'breach_serious',
            'breach_minor_repeated',
            'sickness_regime',
        ],
        'agreement' => [
            'none',
            'organizational',
            'health_long_term',
            'health_work_injury',
            'max_exposure',
        ],
        'employee_notice' => ['none'],
        'employer_immediate' => ['breach_gross', 'criminal_conviction'],
        'employee_immediate' => ['health_no_transfer', 'wage_not_paid'],
        'probation_employer' => ['none'],
        'probation_employee' => ['none'],
        'fixed_term_expiry' => ['none'],
        'death' => ['none'],
        'foreigner_permit' => ['none'],
        'other' => ['none'],
    ];

    public function __construct(
        public string $method,
        public string $ground,
    ) {
        if (!in_array($method, self::METHODS, true)) {
            throw new \InvalidArgumentException('Způsob skončení není podporovaný.');
        }
        if (!in_array($ground, self::ALLOWED_GROUNDS[$method], true)) {
            throw new \InvalidArgumentException(
                'Zvolený důvod neodpovídá způsobu skončení. U výpovědi dané'
                . ' zaměstnavatelem vyberte výpovědní důvod podle § 52 ZP,'
                . ' u okamžitého zrušení důvod podle § 55 nebo § 56 ZP;'
                . ' ostatní způsoby důvod nemají.',
            );
        }
    }

    /**
     * Kód „CIS Důvod ukončení PPV" (atribut 10380) pro podklady Úřadu práce
     * v odhlášce REGZEC A2.
     */
    public function regzecReasonCode(): string
    {
        return match ($this->method) {
            'foreigner_permit' => '1',
            'employee_notice' => '3',
            'fixed_term_expiry' => '12',
            'probation_employer' => '13',
            'probation_employee' => '14',
            'death', 'other' => '15',
            'agreement' => match ($this->ground) {
                'organizational' => '4',
                'health_long_term', 'health_work_injury', 'max_exposure' => '5',
                default => '2',
            },
            'employer_notice' => match ($this->ground) {
                'organizational' => '4',
                'health_long_term', 'health_work_injury', 'max_exposure' => '5',
                'requirements_unmet' => '6',
                'breach_gross', 'breach_serious' => '7',
                'breach_minor_repeated' => '8',
                'sickness_regime' => '9',
            },
            'employer_immediate' => $this->ground === 'criminal_conviction' ? '10' : '7',
            'employee_immediate' => $this->ground === 'health_no_transfer' ? '5' : '11',
        };
    }

    /**
     * Druh skončení v potvrzení zaměstnavatele pro Úřad práce (§ 313 odst. 2
     * ZP). Úřad z něj posuzuje § 39 odst. 2 písm. c) a § 50 odst. 3 zákona
     * o zaměstnanosti: rozhoduje zvlášť hrubé porušení a vážný důvod, proto
     * „závažné" (ne zvlášť hrubé) porušení i soustavné méně závažné spadají
     * do „žádný ze zvláštních důvodů".
     */
    public function unemploymentOfficeKind(): string
    {
        return match (true) {
            $this->ground === 'organizational' => 'organizational',
            in_array($this->ground, ['health_long_term', 'health_work_injury', 'max_exposure', 'health_no_transfer'], true) => 'health',
            $this->ground === 'breach_gross' => 'gross_breach',
            $this->ground === 'sickness_regime' => 'sickness_regime_breach',
            $this->ground === 'wage_not_paid' => 'employer_breach',
            $this->method === 'employee_notice' => 'employee_unilateral',
            $this->method === 'agreement' => 'agreement',
            default => 'none',
        };
    }

    public function endedByDeath(): bool
    {
        return $this->method === 'death';
    }

    /**
     * Základ odstupného podle § 67 ZP: `organizational` (odst. 1, násobky
     * podle trvání) nebo `max_exposure` (odst. 3, dvanáctinásobek). Jiný
     * způsob či důvod odstupné ze zákona nezakládá.
     */
    public function severanceBasis(): ?string
    {
        if (!in_array($this->method, ['employer_notice', 'agreement'], true)) {
            return null;
        }

        return match ($this->ground) {
            'organizational' => 'organizational',
            'max_exposure' => 'max_exposure',
            default => null,
        };
    }

    /**
     * Jednorázová náhrada dvanáctinásobku průměrného měsíčního výdělku
     * (§ 271ca ZP) — výpověď nebo dohoda pro dlouhodobou nezpůsobilost
     * z pracovního úrazu či nemoci z povolání. Po novele zák. č. 120/2025 Sb.
     * už to není odstupné; v A2 se hlásí jako `replacement` (atribut 10530).
     */
    public function workInjuryCompensation(): bool
    {
        return in_array($this->method, ['employer_notice', 'agreement'], true)
            && $this->ground === 'health_work_injury';
    }

    /**
     * Důvod uvedený zaměstnancem se tiskne jen u skončení z jeho vůle
     * (výpověď zaměstnance, dohoda bez zákonného důvodu).
     */
    public function statedReasonAllowed(): bool
    {
        return in_array($this->unemploymentOfficeKind(), ['employee_unilateral', 'agreement'], true);
    }

    /** Údaje o odstupném (10378 a násl.) přijme DIS jen u důvodu 4 nebo 5. */
    public function settlementReportable(): bool
    {
        return in_array($this->regzecReasonCode(), ['4', '5'], true);
    }

    /** Vztahy, u kterých zákoník práce odstupné a náhradu § 271ca zná. */
    public const SEVERANCE_RELATIONS = ['employment', 'small_scale_employment'];

    /**
     * Co odhláška A2 hlásí o odstupném — jediné místo pro kartu skončení
     * (předvyplnění) i schválení A2 (kontrola proti záznamu):
     *
     *  - `golden_handshake` = odstupné podle § 67 odst. 1 ZP (10531),
     *  - `replacement` = jednorázová náhrada podle § 271ca ZP (10530),
     *  - `null` = nic nenáleží, 10378 („náleží") je N nebo se neuvádí.
     *
     * Údaj přijme DIS jen u důvodu ukončení 4 nebo 5 (aktualita MPSV
     * 14. 8. 2026, kontrola 10378 × 10380); dohoda o provedení práce ani
     * pracovní činnosti odstupné nezakládá.
     */
    public function a2SettlementKind(string $relationType): ?string
    {
        if (!$this->settlementReportable()
            || !in_array($relationType, self::SEVERANCE_RELATIONS, true)
        ) {
            return null;
        }
        if ($this->workInjuryCompensation()) {
            return 'replacement';
        }

        return $this->severanceBasis() !== null ? 'golden_handshake' : null;
    }
}
