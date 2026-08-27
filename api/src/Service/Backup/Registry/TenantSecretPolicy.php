<?php

declare(strict_types=1);

namespace MyInvoice\Service\Backup\Registry;

/** Přenosová politika jednoho citlivého sloupce nebo souboru. */
enum TenantSecretPolicy: string
{
    case ProtectedDomainSecret = 'protected_domain_secret';
    case OptionalCredential = 'optional_credential';
    case PersonalWithDualConsent = 'personal_with_dual_consent';
    case OmitAndReconfigure = 'omit_and_reconfigure';
    case ExternalReference = 'external_reference';
    case NotSecret = 'not_secret';
}
