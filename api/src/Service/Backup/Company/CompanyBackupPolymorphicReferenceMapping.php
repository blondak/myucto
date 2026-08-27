<?php

declare(strict_types=1);

namespace MyInvoice\Service\Backup\Company;

/** Způsob zacházení s jednou diskriminovanou variantou sloupcové hodnoty. */
enum CompanyBackupPolymorphicReferenceMapping: string
{
    case TenantId = 'tenant_id';
    case Preserve = 'preserve';
}
