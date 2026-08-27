<?php

declare(strict_types=1);

namespace MyInvoice\Service\Backup\Company\Upcast;

final class BackupUpcasterRegistry
{
    /** @var array<string,BackupUpcaster> */
    private array $bySource = [];

    /** @var array<string,true> */
    private array $ids = [];

    /** @param array<mixed> $upcasters */
    private function __construct(array $upcasters)
    {
        foreach ($upcasters as $upcaster) {
            if (!$upcaster instanceof BackupUpcaster) {
                throw new \InvalidArgumentException('Registr obsahuje neplatný upcaster.');
            }
            $id = self::identifier($upcaster->id(), 'ID upcasteru');
            $source = self::identifier($upcaster->sourceRevision(), 'Zdrojová schema revision');
            $target = self::identifier($upcaster->targetRevision(), 'Cílová schema revision');
            if ($source === $target) {
                throw new \InvalidArgumentException('Upcaster nesmí mít shodnou zdrojovou a cílovou revision.');
            }
            if (isset($this->ids[$id])) {
                throw new \InvalidArgumentException('Duplicitní ID upcasteru ' . $id . '.');
            }
            if (isset($this->bySource[$source])) {
                throw new \InvalidArgumentException(
                    'Schema revision ' . $source . ' má více výstupních upcasterů; cesta by nebyla jednoznačná.',
                );
            }
            $this->ids[$id] = true;
            $this->bySource[$source] = $upcaster;
        }
        $this->assertAcyclic();
    }

    public static function empty(): self
    {
        return new self([]);
    }

    /** @param list<BackupUpcaster> $upcasters */
    public static function fromUpcasters(array $upcasters): self
    {
        return new self($upcasters);
    }

    public function path(string $sourceRevision, string $targetRevision): ?BackupUpcastPath
    {
        self::identifier($sourceRevision, 'Zdrojová schema revision');
        self::identifier($targetRevision, 'Cílová schema revision');
        if ($sourceRevision === $targetRevision) {
            return BackupUpcastPath::identity($sourceRevision);
        }

        $current = $sourceRevision;
        $path = [];
        while (isset($this->bySource[$current])) {
            $upcaster = $this->bySource[$current];
            $path[] = $upcaster;
            $current = $upcaster->targetRevision();
            if ($current === $targetRevision) {
                return BackupUpcastPath::fromUpcasters($sourceRevision, $targetRevision, $path);
            }
        }
        return null;
    }

    private function assertAcyclic(): void
    {
        foreach (array_keys($this->bySource) as $start) {
            $seen = [];
            $current = $start;
            while (isset($this->bySource[$current])) {
                if (isset($seen[$current])) {
                    throw new \InvalidArgumentException(
                        'Registr upcasterů obsahuje cyklus začínající schema revision ' . $current . '.',
                    );
                }
                $seen[$current] = true;
                $current = $this->bySource[$current]->targetRevision();
            }
        }
    }

    private static function identifier(string $value, string $label): string
    {
        if (preg_match('/^[a-z][a-z0-9._-]{0,127}$/D', $value) !== 1) {
            throw new \InvalidArgumentException($label . ' nemá bezpečný identifikátor.');
        }
        return $value;
    }
}
