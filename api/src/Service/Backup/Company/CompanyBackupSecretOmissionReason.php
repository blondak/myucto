<?php

declare(strict_types=1);

namespace MyInvoice\Service\Backup\Company;

use MyInvoice\Service\Backup\Registry\TenantSecretPolicy;

/** Stabilní důvod bezpečného výchozího vynechání secretu. */
enum CompanyBackupSecretOmissionReason: string
{
    case CredentialNotSelected = 'credential_not_selected';
    case PersonalCredentialNotSelected = 'personal_credential_not_selected';
    case ReconfigureAfterRestore = 'reconfigure_after_restore';

    public static function forPolicy(
        TenantSecretPolicy $policy,
    ): ?self {
        return match ($policy) {
            TenantSecretPolicy::OptionalCredential => self::CredentialNotSelected,
            TenantSecretPolicy::PersonalWithDualConsent =>
                self::PersonalCredentialNotSelected,
            TenantSecretPolicy::OmitAndReconfigure => self::ReconfigureAfterRestore,
            TenantSecretPolicy::ProtectedDomainSecret,
            TenantSecretPolicy::ExternalReference,
            TenantSecretPolicy::NotSecret => null,
        };
    }
}
