<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Submission\HealthInsurance;

/**
 * Kdo, kdy a co hlásí zdravotní pojišťovně.
 *
 * Katalog je časově rozvrstvený, protože k 1. 1. 2026 se oznamovací povinnost
 * zaměstnavatele u kategorií, kde je plátcem stát, ZÚŽILA na nástup na
 * mateřskou a rodičovskou dovolenou. Ostatní skutečnosti (přiznání důchodu,
 * evidence uchazeče o zaměstnání, nezaopatřenost dítěte, péče o dítě) hlásí
 * nově sám pojištěnec.
 *
 * Past, kvůli které je katalog nutný: **jednotné XSD to nereflektuje.** Enum
 * `kodZmenyZamestnaceTyp` obsahuje i po 1. 1. 2026 všech 25 kódů, takže
 * schéma propustí i to, co zaměstnavatel podle zákona hlásit nemá. Validace
 * proti XSD tedy NESTAČÍ a povinnost se musí vyhodnotit tady.
 */
final class HealthNotificationDutyCatalog
{
    public const VERIFIED_ON = '2026-08-15';
    public const VERIFICATION_REFERENCE =
        'private/Mzdy/21-ZP-PODANI-RESERSE.md';

    /** Den, ke kterému se zúžila povinnost u kategorií s plátcem státem. */
    public const NARROWING_EFFECTIVE_FROM = '2026-01-01';

    private const ACT_PUBLIC_HEALTH_INSURANCE =
        'zákon č. 48/1997 Sb., o veřejném zdravotním pojištění';

    /**
     * Lhůta i sama oznamovací povinnost zaměstnavatele plynou z § 10; přesný
     * odstavec a písmeno z podkladů neplynou, proto se neuvádějí.
     */
    private const SECTION_NOTIFICATION_DUTY =
        '§ 10 zákona č. 48/1997 Sb.';

    /**
     * Zúžení k 1. 1. 2026 je doložené shodně publikacemi VZP a ČPZP, ne textem
     * novely — číslo novelizujícího zákona se v podkladech nevyskytuje. Proto
     * `external_unverified` a prázdné ustanovení: pramen se váže na ustanovení,
     * které povinnost skutečně stanoví, nebo se neuvádí vůbec.
     */
    private const NARROWING_NOTE =
        'Zúžení oznamovací povinnosti zaměstnavatele u kategorií, kde je plátcem '
        . 'stát, k 1. 1. 2026: zaměstnavatel nadále hlásí pouze nástup na '
        . 'mateřskou a rodičovskou dovolenou, ostatní skutečnosti hlásí sám '
        . 'pojištěnec. Doloženo publikacemi VZP (8. 12. 2025) a ČPZP; text '
        . 'novelizujícího zákona v podkladech není, proto se ustanovení neuvádí.';

    /** @var list<HealthNotificationDutyRule>|null */
    private static ?array $rules = null;

    /** @return list<HealthNotificationDutyRule> */
    public function rules(): array
    {
        return self::$rules ??= self::build();
    }

    /**
     * Pravidlo účinné k danému dni. Jeden druh povinnosti může mít víc období
     * s různým výsledkem — proto se vybírá podle data, ne podle druhu.
     */
    public function ruleFor(
        HealthNotificationDutyKind $kind,
        string $onDate,
    ): HealthNotificationDutyRule {
        foreach ($this->rules() as $rule) {
            if ($rule->kind === $kind && $rule->appliesOn($onDate)) {
                return $rule;
            }
        }

        throw new HealthNotificationException(
            'zp_duty_rule_unavailable',
            'Oznamovací povinnost nemá pro zvolený den účinné pravidlo.',
        );
    }

    /** Hlásí zaměstnavatel tuhle skutečnost ke dni jejího vzniku? */
    public function employerReports(
        HealthNotificationDutyKind $kind,
        string $onDate,
    ): bool {
        return $this->ruleFor($kind, $onDate)->employerReports;
    }

    /** @return list<HealthNotificationDutyRule> */
    private static function build(): array
    {
        $employment = static fn (
            HealthNotificationDutyKind $kind,
            string $label,
            string $note,
        ): HealthNotificationDutyRule => new HealthNotificationDutyRule(
            kind: $kind,
            label: $label,
            employerReports: true,
            effectiveFrom: '1997-04-01',
            effectiveTo: null,
            act: self::ACT_PUBLIC_HEALTH_INSURANCE,
            section: self::SECTION_NOTIFICATION_DUTY,
            sourceStatus: HealthNotificationDutyRule::STATUTE_VERIFIED,
            verifiedOn: self::VERIFIED_ON,
            note: $note,
        );

        return [
            $employment(
                HealthNotificationDutyKind::EmploymentStart,
                'Nástup zaměstnance do zaměstnání',
                'Přihláška zaměstnance. Povinnost zaměstnavatele se zúžením '
                . 'od 1. 1. 2026 nijak nedotčena — týká se jen kategorií, kde '
                . 'je plátcem stát.',
            ),
            $employment(
                HealthNotificationDutyKind::EmploymentEnd,
                'Skončení zaměstnání',
                'Odhláška zaměstnance.',
            ),
            $employment(
                HealthNotificationDutyKind::EmployeeDataChange,
                'Změna údajů oznámených pojišťovně',
                'Změna jména, příjmení, adresy nebo čísla pojištěnce už '
                . 'oznámeného zaměstnance.',
            ),
            $employment(
                HealthNotificationDutyKind::InsurerChange,
                'Změna zdravotní pojišťovny zaměstnance',
                'Změna se oznamuje oběma dotčeným pojišťovnám; podání je proto '
                . 'vždy dvojí a nesmí se sloučit do jednoho.',
            ),

            // Mateřská a rodičovská: jediné dvě skutečnosti ze skupiny „plátcem
            // je stát", které zaměstnavatel po 1. 1. 2026 hlásí dál.
            new HealthNotificationDutyRule(
                kind: HealthNotificationDutyKind::MaternityLeaveStart,
                label: 'Nástup na mateřskou dovolenou',
                employerReports: true,
                effectiveFrom: '1997-04-01',
                effectiveTo: null,
                act: self::ACT_PUBLIC_HEALTH_INSURANCE,
                section: self::SECTION_NOTIFICATION_DUTY,
                sourceStatus: HealthNotificationDutyRule::STATUTE_VERIFIED,
                verifiedOn: self::VERIFIED_ON,
                note: 'Povinnost přetrvává i po zúžení k 1. 1. 2026. ' . self::NARROWING_NOTE,
            ),
            new HealthNotificationDutyRule(
                kind: HealthNotificationDutyKind::ParentalLeaveStart,
                label: 'Nástup na rodičovskou dovolenou',
                employerReports: true,
                effectiveFrom: '1997-04-01',
                effectiveTo: null,
                act: self::ACT_PUBLIC_HEALTH_INSURANCE,
                section: self::SECTION_NOTIFICATION_DUTY,
                sourceStatus: HealthNotificationDutyRule::STATUTE_VERIFIED,
                verifiedOn: self::VERIFIED_ON,
                note: 'Povinnost přetrvává i po zúžení k 1. 1. 2026. ' . self::NARROWING_NOTE,
            ),
            new HealthNotificationDutyRule(
                kind: HealthNotificationDutyKind::MaternityOrParentalLeaveEnd,
                label: 'Ukončení mateřské nebo rodičovské dovolené',
                employerReports: true,
                effectiveFrom: '1997-04-01',
                effectiveTo: null,
                act: self::ACT_PUBLIC_HEALTH_INSURANCE,
                section: self::SECTION_NOTIFICATION_DUTY,
                sourceStatus: HealthNotificationDutyRule::STATUTE_VERIFIED,
                verifiedOn: self::VERIFIED_ON,
                note: 'Zúžení mluví o nástupu; ukončení se oznamuje souhrnně '
                    . 'se zahájením do 20. dne následujícího měsíce, takže '
                    . 'povinnost zůstává na zaměstnavateli.',
            ),

            // Ostatní kategorie s plátcem státem: do 31. 12. 2025 hlásil
            // zaměstnavatel, od 1. 1. 2026 pojištěnec sám. Obě období musí být
            // v katalogu, jinak by se zpětné podání za rok 2025 odmítlo.
            new HealthNotificationDutyRule(
                kind: HealthNotificationDutyKind::StateCategoryOther,
                label: 'Ostatní skutečnosti, kde je plátcem stát',
                employerReports: true,
                effectiveFrom: '1997-04-01',
                effectiveTo: '2025-12-31',
                act: self::ACT_PUBLIC_HEALTH_INSURANCE,
                section: self::SECTION_NOTIFICATION_DUTY,
                sourceStatus: HealthNotificationDutyRule::STATUTE_VERIFIED,
                verifiedOn: self::VERIFIED_ON,
                note: 'Přiznání a odejmutí důchodu, evidence uchazeče o '
                    . 'zaměstnání, nezaopatřenost dítěte, péče o dítě a další. '
                    . 'Do 31. 12. 2025 je hlásil zaměstnavatel.',
            ),
            new HealthNotificationDutyRule(
                kind: HealthNotificationDutyKind::StateCategoryOther,
                label: 'Ostatní skutečnosti, kde je plátcem stát',
                employerReports: false,
                effectiveFrom: self::NARROWING_EFFECTIVE_FROM,
                effectiveTo: null,
                act: self::ACT_PUBLIC_HEALTH_INSURANCE,
                section: null,
                sourceStatus: HealthNotificationDutyRule::EXTERNAL_UNVERIFIED,
                verifiedOn: self::VERIFIED_ON,
                note: self::NARROWING_NOTE,
            ),
        ];
    }
}
