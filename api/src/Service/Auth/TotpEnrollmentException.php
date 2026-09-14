<?php

declare(strict_types=1);

namespace MyInvoice\Service\Auth;

final class TotpEnrollmentException extends \RuntimeException
{
    public const ALREADY_ENABLED = 'already_enabled';
    public const SESSION_INVALID = 'session_invalid';
    public const STALE_AUTHORIZATION = 'stale_authorization';

    public function __construct(public readonly string $reason, ?\Throwable $previous = null)
    {
        parent::__construct($reason, 0, $previous);
    }
}
