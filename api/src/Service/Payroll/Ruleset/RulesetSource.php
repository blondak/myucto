<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Ruleset;

use InvalidArgumentException;

final readonly class RulesetSource
{
    public function __construct(
        public string $id,
        public string $title,
        public string $url,
        public string $retrievedOn,
    ) {
        if ($id === '' || $title === '') {
            throw new InvalidArgumentException('Ruleset source ID and title are required.');
        }
        if (filter_var($url, FILTER_VALIDATE_URL) === false || !str_starts_with($url, 'https://')) {
            throw new InvalidArgumentException('Ruleset source must use an absolute HTTPS URL.');
        }
        self::assertDate($retrievedOn, 'retrieval date');
    }

    /** @return array{id:string,retrieved_on:string,title:string,url:string} */
    public function toCanonicalArray(): array
    {
        return [
            'id' => $this->id,
            'retrieved_on' => $this->retrievedOn,
            'title' => $this->title,
            'url' => $this->url,
        ];
    }

    private static function assertDate(string $value, string $label): void
    {
        $parsed = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        if ($parsed === false || $parsed->format('Y-m-d') !== $value) {
            throw new InvalidArgumentException("Ruleset source {$label} must use YYYY-MM-DD.");
        }
    }
}
