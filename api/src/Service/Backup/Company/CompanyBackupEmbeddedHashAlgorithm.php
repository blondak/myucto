<?php

declare(strict_types=1);

namespace MyInvoice\Service\Backup\Company;

/** Povolené deterministické algoritmy pro pečetě uvnitř JSON payloadu. */
enum CompanyBackupEmbeddedHashAlgorithm: string
{
    case Sha256CanonicalJson = 'sha256_canonical_json';
    case Sha256ExactString = 'sha256_exact_string';
}
