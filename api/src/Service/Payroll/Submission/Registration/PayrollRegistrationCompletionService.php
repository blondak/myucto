<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Submission\Registration;

use MyInvoice\Repository\Payroll\PayrollRegistrationCompletionRepository;
use Psr\Clock\ClockInterface;

/**
 * Hromadné dohlášení údajů zaměstnanců přihlášených dřív přes ONZ.
 *
 * Pro každý vybraný vztah se schválí neměnná událost REGZEC A3 s úplným nebo
 * dohlašovaným rozsahem profilu a hned se z ní připraví podání. Odeslání na
 * ČSSZ zůstává samostatný krok ve frontě podání — tahle služba nic neodesílá.
 * Vada u jednoho vztahu ostatní nezastaví; výsledek říká u koho a proč.
 */
final readonly class PayrollRegistrationCompletionService
{
    private const MAX_BATCH = 200;

    public function __construct(
        private PayrollRegistrationCompletionRepository $repository,
        private PayrollRegistrationSubmissionService $registrations,
        private ClockInterface $clock,
    ) {}

    /** @return array{items:list<array<string,mixed>>,today:string} */
    public function candidates(int $supplierId, string $environment): array
    {
        return [
            'items' => $this->repository->candidates($supplierId, $environment),
            'today' => $this->today(),
        ];
    }

    /**
     * @param list<mixed> $employmentIds
     * @return array{results:list<array<string,mixed>>,effective_on:string,completion:string}
     */
    public function complete(
        int $supplierId,
        string $environment,
        array $employmentIds,
        mixed $mode,
        mixed $effectiveOn,
        int $userId,
    ): array {
        $mode = PayrollRegistrationProfileCompletion::requireMode($mode);
        $today = $this->today();
        $effective = $effectiveOn === null || $effectiveOn === '' ? $today : $effectiveOn;
        if (!is_string($effective)
            || \DateTimeImmutable::createFromFormat('!Y-m-d', $effective)?->format('Y-m-d') !== $effective
        ) {
            throw new \InvalidArgumentException(
                'Den odeslání dohlášení musí být datum ve tvaru RRRR-MM-DD, '
                    . 'například ' . $today . '.',
            );
        }
        if ($effective > $today) {
            throw new \InvalidArgumentException(
                'Dohlášení nejde datovat dopředu: do údaje „platnost od" '
                    . '(10009) patří den, kdy podání skutečně odchází.',
            );
        }
        $ids = [];
        foreach ($employmentIds as $id) {
            if (!is_int($id) || $id <= 0) {
                throw new \InvalidArgumentException(
                    'Seznam pracovních vztahů k dohlášení obsahuje neplatné číslo.',
                );
            }
            $ids[$id] = $id;
        }
        if ($ids === [] || count($ids) > self::MAX_BATCH) {
            throw new \InvalidArgumentException(
                'Vyberte 1 až ' . self::MAX_BATCH . ' pracovních vztahů k dohlášení.',
            );
        }
        $results = [];
        foreach ($ids as $employmentId) {
            $results[] = $this->completeOne(
                $supplierId,
                $environment,
                $employmentId,
                $mode,
                $effective,
                $userId,
            );
        }

        return [
            'results' => $results,
            'effective_on' => $effective,
            'completion' => $mode,
        ];
    }

    /** @return array<string,mixed> */
    private function completeOne(
        int $supplierId,
        string $environment,
        int $employmentId,
        string $mode,
        string $effectiveOn,
        int $userId,
    ): array {
        try {
            $event = $this->registrations->approveEvent(
                $supplierId,
                $environment,
                $employmentId,
                [
                    'interaction' => 'change',
                    'effective_on' => $effectiveOn,
                    'source_reference' => PayrollRegistrationCompletionRepository::SOURCE_PREFIX
                        . $mode . ':' . $effectiveOn,
                    'completion' => $mode,
                ],
                $userId,
            );
            $prepared = $this->registrations->prepare(
                $supplierId,
                $environment,
                $employmentId,
                $userId,
                (int) $event['id'],
            );
        } catch (PayrollRegistrationXmlException
            | PayrollRegistrationIdentitySnapshotException $exception
        ) {
            return $this->failed($employmentId, $exception->validationCode, $exception->getMessage());
        } catch (\InvalidArgumentException
            | \DomainException
            | \OutOfBoundsException $exception
        ) {
            return $this->failed($employmentId, 'validation_failed', $exception->getMessage());
        }

        return [
            'employment_id' => $employmentId,
            'status' => 'prepared',
            'event_id' => (int) $event['id'],
            'submission_id' => (int) $prepared['submission_id'],
            'created' => (bool) ($prepared['created'] ?? true),
            'code' => null,
            'message' => null,
        ];
    }

    /** @return array<string,mixed> */
    private function failed(int $employmentId, string $code, string $message): array
    {
        return [
            'employment_id' => $employmentId,
            'status' => 'failed',
            'event_id' => null,
            'submission_id' => null,
            'created' => false,
            'code' => $code,
            'message' => $message,
        ];
    }

    private function today(): string
    {
        return $this->clock->now()
            ->setTimezone(new \DateTimeZone('Europe/Prague'))
            ->format('Y-m-d');
    }
}
