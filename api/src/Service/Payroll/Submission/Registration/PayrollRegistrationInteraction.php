<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Submission\Registration;

/**
 * Jediný seznam registračních interakcí, které tenhle core umí vyrobit.
 *
 * Opravy, storna a další akce (REGZEC A2–A8) tady schválně nejsou. Připnuté
 * REGZEC25 XSD povoluje `employee/@act` v rozsahu 1..99, takže bez allowlistu
 * by je serializér bez odporu vyrobil a XSD by je propustilo.
 */
final readonly class PayrollRegistrationInteraction
{
    /** @var array<string,array{document_type:string,action_code:int}> */
    public const SUPPORTED = [
        'limited_pre_registration' => [
            'document_type' => 'PREZEC26',
            'action_code' => 9,
        ],
        'pre_registration_no_show' => [
            'document_type' => 'PREZEC26',
            'action_code' => 10,
        ],
        'direct_full_registration' => [
            'document_type' => 'REGZEC25',
            'action_code' => 1,
        ],
        'full_registration_after_p1' => [
            'document_type' => 'REGZEC25',
            'action_code' => 1,
        ],
    ];

    public function __construct(
        public string $documentType,
        public string $interaction,
        public int $actionCode,
    ) {}

    public static function isSupported(
        string $documentType,
        string $interaction,
        int $actionCode,
    ): bool {
        $definition = self::SUPPORTED[$interaction] ?? null;

        return $definition !== null
            && $definition['document_type'] === $documentType
            && $definition['action_code'] === $actionCode;
    }

    public function supported(): bool
    {
        return self::isSupported(
            $this->documentType,
            $this->interaction,
            $this->actionCode,
        );
    }
}
