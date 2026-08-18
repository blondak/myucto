<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Submission\Ozuspoj;

/**
 * Stav záměru uplatňovat slevu na pojistném v evidenci aplikace.
 *
 * Stav NENÍ totéž co stav podání. Podání může být `ready` a záměr přesto
 * neplatit — § 7a odst. 5 váže nárok na OKAMŽIK DORUČENÍ oznámení České správě
 * sociálního zabezpečení, ne na to, že jsme XML vyrobili. Proto se sleva smí
 * uplatnit jen podle {@see self::Accepted} nebo {@see self::Ended}; všechny
 * ostatní stavy končí neuplatněním.
 *
 * `Rejected` existuje kvůli souběhu podle § 7a odst. 5 věty třetí: má-li záměr
 * za téhož zaměstnance více zaměstnavatelů, sleva náleží tomu, kdo oznámil
 * PRVNÍ. Kdo to byl, ví jedině ČSSZ (§ 23f odst. 1 a 2) — aplikace to nesmí
 * odhadovat, jen zaznamenat, když ji ČSSZ odmítne.
 */
enum OzuspojIntentStatus: string
{
    case Draft = 'draft';
    case Submitted = 'submitted';
    case Accepted = 'accepted';
    case Rejected = 'rejected';
    case Ended = 'ended';
    case Cancelled = 'cancelled';

    /** Zakládá tenhle stav doložený záměr ve smyslu § 7a odst. 5? */
    public function isEvidenced(): bool
    {
        return $this === self::Accepted || $this === self::Ended;
    }
}
