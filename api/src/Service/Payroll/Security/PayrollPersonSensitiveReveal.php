<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Security;

use JsonSerializable;

/**
 * @phpstan-type RevealedIdentifier array{
 *   id:int,
 *   identifier_type:string,
 *   value:string
 * }
 * @phpstan-type RevealedContact array{
 *   id:int,
 *   contact_type:string,
 *   value:string
 * }
 * @phpstan-type RevealedAccount array{
 *   id:int,
 *   label:string,
 *   bank_account:string
 * }
 * @phpstan-type RevealedDependant array{
 *   id:int,
 *   full_name:string,
 *   birth_number:string
 * }
 * @phpstan-type RevealedAddress array{
 *   id:int,
 *   address_type:string,
 *   address:string,
 *   effective_from:string,
 *   effective_to:?string
 * }
 */
final readonly class PayrollPersonSensitiveReveal implements JsonSerializable
{
    /**
     * @param list<RevealedIdentifier> $identifiers
     * @param list<RevealedContact> $contacts
     * @param list<RevealedAccount> $accounts
     * @param list<RevealedDependant> $dependants
     * @param list<RevealedAddress> $addresses
     */
    public function __construct(
        public int $employeeId,
        public array $identifiers,
        public array $contacts,
        public array $accounts,
        public array $dependants = [],
        public array $addresses = [],
    ) {}

    public function cacheControl(): string
    {
        return 'private, no-store';
    }

    /** @return array{Cache-Control:string,Pragma:string} */
    public function responseHeaders(): array
    {
        return [
            'Cache-Control' => $this->cacheControl(),
            'Pragma' => 'no-cache',
        ];
    }

    /**
     * @return array{
     *   employee_id:int,
     *   identifiers:list<RevealedIdentifier>,
     *   contacts:list<RevealedContact>,
     *   accounts:list<RevealedAccount>,
     *   dependants:list<RevealedDependant>,
     *   addresses:list<RevealedAddress>
     * }
     */
    public function jsonSerialize(): array
    {
        return [
            'employee_id' => $this->employeeId,
            'identifiers' => $this->identifiers,
            'contacts' => $this->contacts,
            'accounts' => $this->accounts,
            'dependants' => $this->dependants,
            'addresses' => $this->addresses,
        ];
    }
}
