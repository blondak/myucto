<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Submission\Jmhz;

final readonly class JmhzPackageSplitter
{
    public const DEFAULT_FORM_LIMIT = 1500;

    public function __construct(public int $formLimit = self::DEFAULT_FORM_LIMIT)
    {
        if ($formLimit <= 0) {
            throw new \InvalidArgumentException('Limit součástí dílčího balíku musí být kladný.');
        }
    }

    public function requiresSplit(int $formCount): bool
    {
        return $formCount > $this->formLimit;
    }

    /**
     * @template T
     * @param list<T> $forms
     * @return list<list<T>>
     */
    public function split(array $forms): array
    {
        return $this->requiresSplit(count($forms))
            ? array_chunk($forms, $this->formLimit)
            : [$forms];
    }
}
