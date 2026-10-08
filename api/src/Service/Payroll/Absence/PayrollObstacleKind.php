<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Absence;

/**
 * Druh placené překážky v práci a sazba náhrady mzdy, která za ni přísluší.
 *
 * Náhrada se počítá z průměrného výdělku a její sazbu určuje DRUH překážky,
 * ne účetní. Proto je tu tabulka: účetní vybere, co se stalo, a sazba se
 * dohledá. Přepsat ji jde jen v mezích zákona a s důvodem
 * ({@see self::rateBounds()}).
 *
 * Strana zaměstnance (`employee_obstacle`) — zákoník práce část osmá, hlava I:
 * důležité osobní překážky podle § 199 a přílohy nařízení vlády č. 590/2006 Sb.
 * a překážky z důvodu obecného zájmu, za které zákon náhradu přiznává (§ 203
 * odst. 2, § 205). Náhrada je vždy průměrný výdělek (§ 199 odst. 1 věta druhá).
 * V katalogu jsou JEN případy s náhradou; body přílohy bez náhrady (bod 3,
 * bod 5 písm. b) svatba rodiče, bod 6 písm. b), bod 7 písm. c), bod 8 písm. a)
 * bod 2 a písm. c), bod 10 bez zájmu zaměstnavatele, bod 11 písm. b) a c))
 * patří do druhu `employee_obstacle_unpaid`.
 *
 * Strana zaměstnavatele (`employer_obstacle`) — hlava III:
 * - § 207 písm. a) prostoj: nejméně 80 % průměrného výdělku,
 * - § 207 písm. b) nepříznivé povětrnostní vlivy, živelní událost: nejméně 60 %,
 * - § 208 jiné překážky: průměrný výdělek,
 * - § 209 částečná nezaměstnanost: nejméně 60 %, výši určuje dohoda s odborovou
 *   organizací nebo vnitřní předpis; bez něj jde o jinou překážku podle § 208.
 * - částečná práce s příspěvkem (§ 120a a násl. zákona č. 435/2004 Sb.): náhrada
 *   nejméně 80 %; zaměstnanec v ní je uveden v měsíčním přehledu nákladů pro
 *   příspěvek (§ 120e odst. 5), a proto za něj za ten měsíc nenáleží sleva na
 *   pojistném (§ 7a odst. 3 písm. e) zákona č. 589/1992 Sb.).
 *
 * Výběr navazuje na měsíční hlášení: náhrada strany zaměstnance jde do 10341,
 * strany zaměstnavatele do 10340 (pokyny MPSV k vyplnění MH 1.4.13).
 */
enum PayrollObstacleKind: string
{
    case MedicalExamination = 'medical_examination';
    case CommutePreventedDisabled = 'commute_prevented_disabled';
    case OwnWedding = 'own_wedding';
    case ChildWedding = 'child_wedding';
    case ChildbirthTransport = 'childbirth_transport';
    case DeathOfCloseRelative = 'death_close_relative';
    case DeathOfRelative = 'death_relative';
    case FamilyEscort = 'family_escort';
    case DisabledChildEscort = 'disabled_child_escort';
    case CoworkerFuneral = 'coworker_funeral';
    case RelocationEmployerInterest = 'relocation_employer_interest';
    case JobSearchRedundancy = 'job_search_redundancy';
    case BloodDonation = 'blood_donation';
    case EmployeeRepresentation = 'employee_representation';
    case QualificationTraining = 'qualification_training';
    case OtherPaidEmployee = 'other_paid_employee';
    case Downtime = 'downtime';
    case WeatherInterruption = 'weather_interruption';
    case OtherEmployerObstacle = 'other_employer_obstacle';
    case PartialUnemployment = 'partial_unemployment';
    case PartialWork = 'partial_work';

    public const EMPLOYEE_SIDE_TYPE = 'employee_obstacle';
    public const EMPLOYER_SIDE_TYPE = 'employer_obstacle';

    public const FULL_RATE_BASIS_POINTS = 10_000;

    /** Druh nepřítomnosti, pod který tahle překážka patří. */
    public function absenceType(): string
    {
        return match ($this) {
            self::Downtime, self::WeatherInterruption, self::OtherEmployerObstacle,
            self::PartialUnemployment, self::PartialWork => self::EMPLOYER_SIDE_TYPE,
            default => self::EMPLOYEE_SIDE_TYPE,
        };
    }

    /** Mzdová složka, na kterou se náhrada zúčtuje (JMHZ 10341, resp. 10340). */
    public function componentCode(): string
    {
        return $this->absenceType() === self::EMPLOYER_SIDE_TYPE
            ? 'NAHRADA_MZDY_PREKAZKY_ZAMESTNAVATEL'
            : 'NAHRADA_MZDY_PREKAZKY_ZAMESTNANEC';
    }

    /** Zákonný podklad, který se ukládá do stopy výpočtu náhrady. */
    public function statutoryBasis(): string
    {
        return match ($this) {
            self::MedicalExamination => 'zp-199+nv-590-2006-bod-1',
            self::CommutePreventedDisabled => 'zp-199+nv-590-2006-bod-4',
            self::OwnWedding => 'zp-199+nv-590-2006-bod-5a',
            self::ChildWedding => 'zp-199+nv-590-2006-bod-5b',
            self::ChildbirthTransport => 'zp-199+nv-590-2006-bod-6a',
            self::DeathOfCloseRelative => 'zp-199+nv-590-2006-bod-7a',
            self::DeathOfRelative => 'zp-199+nv-590-2006-bod-7b',
            self::FamilyEscort => 'zp-199+nv-590-2006-bod-8a1',
            self::DisabledChildEscort => 'zp-199+nv-590-2006-bod-8b',
            self::CoworkerFuneral => 'zp-199+nv-590-2006-bod-9',
            self::RelocationEmployerInterest => 'zp-199+nv-590-2006-bod-10',
            self::JobSearchRedundancy => 'zp-199+nv-590-2006-bod-11a',
            self::BloodDonation => 'zp-203-2-d-e',
            self::EmployeeRepresentation => 'zp-203-2-a-c',
            self::QualificationTraining => 'zp-205+232',
            self::OtherPaidEmployee => 'zp-199-200+vnitrni-predpis',
            self::Downtime => 'zp-207-a',
            self::WeatherInterruption => 'zp-207-b',
            self::OtherEmployerObstacle => 'zp-208',
            self::PartialUnemployment => 'zp-209',
            self::PartialWork => 'zoz-120a-120e',
        };
    }

    public function defaultRateBasisPoints(): int
    {
        return match ($this) {
            self::Downtime, self::PartialWork => 8_000,
            self::WeatherInterruption, self::PartialUnemployment => 6_000,
            default => self::FULL_RATE_BASIS_POINTS,
        };
    }

    /**
     * Rozsah sazby, který zákon připouští. Zákonné minimum smí zaměstnavatel
     * zvýšit až na průměrný výdělek; náhrada nad průměr už náhradou mzdy není.
     *
     * @return array{0:int,1:int}
     */
    public function rateBounds(): array
    {
        return [$this->defaultRateBasisPoints(), self::FULL_RATE_BASIS_POINTS];
    }

    /**
     * Částečná nezaměstnanost smí mít nižší náhradu jen na základě dohody
     * s odborovou organizací nebo vnitřního předpisu (§ 209 odst. 2 ZP) a jiná
     * placená překážka nemá sazbu ze zákona. Obě proto bez uvedeného podkladu
     * uložit nejde.
     */
    public function requiresReason(): bool
    {
        return $this === self::PartialUnemployment || $this === self::OtherPaidEmployee;
    }

    /**
     * Tabulka pro formulář nepřítomnosti: formulář z ní předvyplní sazbu
     * a hlídá meze, server je stejně ověří znovu ({@see \MyInvoice\Service\Payroll\PayrollAbsenceValidator}).
     *
     * @return list<array{kind:string,absence_type:string,default_rate_basis_points:int,min_rate_basis_points:int,max_rate_basis_points:int,requires_reason:bool,statutory_basis:string}>
     */
    public static function catalog(): array
    {
        return array_map(static function (self $kind): array {
            [$minimum, $maximum] = $kind->rateBounds();

            return [
                'kind' => $kind->value,
                'absence_type' => $kind->absenceType(),
                'default_rate_basis_points' => $kind->defaultRateBasisPoints(),
                'min_rate_basis_points' => $minimum,
                'max_rate_basis_points' => $maximum,
                'requires_reason' => $kind->requiresReason(),
                'statutory_basis' => $kind->statutoryBasis(),
            ];
        }, self::cases());
    }

    /** @return list<self> */
    public static function forAbsenceType(string $absenceType): array
    {
        return array_values(array_filter(
            self::cases(),
            static fn (self $kind): bool => $kind->absenceType() === $absenceType,
        ));
    }

    /** Druhy nepřítomnosti, které druh překážky nesou. */
    public static function isObstacleType(?string $absenceType): bool
    {
        return $absenceType === self::EMPLOYEE_SIDE_TYPE || $absenceType === self::EMPLOYER_SIDE_TYPE;
    }
}
