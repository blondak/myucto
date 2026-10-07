<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Import\Ozuspoj;

use MyInvoice\Service\Payroll\Submission\Ozuspoj\OzuspojXmlPayload;

/**
 * Přečtená datová věta OZUSPOJ23, kterou podal předchozí mzdový program.
 *
 * Obsah je týž jako u vlastního podání ({@see OzuspojXmlPayload}); `sha256`
 * je otisk přesných bajtů souboru a slouží jako odkaz převzatého záměru na
 * zdroj.
 */
final readonly class OzuspojPredecessorFile
{
    public function __construct(
        public OzuspojXmlPayload $payload,
        public string $sha256,
    ) {}
}
