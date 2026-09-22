<?php

declare(strict_types=1);

namespace MyInvoice\Service\Migration\MoneyS3;

use MyInvoice\Service\Migration\Shared\VatCoefficientSeeder as SharedSeeder;

/**
 * Koeficient krácení odpočtu (§ 76) převedených let Money S3.
 *
 * Money ho v záloze nedrží tak, aby šel spolehlivě přečíst; výpočet a pravidla jsou
 * společná všem převodům ({@see SharedSeeder}).
 */
final class VatCoefficientSeeder
{
    public const STEP = SharedSeeder::STEP;

    public function __construct(
        private readonly SharedSeeder $seeder,
    ) {}

    public function run(ImportContext $ctx): void
    {
        $this->seeder->seed($ctx->supplierId, array_keys($ctx->periods), $ctx->userId, $ctx->protocol);
    }
}
