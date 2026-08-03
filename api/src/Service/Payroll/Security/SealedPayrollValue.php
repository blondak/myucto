<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Security;

final readonly class SealedPayrollValue
{
    public function __construct(
        public string $ciphertext,
        public string $lookupHash,
        public string $masked,
    ) {
        if (!str_starts_with($ciphertext, 'enc:v2:')
            || strlen($lookupHash) !== 32
            || $masked === ''
        ) {
            throw new \InvalidArgumentException('Neplatná zapečetěná mzdová hodnota.');
        }
    }

    /** @return array{ciphertext:string,lookup_hash:string,masked:string} */
    public function toStorage(): array
    {
        return [
            'ciphertext' => $this->ciphertext,
            'lookup_hash' => $this->lookupHash,
            'masked' => $this->masked,
        ];
    }
}
