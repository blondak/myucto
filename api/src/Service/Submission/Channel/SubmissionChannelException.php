<?php

declare(strict_types=1);

namespace MyInvoice\Service\Submission\Channel;

/**
 * Selhání kanálu.
 *
 * Nese strojový kód a větu pro uživatele. Přístupové údaje se do ní nikdy
 * nedostanou: adaptéry je předávají výhradně přes {@see ChannelContext} a ta
 * je nikam neserializuje — viz test `CredentialLeakGuardTest`.
 */
final class SubmissionChannelException extends \RuntimeException
{
    public function __construct(
        public readonly string $errorCode,
        string $message,
        public readonly int $httpStatus = 502,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }
}
