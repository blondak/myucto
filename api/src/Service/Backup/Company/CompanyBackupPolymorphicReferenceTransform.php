<?php

declare(strict_types=1);

namespace MyInvoice\Service\Backup\Company;

/** Reverzibilní kódování tenantového ID uvnitř polymorfního číselného klíče. */
enum CompanyBackupPolymorphicReferenceTransform: string
{
    case Identity = 'identity';
    case DecimalSlot = 'decimal_slot';
    case IdentityOrDecimalSlot = 'identity_or_decimal_slot';
    case IdentityOrOffset = 'identity_or_offset';
}
