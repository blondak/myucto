<?php

declare(strict_types=1);

namespace MyInvoice\Service\Backup\Company;

/** Umístění vybraného credential pole mimo běžný JSONL payload. */
enum CompanyBackupCredentialTransport: string
{
    case SecretAttachment = 'secret_attachment';
    case SecretEnvelope = 'secret_envelope';
    case ExternalReference = 'external_reference';
}
