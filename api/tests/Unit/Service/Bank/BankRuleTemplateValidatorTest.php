<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Bank;

use MyInvoice\Service\Accounting\Bank\BankRuleTemplateValidator;
use MyInvoice\Service\Accounting\PostingException;
use PHPUnit\Framework\TestCase;

final class BankRuleTemplateValidatorTest extends TestCase
{
    public function testNormalizesCompleteTemplate(): void
    {
        $result = (new BankRuleTemplateValidator())->normalize($this->valid([
            'counterparty_prefix' => '000705',
            'message_contains' => 'Bankovní poplatek 07/2026',
            'is_active' => 'false',
        ]));

        self::assertSame('705', $result['counterparty_prefix']);
        self::assertSame('bankovni poplatek', $result['message_contains']);
        self::assertFalse($result['is_active']);
    }

    public function testRejectsTemplateWithoutMatchingCriterion(): void
    {
        try {
            (new BankRuleTemplateValidator())->normalize($this->valid([
                'counterparty_bank' => null,
                'counterparty_prefix' => null,
                'vs_placeholder' => null,
                'message_contains' => null,
            ]));
            self::fail('Expected PostingException.');
        } catch (PostingException $e) {
            self::assertSame('template_criteria_missing', $e->errorCode);
        }
    }

    public function testRejectsUnknownOperationType(): void
    {
        try {
            (new BankRuleTemplateValidator())->normalize($this->valid(['operation_type' => 'document.invoice']));
            self::fail('Expected PostingException.');
        } catch (PostingException $e) {
            self::assertSame('invalid_operation_type', $e->errorCode);
        }
    }

    public function testRejectsUnknownPlaceholder(): void
    {
        try {
            (new BankRuleTemplateValidator())->normalize($this->valid(['vs_placeholder' => '{custom}']));
            self::fail('Expected PostingException.');
        } catch (PostingException $e) {
            self::assertSame('invalid_placeholder', $e->errorCode);
        }
    }

    /** @param array<string,mixed> $overrides @return array<string,mixed> */
    private function valid(array $overrides = []): array
    {
        return array_replace([
            'template_key' => 'bank.fee.test',
            'name_cs' => 'Bankovní poplatek',
            'name_en' => 'Bank fee',
            'direction' => 'outgoing',
            'operation_type' => 'bank.fee',
            'counterparty_bank' => '0100',
            'counterparty_prefix' => null,
            'vs_placeholder' => null,
            'message_contains' => null,
            'rule_key' => 'bank.fee',
            'default_priority' => 40,
            'sort_order' => 10,
            'is_active' => true,
        ], $overrides);
    }
}
