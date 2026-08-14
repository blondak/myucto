<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Submission\Jmhz\Transport;

/**
 * Dva doložené druhy protokolu o zpracování: z dílčího podání (GovTalk
 * `ProcessingResult`, chodí stejným kanálem hned) a o kompletnosti (odpověď
 * DZMH, chodí i do datové schránky bez ohledu na vstupní kanál).
 */
enum JmhzProtocolKind: string
{
    case PartialSubmission = 'partial_submission';
    case Completeness = 'completeness';
}
