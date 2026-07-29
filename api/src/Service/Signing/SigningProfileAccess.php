<?php

declare(strict_types=1);

namespace MyInvoice\Service\Signing;

/**
 * RBAC pravidla pro obecné podpisové profily.
 *
 * Admin spravuje supplier/admin profily. Accountant smí spravovat jen vlastní
 * profily a jen pokud to admin v signing_settings povolil. Readonly nemutuje.
 */
final class SigningProfileAccess
{
    public function canCreate(bool $isSuperadmin, bool $canWrite, bool $accountantProfilesEnabled): bool
    {
        if ($isSuperadmin) {
            return true;
        }

        return $canWrite && $accountantProfilesEnabled;
    }

    public function canManage(
        bool $isSuperadmin,
        bool $canWrite,
        int $currentUserId,
        ?int $ownerUserId,
        bool $accountantProfilesEnabled,
    ): bool {
        if ($isSuperadmin) {
            return true;
        }

        return $canWrite
            && $accountantProfilesEnabled
            && $ownerUserId !== null
            && $ownerUserId === $currentUserId;
    }

    public function canManageSupplierDefaults(bool $isSuperadmin): bool
    {
        return $isSuperadmin;
    }
}
