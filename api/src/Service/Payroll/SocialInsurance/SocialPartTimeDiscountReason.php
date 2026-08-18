<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\SocialInsurance;

/**
 * Důvod nároku na slevu zaměstnavatele podle § 7a odst. 1 zák. č. 589/1992 Sb.
 *
 * Výčet je UZAVŘENÝ — § 7a odst. 1 vyjmenovává písmena a) až g) a jiný důvod
 * slevu nezakládá. Proto to není volný text: kdyby uživatel mohl napsat cokoli,
 * nešlo by rozhodnout ani to, jestli se má uplatnit podmínka kratší pracovní
 * doby podle § 7a odst. 2 (ta platí jen pro písmena a) až f), nikoli pro g)).
 */
enum SocialPartTimeDiscountReason: string
{
    case Age55Plus = 'age_55_plus';
    case ChildCareUnder10 = 'child_care_under_10';
    case DependentClosePersonCare = 'dependent_close_person_care';
    case StudyUnder26 = 'study_under_26';
    case RetrainingJobseeker = 'retraining_jobseeker';
    case DisabledPerson = 'disabled_person';
    case Under21 = 'under_21';

    /** Písmeno § 7a odst. 1, pod které důvod spadá. */
    public function paragraph7aLetter(): string
    {
        return match ($this) {
            self::Age55Plus => 'a',
            self::ChildCareUnder10 => 'b',
            self::DependentClosePersonCare => 'c',
            self::StudyUnder26 => 'd',
            self::RetrainingJobseeker => 'e',
            self::DisabledPerson => 'f',
            self::Under21 => 'g',
        };
    }

    /**
     * Podmínka kratší pracovní nebo služební doby podle § 7a odst. 2 a hodinový
     * limit podle § 7a odst. 3 písm. c) míří výslovně jen na zaměstnance podle
     * odstavce 1 písm. a) až f). Zaměstnanci mladšímu 21 let podle písm. g)
     * sleva náleží i při plném úvazku.
     */
    public function requiresShorterWorkingTime(): bool
    {
        return $this !== self::Under21;
    }
}
