<?php

declare(strict_types=1);

namespace MyInvoice\Service\Submission\Channel;

/**
 * Co se odesílá — kanálu předaný celek, nezávislý na tom, odkud artefakt
 * pochází (mzdové podání, daňové přiznání, přehled pojišťovně).
 *
 * `correlationReference` je RAZÍTKO, ne poznámka: kanál ho MUSÍ vložit do
 * odchozí zprávy (u ISDS jako `dmSenderRefNumber`) ještě než ji odešle. Je to
 * jediný způsob, jak po přerušeném volání zjistit dohledáním v odeslaných
 * zprávách, jestli zpráva odešla, nebo ne.
 */
final readonly class OutboundSubmission
{
    public function __construct(
        public int $outboxId,
        public int $supplierId,
        public string $environment,
        public string $agendaCode,
        public string $subject,
        /** ID datové schránky příjemce; u EPO null (příjemce je dán bránou). */
        public ?string $recipientBoxId,
        public string $artifactFilename,
        public string $artifactMimeType,
        public string $artifactBytes,
        public string $artifactSha256,
        public string $correlationReference,
    ) {}
}
