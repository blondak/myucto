<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Submission\Sickness;

/**
 * Stav JEDNOHO podání případu dávky (NEMPRI nebo HZUPN).
 *
 * NEMPRI (§ 97 odst. 1 a 2 zák. č. 187/2006 Sb.) a HZUPN (§ 97 odst. 3) jsou
 * dvě samostatná podání se samostatnými lhůtami. Kdyby případ nesl jediný stav,
 * zapsané přijetí NEMPRI by zamklo i HZUPN a jeho lhůta by se přestala hlídat.
 * Společný stav případu ({@see SicknessCaseStatus}) se z obou jen odvozuje.
 *
 * Připravené, ale nedoručené podání je pořád `pending`: připravené XML není
 * předané podání. Pozná se podle vazby na podání (`*_submission_id`).
 */
enum SicknessDocumentStatus: string
{
    /** Ještě nedoručeno územní správě. */
    case Pending = 'pending';
    /** Doručeno; den doručení z protokolu ČSSZ je povinný. */
    case Accepted = 'accepted';
    /** Odmítnuto; čeká na nové podání. */
    case Rejected = 'rejected';
    /** Podal předchozí mzdový program, MyÚčto ho nepodává. */
    case Predecessor = 'predecessor';

    /** Je podání vyřízené, takže se jeho lhůta už nehlídá? */
    public function isSettled(): bool
    {
        return $this === self::Accepted || $this === self::Predecessor;
    }

    /** Čeká podání na krok účetní (podat, nebo podat znovu)? */
    public function needsAction(): bool
    {
        return !$this->isSettled();
    }
}
