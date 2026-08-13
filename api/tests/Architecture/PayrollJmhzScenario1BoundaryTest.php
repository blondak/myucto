<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Architecture;

use MyInvoice\Service\Payroll\Submission\Jmhz\JmhzScenario1Blocker;
use MyInvoice\Service\Payroll\Submission\Jmhz\JmhzScenario1DocumentResolver;
use MyInvoice\Service\Payroll\Submission\Jmhz\JmhzScenario1NormalizedDocument;
use MyInvoice\Service\Payroll\Submission\Jmhz\JmhzScenario1Resolution;
use MyInvoice\Service\Payroll\Submission\Jmhz\JmhzVerifiedPreparationSnapshot;
use PHPUnit\Framework\TestCase;

final class PayrollJmhzScenario1BoundaryTest extends TestCase
{
    public function testPureResolverHasNoPersistenceTransportOrClockDependency(): void
    {
        $constructor = (new \ReflectionClass(
            JmhzScenario1DocumentResolver::class,
        ))->getConstructor();
        self::assertNull($constructor);

        $source = file_get_contents((new \ReflectionClass(
            JmhzScenario1DocumentResolver::class,
        ))->getFileName());
        self::assertIsString($source);
        foreach ([
            'Repository\\',
            'Infrastructure\\Database',
            'PayrollSubmissionService',
            'DateTimeImmutable',
            'Uuid',
        ] as $forbidden) {
            self::assertStringNotContainsString($forbidden, $source);
        }
    }

    public function testInternalDtosCannotBeSerializedImplicitly(): void
    {
        foreach ([
            JmhzVerifiedPreparationSnapshot::class,
            JmhzScenario1Blocker::class,
            JmhzScenario1NormalizedDocument::class,
            JmhzScenario1Resolution::class,
        ] as $class) {
            self::assertFalse(
                (new \ReflectionClass($class))
                    ->implementsInterface(\JsonSerializable::class),
            );
        }
    }
}
