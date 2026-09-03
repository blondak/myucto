<?php

declare(strict_types=1);

namespace MyInvoice\Service\Backup\Company;

/** Paměťově omezená agregace externích rozhodnutí během streamovaného preflightu. */
final class CompanyBackupExternalReferenceCollector
{
    /** @var array<string,CompanyBackupExternalReferenceRequirement> */
    private array $requirements = [];

    private ?CompanyBackupExternalReferenceInventory $inventory = null;

    public function __construct(
        private readonly CompanyBackupArchiveLimits $limits =
            new CompanyBackupArchiveLimits(),
    ) {}

    public function accept(CompanyBackupReferenceOccurrence $occurrence): void
    {
        if ($this->inventory !== null) {
            throw new \LogicException('Dokončený mapovací inventář nelze měnit.');
        }
        if (!CompanyBackupExternalReferenceRequirement::isExternalMapping(
            $occurrence->mapping,
        )) {
            return;
        }

        $requirement = CompanyBackupExternalReferenceRequirement::fromOccurrence(
            $occurrence,
        );
        $existing = $this->requirements[$requirement->id] ?? null;
        if ($existing !== null) {
            $this->requirements[$requirement->id] = $existing->withOccurrence(
                $occurrence,
            );
            return;
        }
        if (count($this->requirements) >= $this->limits->maxReferenceRequirements) {
            throw new CompanyBackupPreflightException(
                'reference_requirement_limit_exceeded',
            );
        }
        $this->requirements[$requirement->id] = $requirement;
    }

    public function finish(): CompanyBackupExternalReferenceInventory
    {
        return $this->inventory ??= new CompanyBackupExternalReferenceInventory(
            array_values($this->requirements),
        );
    }
}
