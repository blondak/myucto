<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Submission\Jmhz;

use MyInvoice\Repository\Payroll\PayrollSubmissionRepository;
use MyInvoice\Service\Payroll\Submission\PayrollSubmissionService;

/**
 * Zmrazená datová věta podání a její identita, načtené z archivu artefaktů.
 *
 * Existuje proto, že tutéž otázku — „co jsme vlastně odeslali?" — potřebují tři
 * různá místa: odeslání bez ručně předaného XML, dotaz na výsledek na pozadí
 * (potřebuje variabilní symbol) a storno (potřebuje GUID a rozhodné období).
 * Kdyby si každé sahalo do archivu po svém, rozešly by se v tom, co považují za
 * zdroj pravdy — a rozejít se dá jen tak, že jedno z nich sáhne po jiném podání.
 *
 * `artifactBytes()` sám ověřuje délku i SHA-256 proti archivu, takže odsud
 * nevyjde nic jiného než přesně to, co se kdysi zmrazilo.
 */
final readonly class JmhzFrozenPayloadReader
{
    public function __construct(
        private PayrollSubmissionRepository $repository,
        private PayrollSubmissionService $submissions,
    ) {}

    public function bytes(int $supplierId, string $environment, int $submissionId): string
    {
        $artifactId = $this->repository->findOutboundXmlArtifactId(
            $supplierId,
            $environment,
            $submissionId,
        );
        if ($artifactId === null) {
            throw new JmhzXmlException(
                'jmhz_submission_frozen_payload_missing',
                'Podání nemá uloženou zmrazenou datovou větu, takže s ním nelze'
                    . ' dál pracovat. Zmrazte hlášení znovu z přípravy.',
            );
        }

        return $this->submissions->artifactBytes($supplierId, $artifactId);
    }

    public function identity(
        int $supplierId,
        string $environment,
        int $submissionId,
    ): JmhzFrozenSubmissionIdentity {
        return JmhzFrozenSubmissionIdentity::read(
            $this->bytes($supplierId, $environment, $submissionId),
        );
    }
}
