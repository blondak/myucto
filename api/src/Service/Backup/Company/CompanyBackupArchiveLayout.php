<?php

declare(strict_types=1);

namespace MyInvoice\Service\Backup\Company;

/** Stabilní názvy technických položek, sdílené writerem, readerem a checksumy. */
final class CompanyBackupArchiveLayout
{
    public const MANIFEST = 'manifest.json';
    public const CHECKSUMS = 'CHECKSUMS.txt';
    public const README = 'CTI-MNE.txt';
    public const SECRET_ENVELOPE = 'secrets/tenant.sealed';

    /** @var list<string> */
    public const REQUIRED_ENTRIES = [self::MANIFEST, self::CHECKSUMS, self::README];

    private function __construct() {}
}
