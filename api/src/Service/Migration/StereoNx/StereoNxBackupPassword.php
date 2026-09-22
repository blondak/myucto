<?php

declare(strict_types=1);

namespace MyInvoice\Service\Migration\StereoNx;

/** Výchozí heslo záloh Stereo NX; XOR je pouze obfuskace, nikoli ochrana tajemství. */
final class StereoNxBackupPassword
{
    private const MASK = 167;
    private const BYTES = [195, 229, 248, 247, 231, 212, 212, 240, 151, 245, 195, 248, 150, 248, 233, 255];

    public static function value(): string
    {
        return implode('', array_map(static fn (int $byte): string => chr($byte ^ self::MASK), self::BYTES));
    }
}
