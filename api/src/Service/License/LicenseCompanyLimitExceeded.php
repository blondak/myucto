<?php

declare(strict_types=1);

namespace MyInvoice\Service\License;

final class LicenseCompanyLimitExceeded extends \DomainException
{
    public function __construct(
        public readonly LicenseState $state,
        public readonly int $companies,
    ) {
        parent::__construct('Byl dosažen počet firem podle licence.');
    }
}
