<?php

declare(strict_types=1);

namespace MyInvoice\Service\Backup\Company;

use MyInvoice\Service\Backup\CanonicalJson;

/** Deduplikované externí mapovací rozhodnutí požadované zdrojovými řádky. */
final readonly class CompanyBackupExternalReferenceRequirement
{
    /** @var array<string,int|string> */
    public array $sourceKey;

    /** @var list<string> */
    public array $fallbacks;

    /**
     * @var list<array{registry_key:string,column:string,kind:string,signature:string}>
     */
    public array $sources;

    /**
     * @param array<string,int|string> $sourceKey
     * @param list<string> $fallbacks
     * @param list<array{registry_key:string,column:string,kind:string,signature:string}> $sources
     */
    private function __construct(
        public string $id,
        public CompanyBackupReferenceMapping $mapping,
        public string $targetRegistryKey,
        array $sourceKey,
        public int $occurrenceCount,
        array $fallbacks,
        array $sources,
    ) {
        $this->sourceKey = $sourceKey;
        $this->fallbacks = $fallbacks;
        $this->sources = $sources;
    }

    public static function isExternalMapping(
        CompanyBackupReferenceMapping $mapping,
    ): bool {
        return in_array($mapping, [
            CompanyBackupReferenceMapping::Actor,
            CompanyBackupReferenceMapping::GlobalNaturalKey,
            CompanyBackupReferenceMapping::CredentialDecision,
        ], true);
    }

    public static function fromOccurrence(
        CompanyBackupReferenceOccurrence $occurrence,
    ): self {
        if (!self::isExternalMapping($occurrence->mapping)) {
            throw new \InvalidArgumentException(
                'Interní reference není externí mapovací požadavek.',
            );
        }
        return new self(
            self::idFor(
                $occurrence->mapping,
                $occurrence->targetRegistryKey,
                $occurrence->sourceKey,
            ),
            $occurrence->mapping,
            $occurrence->targetRegistryKey,
            $occurrence->sourceKey,
            1,
            $occurrence->fallbacks,
            [self::source($occurrence)],
        );
    }

    public function withOccurrence(
        CompanyBackupReferenceOccurrence $occurrence,
    ): self {
        $incoming = self::fromOccurrence($occurrence);
        if (!hash_equals($this->id, $incoming->id)
            || $this->mapping !== $incoming->mapping
            || $this->targetRegistryKey !== $incoming->targetRegistryKey
            || $this->sourceKey !== $incoming->sourceKey
        ) {
            throw new \LogicException('Nelze sloučit odlišné mapovací požadavky.');
        }

        $fallbacks = array_values(array_intersect(
            $this->fallbacks,
            $incoming->fallbacks,
        ));
        sort($fallbacks, SORT_STRING);
        $sources = [];
        foreach ([...$this->sources, ...$incoming->sources] as $source) {
            $sources[CanonicalJson::encode($source)] = $source;
        }
        ksort($sources, SORT_STRING);

        return new self(
            $this->id,
            $this->mapping,
            $this->targetRegistryKey,
            $this->sourceKey,
            $this->occurrenceCount + 1,
            $fallbacks,
            array_values($sources),
        );
    }

    /** @param array<string,int|string> $sourceKey */
    public static function idFor(
        CompanyBackupReferenceMapping $mapping,
        string $targetRegistryKey,
        array $sourceKey,
    ): string {
        return 'sha256:' . CanonicalJson::sha256([
            'mapping' => $mapping->value,
            'source_key' => $sourceKey,
            'target_registry_key' => $targetRegistryKey,
        ]);
    }

    /**
     * @return array{
     *   id:string,
     *   mapping:string,
     *   target_registry_key:string,
     *   source_key:array<string,int|string>,
     *   occurrence_count:int,
     *   fallbacks:list<string>,
     *   sources:list<array{registry_key:string,column:string,kind:string,signature:string}>
     * }
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'mapping' => $this->mapping->value,
            'target_registry_key' => $this->targetRegistryKey,
            'source_key' => $this->sourceKey,
            'occurrence_count' => $this->occurrenceCount,
            'fallbacks' => $this->fallbacks,
            'sources' => $this->sources,
        ];
    }

    /**
     * @return array{registry_key:string,column:string,kind:string,signature:string}
     */
    private static function source(
        CompanyBackupReferenceOccurrence $occurrence,
    ): array {
        return [
            'registry_key' => $occurrence->sourceRegistryKey,
            'column' => $occurrence->sourceColumn,
            'kind' => $occurrence->sourceKind,
            'signature' => $occurrence->signature,
        ];
    }
}
