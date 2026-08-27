<?php

declare(strict_types=1);

namespace MyInvoice\Service\Backup\Registry;

/** Explicitní osud objektu při záloze a obnově firmy. */
enum TenantDataPolicy: string
{
    case TenantRoot = 'tenant_root';
    case TenantOwned = 'tenant_owned';
    case TenantOwnedIndirect = 'tenant_owned_indirect';
    case TenantRelation = 'tenant_relation';
    case GlobalReference = 'global_reference';
    case InstanceOwned = 'instance_owned';
    case ProtectedDomainSecret = 'protected_domain_secret';
    case OptionalCredential = 'optional_credential';
    case PersonalSecretAttachment = 'personal_secret_attachment';
    case RuntimeDerived = 'runtime_derived';
    case Unsupported = 'unsupported';
}
