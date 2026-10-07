<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Payroll;

use MyInvoice\Repository\Payroll\PayrollDiscountIntentRepository;
use MyInvoice\Service\Payroll\Run\PayrollRunSnapshotBatchLoader;
use MyInvoice\Service\Payroll\Submission\Ozuspoj\OzuspojClaimDeadlinePolicy;
use MyInvoice\Service\Payroll\Submission\Ozuspoj\OzuspojDeadlinePolicy;
use MyInvoice\Service\Payroll\Submission\Ozuspoj\OzuspojException;
use MyInvoice\Service\Payroll\Submission\Ozuspoj\OzuspojIntentService;
use MyInvoice\Tests\Support\PayrollFullFlowTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Psr\Clock\ClockInterface;

/**
 * Poučení zaměstnance o slevě na pojistném (§ 23d odst. 2 zákona č. 589/1992 Sb.)
 * jako doklad u záměru. Všechna data jsou syntetická.
 */
#[Group('integration')]
final class OzuspojEmployeeInformedTest extends TestCase
{
    use PayrollFullFlowTrait;

    private int $officeId;

    protected function setUp(): void
    {
        $this->bootPayrollFullFlow();
        $this->officeId = $this->createOffice('OZIN', 'Syntetická účtárna poučení', '1234567890');
        $this->db->pdo()->prepare(
            'INSERT INTO payroll_employer_settings
                (supplier_id, default_office_id, social_security_office_code)
             VALUES (?, ?, "112")',
        )->execute([$this->supplierId, $this->officeId]);
    }

    protected function tearDown(): void
    {
        $this->tearDownPayrollFullFlow();
    }

    public function testInformedDateCanBeRecordedAfterwardsAndReachesTheValidationRecord(): void
    {
        $person = $this->discountEmployment();
        $service = $this->service('2026-12-01');
        $created = $service->create(
            $this->supplierId,
            'production',
            $person['employment_id'],
            '2026-11-01',
            null,
            $this->actors[0],
        );
        self::assertNull($created['employee_informed_on']);
        $this->markAccepted((int) $created['id'], '2026-10-20');

        $record = $this->loader()->discountIntentRecords(
            $this->supplierId,
            [$person['employment_id']],
            '2026-11-01',
            '2026-11-30',
        )[$person['employment_id']];
        self::assertNull($record['employee_informed_on']);
        self::assertNull($record['predecessor_source']);

        $updated = $service->recordEmployeeInformed(
            $this->supplierId,
            'production',
            (int) $created['id'],
            '2026-10-25',
        );
        self::assertSame('2026-10-25', $updated['employee_informed_on']);

        $record = $this->loader()->discountIntentRecords(
            $this->supplierId,
            [$person['employment_id']],
            '2026-11-01',
            '2026-11-30',
        )[$person['employment_id']];
        self::assertSame('2026-10-25', $record['employee_informed_on']);
    }

    /** Snímek vstupů běhu nesmí dostat sloupce navíc, jinak se změní input_hash starších revizí. */
    public function testSnapshotRowKeepsOnlyTheOriginalColumns(): void
    {
        $person = $this->discountEmployment();
        $service = $this->service('2026-12-01');
        $created = $service->create(
            $this->supplierId,
            'production',
            $person['employment_id'],
            '2026-11-01',
            '2026-10-25',
            $this->actors[0],
        );
        $this->markAccepted((int) $created['id'], '2026-10-20');

        $row = $this->loader()->discountIntents(
            $this->supplierId,
            [$person['employment_id']],
            '2026-11-01',
            '2026-11-30',
        )[$person['employment_id']];

        self::assertSame(
            ['accepted_on', 'discount_reason', 'intent_from', 'intent_to', 'status'],
            (static function (array $keys): array {
                sort($keys);

                return $keys;
            })(array_keys($row)),
        );
    }

    public function testInformedDateInTheFutureIsRefused(): void
    {
        $person = $this->discountEmployment();
        $service = $this->service('2026-12-01');
        $created = $service->create(
            $this->supplierId,
            'production',
            $person['employment_id'],
            '2026-11-01',
            null,
            $this->actors[0],
        );

        try {
            $service->recordEmployeeInformed(
                $this->supplierId,
                'production',
                (int) $created['id'],
                '2026-12-24',
            );
            self::fail('Poučení v budoucnosti se nesmí zapsat.');
        } catch (OzuspojException $exception) {
            self::assertSame('ozuspoj_informed_on_in_future', $exception->validationCode);
        }
    }

    /** @return array{employee_id:int,employment_id:int,name:string} */
    private function discountEmployment(): array
    {
        $person = $this->createEmployment(
            $this->officeId,
            'Zaměstnanec Poučený',
            1,
            'hpp',
            'employment',
            20,
            5_000,
        );
        $this->db->pdo()->prepare(
            'UPDATE payroll_employment_terms
                SET social_part_time_discount_reason = "age_55_plus"
              WHERE supplier_id = ? AND employment_id = ?',
        )->execute([$this->supplierId, $person['employment_id']]);

        return $person;
    }

    private function markAccepted(int $intentId, string $acceptedOn): void
    {
        $repository = $this->container->get(PayrollDiscountIntentRepository::class);
        self::assertInstanceOf(PayrollDiscountIntentRepository::class, $repository);
        $row = $repository->find($this->supplierId, 'production', $intentId);
        self::assertIsArray($row);
        self::assertTrue($repository->update(
            $this->supplierId,
            'production',
            $intentId,
            (int) $row['row_version'],
            ['status' => 'accepted', 'accepted_on' => $acceptedOn],
        ));
    }

    private function loader(): PayrollRunSnapshotBatchLoader
    {
        $loader = $this->container->get(PayrollRunSnapshotBatchLoader::class);
        self::assertInstanceOf(PayrollRunSnapshotBatchLoader::class, $loader);

        return $loader;
    }

    private function service(string $today): OzuspojIntentService
    {
        $repository = $this->container->get(PayrollDiscountIntentRepository::class);
        self::assertInstanceOf(PayrollDiscountIntentRepository::class, $repository);

        return new OzuspojIntentService(
            $repository,
            new OzuspojDeadlinePolicy(),
            new OzuspojClaimDeadlinePolicy(),
            new class ($today) implements ClockInterface {
                public function __construct(private readonly string $today) {}

                public function now(): \DateTimeImmutable
                {
                    return new \DateTimeImmutable($this->today . ' 12:00:00', new \DateTimeZone('Europe/Prague'));
                }
            },
        );
    }
}
