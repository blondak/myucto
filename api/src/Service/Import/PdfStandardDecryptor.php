<?php

declare(strict_types=1);

namespace MyInvoice\Service\Import;

/**
 * Dešifrování PDF zamčeného Standard security handlerem s PRÁZDNÝM uživatelským
 * heslem (ISO 32000-1 § 7.6.3, ISO 32000-2 § 7.6.4.3).
 *
 * Tak PDF zamyká řada fakturačních systémů (iÚčto přes mPDF, …): kdokoliv ho
 * otevře, jen oprávnění (tisk, kopírování) řídí heslo vlastníka. Streamy včetně
 * vložené ISDOC přílohy jsou ale zašifrované, takže bez dešifrování je
 * {@see PdfIsdocExtractor} nevidí a doklad by zbytečně padal do AI vytěžování.
 *
 * Podporované: V1/V2 (RC4 40–128 bit, R2/R3), V4 (crypt filter RC4 nebo AESV2, R4),
 * V5 (AESV3, R5 i R6). PDF s neprázdným uživatelským heslem nebo jiným handlerem
 * vrátí z {@see fromPdf()} null — otevřít ho nejde, ISDOC z něj nevytáhneme.
 */
final class PdfStandardDecryptor
{
    private const PAD = "\x28\xBF\x4E\x5E\x4E\x75\x8A\x41\x64\x00\x4E\x56\xFF\xFA\x01\x08"
        . "\x2E\x2E\x00\xB6\xD0\x68\x3E\x80\x2F\x0C\xA9\xFE\x64\x53\x69\x7A";

    private const METHOD_NONE = 'none';
    private const METHOD_RC4 = 'rc4';
    private const METHOD_AESV2 = 'aesv2';
    private const METHOD_AESV3 = 'aesv3';

    private function __construct(
        private readonly string $fileKey,
        private readonly string $streamMethod,
    ) {
    }

    /** Vrátí dešifrovač, nebo null, když PDF šifrované není nebo ho otevřít neumíme. */
    public static function fromPdf(string $pdf): ?self
    {
        if (!preg_match_all('#/Encrypt\s*(<<|(\d+)\s+(\d+)\s+R)#', $pdf, $m, PREG_OFFSET_CAPTURE | PREG_SET_ORDER)) {
            return null;
        }
        $hit = end($m);
        $refPos = $hit[0][1];
        if ($hit[1][0] === '<<') {
            $dict = (new PdfObjectReader($pdf))->readAt($hit[1][1]);
        } else {
            $dict = self::readIndirectDict($pdf, (int) $hit[2][0], (int) $hit[3][0]);
        }
        if (!is_array($dict) || ($dict['Filter'] ?? null) !== '/Standard') {
            return null;
        }

        try {
            return self::open($dict, self::findFirstId($pdf, $refPos));
        } catch (\Throwable) {
            return null;
        }
    }

    public function decryptStream(string $data, int $objNum, int $gen): ?string
    {
        switch ($this->streamMethod) {
            case self::METHOD_NONE:
                return $data;
            case self::METHOD_RC4:
                return self::rc4($this->objectKey($objNum, $gen, false), $data);
            case self::METHOD_AESV2:
                return self::aesCbcDecrypt($this->objectKey($objNum, $gen, true), $data);
            case self::METHOD_AESV3:
                return self::aesCbcDecrypt($this->fileKey, $data);
        }
        return null;
    }

    /** @param array<string,mixed> $dict */
    private static function open(array $dict, ?string $firstId): ?self
    {
        $v = (int) ($dict['V'] ?? 0);
        $r = (int) ($dict['R'] ?? 0);
        $o = $dict['O'] ?? null;
        $u = $dict['U'] ?? null;
        if (!is_string($o) || !is_string($u)) {
            return null;
        }

        if ($v === 5) {
            $key = self::userKeyAesV3($r, $u, $dict['UE'] ?? null);
            return $key === null ? null : new self($key, self::METHOD_AESV3);
        }

        if ($firstId === null || !in_array($v, [1, 2, 4], true) || !in_array($r, [2, 3, 4], true)) {
            return null;
        }
        $method = self::METHOD_RC4;
        $lengthBits = $v === 1 ? 40 : (int) ($dict['Length'] ?? 40);
        if ($v === 4) {
            $method = self::cryptFilterMethod($dict);
            if ($method === null) {
                return null;
            }
            $lengthBits = 128;
        }
        $n = $r === 2 ? 5 : intdiv($lengthBits, 8);
        if ($n < 5 || $n > 16) {
            return null;
        }
        $encryptMetadata = ($dict['EncryptMetadata'] ?? true) !== false;

        $p = pack('V', ((int) ($dict['P'] ?? 0)) & 0xFFFFFFFF);
        $hash = md5(self::PAD . substr($o, 0, 32) . $p . $firstId
            . ($r >= 4 && !$encryptMetadata ? "\xFF\xFF\xFF\xFF" : ''), true);
        if ($r >= 3) {
            for ($i = 0; $i < 50; $i++) {
                $hash = md5(substr($hash, 0, $n), true);
            }
        }
        $key = substr($hash, 0, $n);

        if ($r === 2) {
            $ok = self::rc4($key, self::PAD) === substr($u, 0, 32);
        } else {
            $check = self::rc4($key, md5(self::PAD . $firstId, true));
            for ($i = 1; $i <= 19; $i++) {
                $check = self::rc4($key ^ str_repeat(chr($i), $n), $check);
            }
            $ok = $check === substr($u, 0, 16);
        }
        return $ok ? new self($key, $method) : null;
    }

    /** @param array<string,mixed> $dict */
    private static function cryptFilterMethod(array $dict): ?string
    {
        $name = $dict['StmF'] ?? '/Identity';
        if ($name === '/Identity') {
            return self::METHOD_NONE;
        }
        $filter = $dict['CF'][substr((string) $name, 1)] ?? null;
        return match (is_array($filter) ? ($filter['CFM'] ?? '/None') : null) {
            '/V2'    => self::METHOD_RC4,
            '/AESV2' => self::METHOD_AESV2,
            '/None'  => self::METHOD_NONE,
            default  => null,
        };
    }

    private static function userKeyAesV3(int $r, string $u, mixed $ue): ?string
    {
        if (!is_string($ue) || strlen($u) < 48 || strlen($ue) < 32) {
            return null;
        }
        $validationSalt = substr($u, 32, 8);
        $keySalt = substr($u, 40, 8);
        $hash = $r >= 6 ? self::hashR6('', $validationSalt) : hash('sha256', $validationSalt, true);
        if (!hash_equals(substr($u, 0, 32), $hash)) {
            return null;
        }
        $intermediate = $r >= 6 ? self::hashR6('', $keySalt) : hash('sha256', $keySalt, true);
        $key = openssl_decrypt(substr($ue, 0, 32), 'aes-256-cbc', $intermediate,
            OPENSSL_RAW_DATA | OPENSSL_ZERO_PADDING, str_repeat("\0", 16));
        return is_string($key) && strlen($key) === 32 ? $key : null;
    }

    /** ISO 32000-2 algoritmus 2.B pro uživatelské heslo (bez U dat). */
    private static function hashR6(string $password, string $salt): string
    {
        $k = hash('sha256', $password . $salt, true);
        for ($round = 0; ; $round++) {
            $k1 = str_repeat($password . $k, 64);
            $e = (string) openssl_encrypt($k1, 'aes-128-cbc', substr($k, 0, 16),
                OPENSSL_RAW_DATA | OPENSSL_ZERO_PADDING, substr($k, 16, 16));
            $mod = array_sum(unpack('C16', $e)) % 3;
            $k = hash(['sha256', 'sha384', 'sha512'][$mod], $e, true);
            if ($round >= 63 && ord($e[strlen($e) - 1]) <= $round - 32) {
                break;
            }
        }
        return substr($k, 0, 32);
    }

    private function objectKey(int $objNum, int $gen, bool $aes): string
    {
        $seed = $this->fileKey . substr(pack('V', $objNum), 0, 3) . substr(pack('V', $gen), 0, 2) . ($aes ? 'sAlT' : '');
        return substr(md5($seed, true), 0, min(strlen($this->fileKey) + 5, 16));
    }

    private static function aesCbcDecrypt(string $key, string $data): ?string
    {
        if (strlen($data) < 32 || strlen($data) % 16 !== 0) {
            return null;
        }
        $cipher = strlen($key) === 32 ? 'aes-256-cbc' : 'aes-128-cbc';
        $plain = openssl_decrypt(substr($data, 16), $cipher, $key, OPENSSL_RAW_DATA, substr($data, 0, 16));
        return is_string($plain) ? $plain : null;
    }

    /** RC4 v čistém PHP — OpenSSL 3 ho má jen v legacy provideru, který v kontejneru není. */
    private static function rc4(string $key, string $data): string
    {
        $s = range(0, 255);
        $keyLen = strlen($key);
        for ($i = 0, $j = 0; $i < 256; $i++) {
            $j = ($j + $s[$i] + ord($key[$i % $keyLen])) & 0xFF;
            [$s[$i], $s[$j]] = [$s[$j], $s[$i]];
        }
        $out = '';
        $len = strlen($data);
        for ($n = 0, $i = 0, $j = 0; $n < $len; $n++) {
            $i = ($i + 1) & 0xFF;
            $j = ($j + $s[$i]) & 0xFF;
            [$s[$i], $s[$j]] = [$s[$j], $s[$i]];
            $out .= $data[$n] ^ chr($s[($s[$i] + $s[$j]) & 0xFF]);
        }
        return $out;
    }

    /** @return array<string,mixed>|null */
    private static function readIndirectDict(string $pdf, int $num, int $gen): ?array
    {
        if (!preg_match_all('#(?<!\d)' . $num . '\s+' . $gen . '\s+obj\b#', $pdf, $m, PREG_OFFSET_CAPTURE)) {
            return null;
        }
        $last = end($m[0]);
        $value = (new PdfObjectReader($pdf))->readAt($last[1] + strlen($last[0]));
        return is_array($value) ? $value : null;
    }

    /**
     * První prvek `/ID` z trailer slovníku (resp. slovníku XRef streamu), ve kterém
     * leží odkaz na `/Encrypt`. Hledáme nejbližší `/ID` kolem toho odkazu.
     */
    private static function findFirstId(string $pdf, int $encryptRefPos): ?string
    {
        $from = max(0, $encryptRefPos - 2048);
        $window = substr($pdf, $from, 4096);
        if (!preg_match_all('#/ID\s*\[#', $window, $m, PREG_OFFSET_CAPTURE)) {
            return null;
        }
        $best = null;
        foreach ($m[0] as $hit) {
            $abs = $from + $hit[1];
            if ($best === null || abs($abs - $encryptRefPos) < abs($best - $encryptRefPos)) {
                $best = $abs;
            }
        }
        $ids = (new PdfObjectReader($pdf))->readAt($best + 3);
        return is_array($ids) && isset($ids[0]) && is_string($ids[0]) ? $ids[0] : null;
    }
}
