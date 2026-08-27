<?php

declare(strict_types=1);

namespace MyInvoice\Service\Backup\Company;

/** Zda musí být sémantická reference v aktuálním schématu vynucená FK. */
enum CompanyBackupReferenceConstraint: string
{
    case Required = 'required';
    case Optional = 'optional';
}
