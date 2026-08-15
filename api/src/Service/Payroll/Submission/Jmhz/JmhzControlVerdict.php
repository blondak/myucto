<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Submission\Jmhz;

final readonly class JmhzControlVerdict
{
    public function __construct(
        public JmhzControlOutcome $outcome,
        public string $part,
        public ?int $formOrdinal = null,
        public string $message = '',
    ) {}

    public static function passed(string $part, ?int $formOrdinal = null): self
    {
        return new self(JmhzControlOutcome::Passed, $part, $formOrdinal);
    }

    public static function failed(string $part, ?int $formOrdinal, string $message): self
    {
        return new self(JmhzControlOutcome::Failed, $part, $formOrdinal, $message);
    }

    public static function notApplicable(string $part, string $message = ''): self
    {
        return new self(JmhzControlOutcome::NotApplicable, $part, null, $message);
    }

    public static function notEvaluable(string $part, string $message): self
    {
        return new self(JmhzControlOutcome::NotEvaluable, $part, null, $message);
    }

    /**
     * Kontrolu bychom vyhodnotit uměli, ale chybí předpoklad — připnutý
     * číselník, doložená validace proti schématu, obálka. Provozní mezera,
     * ne rozhodnutí ČSSZ.
     */
    public static function unverifiable(string $part, string $message): self
    {
        return new self(JmhzControlOutcome::Unverifiable, $part, null, $message);
    }

    public static function unimplemented(string $part, string $message): self
    {
        return new self(JmhzControlOutcome::Unimplemented, $part, null, $message);
    }
}
