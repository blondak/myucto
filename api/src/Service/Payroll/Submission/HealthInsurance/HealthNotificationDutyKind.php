<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Submission\HealthInsurance;

/**
 * Druhy oznamovací povinnosti zaměstnavatele vůči zdravotní pojišťovně.
 *
 * Záměrně to NEJSOU kódy změny z datové věty. Kód je jedno písmeno z enumu
 * `kodZmenyZamestnaceTyp` a jeho význam podklady v `private/Mzdy/podklady/`
 * nepopisují; povinnost naproti tomu plyne přímo ze zákona a doložit ji lze.
 * Doména proto rozhoduje o POVINNOSTI a teprve
 * {@see HealthNotificationCodeCatalog} řeší, jestli se na ni dá navázat kód.
 */
enum HealthNotificationDutyKind: string
{
    /** Nástup zaměstnance do zaměstnání, které zakládá účast na pojištění. */
    case EmploymentStart = 'employment_start';

    /** Skončení takového zaměstnání. */
    case EmploymentEnd = 'employment_end';

    /** Změna údajů dosud oznámených pojišťovně (jméno, adresa, číslo pojištěnce). */
    case EmployeeDataChange = 'employee_data_change';

    /** Změna zdravotní pojišťovny zaměstnance. */
    case InsurerChange = 'insurer_change';

    /** Nástup na mateřskou dovolenou. */
    case MaternityLeaveStart = 'maternity_leave_start';

    /** Nástup na rodičovskou dovolenou. */
    case ParentalLeaveStart = 'parental_leave_start';

    /** Ukončení mateřské nebo rodičovské dovolené. */
    case MaternityOrParentalLeaveEnd = 'maternity_or_parental_leave_end';

    /**
     * Skutečnost ze skupiny „stát je plátcem", kterou zaměstnavatel od
     * 1. 1. 2026 nehlásí. Zůstává v doméně proto, aby se dala pojmenovat
     * a odmítnout, ne aby se podala.
     */
    case StateCategoryOther = 'state_category_other';
}
