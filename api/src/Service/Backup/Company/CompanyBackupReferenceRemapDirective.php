<?php

declare(strict_types=1);

namespace MyInvoice\Service\Backup\Company;

/** Výslovná interní instrukce pro první průchod obnovy. */
enum CompanyBackupReferenceRemapDirective
{
    case Defer;
}
