<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Submission\Jmhz\Transport;

use MyInvoice\Service\Payroll\Submission\Jmhz\JmhzControlId;

/**
 * Jedna chyba z protokolu. Kód chyby je podle katalogu kontrol offsetovaný:
 * DIS = ID kontroly + 20000, cJMHZ = ID kontroly + 40000, takže se z něj dá ID
 * kontroly spočítat zpět. Kulaté 20000 a 40000 jsou v ukázkách obálkové
 * „Technická chyba" s detailem v textu, ne kontroly — ID proto nemají.
 *
 * Ostatní kódy jsou platformní (odmítnutí na vstupu, obálka, podpis, šifrování)
 * a musí být v doloženém katalogu; neznámý kód je tvrdá chyba, ne „nezařazeno".
 */
final readonly class JmhzProtocolError
{
    private const DIS_OFFSET = 20_000;
    private const CJMHZ_OFFSET = 40_000;
    private const RANGE = 19_999;

    /** Doložené platformní kódy z pokynů (atribut 10018 „Důvod odmítnutí"). */
    private const PLATFORM_CODES = [
        1, 2, 3, 4, 5, 6, 7, 8, 9, 10, 11, 12, 13, 14, 16, 17, 18, 19, 20, 21,
        22, 23, 24, 25, 26, 27, 61, 62, 63, 64,
        101, 102, 103, 104, 105, 201, 202, 300, 302, 305, 310, 400,
        17800, 17801, 17803, 17804, 17805, 17806, 17807, 17808, 17810, 17814,
        17820, 17824, 17830, 17832, 17833, 17835, 17836, 17837, 17839, 17840,
    ];

    private function __construct(
        public int $code,
        public string $message,
        public JmhzProtocolErrorOrigin $origin,
        public ?JmhzControlId $controlId,
    ) {}

    public static function fromCode(int $code, string $message): self
    {
        if ($code === self::DIS_OFFSET) {
            return new self($code, $message, JmhzProtocolErrorOrigin::Dis, null);
        }
        if ($code === self::CJMHZ_OFFSET) {
            return new self($code, $message, JmhzProtocolErrorOrigin::Cjmhz, null);
        }
        if ($code > self::DIS_OFFSET && $code <= self::DIS_OFFSET + self::RANGE) {
            return new self(
                $code,
                $message,
                JmhzProtocolErrorOrigin::Dis,
                new JmhzControlId($code - self::DIS_OFFSET),
            );
        }
        if ($code > self::CJMHZ_OFFSET && $code <= self::CJMHZ_OFFSET + self::RANGE) {
            return new self(
                $code,
                $message,
                JmhzProtocolErrorOrigin::Cjmhz,
                new JmhzControlId($code - self::CJMHZ_OFFSET),
            );
        }
        if (in_array($code, self::PLATFORM_CODES, true)) {
            return new self(
                $code,
                $message,
                JmhzProtocolErrorOrigin::Platform,
                null,
            );
        }

        throw new JmhzTransportException(
            'jmhz_protocol_error_code_unknown',
            "Kód chyby {$code} není v doloženém katalogu ani v rozsahu kontrol DIS a cJMHZ.",
        );
    }

    public function requireControlId(): JmhzControlId
    {
        if ($this->controlId === null) {
            throw new JmhzTransportException(
                'jmhz_protocol_error_not_a_control',
                "Kód chyby {$this->code} neodkazuje na kontrolu z katalogu.",
            );
        }

        return $this->controlId;
    }
}
