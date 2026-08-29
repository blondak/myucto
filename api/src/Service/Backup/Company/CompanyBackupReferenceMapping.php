<?php

declare(strict_types=1);

namespace MyInvoice\Service\Backup\Company;

/** Způsob překladu zdrojové reference při obnově firmy pod novými ID. */
enum CompanyBackupReferenceMapping: string
{
    case TenantId = 'tenant_id';
    case TenantIdOrZero = 'tenant_id_or_zero';
    case TenantReferenceKey = 'tenant_reference_key';
    case TenantNaturalKey = 'tenant_natural_key';
    case GlobalNaturalKey = 'global_natural_key';
    case Actor = 'actor';
    case CredentialDecision = 'credential_decision';
}
