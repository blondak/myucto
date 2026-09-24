<?php

declare(strict_types=1);

namespace MyInvoice\Service\Migration\StereoNx;

use MyInvoice\Service\Migration\Shared\MigrationCompanyIdentity;

/**
 * Ověřený tvar firma.bin: TPF0, verze 251, popis polí a jeden řádek.
 * Typové značky: https://docwiki.embarcadero.com/Libraries/Athens/en/System.Classes.TValueType
 * Podporujeme jen nutnou podmnožinu; nikdy nevyhledáváme IČO regulárem v celém blobu.
 * Osobní identifikátory ani pole nesouvisející s firemním profilem nevracíme.
 */
final class StereoNxCompanyMetadata
{
    private int $position = 4;
    private int $nodes = 0;

    private function __construct(#[\SensitiveParameter] private readonly string $bytes) {}

    /** @return array{ico:string,dic:string,name:string,vat_payer:bool,company_profile:array<string,?string>} */
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
        $profile = [];
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
            if (in_array($name, ['ObchJmeno', 'Ulice', 'CisloPopisne', 'CisloOrientacni', 'Misto', 'PSC', 'Stat', 'Email', 'Telefon', 'WWW'], true)) {
                if ($type !== 'String' || ($value !== null && !is_string($value))) {
                    throw new StereoNxException('company_metadata_profile', 'Údaje o firmě ve firma.bin nemají očekávaný typ.');
                }
                $profile[$name] = $value === null ? null : trim($value);
            }
        }
        if ($position !== count($row) || count($identity) !== 4 || !preg_match('/^[0-9]{8}$/D', trim($identity['ICO']))) {
            throw new StereoNxException('company_metadata_identity', 'Ve firma.bin chybí jednoznačná identita firmy.');
        }
        $number = $profile['CisloPopisne'] ?? '';
        if (($profile['CisloOrientacni'] ?? '') !== '') {
            $number .= ($number === '' ? '' : '/') . $profile['CisloOrientacni'];
        }
        $street = array_filter([$profile['Ulice'] ?? null, $number], static fn (?string $v): bool => $v !== null && $v !== '');
        $optional = static fn (?string $value): ?string => $value === null || $value === '' ? null : $value;
        $country = mb_strtoupper($profile['Stat'] ?? '', 'UTF-8');
        // Existující cílová firma: source_backup je údaj pro porovnání, nikoli
        // důvod měnit registraci DPH. ARES ani zakládání nové firmy sem nepatří.
        $company = new MigrationCompanyIdentity(
            ico: trim($identity['ICO']), name: trim($identity['Nazev']), dic: trim($identity['DIC']),
            address: ['street' => implode(' ', $street), 'city' => $profile['Misto'] ?? '', 'zip' => $profile['PSC'] ?? ''],
            vatPayer: $identity['PlatDPH'], vatSource: 'source_backup', taxpayerType: null,
            nace: null, category: null, audit: null, firstPeriodStart: null, notes: [],
        );
        return ['ico' => $company->ico, 'dic' => $company->dic,
            'name' => $company->name, 'vat_payer' => $company->vatPayer,
            'company_profile' => [
                'company_name' => $optional($profile['ObchJmeno'] ?? null) ?? $optional(trim($identity['Nazev'])),
                'street' => $optional($company->address['street']),
                'city' => $optional($company->address['city']),
                'zip' => $optional($company->address['zip']),
                'email' => $optional($profile['Email'] ?? null),
                'phone' => $optional($profile['Telefon'] ?? null),
                'web' => $optional($profile['WWW'] ?? null),
                'country_code' => in_array($country, ['ČR', 'CZ'], true) ? 'CZ' : null,
            ]];
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
