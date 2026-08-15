<?php

declare(strict_types=1);

namespace MyInvoice\Service\Submission\Channel\Isds;

/**
 * Spojení se přerušilo uprostřed odesílání a výsledek NENÍ znám.
 *
 * Vlastní typ (a ne `SubmissionChannelException`) proto, že tohle není chyba,
 * ale nevědomost, a zachází se s ní opačně: chyba znamená „nic neodešlo, můžeš
 * opakovat", tohle znamená „nesmíš opakovat, dokud nedohledáš".
 */
final class IsdsTransportTimeout extends \RuntimeException
{
    public function __construct(
        public readonly string $errorCode,
        string $message,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }
}
