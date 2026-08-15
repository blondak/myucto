<?php

declare(strict_types=1);

namespace MyInvoice\Service\Submission\Channel;

/**
 * Osa VYŘÍZENÍ — co o podání rozhodl úřad.
 *
 * `Unknown` je legitimní koncový stav, ne mezistupeň: u datové schránky se
 * z něj bez protokolu od úřadu nikdy nepohneme, a je to správně. Podání
 * doručené datovkou VÍME, že dorazilo; NEVÍME, jestli ho úřad přijal.
 */
enum AcceptanceState: string
{
    case Unknown = 'unknown';
    case Accepted = 'accepted';
    case Rejected = 'rejected';
}
