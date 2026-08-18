<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Submission\Ozuspoj;

/**
 * Okno, ve kterém smí být oznámení záměru DORUČENO České správě sociálního
 * zabezpečení.
 *
 * Obě hranice jsou hranicemi DORUČENÍ, ne odeslání — § 7a odst. 5 věta první
 * říká výslovně, že „oznámením tohoto záměru se rozumí okamžik jeho doručení
 * České správě sociálního zabezpečení“. Uložené `accepted_on` je proto den
 * doručení, ne den, kdy uživatel klikl na Připravit.
 */
final readonly class OzuspojNotificationWindow
{
    public function __construct(
        public string $earliestNotificationOn,
        public string $dueOn,
        public string $calendarBasis,
        public string $rulesetId,
        public string $rulesetHash,
    ) {}
}
