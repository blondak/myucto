<?php

declare(strict_types=1);

namespace Tests\Unit\Service\Migration\Abra;

use MyInvoice\Service\Migration\Abra\AbraOssSettingsImporter;
use PHPUnit\Framework\TestCase;

final class AbraOssSettingsImporterTest extends TestCase
{
    public function testUsesBeginningOfContinuousEuRegistration(): void
    {
        $state = AbraOssSettingsImporter::sourceState([
            ['platiOdData' => '', 'ossEU' => false],
            ['platiOdData' => '2025-02-01', 'ossEU' => true],
            ['platiOdData' => '2026-01-01', 'ossEU' => true, 'ossMimoEU' => false, 'ossDovoz' => false],
        ], [2026]);

        self::assertSame(['eu' => true, 'non_eu' => false, 'import' => false, 'valid_from' => '2025-02-01'], $state);
    }

    public function testLaterDisabledVersionDoesNotEnableTarget(): void
    {
        $state = AbraOssSettingsImporter::sourceState([
            ['platiOdData' => '', 'ossEU' => true],
            ['platiOdData' => '2026-01-01', 'ossEU' => false],
        ], [2026]);

        self::assertFalse($state['eu']);
        self::assertNull($state['valid_from']);
    }

    public function testUnknownSourceSettingDoesNotClaimEuRegistration(): void
    {
        self::assertNull(AbraOssSettingsImporter::sourceState([['platiOdData' => '']], [2026])['eu']);
    }
}
