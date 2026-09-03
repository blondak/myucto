<?php

declare(strict_types=1);

namespace MyInvoice\Service\Backup\Company;

/** Interní řídicí tok storna na bezpečné hranici mezi exportními fázemi. */
final class CompanyBackupWorkerCancelled extends \RuntimeException
{
}
