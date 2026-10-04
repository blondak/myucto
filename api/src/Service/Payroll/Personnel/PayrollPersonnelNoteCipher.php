<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Personnel;

use MyInvoice\Service\Payroll\Document\PayrollDocumentKeyRing;

/**
 * Šifrování textu poznámek personálního spisu datovým klíčem osoby.
 *
 * Poznámky personalisty bývají to nejcitlivější, co o člověku firma vede
 * (zdravotní omezení, kázeňská řízení). Leží proto v databázi i v jejím dumpu
 * jen jako ciphertext a zahození klíče při výmazu osobních údajů je
 * znečitelní stejně jako soubory spisu ({@see PayrollPersonnelFileStorage}).
 */
final class PayrollPersonnelNoteCipher
{
    private const CIPHER = 'aes-256-gcm';
    private const PREFIX = 'pnote:v1:';
    private const NONCE_LEN = 12;
    private const TAG_LEN = 16;

    public function __construct(
        private readonly PayrollDocumentKeyRing $keyRing,
    ) {}

    public function encrypt(int $supplierId, int $employeeId, string $text, ?int $actorUserId): string
    {
        $nonce = random_bytes(self::NONCE_LEN);
        $tag = '';
        $cipher = openssl_encrypt(
            $text,
            self::CIPHER,
            $this->keyRing->dataKeyForWrite($supplierId, $employeeId, $actorUserId),
            OPENSSL_RAW_DATA,
            $nonce,
            $tag,
            self::aad($supplierId, $employeeId),
        );
        if ($cipher === false) {
            throw new \RuntimeException('Poznámku se nepodařilo zašifrovat.');
        }

        return self::PREFIX . base64_encode($nonce . $cipher . $tag);
    }

    /**
     * @throws \MyInvoice\Service\Payroll\Document\PayrollDocumentKeyDestroyedException
     */
    public function decrypt(int $supplierId, int $employeeId, string $stored): string
    {
        if (!str_starts_with($stored, self::PREFIX)) {
            throw new \RuntimeException('Poznámka není zašifrovaná.');
        }
        $blob = base64_decode(substr($stored, strlen(self::PREFIX)), true);
        if ($blob === false || strlen($blob) < self::NONCE_LEN + self::TAG_LEN) {
            throw new \RuntimeException('Poznámka je poškozená.');
        }
        $plaintext = openssl_decrypt(
            substr($blob, self::NONCE_LEN, -self::TAG_LEN),
            self::CIPHER,
            $this->keyRing->dataKeyForRead($supplierId, $employeeId),
            OPENSSL_RAW_DATA,
            substr($blob, 0, self::NONCE_LEN),
            substr($blob, -self::TAG_LEN),
            self::aad($supplierId, $employeeId),
        );
        if ($plaintext === false) {
            throw new \RuntimeException('Poznámka je poškozená.');
        }

        return $plaintext;
    }

    private static function aad(int $supplierId, int $employeeId): string
    {
        return 'payroll-personnel-note:' . $supplierId . ':' . $employeeId;
    }
}
