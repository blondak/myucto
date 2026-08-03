<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Payroll\Security;

use MyInvoice\Infrastructure\Config\Config;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Infrastructure\Database\DbErrorLogger;
use MyInvoice\Service\ActivityLogger;
use PDOException;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

final class PayrollLogRedactionTest extends TestCase
{
    public function testActivityPayloadRedactsNestedPayrollValues(): void
    {
        $logger = new ActivityLogger(new Connection(new Config([])));
        $redacted = $logger->redact([
            'employee_id' => 42,
            'profile' => [
                'birth_number' => 'SYNTHETIC-IDENTIFIER',
                'bank_account' => 'SYNTHETIC-ACCOUNT',
                'diagnosis' => 'SYNTHETIC-DIAGNOSIS',
            ],
            'monthly_gross' => 123_400,
        ]);

        self::assertSame(42, $redacted['employee_id']);
        $profile = $redacted['profile'];
        self::assertIsArray($profile);
        self::assertSame('[REDACTED]', $profile['birth_number']);
        self::assertSame('[REDACTED]', $profile['bank_account']);
        self::assertSame('[REDACTED]', $profile['diagnosis']);
        self::assertSame('[REDACTED]', $redacted['monthly_gross']);
    }

    public function testDatabaseErrorHidesAllParametersForPayrollSensitiveColumns(): void
    {
        foreach ([
            'UPDATE payroll_employees SET birth_number = ? WHERE id = ?',
            'INSERT INTO payroll_person_accounts (iban_ciphertext, supplier_id) VALUES (?, ?)',
            'INSERT INTO payroll_sickness (diagnosis, employee_id) VALUES (?, ?)',
        ] as $sql) {
            $logger = $this->createMock(LoggerInterface::class);
            $logger->expects(self::once())
                ->method('error')
                ->with(
                    self::stringContains('DB error'),
                    self::callback(static fn (array $context): bool =>
                        $context['params'] === [
                            '__redacted__' => '*** params hidden (sensitive column referenced) ***',
                        ]),
                );

            DbErrorLogger::log(
                $logger,
                new PDOException('synthetic failure'),
                $sql,
                ['SYNTHETIC-SECRET', 42],
            );
        }
    }
}
