<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Submission\Jmhz\Transport;

/**
 * Výsledek kontrol jedné části podání. U individualizovaných formulářů je
 * `formGuid` hodnota `idFormulare` ze vstupní datové věty (atribut `sqnr`),
 * takže se odpověď dá napárovat zpět na konkrétní součást; `identifier` má
 * doložený tvar „IKMPSV;IDPPV" a je vyplněný jen tehdy, když už identifikátory
 * existují.
 */
final readonly class JmhzProtocolPart
{
    /** @param list<JmhzProtocolError> $errors */
    public function __construct(
        public JmhzProtocolPartKind $kind,
        public JmhzSubmissionStatus $status,
        public ?string $formGuid,
        public ?string $ikMpsv,
        public ?string $idPpv,
        public array $errors,
    ) {}
}
