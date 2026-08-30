<?php

declare(strict_types=1);

namespace MyInvoice\Service\Backup\Company;

/** Ciphertext spolu s ověřeným veřejným descriptorem secret envelope. */
final readonly class CompanyBackupSealedSecretEnvelope
{
    private function __construct(
        public CompanyBackupSecretEnvelopeDescriptor $descriptor,
        public string $ciphertext,
    ) {
        $descriptor->assertCiphertext($ciphertext);
    }

    public static function create(
        string $salt,
        string $nonce,
        string $ciphertext,
    ): self {
        return new self(
            CompanyBackupSecretEnvelopeDescriptor::fromCiphertext(
                $salt,
                $nonce,
                $ciphertext,
            ),
            $ciphertext,
        );
    }

    public static function fromArray(
        mixed $descriptor,
        string $ciphertext,
    ): self {
        return new self(
            CompanyBackupSecretEnvelopeDescriptor::fromArray($descriptor),
            $ciphertext,
        );
    }
}
