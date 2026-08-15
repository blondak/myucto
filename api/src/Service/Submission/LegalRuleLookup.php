<?php

declare(strict_types=1);

namespace MyInvoice\Service\Submission;

/**
 * Hodnota lhůty i s tím, odkud přišla.
 *
 * Zdroj se táhne až do databáze, protože samotné číslo o pár let později nikomu
 * neřekne, jestli výpočet vznikl podle spravovaného rulesetu, nebo podle
 * zákonné konstanty v kódu.
 */
final readonly class LegalRuleLookup
{
    public function __construct(
        public int $value,
        /** {@see SubmissionLegalRules::SOURCE_RULESET} nebo `SOURCE_STATUTE`. */
        public string $source,
        public string $key,
    ) {}

    public function isFromRuleset(): bool
    {
        return $this->source === SubmissionLegalRules::SOURCE_RULESET;
    }
}
