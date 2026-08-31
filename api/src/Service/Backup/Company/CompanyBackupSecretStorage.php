<?php

declare(strict_types=1);

namespace MyInvoice\Service\Backup\Company;

use MyInvoice\Service\Auth\SecretEncryption;

/** Zdrojový a cílový at-rest formát přenositelné secret hodnoty. */
enum CompanyBackupSecretStorage: string
{
    case Raw = 'raw';
    case ApplicationEncrypted = 'application_encrypted';
    case ApplicationEncryptedContext = 'application_encrypted_context';

    public function decode(
        #[\SensitiveParameter] string $stored,
        ?string $context,
        SecretEncryption $encryption,
    ): string {
        return match ($this) {
            self::Raw => $stored,
            self::ApplicationEncrypted => $encryption->decrypt($stored),
            self::ApplicationEncryptedContext => $encryption->decryptFor(
                $stored,
                $context ?? throw new \LogicException(
                    'Kontextové šifrování nemá ověřený kontext.',
                ),
            ),
        };
    }
}
