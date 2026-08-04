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
 */
final readonly class PayrollPersonSensitiveReveal implements JsonSerializable
{
    /**
     * @param list<RevealedIdentifier> $identifiers
     * @param list<RevealedContact> $contacts
     * @param list<RevealedAccount> $accounts
     */
    public function __construct(
        public int $employeeId,
        public array $identifiers,
        public array $contacts,
        public array $accounts,
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
     *   accounts:list<RevealedAccount>
     * }
     */
    public function jsonSerialize(): array
    {
        return [
            'employee_id' => $this->employeeId,
            'identifiers' => $this->identifiers,
            'contacts' => $this->contacts,
            'accounts' => $this->accounts,
        ];
    }
}
