<?php

declare(strict_types=1);

namespace MyInvoice\Service\Migration\StereoNx;

/**
 * Ověřený tvar firma.bin: TPF0, verze 251, popis polí a jeden řádek.
 * Typové značky: https://docwiki.embarcadero.com/Libraries/Athens/en/System.Classes.TValueType
 * Podporujeme jen nutnou podmnožinu; nikdy nevyhledáváme IČO regulárem v celém blobu.
 * Hodnoty ostatních polí (včetně osobních údajů) nejsou součástí výsledku.
 */
final class StereoNxCompanyMetadata
{
    private int $position = 4;
    private int $nodes = 0;

    private function __construct(#[\SensitiveParameter] private readonly string $bytes) {}

    /** @return array{ico:string,dic:string,name:string,vat_payer:bool} */
    public static function parse(#[\SensitiveParameter] string $bytes): array
    {
        if (strlen($bytes) > 1048576 || !str_starts_with($bytes, 'TPF0')) {
            throw new StereoNxException('company_metadata_format', 'Nepodporovaný formát firma.bin.');
        }
        $reader = new self($bytes);
        $version = $reader->node();
        $schema = $reader->node();
        $indexes = $reader->node();
        $types = $reader->node();
        $rows = $reader->node();
        if ($reader->position !== strlen($bytes) || $version !== 251 || !is_array($schema)
            || count($schema) % 10 !== 0 || $schema === [] || $indexes !== []
            || !is_array($types) || count($types) !== count($schema) / 10 + 1
            || $types[0] !== count($schema) / 10 || !is_array($rows) || count($rows) !== 1
            || !is_array($rows[0]) || ($rows[0][0] ?? null) !== 0) {
            throw new StereoNxException('company_metadata_layout', 'Neověřené uspořádání firma.bin.');
        }
        $row = $rows[0];
        $position = 1;
        $identity = [];
        $names = [];
        foreach (array_chunk($schema, 10) as $field) {
            [$name, $type] = $field;
            if (!is_string($name) || !is_string($type) || $name === '' || isset($names[strtolower($name)])) {
                throw new StereoNxException('company_metadata_schema', 'Nejednoznačný popis polí firma.bin.');
            }
            $names[strtolower($name)] = true;
            $isNull = $row[$position++] ?? null;
            if (!is_bool($isNull)) {
                throw new StereoNxException('company_metadata_null', 'Neplatný příznak prázdného pole firma.bin.');
            }
            if (!$isNull && !array_key_exists($position, $row)) {
                throw new StereoNxException('company_metadata_truncated', 'Neúplný řádek firma.bin.');
            }
            $value = $isNull ? null : $row[$position++];
            $expectedType = match ($name) { 'ICO', 'DIC', 'Nazev' => 'String', 'PlatDPH' => 'Boolean', default => null };
            if ($expectedType !== null) {
                if ($type !== $expectedType || ($type === 'String' ? !is_string($value) : !is_bool($value))) {
                    throw new StereoNxException('company_metadata_identity', 'Identita nebo příznak DPH ve firma.bin nejsou ověřitelné.');
                }
                $identity[$name] = $value;
            }
        }
        if ($position !== count($row) || count($identity) !== 4 || !preg_match('/^[0-9]{8}$/D', trim($identity['ICO']))) {
            throw new StereoNxException('company_metadata_identity', 'Ve firma.bin chybí jednoznačná identita firmy.');
        }
        return ['ico' => trim($identity['ICO']), 'dic' => trim($identity['DIC']),
            'name' => trim($identity['Nazev']), 'vat_payer' => $identity['PlatDPH']];
    }

    private function node(int $depth = 0): mixed
    {
        if ($depth > 8 || ++$this->nodes > 20000) {
            throw new StereoNxException('company_metadata_limit', 'Struktura firma.bin překračuje povolený rozsah.');
        }
        $type = ord($this->take(1));
        return match ($type) {
            0 => null,
            1 => $this->list($depth + 1),
            2 => unpack('c', $this->take(1))[1],
            3 => ($value = unpack('v', $this->take(2))[1]) >= 0x8000 ? $value - 0x10000 : $value,
            4 => ($value = unpack('V', $this->take(4))[1]) >= 0x80000000 ? $value - 0x100000000 : $value,
            // Extended obsahuje datum/čas. Pro identitu ho nepotřebujeme a nepřekládáme.
            5 => ['opaque_extended' => $this->take(10)],
            6, 7 => $this->text(ord($this->take(1)), false),
            8 => false,
            9 => true,
            12 => $this->text(unpack('V', $this->take(4))[1], false),
            20 => $this->text(unpack('V', $this->take(4))[1], true),
            default => throw new StereoNxException('company_metadata_type', 'Nepodporovaný datový typ firma.bin.'),
        };
    }

    /** @return list<mixed> */
    private function list(int $depth): array
    {
        $values = [];
        while (true) {
            if ($this->position >= strlen($this->bytes)) {
                throw new StereoNxException('company_metadata_truncated', 'Neúplný seznam firma.bin.');
            }
            if ($this->bytes[$this->position] === "\0") {
                $this->position++;
                return $values;
            }
            $values[] = $this->node($depth);
        }
    }

    private function text(int $length, bool $utf8): string
    {
        $bytes = $this->take($length);
        $text = $utf8 ? $bytes : @iconv('Windows-1250', 'UTF-8', $bytes);
        if ($text === false || !mb_check_encoding($text, 'UTF-8')) {
            throw new StereoNxException('company_metadata_encoding', 'Neplatné kódování firma.bin.');
        }
        return $text;
    }

    private function take(int $length): string
    {
        if ($length < 0 || $length > strlen($this->bytes) - $this->position) {
            throw new StereoNxException('company_metadata_truncated', 'Neúplná data firma.bin.');
        }
        $bytes = substr($this->bytes, $this->position, $length);
        $this->position += $length;
        return $bytes;
    }
}
