<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Document;

final class PayrollDocumentStorageScope
{
    /** @var array<string,true> */
    private array $createdKeys = [];
    private bool $closed = false;

    public function recordCreated(string $storageKey): void
    {
        if ($this->closed) {
            throw new \LogicException('Storage scope is already closed.');
        }
        $this->createdKeys[$storageKey] = true;
    }

    /** @return list<string> */
    public function createdKeys(): array
    {
        return array_keys($this->createdKeys);
    }

    public function close(): void
    {
        $this->closed = true;
        $this->createdKeys = [];
    }
}
