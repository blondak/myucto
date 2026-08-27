<?php

declare(strict_types=1);

namespace MyInvoice\Service\Backup\Company\Upcast;

interface BackupUpcaster
{
    public function id(): string;

    public function sourceRevision(): string;

    public function targetRevision(): string;

    public function isLossless(): bool;

    /** @return list<string> */
    public function warnings(): array;

    /**
     * @param array<string,mixed> $manifest
     * @return array<string,mixed>
     */
    public function upcastManifest(array $manifest): array;

    /**
     * @param iterable<array<string,mixed>> $rows
     * @return iterable<array<string,mixed>>
     */
    public function upcastRows(string $logicalObject, iterable $rows): iterable;
}
