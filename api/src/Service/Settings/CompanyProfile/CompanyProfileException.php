<?php

declare(strict_types=1);

namespace MyInvoice\Service\Settings\CompanyProfile;

/**
 * Neplatný profil firmy nebo položka, kterou nejde do firmy zapsat. Nese sekci profilu,
 * ve které chyba vznikla, ať ji UI i CLI umí ukázat na správném místě.
 */
final class CompanyProfileException extends \RuntimeException
{
    public function __construct(
        public readonly string $errorCode,
        string $message,
        public readonly ?string $section = null,
        public readonly int $httpStatus = 422,
    ) {
        parent::__construct($message);
    }
}
