<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Import\Registration;

/** Soubor, který import registrací nepřečte. Hláška jde rovnou do `files[].error`. */
final class RegistrationImportFileException extends \RuntimeException
{
}
