<?php

declare(strict_types=1);

namespace MyInvoice\Service\Backup\Company;

/** Fyzická reprezentace interní reference, která ovlivňuje pořadí importu. */
enum CompanyBackupImportDependencyKind: string
{
    case Column = 'column';
    case Encoded = 'encoded';
    case Embedded = 'embedded';
    case EmbeddedHash = 'embedded_hash';
    case Polymorphic = 'polymorphic';
}
