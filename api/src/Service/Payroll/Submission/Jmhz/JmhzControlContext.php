<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Submission\Jmhz;

/**
 * Okolnosti, které kontroly potřebují a v samotném podání nejsou:
 * den vyhodnocení (kontrola 90 porovnává období s aktuálním kalendářem)
 * a variabilní symbol z GovTalk obálky VREP (kontrola 355 hlídá jejich shodu).
 *
 * Obálka se dodává až s transportní vrstvou. Dokud není, kontrola 355 se
 * nevyhodnocuje — nesmí projít jako splněná jen proto, že nemá s čím porovnat.
 */
final readonly class JmhzControlContext
{
    public function __construct(
        public string $evaluatedOn,
        public ?string $govTalkVariableSymbol = null,
    ) {
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/D', $evaluatedOn) !== 1) {
            throw new \InvalidArgumentException('Den vyhodnocení kontrol musí být ve tvaru RRRR-MM-DD.');
        }
    }

    public static function today(?string $govTalkVariableSymbol = null): self
    {
        return new self(gmdate('Y-m-d'), $govTalkVariableSymbol);
    }
}
