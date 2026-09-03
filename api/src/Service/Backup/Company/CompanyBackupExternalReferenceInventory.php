<?php

declare(strict_types=1);

namespace MyInvoice\Service\Backup\Company;

use MyInvoice\Service\Backup\CanonicalJson;

/** Kanonický souhrn externích mapování nalezených v celém zdrojovém snapshotu. */
final readonly class CompanyBackupExternalReferenceInventory
{
    public const FORMAT = 'myucto-company-external-reference-inventory';
    public const VERSION = 1;

    /** @var list<CompanyBackupExternalReferenceRequirement> */
    public array $requirements;

    /** @var array<string,int> */
    public array $countsByMapping;

    public int $occurrenceCount;

    /** @var array<string,CompanyBackupExternalReferenceRequirement> */
    private array $requirementsById;

    /** @param array<mixed> $requirements */
    public function __construct(array $requirements)
    {
        if (!array_is_list($requirements)) {
            throw new \InvalidArgumentException(
                'Externí mapovací požadavky musí být předané jako seznam.',
            );
        }
        $requirementsById = [];
        $occurrenceCount = 0;
        $countsByMapping = [];
        foreach ($requirements as $requirement) {
            if (!$requirement instanceof CompanyBackupExternalReferenceRequirement) {
                throw new \InvalidArgumentException(
                    'Inventář obsahuje neplatný externí mapovací požadavek.',
                );
            }
            if (isset($requirementsById[$requirement->id])) {
                throw new \InvalidArgumentException(
                    'Inventář obsahuje duplicitní externí mapovací požadavek.',
                );
            }
            $requirementsById[$requirement->id] = $requirement;
            $occurrenceCount += $requirement->occurrenceCount;
            $mapping = $requirement->mapping->value;
            $countsByMapping[$mapping] = ($countsByMapping[$mapping] ?? 0) + 1;
        }
        ksort($requirementsById, SORT_STRING);
        ksort($countsByMapping, SORT_STRING);
        $this->requirements = array_values($requirementsById);
        $this->requirementsById = $requirementsById;
        $this->occurrenceCount = $occurrenceCount;
        $this->countsByMapping = $countsByMapping;
    }

    /** @param array<string,int|string> $sourceKey */
    public function find(
        CompanyBackupReferenceMapping $mapping,
        string $targetRegistryKey,
        array $sourceKey,
    ): ?CompanyBackupExternalReferenceRequirement {
        $id = CompanyBackupExternalReferenceRequirement::idFor(
            $mapping,
            $targetRegistryKey,
            $sourceKey,
        );
        return $this->requirementsById[$id] ?? null;
    }

    /**
     * @return array{
     *   format:string,
     *   version:int,
     *   occurrence_count:int,
     *   counts_by_mapping:array<string,int>,
     *   requirements:list<array<string,mixed>>
     * }
     */
    public function toArray(): array
    {
        return [
            'format' => self::FORMAT,
            'version' => self::VERSION,
            'occurrence_count' => $this->occurrenceCount,
            'counts_by_mapping' => $this->countsByMapping,
            'requirements' => array_map(
                static fn (CompanyBackupExternalReferenceRequirement $requirement): array =>
                    $requirement->toArray(),
                $this->requirements,
            ),
        ];
    }

    public function sha256(): string
    {
        return CanonicalJson::sha256($this->toArray());
    }
}
