<?php

declare(strict_types=1);

namespace MyInvoice\Service\Backup\Company\Upcast;

final readonly class BackupUpcastPath
{
    /** @var list<BackupUpcaster> */
    private array $upcasters;

    /** @param list<BackupUpcaster> $upcasters */
    private function __construct(
        public string $sourceRevision,
        public string $targetRevision,
        array $upcasters,
    ) {
        $current = $sourceRevision;
        foreach ($upcasters as $upcaster) {
            if ($upcaster->sourceRevision() !== $current) {
                throw new \InvalidArgumentException('Řetězec upcasterů není souvislý.');
            }
            $current = $upcaster->targetRevision();
        }
        if ($current !== $targetRevision) {
            throw new \InvalidArgumentException('Řetězec upcasterů nekončí v cílové schema revision.');
        }
        $this->upcasters = $upcasters;
    }

    public static function identity(string $revision): self
    {
        return new self($revision, $revision, []);
    }

    /** @param list<BackupUpcaster> $upcasters */
    public static function fromUpcasters(string $source, string $target, array $upcasters): self
    {
        return new self($source, $target, $upcasters);
    }

    /** @return list<string> */
    public function ids(): array
    {
        return array_map(static fn (BackupUpcaster $upcaster): string => $upcaster->id(), $this->upcasters);
    }

    public function isLossless(): bool
    {
        foreach ($this->upcasters as $upcaster) {
            if (!$upcaster->isLossless()) {
                return false;
            }
        }
        return true;
    }

    /** @return list<string> */
    public function warnings(): array
    {
        $warnings = [];
        foreach ($this->upcasters as $upcaster) {
            foreach ($upcaster->warnings() as $warning) {
                if ($warning === '') {
                    throw new \LogicException('Upcaster vrátil neplatné varování.');
                }
                $warnings[] = $warning;
            }
        }
        return $warnings;
    }

    /**
     * @param array<string,mixed> $manifest
     * @return array<string,mixed>
     */
    public function upcastManifest(array $manifest): array
    {
        if (($manifest['source']['schema_revision'] ?? null) !== $this->sourceRevision) {
            throw new \InvalidArgumentException('Manifest nezačíná zdrojovou schema revision řetězce.');
        }
        foreach ($this->upcasters as $upcaster) {
            $manifest = $upcaster->upcastManifest($manifest);
            if (($manifest['source']['schema_revision'] ?? null) !== $upcaster->targetRevision()) {
                throw new \LogicException(
                    'Upcaster ' . $upcaster->id() . ' nenastavil deklarovanou cílovou schema revision.',
                );
            }
        }
        return $manifest;
    }

    /**
     * @param iterable<array<string,mixed>> $rows
     * @return iterable<array<string,mixed>>
     */
    public function upcastRows(string $logicalObject, iterable $rows): iterable
    {
        $result = $rows;
        foreach ($this->upcasters as $upcaster) {
            $result = $upcaster->upcastRows($logicalObject, $result);
        }
        return $result;
    }
}
