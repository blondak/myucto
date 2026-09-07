<?php

declare(strict_types=1);

namespace MyInvoice\Service\Backup\Company;

/** Povolené deterministické algoritmy pro pečetě odvozené z DB řádku. */
enum CompanyBackupDerivedHashAlgorithm: string
{
    case Sha256CanonicalJson = 'sha256_canonical_json';
    case Sha256CanonicalProjection = 'sha256_canonical_projection';
}
