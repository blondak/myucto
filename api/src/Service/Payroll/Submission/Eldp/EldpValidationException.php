<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Submission\Eldp;

/**
 * Chyba sestavení nebo validace evidenčního listu důchodového pojištění.
 *
 * `blockers` nese strojově čitelný seznam toho, co konkrétně chybí nebo co
 * modul neumí doložit. ELDP je podklad pro výpočet důchodu o desítky let
 * později, takže se nikdy nedopočítává odhadem: co není doložené, blokuje.
 */
final class EldpValidationException extends \DomainException
{
    /**
     * @param list<array{code:string,message:string,detail?:array<string,mixed>}> $blockers
     */
    public function __construct(
        public readonly string $validationCode,
        string $message,
        public readonly array $blockers = [],
    ) {
        parent::__construct($message);
    }

    /**
     * @param list<array{code:string,message:string,detail?:array<string,mixed>}> $blockers
     */
    public static function blocked(array $blockers): self
    {
        if ($blockers === []) {
            throw new \InvalidArgumentException(
                'Blokující seznam ELDP nesmí být prázdný.',
            );
        }
        $messages = array_map(
            static fn (array $blocker): string => $blocker['message'],
            $blockers,
        );

        return new self(
            'eldp_source_incomplete',
            'Evidenční list nelze sestavit: ' . implode(' ', $messages),
            $blockers,
        );
    }
}
