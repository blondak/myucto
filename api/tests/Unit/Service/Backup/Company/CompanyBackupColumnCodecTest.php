<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Backup\Company;

use MyInvoice\Service\Backup\Company\CompanyBackupColumnCodec;
use MyInvoice\Service\Backup\Company\CompanyBackupDataSourceException;
use PHPUnit\Framework\TestCase;

final class CompanyBackupColumnCodecTest extends TestCase
{
    public function testBinaryHexRoundTripsEveryByteWithoutUtf8Substitution(): void
    {
        $binary = implode('', array_map(chr(...), range(0, 255)));
        $codec = CompanyBackupColumnCodec::BinaryHex;
        $encoded = $codec->encode($binary, 'table:synthetic_records', 'digest');

        self::assertIsString($encoded);
        self::assertSame(512, strlen($encoded));
        self::assertMatchesRegularExpression('/^[0-9a-f]+$/D', $encoded);
        self::assertSame(
            $binary,
            $codec->decode($encoded, 'table:synthetic_records', 'digest'),
        );
    }

    public function testBinaryHexRejectsNonCanonicalPayload(): void
    {
        try {
            CompanyBackupColumnCodec::BinaryHex->decode(
                'B131',
                'table:synthetic_records',
                'digest',
            );
            self::fail('Binární payload musí mít jedinou kanonickou reprezentaci.');
        } catch (CompanyBackupDataSourceException $e) {
            self::assertSame('data_column_codec_payload_invalid', $e->errorCode);
            self::assertSame('digest', $e->column);
        }
    }
}
