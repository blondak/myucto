<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll;

use MyInvoice\Repository\Payroll\PayrollHealthInsurerNoticeRepository;
use MyInvoice\Service\Codebook\HealthInsurers;
use MyInvoice\Service\Payroll\Submission\PayrollSubmissionCalendar;
use MyInvoice\Service\Pdf\PayrollHealthInsurerNoticePdfRenderer;
use Psr\Clock\ClockInterface;

/**
 * Sdělení zdravotní pojišťovny zaměstnancem a písemné potvrzení zaměstnavatele.
 *
 * § 12 písm. b) zákona č. 48/1997 Sb.: pojištěnec sdělí zaměstnavateli
 * pojišťovnu při nástupu a změnu do osmi dnů, zaměstnavatel přijetí sdělení
 * písemně potvrdí. Služba eviduje obě data u věty historie pojišťovny osoby
 * a umí potvrzení vygenerovat. Obsah hromadného oznámení (HOZ) nemění.
 */
final readonly class PayrollHealthInsurerNoticeService
{
    public function __construct(
        private PayrollHealthInsurerNoticeRepository $notices,
        private PayrollHealthInsurerNoticePdfRenderer $renderer,
        private ClockInterface $clock,
    ) {}

    /** @return list<array<string,mixed>> */
    public function list(int $supplierId, int $employeeId): array
    {
        return array_map(
            fn (array $row): array => $this->describe($row),
            $this->notices->listForEmployee($supplierId, $employeeId),
        );
    }

    /** @return array<string,mixed> */
    public function record(
        int $supplierId,
        int $employeeId,
        int $coverageId,
        ?string $employeeNotifiedOn,
        ?string $employerConfirmedOn,
        ?int $userId,
    ): array {
        $row = $this->notices->find($supplierId, $employeeId, $coverageId)
            ?? throw new \OutOfBoundsException('Věta historie zdravotní pojišťovny nebyla nalezena.');
        $notified = $this->optionalDate($employeeNotifiedOn, 'Den sdělení zaměstnance');
        $confirmed = $this->optionalDate($employerConfirmedOn, 'Den potvrzení zaměstnavatele');
        $today = $this->today();
        foreach ([
            'Den sdělení zaměstnance' => $notified,
            'Den potvrzení zaměstnavatele' => $confirmed,
        ] as $label => $value) {
            if ($value !== null && $value > $today) {
                throw new \InvalidArgumentException($label . ' nemůže být v budoucnosti.');
            }
        }
        if ($confirmed !== null && $notified === null) {
            throw new \InvalidArgumentException(
                'Potvrzení lze zapsat jen ke sdělení: nejdřív vyplňte den, kdy zaměstnanec pojišťovnu sdělil.',
            );
        }
        if ($confirmed !== null && $notified !== null && $confirmed < $notified) {
            throw new \InvalidArgumentException(
                'Zaměstnavatel nemůže přijetí sdělení potvrdit dřív, než ho přijal.',
            );
        }
        $this->notices->record($supplierId, $employeeId, (int) $row['id'], $notified, $confirmed, $userId);

        return $this->describe($this->notices->find($supplierId, $employeeId, $coverageId) ?? $row);
    }

    /**
     * Data potvrzení. Den potvrzení je zapsaný den, a když ještě není, den
     * vystavení (dnes); sám se nezapisuje, protože potvrzení se může jen
     * vytisknout a podepsat později.
     *
     * @return array<string,mixed>
     */
    public function confirmationData(int $supplierId, int $employeeId, int $coverageId): array
    {
        $row = $this->notices->find($supplierId, $employeeId, $coverageId)
            ?? throw new \OutOfBoundsException('Věta historie zdravotní pojišťovny nebyla nalezena.');
        $notified = $row['employee_notified_on'] ?? null;
        if (!is_string($notified) || $notified === '') {
            throw new \DomainException(
                'Potvrzení nejde vystavit, dokud není zapsaný den, kdy zaměstnanec pojišťovnu sdělil.',
            );
        }
        $effectiveFrom = (string) $row['effective_from'];
        $parties = $this->notices->confirmationParties($supplierId, $employeeId, $effectiveFrom)
            ?? throw new \OutOfBoundsException('Zaměstnanec nebyl nalezen.');
        $code = (string) $row['insurer_code'];

        return [
            'employer_name' => $parties['employer_name'],
            'employer_ic' => $parties['employer_ic'],
            'employee_name' => $parties['employee_name'],
            'insurer_code' => $code,
            'insurer_name' => HealthInsurers::CODES[$code] ?? null,
            'effective_from' => $effectiveFrom,
            'employee_notified_on' => $notified,
            'confirmed_on' => is_string($row['employer_confirmed_on'] ?? null) && $row['employer_confirmed_on'] !== ''
                ? (string) $row['employer_confirmed_on']
                : $this->today(),
        ];
    }

    /** @return array{bytes:string,filename:string} */
    public function confirmationPdf(int $supplierId, int $employeeId, int $coverageId): array
    {
        $data = $this->confirmationData($supplierId, $employeeId, $coverageId);

        return [
            'bytes' => $this->renderer->render($data),
            'filename' => sprintf('potvrzeni-zp-%d-%s.pdf', $coverageId, (string) $data['effective_from']),
        ];
    }

    /**
     * @param array<string,mixed> $row
     * @return array<string,mixed>
     */
    private function describe(array $row): array
    {
        $code = (string) $row['insurer_code'];
        $notified = $row['employee_notified_on'] ?? null;

        return [
            'id' => (int) $row['id'],
            'insurer_code' => $code,
            'insurer_name' => HealthInsurers::CODES[$code] ?? null,
            'insurer_status' => (string) $row['insurer_status'],
            'effective_from' => (string) $row['effective_from'],
            'effective_to' => $row['effective_to'] ?? null,
            'employee_notified_on' => $notified,
            'employer_confirmed_on' => $row['employer_confirmed_on'] ?? null,
            'confirmation_available' => is_string($notified) && $notified !== '',
        ];
    }

    private function optionalDate(?string $value, string $label): ?string
    {
        if ($value === null || trim($value) === '') {
            return null;
        }
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        if (!$date instanceof \DateTimeImmutable || $date->format('Y-m-d') !== $value) {
            throw new \InvalidArgumentException($label . ' musí být datum ve tvaru RRRR-MM-DD.');
        }

        return $value;
    }

    private function today(): string
    {
        return PayrollSubmissionCalendar::today($this->clock->now());
    }
}
