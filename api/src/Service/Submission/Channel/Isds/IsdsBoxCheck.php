<?php

declare(strict_types=1);

namespace MyInvoice\Service\Submission\Channel\Isds;

/**
 * Výsledek ověření schránky příjemce dotazem do ISDS.
 *
 * Číselník v aplikaci smí zestárnout (seznam schránek Finanční správy je
 * z roku 2023), ISDS ne. Proto se schránka před každým odesláním ověřuje
 * a teprve tenhle objekt otevírá cestu ven.
 *
 * `usable === false` znamená prokazatelně nepoužitelnou schránku (zrušená,
 * znepřístupněná). Když se ověřit nepodaří, kanál musí hodit
 * {@see \MyInvoice\Service\Submission\Channel\SubmissionChannelException} —
 * nevědomost se nesmí tvářit jako „schránka je v pořádku" ani jako „schránka
 * je zrušená".
 */
final readonly class IsdsBoxCheck
{
    private function __construct(
        public bool $usable,
        public string $boxId,
        public ?string $ownerName,
        public ?string $reason,
    ) {}

    public static function usable(string $boxId, ?string $ownerName = null): self
    {
        return new self(true, $boxId, $ownerName, null);
    }

    public static function unusable(string $boxId, string $reason): self
    {
        return new self(false, $boxId, null, $reason);
    }
}
