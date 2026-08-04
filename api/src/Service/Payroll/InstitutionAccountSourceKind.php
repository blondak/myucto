<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll;

enum InstitutionAccountSourceKind: string
{
    case OFFICIAL_REGISTRY = 'official_registry';
    case OFFICIAL_DOCUMENT = 'official_document';
    case INSTITUTION_NOTICE = 'institution_notice';
    case USER_VERIFIED = 'user_verified';
    case IMPORTED = 'imported';
}
