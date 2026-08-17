<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Submission\Jmhz\Transport;

/**
 * Doložený adresát podání v datové schránce.
 *
 * `note` není dekorace: v ručním režimu ji uživatel vidí vedle ID schránky a je
 * to jediné, podle čeho pozná, že opisuje správných sedm znaků.
 */
final readonly class JmhzIsdsRecipient
{
    public function __construct(
        public string $boxId,
        public string $boxName,
        public string $environment,
        public string $note,
    ) {}
}
