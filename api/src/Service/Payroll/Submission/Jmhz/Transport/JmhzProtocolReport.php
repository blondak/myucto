<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Submission\Jmhz\Transport;

final readonly class JmhzProtocolReport
{
    /**
     * @param list<JmhzProtocolPart> $parts
     * @param list<JmhzProtocolError> $errors
     * @param array<string,JmhzSubmissionStatus> $formStatuses klíčem je GUID formuláře
     */
    public function __construct(
        public JmhzProtocolKind $kind,
        public string $submissionClass,
        public JmhzSubmissionStatus $status,
        public ?string $correlationReference,
        public array $parts,
        public array $errors,
        public array $formStatuses,
    ) {}

    /** @return list<JmhzProtocolError> */
    public function errorsForForm(string $formGuid): array
    {
        $needle = strtoupper($formGuid);
        $found = [];
        foreach ($this->parts as $part) {
            if ($part->formGuid !== null && strtoupper($part->formGuid) === $needle) {
                $found = [...$found, ...$part->errors];
            }
        }

        return $found;
    }
}
