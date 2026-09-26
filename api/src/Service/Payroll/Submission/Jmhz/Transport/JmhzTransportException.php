<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Submission\Jmhz\Transport;

final class JmhzTransportException extends \RuntimeException
{
    public function __construct(
        public readonly string $errorCode,
        string $message,
        public readonly ?int $remoteHttpStatus = null,
        /**
         * Požadavek MOHL dorazit k ČSSZ: spojení se navázalo a zpráva se
         * odeslala, jen se nevrátila použitelná odpověď (vypršel čas čtení,
         * spojení spadlo, brána vrátila 5xx nebo nečitelné tělo). Takový
         * neúspěch se nesmí brát jako „nic neodešlo", jinak by opakování
         * založilo u ČSSZ druhé podání.
         */
        public readonly bool $possiblyDelivered = false,
    ) {
        parent::__construct($message);
    }
}
