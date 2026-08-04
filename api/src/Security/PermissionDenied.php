<?php

declare(strict_types=1);

namespace MyInvoice\Security;

final class PermissionDenied extends \RuntimeException
{
    public function __construct(
        public readonly string $permissionKey,
        public readonly AccessLevel $minimum,
    ) {
        parent::__construct('Permission denied: ' . $permissionKey);
    }
}
