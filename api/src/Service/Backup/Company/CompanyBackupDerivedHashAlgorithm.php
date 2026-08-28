<?php

declare(strict_types=1);

namespace MyInvoice\Service\Backup\Company;

/** Povolený deterministický algoritmus pro pečetě odvozené z JSON payloadu. */
enum CompanyBackupDerivedHashAlgorithm: string
{
    case Sha256CanonicalJson = 'sha256_canonical_json';
}
