<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Migration\StereoNx;

use MyInvoice\Service\Migration\StereoNx\StereoNxCompanyMetadata;
use MyInvoice\Service\Migration\StereoNx\StereoNxException;
use PHPUnit\Framework\TestCase;

final class StereoNxCompanyMetadataTest extends TestCase
{
    private function encode(mixed $value): string
    {
        if (is_array($value)) return "\x01" . implode('', array_map($this->encode(...), $value)) . "\0";
        if (is_bool($value)) return $value ? "\x09" : "\x08";
        if (is_int($value)) return "\x03" . pack('v', $value);
        return "\x14" . pack('V', strlen($value)) . $value;
    }

    private function fixture(array $changes = [], int $version = 251): string
    {
        $fields = ['ICO' => ['String', '00000000'], 'DIC' => ['String', 'CZ00000000'],
            'Nazev' => ['String', 'Syntetická testovací firma'], 'PlatDPH' => ['Boolean', true],
            'UnrelatedPrivateField' => ['String', 'synthetic-private-value']] ;
        $fields = $changes + $fields;
        $schema = [];
        $row = [0];
        foreach ($fields as $name => [$type, $value]) {
            array_push($schema, $name, $type, 0, $name, '', 0, false, false, 'NONE', '');
            $row[] = $value === null;
            if ($value !== null) $row[] = $value;
        }
        return 'TPF0' . $this->encode($version) . $this->encode($schema) . $this->encode([])
            . $this->encode([count($fields), ...array_fill(0, count($fields), 1)]) . $this->encode([$row]);
    }

    public function testReadsNamedFieldsAndReturnsOnlyCompanyIdentity(): void
    {
        $result = StereoNxCompanyMetadata::parse($this->fixture());
        self::assertSame(['ico' => '00000000', 'dic' => 'CZ00000000',
            'name' => 'Syntetická testovací firma', 'vat_payer' => true], $result);
        self::assertStringNotContainsString('synthetic-private-value', json_encode($result));
    }

    public function testNullUnrelatedFieldDoesNotShiftFollowingValues(): void
    {
        $result = StereoNxCompanyMetadata::parse($this->fixture(['UnrelatedPrivateField' => ['String', null]]));
        self::assertSame('00000000', $result['ico']);
        self::assertTrue($result['vat_payer']);
    }

    public function testUnassignedPayerFlagIsNotTreatedAsNonPayer(): void
    {
        $this->expectException(StereoNxException::class);
        StereoNxCompanyMetadata::parse($this->fixture(['PlatDPH' => ['Boolean', null]]));
    }

    public function testWrongDeclaredIdentityTypeIsRejected(): void
    {
        $this->expectException(StereoNxException::class);
        StereoNxCompanyMetadata::parse($this->fixture(['ICO' => ['Integer', '00000000']]));
    }

    public function testTrailingDataIsRejected(): void
    {
        $this->expectException(StereoNxException::class);
        StereoNxCompanyMetadata::parse($this->fixture() . 'unexpected');
    }

    public function testTruncatedStringIsRejected(): void
    {
        $this->expectException(StereoNxException::class);
        StereoNxCompanyMetadata::parse(substr($this->fixture(), 0, -4));
    }

    public function testUnknownVersionIsRejected(): void
    {
        $this->expectException(StereoNxException::class);
        StereoNxCompanyMetadata::parse($this->fixture(version: 252));
    }

    public function testUnrelatedIdentityLikeTextDoesNotSupplyMissingIdentity(): void
    {
        $this->expectException(StereoNxException::class);
        StereoNxCompanyMetadata::parse($this->fixture(['ICO' => ['String', '']]));
    }
}
